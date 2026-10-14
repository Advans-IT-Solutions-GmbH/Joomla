<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Service;

use Advans\Component\DmarcReports\Administrator\Helper\CryptoHelper;
use Advans\Component\DmarcReports\Administrator\Helper\ImapClient;
use Advans\Component\DmarcReports\Administrator\Helper\ImapException;
use Advans\Component\DmarcReports\Administrator\Helper\Ingestor;
use Advans\Component\DmarcReports\Administrator\Helper\ReportArchive;
use Advans\Component\DmarcReports\Administrator\Helper\SourceIdentity;
use Advans\Component\DmarcReports\Administrator\Helper\SourceLock;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Polls a single configured IMAP source, streams the messages it holds through
 * the {@see Ingestor}, and persists the run outcome together with the import
 * cursor under the same lock discipline the on-demand controller used.
 *
 * The logic lives here, not in a controller, so the on-demand import action and
 * the automatic scheduler task share one implementation: both paths advance the
 * cursor behind a {@see SourceLock} row lock, so an automatic run and a
 * simultaneous manual poll of the same source can never double-process a message
 * or move the stored high-water UID backwards.
 *
 * @since  1.1.0
 */
final class SourcePoller
{
    /**
     * Outcome of a poll: the run was clean.
     */
    public const STATUS_OK = 'ok';

    /**
     * Outcome of a poll: at least one report could not be decoded because a PHP
     * extension is missing. The run advanced past it; the operator must install
     * the extension.
     */
    public const STATUS_BLOCKED = 'blocked';

    /**
     * Outcome of a poll: the mailbox could not be reached, authenticated or
     * configured. The stored cursor is left untouched so the next run retries.
     */
    public const STATUS_ERROR = 'error';

    private DatabaseInterface $db;

    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * Poll one source row and record the run. Returns a structured result so a
     * caller can build a flash message (controller) or a task log line and exit
     * status (scheduler task) without re-implementing the run semantics.
     *
     * @param   object  $source           A published `#__dmarc_sources` row.
     * @param   bool    $allowAutoCreate   Whether an unknown policy domain may be
     *                                     auto-registered (requires core.create).
     *
     * @return  array{status:string, message:string, blocked:int, summary:array<string,int|string>}
     */
    public function poll(object $source, bool $allowAutoCreate): array
    {
        $sourceId = (int) $source->id;
        $domainId = (int) $source->domain_id;

        // Decide whether this poll forces the source's bound domain onto every
        // report, or lets each report be attributed to its own policy domain so
        // newly seen domains are registered. Forcing the bound id unconditionally
        // would make task_auto_create/auto_create_domains a no-op for scheduled
        // polling, because a forced domain always bypasses DomainRegistry.
        $forcedDomainId = self::forcedDomainId($domainId, $allowAutoCreate);

        try {
            $client   = new ImapClient($this->boundedConfig('imap_timeout', 20, 5, 120));
            $limit    = $this->boundedConfig('max_messages_per_run', 200, 10, 2000);
            $ingestor = new Ingestor($this->db, $allowAutoCreate);
            $summary  = $ingestor->newSummary();

            // Capture the mailbox identity this poll reads from, so the cursor
            // write below can detect a source edit that re-pointed the source at
            // a different mailbox mid-poll and refuse to persist a stale cursor.
            $polledIdentity = SourceIdentity::fingerprint($source);

            $stats = $client->process(
                [
                    'host'                => $source->host,
                    'port'                => (int) $source->port,
                    'security'            => $source->security,
                    'username'            => $source->username,
                    'password'            => CryptoHelper::decrypt((string) $source->password_enc),
                    'folder'              => $source->folder,
                    'limit'               => $limit,
                    'delete_after_import' => (int) $source->delete_after_import,
                    'uidvalidity'         => (int) ($source->uidvalidity ?? 0),
                    'last_uid'            => (int) ($source->last_uid ?? 0),
                    'deferred_uids'       => $this->parseUidList((string) ($source->deferred_uids ?? '')),
                ],
                function (array $message) use ($ingestor, $sourceId, $forcedDomainId, &$summary): string {
                    return $ingestor->ingestMessage($message, $sourceId, $forcedDomainId, $summary);
                }
            );

            $summary['skipped'] += (int) ($stats['transport_skipped'] ?? 0);

            $blocked = (int) ($summary['blocked'] ?? 0);
            $message = $this->summaryText($summary);
            $status  = self::STATUS_OK;

            if ($blocked > 0) {
                $status   = self::STATUS_BLOCKED;
                $message .= ' ' . Text::sprintf('COM_DMARCREPORTS_INGEST_BLOCKED', $blocked);
            }

            $this->recordRun(
                $sourceId,
                $status,
                $message,
                (int) ($stats['uidvalidity'] ?? 0),
                (int) ($stats['last_uid'] ?? 0),
                $polledIdentity,
                $this->formatUidList((array) ($stats['deferred_uids'] ?? []))
            );

            return ['status' => $status, 'message' => $message, 'blocked' => $blocked, 'summary' => $summary];
        } catch (ImapException $e) {
            // Only the fixed, localized failure category is surfaced; the
            // exception message is never shown, stored or logged, so a mailbox
            // credential can never leak into a flash message or the source row.
            $message = Text::_('COM_DMARCREPORTS_INGEST_FAILED') . ' '
                . Text::_($this->imapReasonKey($e->getCategory()));
            $this->recordRun($sourceId, self::STATUS_ERROR, $message);

            return ['status' => self::STATUS_ERROR, 'message' => $message, 'blocked' => 0, 'summary' => []];
        } catch (\Throwable $e) {
            $message = Text::_('COM_DMARCREPORTS_INGEST_FAILED');
            $this->recordRun($sourceId, self::STATUS_ERROR, $message);

            return ['status' => self::STATUS_ERROR, 'message' => $message, 'blocked' => 0, 'summary' => []];
        }
    }

    /**
     * Decide the domain id to force onto every report a poll ingests.
     *
     * A source is bound to one monitored domain. By default that bound domain is
     * forced onto every report the mailbox delivers, but only a report for the
     * bound domain itself or one of its subdomains is stored under it (see
     * {@see \Advans\Component\DmarcReports\Administrator\Helper\DomainBinding});
     * a report for a parent, sibling or unrelated domain is skipped, so a
     * single-tenant mailbox cannot cross-attribute reports. When automatic
     * domain creation is enabled — a
     * privileged opt-in gated by both the component option and core.create — the
     * bound domain is NOT forced: each report is attributed to its own policy
     * domain and a newly seen domain is registered by {@see DomainRegistry},
     * which is the whole point of an aggregating mailbox. Returning 0 selects
     * that resolve-from-report path; otherwise the bound id is forced.
     *
     * Exposed as a pure static so the forcing decision is unit-tested without an
     * IMAP transport or database.
     *
     * @since  1.1.0
     */
    public static function forcedDomainId(int $boundDomainId, bool $allowAutoCreate): int
    {
        return $allowAutoCreate ? 0 : $boundDomainId;
    }

    /**
     * Load every published source bound to a published domain that is configured
     * for automatic polling. The automatic task never touches an unpublished
     * source or one whose domain is unpublished, mirroring the backend lists.
     *
     * @return  object[]
     */
    public function activeSources(): array
    {
        $db    = $this->db;
        $query = $this->createDbQuery($db)
            ->select($db->quoteName('s') . '.*')
            ->from($db->quoteName('#__dmarc_sources', 's'))
            ->join(
                'INNER',
                $db->quoteName('#__dmarc_domains', 'd'),
                $db->quoteName('d.id') . ' = ' . $db->quoteName('s.domain_id')
            )
            ->where($db->quoteName('s.published') . ' = 1')
            ->where($db->quoteName('d.published') . ' = 1')
            ->where($db->quoteName('s.protocol') . ' = ' . $db->quote('imap'))
            ->order($db->quoteName('s.id') . ' ASC');

        return $db->setQuery($query)->loadObjectList() ?: [];
    }

    /**
     * Map an {@see ImapException} category to its localized reason key. The
     * category is a fixed enumeration, so the message never contains any
     * server-supplied or credential-derived text.
     */
    private function imapReasonKey(string $category): string
    {
        return match ($category) {
            ImapException::CATEGORY_TLS    => 'COM_DMARCREPORTS_INGEST_REASON_TLS',
            ImapException::CATEGORY_LOGIN  => 'COM_DMARCREPORTS_INGEST_REASON_LOGIN',
            ImapException::CATEGORY_CONFIG => 'COM_DMARCREPORTS_INGEST_REASON_CONFIG',
            default                        => 'COM_DMARCREPORTS_INGEST_REASON_CONNECTION',
        };
    }

    private function recordRun(int $sourceId, string $status, string $message, ?int $uidvalidity = null, ?int $lastUid = null, ?array $polledIdentity = null, ?string $deferredUids = null): void
    {
        if ($sourceId <= 0) {
            return;
        }

        $db  = $this->db;
        $row = (object) [
            'id'           => $sourceId,
            'last_run'     => Factory::getDate()->toSql(),
            'last_status'  => $status,
            'last_message' => $message,
        ];

        // The error path passes a null cursor so a failed run keeps the previous
        // one and the next run re-examines the same UID range; it needs no lock.
        if ($uidvalidity === null || $lastUid === null) {
            $db->updateObject('#__dmarc_sources', $row, 'id');

            return;
        }

        // Advancing the cursor must coordinate with a concurrent source edit and
        // with a second poll of the same source finishing out of order. Re-read
        // the identity and cursor under a row lock and only advance when the
        // mailbox still matches this poll and the high-water UID would not move
        // backwards.
        $db->transactionStart();

        try {
            if (!SourceLock::lock($db, $sourceId)) {
                $db->transactionRollback();

                return;
            }

            if ($polledIdentity === null || !$this->identityChanged($db, $sourceId, $polledIdentity)) {
                if (!$this->cursorWouldRegress($db, $sourceId, $uidvalidity, $lastUid)) {
                    $row->uidvalidity = $uidvalidity;
                    $row->last_uid    = $lastUid;

                    if ($deferredUids !== null) {
                        $row->deferred_uids = $deferredUids;
                    }
                }
            }

            $db->updateObject('#__dmarc_sources', $row, 'id');
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }
    }

    /**
     * Whether the source's mailbox identity differs from the one a poll read,
     * evaluated against the row currently locked in the caller's transaction.
     *
     * @param   array<string, mixed>  $polledIdentity
     */
    private function identityChanged(DatabaseInterface $db, int $sourceId, array $polledIdentity): bool
    {
        $query = $this->createDbQuery($db)
            ->select($db->quoteName(SourceIdentity::FIELDS))
            ->from($db->quoteName('#__dmarc_sources'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        $current = $db->setQuery($query)->loadAssoc();

        return $current === null || SourceIdentity::changed($current, $polledIdentity);
    }

    /**
     * Whether persisting this poll's cursor would move the stored high-water UID
     * strictly backwards, evaluated against the row locked in the caller's
     * transaction.
     */
    private function cursorWouldRegress(DatabaseInterface $db, int $sourceId, int $uidvalidity, int $lastUid): bool
    {
        $query = $this->createDbQuery($db)
            ->select($db->quoteName(['uidvalidity', 'last_uid']))
            ->from($db->quoteName('#__dmarc_sources'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        $current = $db->setQuery($query)->loadAssoc();

        if ($current === null) {
            return false;
        }

        return (int) $current['uidvalidity'] === $uidvalidity
            && (int) $current['last_uid'] > $lastUid;
    }

    /**
     * @param   array<string, int|string>  $summary
     */
    private function summaryText(array $summary): string
    {
        $text = Text::sprintf(
            'COM_DMARCREPORTS_INGEST_SUMMARY',
            (int) ($summary['imported'] ?? 0),
            (int) ($summary['duplicates'] ?? 0),
            (int) ($summary['failed'] ?? 0),
            (int) ($summary['skipped'] ?? 0)
        );

        $dropped = (int) ($summary['dropped'] ?? 0);

        if ($dropped > 0) {
            $text .= ' ' . Text::sprintf('COM_DMARCREPORTS_INGEST_DROPPED', $dropped, ReportArchive::MAX_DOCUMENTS);
        }

        return $text;
    }

    private function getConfig(string $key, $default)
    {
        return ComponentHelper::getParams('com_dmarcreports')->get($key, $default);
    }

    /**
     * Read an integer option and clamp it to the declared [min, max] range,
     * falling back to the default when the stored value is empty, non-numeric or
     * out of range.
     */
    private function boundedConfig(string $key, int $default, int $min, int $max): int
    {
        $value = $this->getConfig($key, $default);

        if (!is_scalar($value) || !preg_match('/^-?\d+$/', (string) $value)) {
            return $default;
        }

        $value = (int) $value;

        if ($value < $min || $value > $max) {
            return $default;
        }

        return $value;
    }

    private function createDbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * Parse a stored comma-separated UID retry list into integers, ignoring any
     * malformed token so a corrupt column can never inject non-numeric input.
     *
     * @return  int[]
     */
    private function parseUidList(string $csv): array
    {
        $ids = [];

        foreach (explode(',', $csv) as $token) {
            $token = trim($token);

            if ($token !== '' && ctype_digit($token)) {
                $ids[] = (int) $token;
            }
        }

        return $ids;
    }

    /**
     * Serialise a UID retry list to an ascending, de-duplicated CSV for storage.
     *
     * @param   int[]  $ids
     */
    private function formatUidList(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return implode(',', $ids);
    }
}
