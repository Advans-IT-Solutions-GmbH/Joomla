<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Orchestrates the ingestion pipeline: raw container -> XML document(s) ->
 * parsed report -> deduplicated storage. The same report, imported twice, is
 * stored once because every report is keyed by a content fingerprint. All
 * writes use prepared statements and run inside a transaction per report.
 *
 * @since  1.0.0
 *
 * @phpstan-type DmarcIngestSummary array{imported:int, duplicates:int, failed:int, skipped:int, blocked:int, records:int, dropped:int, reason:string}
 */
final class Ingestor
{
    /**
     * Per-message ingestion outcomes returned by {@see ingestMessage()}. They
     * tell a streaming caller (the IMAP client) whether the source message may
     * be advanced: a transient failure returns RESULT_RETRY so the message is
     * left in place for the next run instead of being marked processed and lost,
     * while RESULT_BLOCKED reports a host that cannot decode the container at all
     * (a missing PHP extension) — a permanent condition the caller advances past
     * so one undecodable report cannot starve every newer message, and surfaces
     * to the operator as a concrete requirement instead of a silent retry loop.
     */
    public const RESULT_NEW     = 'new';
    public const RESULT_SKIP    = 'skip';
    public const RESULT_RETRY   = 'retry';
    public const RESULT_BLOCKED = 'blocked';

    /**
     * Upper bound on a manually uploaded report file before decompression. The
     * archive helper enforces its own decompressed-size limits; this cap stops
     * an oversized upload from being read into memory wholesale beforehand.
     */
    private const MAX_UPLOAD_BYTES = 16777216; // 16 MiB

    private DatabaseInterface $db;

    /**
     * Whether an unknown policy domain may be auto-registered as a monitored
     * domain on first sight. Creating a domain is a configuration change, so a
     * caller acting for an operator without the core.create privilege passes
     * false: unknown domains are then skipped rather than silently creating a
     * monitored-domain row the operator is not entitled to add.
     */
    private bool $allowAutoCreate;

    public function __construct(?DatabaseInterface $db = null, bool $allowAutoCreate = true)
    {
        $this->db              = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
        $this->allowAutoCreate = $allowAutoCreate;
    }

    /**
     * Ingest the decoded attachments of raw IMAP messages.
     *
     * @param   array<int, array{uid?: int, raw: string}>  $messages
     * @param   int|null  $sourceId        The source these messages came from, or null.
     * @param   int       $forcedDomainId  When > 0, assign every report to this domain
     *                                     instead of resolving it from the report XML
     *                                     (an operator binding a mailbox to one domain).
     *
     * @return  DmarcIngestSummary  imported, duplicates, failed, skipped, blocked, records, dropped, reason
     */
    public function ingestMessages(array $messages, ?int $sourceId = null, int $forcedDomainId = 0): array
    {
        $summary = $this->newSummary();

        foreach ($messages as $message) {
            $this->ingestMessage($message, $sourceId, $forcedDomainId, $summary);
        }

        return $summary;
    }

    /**
     * Ingest a single raw message, accumulating into a shared summary. Returns
     * a RESULT_* outcome so a streaming caller can decide whether the source
     * message may be advanced: RESULT_NEW (stored a report) and RESULT_SKIP
     * (duplicate or unparseable) are safe to mark processed; RESULT_RETRY (a
     * transient storage or scratch-file failure) means the message must be left
     * untouched so the next run retries it; and RESULT_BLOCKED (the host lacks
     * the PHP extension needed to decode the container) is a permanent condition
     * the caller advances past — retrying it forever would pin the cursor and
     * starve every newer message — while recording it in the summary so the
     * operator is told to install the extension.
     *
     * @param   array{uid?: int, raw?: string}  $message
     * @param   DmarcIngestSummary              $summary  Mutated in place.
     *
     * @return  self::RESULT_*
     */
    public function ingestMessage(array $message, ?int $sourceId, int $forcedDomainId, array &$summary): string
    {
        $before  = (int) ($summary['imported'] ?? 0);
        $retry   = false;
        $blocked = false;

        foreach (MimeMessage::attachments((string) ($message['raw'] ?? '')) as $attachment) {
            $stats = [];

            try {
                $documents = ReportArchive::extract($attachment['content'], $attachment['filename'], $stats);
            } catch (DecoderUnavailableException) {
                // The host cannot decompress this container because the PHP zlib
                // or zip extension is absent. This is a permanent configuration
                // problem, not a transient one: leaving the message in place to
                // retry would pin the import cursor and starve every newer
                // message behind it. Record it as blocked so the operator is
                // told to install the extension, and let the caller advance past
                // it. The report stays in the mailbox and ongoing reports keep
                // importing once the extension is present.
                $blocked = true;
                continue;
            } catch (ArchiveExtractionException) {
                // A transient scratch-file failure prevented extraction. The
                // payload may still hold a valid report, so retry the message on
                // the next run rather than advancing past it and deleting it.
                $retry = true;
                continue;
            }

            // A zip that packs more than the per-archive document cap has its
            // surplus reports counted so the caller can note the dropped total
            // in the run summary instead of discarding them silently.
            $summary['dropped'] = (int) ($summary['dropped'] ?? 0) + (int) ($stats['dropped'] ?? 0);

            $this->ingestDocuments($documents, $sourceId, $forcedDomainId, $summary, $retry);
        }

        if ($blocked) {
            $summary['blocked'] = (int) ($summary['blocked'] ?? 0) + 1;
        }

        if ($retry) {
            // A transient storage failure: leave the message in place so the
            // next run retries it. Any report already stored from this message
            // is recognised as a duplicate and skipped on that retry. A transient
            // failure takes precedence over a blocked attachment so a retriable
            // report on the same message is never advanced past and lost.
            return self::RESULT_RETRY;
        }

        if ($blocked) {
            return self::RESULT_BLOCKED;
        }

        return (int) ($summary['imported'] ?? 0) > $before ? self::RESULT_NEW : self::RESULT_SKIP;
    }

    /**
     * Ingest a single uploaded report file (.xml, .gz or .zip).
     *
     * @return  DmarcIngestSummary
     *
     * @throws  DecoderUnavailableException  When the file is a recognised
     *          compressed container but the PHP extension needed to decode it is
     *          not installed, so the caller can show a concrete requirement.
     * @throws  ArchiveExtractionException  When a temporary scratch file needed
     *          to read a zip container cannot be created or fully written, so the
     *          caller can surface a transient failure instead of a silent skip.
     */
    public function ingestFile(string $path, string $filename, ?int $sourceId = null, int $forcedDomainId = 0): array
    {
        $summary = $this->newSummary();

        if (!is_readable($path)) {
            // Unreadable is not the same as empty: the file may exist but a
            // permission or storage error prevents reading it. Report it as a
            // distinct read error so the operator is not told it was empty.
            $summary['failed']++;
            $summary['reason'] = 'unreadable';

            return $summary;
        }

        $size = (int) @filesize($path);

        if ($size <= 0) {
            $summary['failed']++;
            $summary['reason'] = 'empty';

            return $summary;
        }

        if ($size > self::MAX_UPLOAD_BYTES) {
            // Larger than the accepted compressed ceiling.
            $summary['failed']++;
            $summary['reason'] = 'bomb';

            return $summary;
        }

        // Read at most the ceiling (plus one byte to catch a file that grew
        // between the stat and the read), so the upload never buffers unbounded
        // before the archive helper applies its decompression limits.
        $payload = (string) @file_get_contents($path, false, null, 0, self::MAX_UPLOAD_BYTES + 1);

        if ($payload === '') {
            // filesize() already reported a positive size above, so an empty
            // read here is a read failure (permissions/storage), not a genuinely
            // empty file; classify it as unreadable rather than empty.
            $summary['failed']++;
            $summary['reason'] = 'unreadable';

            return $summary;
        }

        if (\strlen($payload) > self::MAX_UPLOAD_BYTES) {
            $summary['failed']++;
            $summary['reason'] = 'bomb';

            return $summary;
        }

        $retry     = false;
        $stats     = [];
        $documents = ReportArchive::extract($payload, $filename, $stats);
        $summary['dropped'] += (int) ($stats['dropped'] ?? 0);
        $this->ingestDocuments($documents, $sourceId, $forcedDomainId, $summary, $retry);

        // When nothing was imported, explain why. A parse-level reason recorded
        // by ingestDocuments (malformed or wrong format) takes precedence over
        // the container-level reason from the archive helper (empty, bomb or
        // unrecognised format), so the operator sees the most specific cause.
        if ((int) $summary['imported'] === 0 && ($summary['reason'] ?? '') === '' && ($stats['reason'] ?? '') !== '') {
            $summary['reason'] = (string) $stats['reason'];
        }

        return $summary;
    }

    /**
     * @param   string[]            $documents
     * @param   DmarcIngestSummary  $summary
     */
    private function ingestDocuments(array $documents, ?int $sourceId, int $forcedDomainId, array &$summary, bool &$retry): void
    {
        // An attachment or upload that yields no usable document is a failed
        // ingestion, not a silent success.
        if ($documents === []) {
            $summary['failed']++;

            return;
        }

        foreach ($documents as $xml) {
            try {
                $report = DmarcParser::parse($xml);
            } catch (\InvalidArgumentException $e) {
                $summary['failed']++;

                if (($summary['reason'] ?? '') === '') {
                    $summary['reason'] = self::parseReason((int) $e->getCode());
                }

                continue;
            }

            $this->store($report, $sourceId, $forcedDomainId, $summary, $retry);
        }
    }

    /**
     * Map a {@see DmarcParser} failure code to the short reason the upload
     * controller shows the operator. A hardening guard (NUL bytes or a DOCTYPE,
     * i.e. the XXE class) is "malformed"; an empty or non-report document is a
     * wrong-"format" upload. Any other parse failure defaults to malformed.
     */
    private static function parseReason(int $code): string
    {
        return match ($code) {
            DmarcParser::ERROR_EMPTY, DmarcParser::ERROR_NOT_REPORT => 'format',
            default => 'malformed',
        };
    }

    /**
     * @param   array<string, mixed>  $report
     * @param   DmarcIngestSummary    $summary
     */
    private function store(array $report, ?int $sourceId, int $forcedDomainId, array &$summary, bool &$retry): void
    {
        $hash = DmarcParser::fingerprint($report);

        if ($this->reportExists($hash)) {
            $summary['duplicates']++;

            return;
        }

        if ($forcedDomainId > 0) {
            // A mailbox bound to one monitored domain still delivers untrusted
            // content: a valid aggregate report for an unrelated domain would
            // otherwise be stored under this tenant and skew its volume, pass
            // rates and rollout recommendations. Only honour the binding when the
            // report's policy domain belongs to the same organizational domain as
            // the bound one (equal, or either a subdomain of the other under
            // DMARC organizational-policy inheritance); a genuinely unrelated
            // report is skipped rather than cross-attributed.
            if (!$this->boundDomainMatchesPolicy($forcedDomainId, (string) ($report['policy']['domain'] ?? ''))) {
                $summary['skipped']++;

                return;
            }

            $domainId = $forcedDomainId;
        } else {
            $domainId = $this->resolveDomain((string) ($report['policy']['domain'] ?? ''));
        }

        if ($domainId <= 0) {
            // Either the report carries no usable policy domain, or an operator
            // without the core.create privilege may not auto-register an unknown
            // domain. Skip rather than storing an orphan report or creating a
            // monitored-domain configuration row the operator is not entitled to.
            $summary['skipped']++;

            return;
        }

        $this->db->transactionStart();

        try {
            if (!$this->lockDomain($domainId)) {
                // The domain was deleted between resolution above and acquiring
                // the lock here, so writing children now would orphan them with
                // no parent. Roll back and skip rather than store orphan rows.
                $this->db->transactionRollback();
                $summary['skipped']++;

                return;
            }

            // Lock the originating source too (when the report is bound to one)
            // so a concurrent source delete cannot complete while this report is
            // written. If the source was already deleted during this run its
            // reports were reset to the unbound sentinel, so storing this one
            // with the stale id would leave dangling provenance: record it
            // unbound instead, exactly like a manually uploaded report.
            $effectiveSourceId = $sourceId;

            if ($effectiveSourceId !== null && $effectiveSourceId > 0
                && !SourceLock::lock($this->db, $effectiveSourceId)) {
                $effectiveSourceId = 0;
            }

            $reportId = $this->insertReport($report, $hash, $domainId, $effectiveSourceId);

            // Count locally and fold into the running summary only after the
            // commit succeeds. Adding inside the loop would leave the record
            // tally inflated if a later insert or the commit itself failed and
            // rolled the whole report back.
            $records = 0;

            foreach ($report['records'] as $record) {
                $this->insertRecord($record, $reportId, $domainId);
                $records += (int) $record['count'];
            }

            $this->db->transactionCommit();
            $summary['records'] += $records;
            $summary['imported']++;
        } catch (\Throwable) {
            $this->db->transactionRollback();

            // A concurrent import can store this exact report between the
            // preflight reportExists() check and the insert above; the unique
            // report_hash then rejects the duplicate here. That is a successful
            // de-duplication, not a transient failure, so recognise it, count it
            // as a duplicate and do not defer the IMAP UID for a retry.
            if ($this->reportExists($hash)) {
                $summary['duplicates']++;

                return;
            }

            $summary['failed']++;
            // Values are clamped to their column widths before storage, so a
            // deterministic length overflow cannot recur here; a failure that
            // still reaches this point is treated as transient (a lock, a lost
            // connection) and the caller retries rather than dropping a report
            // that was never persisted.
            $retry = true;
        }
    }

    private function reportExists(string $hash): bool
    {
        $query = $this->query()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('report_hash') . ' = :hash')
            ->bind(':hash', $hash);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * Lock the parent domain row for the lifetime of the surrounding
     * transaction so a concurrent domain delete cannot remove it while this
     * report's children are written. Returns false when the row no longer
     * exists, signalling the caller to abort rather than orphan rows.
     */
    private function lockDomain(int $domainId): bool
    {
        return DomainLock::lock($this->db, $domainId);
    }

    /**
     * Whether a mailbox's forced domain binding may accept a report carrying the
     * given policy domain. Returns true only when the bound monitored domain and
     * the report's policy domain share an organizational domain: identical, or
     * either one a subdomain of the other (DMARC organizational-policy
     * inheritance). The leading dot in the suffix test prevents a sibling such as
     * evil-example.com from matching example.com. Without the Public Suffix List
     * the exact organizational boundary cannot be proven, but the binding is
     * operator-chosen, so this blocks the real risk — untrusted mailbox content
     * for an unrelated domain being attributed to this tenant — while still
     * accepting legitimate same-origin subdomain reports.
     */
    private function boundDomainMatchesPolicy(int $domainId, string $policyDomain): bool
    {
        $policyDomain = strtolower(trim($policyDomain));

        if ($policyDomain === '') {
            return false;
        }

        $query = $this->query()
            ->select($this->db->quoteName('domain'))
            ->from($this->db->quoteName('#__dmarc_domains'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $domainId, ParameterType::INTEGER);
        $bound = strtolower(trim((string) $this->db->setQuery($query)->loadResult()));

        if ($bound === '') {
            // The bound domain row was deleted between configuration and this
            // run; treat it as no match so the report is skipped rather than
            // stored under a tenant that no longer exists.
            return false;
        }

        if ($bound === $policyDomain) {
            return true;
        }

        return str_ends_with($policyDomain, '.' . $bound)
            || str_ends_with($bound, '.' . $policyDomain);
    }

    /**
     * Find the configured domain by name, creating a monitored entry on first
     * sight so a shared reporting mailbox populates the domain list by itself.
     */
    private function resolveDomain(string $domain): int
    {
        $domain = strtolower(trim($domain));

        if ($domain === '') {
            return 0;
        }

        $query = $this->query()
            ->select($this->db->quoteName('id'))
            ->from($this->db->quoteName('#__dmarc_domains'))
            ->where($this->db->quoteName('domain') . ' = :domain')
            ->bind(':domain', $domain);
        $id = (int) $this->db->setQuery($query)->loadResult();

        if ($id > 0) {
            return $id;
        }

        if (!$this->allowAutoCreate) {
            // The caller is acting for an operator who may not create domains,
            // so an unknown domain is reported as unresolved and the report is
            // skipped by the caller instead of registering a new configuration.
            return 0;
        }

        $now    = Factory::getDate()->toSql();
        $insert = (object) [
            'domain'          => $domain,
            'title'           => $domain,
            'expected_policy' => $this->defaultPolicyTarget(),
            'published'       => 1,
            'created'         => $now,
        ];

        try {
            $this->db->insertObject('#__dmarc_domains', $insert, 'id');

            return (int) $insert->id;
        } catch (\RuntimeException $e) {
            // The lookup above and this insert are not atomic: a concurrent
            // import of the same previously unknown domain can create the row in
            // between, tripping the unique domain index. Recover by re-reading
            // the row the other request created and treating it as resolved, so
            // the whole IMAP batch is not aborted and left unread for retry.
            // Only a genuine failure (the domain still does not exist) re-throws.
            $existing = (int) $this->db->setQuery($query)->loadResult();

            if ($existing > 0) {
                return $existing;
            }

            throw $e;
        }
    }

    /**
     * The enforcement target assigned to domains discovered automatically from
     * incoming reports, taken from the component configuration (default reject).
     */
    private function defaultPolicyTarget(): string
    {
        $target = strtolower(
            (string) ComponentHelper::getParams('com_dmarcreports')->get('default_policy_target', 'reject')
        );

        return \in_array($target, ['none', 'quarantine', 'reject'], true) ? $target : 'reject';
    }

    /**
     * @param   array<string, mixed>  $report
     */
    private function insertReport(array $report, string $hash, int $domainId, ?int $sourceId): int
    {
        // Clamp every value to its column width before writing. An overlong
        // field would otherwise raise the same deterministic storage error on
        // every run, which the caller would keep treating as transient and
        // retry forever; fitting the schema keeps ingestion idempotent.
        $row = (object) [
            'domain_id'      => $domainId,
            'source_id'      => $sourceId ?: 0,
            'org_name'       => $this->clip((string) $report['org_name'], 255),
            'org_email'      => $this->clip((string) $report['org_email'], 255),
            'report_id'      => $this->clip((string) $report['report_id'], 255),
            'report_hash'    => $hash,
            'date_begin'     => (int) $report['date_begin'],
            'date_end'       => (int) $report['date_end'],
            'policy_domain'  => $this->clip((string) $report['policy']['domain'], 190),
            'policy_p'       => $this->clip((string) $report['policy']['p'], 20),
            'policy_sp'      => $this->clip((string) $report['policy']['sp'], 20),
            'policy_pct'     => (int) $report['policy']['pct'],
            'policy_adkim'   => $this->clip((string) $report['policy']['adkim'], 1),
            'policy_aspf'    => $this->clip((string) $report['policy']['aspf'], 1),
            'message_count'  => (int) ($report['message_count'] ?? array_sum(array_column($report['records'], 'count'))),
            'created'        => Factory::getDate()->toSql(),
        ];

        $this->db->insertObject('#__dmarc_reports', $row, 'id');

        return (int) $row->id;
    }

    /**
     * @param   array<string, mixed>  $record
     */
    private function insertRecord(array $record, int $reportId, int $domainId): void
    {
        // Clamp string fields to their column widths for the same reason as the
        // report row: a deterministic length overflow must never block a run.
        $row = (object) [
            'report_id'     => $reportId,
            'domain_id'     => $domainId,
            'source_ip'     => $this->clip((string) $record['source_ip'], 45),
            'count'         => (int) $record['count'],
            'disposition'   => $this->clip((string) $record['disposition'], 20),
            'dkim_result'   => $this->clip((string) $record['dkim_result'], 20),
            'spf_result'    => $this->clip((string) $record['spf_result'], 20),
            'dkim_aligned'  => (int) $record['dkim_aligned'],
            'spf_aligned'   => (int) $record['spf_aligned'],
            'header_from'   => $this->clip((string) $record['header_from'], 190),
            'envelope_from' => $this->clip((string) $record['envelope_from'], 190),
            'envelope_to'   => $this->clip((string) $record['envelope_to'], 190),
        ];

        $this->db->insertObject('#__dmarc_records', $row);
    }

    /**
     * Trim a value to at most $max characters so it fits its database column.
     * Uses multibyte-aware length because the columns count characters, not
     * bytes, under utf8mb4.
     */
    private function clip(string $value, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    /**
     * A fresh, zeroed ingestion summary. Public so a streaming caller can seed
     * one and accumulate across per-message calls.
     *
     * @return DmarcIngestSummary
     */
    public function newSummary(): array
    {
        return ['imported' => 0, 'duplicates' => 0, 'failed' => 0, 'skipped' => 0, 'blocked' => 0, 'records' => 0, 'dropped' => 0, 'reason' => ''];
    }
}
