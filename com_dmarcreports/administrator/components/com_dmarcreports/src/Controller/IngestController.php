<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Controller;

use Advans\Component\DmarcReports\Administrator\Helper\CryptoHelper;
use Advans\Component\DmarcReports\Administrator\Helper\DecoderUnavailableException;
use Advans\Component\DmarcReports\Administrator\Helper\ImapClient;
use Advans\Component\DmarcReports\Administrator\Helper\ImapException;
use Advans\Component\DmarcReports\Administrator\Helper\Ingestor;
use Advans\Component\DmarcReports\Administrator\Helper\ReportArchive;
use Advans\Component\DmarcReports\Administrator\Helper\SourceIdentity;
use Advans\Component\DmarcReports\Administrator\Helper\SourceLock;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Runs report ingestion, either by polling a configured mailbox over IMAP or by
 * accepting a manually uploaded report file. Every action is CSRF protected and
 * gated by the custom "dmarc.ingest" ACL action.
 *
 * @since  1.0.0
 */
class IngestController extends BaseController
{
    /**
     * Poll one configured mailbox and import the reports it holds.
     */
    public function import()
    {
        $this->checkToken();
        $this->authorise();

        $app      = Factory::getApplication();
        $sourceId = (int) $app->getInput()->getInt('source_id', 0);
        $redirect = Route::_('index.php?option=com_dmarcreports&view=sources', false);

        $source = $this->loadSource($sourceId);

        if ($source === null) {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_INGEST_SOURCE_NOT_FOUND'), 'error');

            return false;
        }

        try {
            $client   = new ImapClient($this->boundedConfig('imap_timeout', 20, 5, 120));
            $limit    = $this->boundedConfig('max_messages_per_run', 200, 10, 2000);
            $ingestor = new Ingestor(null, $this->canAutoCreateDomains());
            $summary  = $ingestor->newSummary();
            $domainId = (int) $source->domain_id;

            // Capture the mailbox identity this poll reads from, so the cursor
            // write below can detect a source edit that re-pointed the source at
            // a different mailbox mid-poll and refuse to persist a cursor that
            // belongs to the old one.
            $polledIdentity = SourceIdentity::fingerprint($source);

            // Bodies are streamed one at a time into the ingestor and released
            // immediately, so a large mailbox cannot be buffered into memory.
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
                function (array $message) use ($ingestor, $sourceId, $domainId, &$summary): string {
                    return $ingestor->ingestMessage($message, $sourceId, $domainId, $summary);
                }
            );

            // Transport-level skips (empty or oversized bodies) never reach the
            // callback, so merge them into the operator summary here. Only the
            // transport count is added; callback-level skips are already recorded
            // by the ingestor, so this cannot double-count.
            $summary['skipped'] += (int) ($stats['transport_skipped'] ?? 0);

            // A blocked report (a compressed container the host cannot decode
            // because a PHP extension is missing) is a permanent, actionable
            // condition. The client advanced past it so it did not starve newer
            // messages; record the run as blocked rather than a clean success and
            // surface a concrete requirement so the operator installs the
            // extension instead of seeing an "ok" run that imported nothing.
            $blocked   = (int) ($summary['blocked'] ?? 0);
            $message   = $this->summaryText($summary);
            $status    = 'ok';
            $flashType = 'message';

            if ($blocked > 0) {
                $status    = 'blocked';
                $flashType = 'warning';
                $message  .= ' ' . Text::sprintf('COM_DMARCREPORTS_INGEST_BLOCKED', $blocked);
            }

            // Persist the component-owned import cursor returned by the run so
            // the next poll resumes above the last fully processed UID instead
            // of depending on the mailbox \Seen state, together with the retry
            // set of UIDs awaiting decode support. Only a successful run advances
            // them; the error path below leaves the stored values intact.
            $this->recordRun(
                $sourceId,
                $status,
                $message,
                (int) ($stats['uidvalidity'] ?? 0),
                (int) ($stats['last_uid'] ?? 0),
                $polledIdentity,
                $this->formatUidList((array) ($stats['deferred_uids'] ?? []))
            );

            $this->setRedirect($redirect, $message, $flashType);
        } catch (ImapException $e) {
            // Give the operator the category of failure — a connection problem, a
            // TLS failure, a login rejection or a misconfiguration — so they know
            // what to fix. Only the fixed, localized category text is shown; the
            // exception message is never surfaced, so a mailbox credential can
            // never leak into a flash message, the stored run status or a log.
            $message = Text::_('COM_DMARCREPORTS_INGEST_FAILED') . ' '
                . Text::_($this->imapReasonKey($e->getCategory()));
            $this->recordRun($sourceId, 'error', $message);
            $this->setRedirect($redirect, $message, 'error');

            return false;
        } catch (\Throwable $e) {
            $this->recordRun($sourceId, 'error', Text::_('COM_DMARCREPORTS_INGEST_FAILED'));
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_INGEST_FAILED'), 'error');

            return false;
        }

        return true;
    }

    /**
     * Map an {@see ImapException} category to its localized reason key. The
     * category is a fixed enumeration, so the message never contains any
     * server-supplied or credential-derived text.
     */
    private function imapReasonKey(string $category): string
    {
        return match ($category) {
            ImapException::CATEGORY_TLS   => 'COM_DMARCREPORTS_INGEST_REASON_TLS',
            ImapException::CATEGORY_LOGIN => 'COM_DMARCREPORTS_INGEST_REASON_LOGIN',
            ImapException::CATEGORY_CONFIG => 'COM_DMARCREPORTS_INGEST_REASON_CONFIG',
            default                       => 'COM_DMARCREPORTS_INGEST_REASON_CONNECTION',
        };
    }

    /**
     * Import a manually uploaded report file.
     */
    public function upload()
    {
        $this->checkToken();
        $this->authorise();

        $app      = Factory::getApplication();
        $redirect = Route::_('index.php?option=com_dmarcreports&view=dashboard', false);
        $file     = $app->getInput()->files->get('report_file', null, 'raw');

        if (!\is_array($file) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_UPLOAD_NO_FILE'), 'error');

            return false;
        }

        try {
            // A manual upload is never bound to a configured source: the form
            // offers no source selector, so no client-supplied source_id is
            // read or trusted. The report is stored unbound (source_id 0) and
            // its domain is resolved from the published policy under the same
            // ACL gate as every other ingest path. Honouring a posted source_id
            // here would let a crafted request attach arbitrary or nonexistent
            // provenance to a report.
            $summary = (new Ingestor(null, $this->canAutoCreateDomains()))->ingestFile(
                (string) $file['tmp_name'],
                (string) ($file['name'] ?? 'report'),
                null
            );

            $type = $summary['imported'] > 0 ? 'message' : 'warning';
            $message = $this->summaryText($summary);

            // When nothing was imported, append the specific reason (empty file,
            // wrong format, malformed/XXE, or a decompression-bomb guard) so the
            // operator knows why rather than seeing only an empty-result summary.
            if ((int) $summary['imported'] === 0 && !empty($summary['reason'])) {
                $key = $this->uploadReasonKey((string) $summary['reason']);

                if ($key !== '') {
                    $message .= ' ' . Text::_($key);
                }
            }

            $this->setRedirect($redirect, $message, $type);
        } catch (DecoderUnavailableException $e) {
            // The uploaded report is a compressed container the host cannot
            // decode because the required PHP extension is missing. Surface a
            // concrete requirement rather than a generic failure.
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_INGEST_DECODER_UNAVAILABLE'), 'error');

            return false;
        } catch (\Throwable $e) {
            $this->setRedirect($redirect, Text::_('COM_DMARCREPORTS_INGEST_FAILED'), 'error');

            return false;
        }

        return true;
    }

    private function authorise(): void
    {
        if (!Factory::getApplication()->getIdentity()->authorise('dmarc.ingest', 'com_dmarcreports')) {
            throw new \Joomla\CMS\Access\Exception\NotAllowed(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    /**
     * Whether the current user may auto-register an unknown domain discovered in
     * an incoming report. Registering a monitored domain is a configuration
     * change, so it requires core.create: an ingest-only operator can import
     * reports for already-configured domains but cannot create new ones.
     */
    private function canAutoCreateDomains(): bool
    {
        return (bool) Factory::getApplication()->getIdentity()->authorise('core.create', 'com_dmarcreports');
    }

    private function loadSource(int $sourceId): ?object
    {
        if ($sourceId <= 0) {
            return null;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $this->createDbQuery($db)
            ->select('*')
            ->from($db->quoteName('#__dmarc_sources'))
            ->where($db->quoteName('id') . ' = :id')
            ->where($db->quoteName('published') . ' = 1')
            ->bind(':id', $sourceId, ParameterType::INTEGER);

        return $db->setQuery($query)->loadObject() ?: null;
    }

    private function recordRun(int $sourceId, string $status, string $message, ?int $uidvalidity = null, ?int $lastUid = null, ?array $polledIdentity = null, ?string $deferredUids = null): void
    {
        if ($sourceId <= 0) {
            return;
        }

        $db  = Factory::getContainer()->get(DatabaseInterface::class);
        $row = (object) [
            'id'          => $sourceId,
            'last_run'    => Factory::getDate()->toSql(),
            'last_status' => $status,
            'last_message' => $message,
        ];

        // The error path passes a null cursor so a failed run keeps the previous
        // one and the next run re-examines the same UID range; it needs no lock.
        if ($uidvalidity === null || $lastUid === null) {
            $db->updateObject('#__dmarc_sources', $row, 'id');

            return;
        }

        // Advancing the cursor must coordinate with a concurrent source edit.
        // That edit resets the cursor to 0 when the mailbox identity changes; if
        // it committed while this poll was reading the old mailbox, writing the
        // cursor computed from it would resurrect a stale high-water UID for the
        // new mailbox and skip its lower-UID reports. Re-read the identity under
        // a row lock and only advance the cursor when it still matches the
        // mailbox this poll read; otherwise leave the reset in place.
        //
        // Two polls of the same source can also finish out of order. Under the
        // same lock, refuse to move the stored high-water UID backwards so an
        // older poll cannot overwrite a newer poll's cursor and force already
        // processed messages to be fetched again.
        $db->transactionStart();

        try {
            if (!SourceLock::lock($db, $sourceId)) {
                // The source was deleted mid-poll; there is nothing to record.
                $db->transactionRollback();

                return;
            }

            if ($polledIdentity === null || !$this->identityChanged($db, $sourceId, $polledIdentity)) {
                if (!$this->cursorWouldRegress($db, $sourceId, $uidvalidity, $lastUid)) {
                    $row->uidvalidity = $uidvalidity;
                    $row->last_uid    = $lastUid;

                    // The retry set is tied to the cursor it was computed
                    // against, so persist it under the same guard: an edit that
                    // changed the mailbox (identity changed) or a late, out-of-
                    // order poll (cursor would regress) leaves the newer value in
                    // place instead of clobbering it with a stale list.
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
     * transaction. Two polls of the same source can finish out of order; the
     * older one must not overwrite the newer one's cursor. When the stored
     * UIDVALIDITY matches the poll's, the UID sequence is monotonic, so only a
     * last_uid strictly below the stored one is a stale finish and is left in
     * place. An equal last_uid is NOT a regression: it re-writes the same cursor
     * value (a no-op for the cursor) while still allowing the run to persist an
     * updated retry set — the below-cursor deferred-retry path intentionally
     * returns the unchanged high-water UID, so treating equal as a regression
     * would prevent a decoded UID from ever being cleared from the retry set. A
     * differing UIDVALIDITY means the mailbox UID space was reset, so the poll's
     * freshly read values are accepted.
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
     * Map an ingest reason token from {@see Ingestor} to its localized upload
     * message key. An unknown token yields an empty string so only the summary
     * is shown.
     */
    private function uploadReasonKey(string $reason): string
    {
        return match ($reason) {
            'empty'      => 'COM_DMARCREPORTS_UPLOAD_REASON_EMPTY',
            'unreadable' => 'COM_DMARCREPORTS_UPLOAD_REASON_UNREADABLE',
            'format'     => 'COM_DMARCREPORTS_UPLOAD_REASON_FORMAT',
            'malformed'  => 'COM_DMARCREPORTS_UPLOAD_REASON_MALFORMED',
            'bomb'       => 'COM_DMARCREPORTS_UPLOAD_REASON_BOMB',
            default      => '',
        };
    }

    /**
     * @param   array<string, int|string>  $summary  Ingest counters plus an
     *                                                optional string `reason`
     *                                                token when nothing imported.
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

        // An archive that held more reports than the per-run cap had the surplus
        // dropped; tell the operator how many so a silently truncated import is
        // never mistaken for a complete one.
        $dropped = (int) ($summary['dropped'] ?? 0);

        if ($dropped > 0) {
            $text .= ' ' . Text::sprintf('COM_DMARCREPORTS_INGEST_DROPPED', $dropped, ReportArchive::MAX_DOCUMENTS);
        }

        return $text;
    }

    private function getConfig(string $key, $default)
    {
        return \Joomla\CMS\Component\ComponentHelper::getParams('com_dmarcreports')->get($key, $default);
    }

    /**
     * Read an integer option and clamp it to the declared [min, max] range,
     * falling back to the default when the stored value is empty, non-numeric or
     * out of range. The form range rule only guards future submissions; a value
     * such as a 999-second timeout that version 1.0.0 persisted before the rule
     * existed must still be normalized at read time so it cannot drive an
     * out-of-range socket timeout or fetch cap after an upgrade.
     *
     * @param   string  $key      Option name.
     * @param   int     $default  Fallback when empty, non-numeric or out of range.
     * @param   int     $min      Lowest value accepted (inclusive).
     * @param   int     $max      Highest value accepted (inclusive).
     *
     * @return  int
     */
    private function boundedConfig(string $key, int $default, int $min, int $max): int
    {
        $value = $this->getConfig($key, $default);

        // is_numeric() would accept "50.5" or "1e2", and the int cast would then
        // silently coerce them to 50/100. The form range rule rejects those as
        // whole numbers, so apply the same plain-integer lexical check here
        // before casting: a legacy or crafted non-integer value that 1.0.0 could
        // have persisted falls back to the default instead of driving an
        // out-of-rule timeout or fetch cap after an upgrade.
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
