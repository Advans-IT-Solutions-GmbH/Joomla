<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * Stores parsed TLS-RPT reports into the dedicated `#__dmarc_tlsrpt_*` tables.
 *
 * TLS-RPT reports arrive in the same mailbox and upload path as DMARC aggregate
 * reports, so {@see Ingestor} routes JSON documents here while XML documents keep
 * their existing path. The storage stays entirely separate from the DMARC
 * aggregate tables, but reuses the shared domain list, the deduplication-by-hash
 * discipline and the row locks so a TLS-RPT write is as safe next to a concurrent
 * poll or domain delete as a DMARC write.
 *
 * @since  1.1.0
 */
final class TlsRptIngestor
{
    private DatabaseInterface $db;

    private bool $allowAutoCreate;

    public function __construct(?DatabaseInterface $db = null, bool $allowAutoCreate = true)
    {
        $this->db              = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
        $this->allowAutoCreate = $allowAutoCreate;
    }

    /**
     * Parse and store every JSON document extracted from one attachment or
     * upload, accumulating into the shared ingest summary. A transient storage
     * failure flips $retry so the caller defers the IMAP message for the next run.
     *
     * @param   string[]            $documents
     * @param   array<string,mixed> $summary  Mutated in place.
     */
    public function ingestDocuments(array $documents, ?int $sourceId, int $forcedDomainId, array &$summary, bool &$retry): void
    {
        if ($documents === []) {
            $summary['failed']++;

            return;
        }

        foreach ($documents as $json) {
            try {
                $report = TlsRptParser::parse($json);
            } catch (\InvalidArgumentException $e) {
                $summary['failed']++;

                if (($summary['reason'] ?? '') === '') {
                    $summary['reason'] = $e->getCode() === TlsRptParser::ERROR_NOT_REPORT ? 'format' : 'malformed';
                }

                continue;
            }

            $this->store($report, $sourceId, $forcedDomainId, $summary, $retry);
        }
    }

    /**
     * @param   array<string, mixed>  $report
     * @param   array<string, mixed>  $summary
     */
    private function store(array $report, ?int $sourceId, int $forcedDomainId, array &$summary, bool &$retry): void
    {
        $hash = TlsRptParser::fingerprint($report);

        if ($this->reportExists($hash)) {
            $summary['duplicates']++;

            return;
        }

        $policyDomain = $this->firstPolicyDomain($report);
        $domainId     = $forcedDomainId > 0
            ? $forcedDomainId
            : DomainRegistry::resolve($this->db, $policyDomain, $this->allowAutoCreate);

        if ($domainId <= 0) {
            // No usable policy domain, or an operator without core.create may not
            // auto-register it. Skip rather than storing an orphan report.
            $summary['skipped']++;

            return;
        }

        [$successCount, $failureCount] = $this->totals($report);

        $this->db->transactionStart();

        try {
            if (!DomainLock::lock($this->db, $domainId)) {
                $this->db->transactionRollback();
                $summary['skipped']++;

                return;
            }

            $effectiveSourceId = $sourceId;

            if ($effectiveSourceId !== null && $effectiveSourceId > 0
                && !SourceLock::lock($this->db, $effectiveSourceId)) {
                $effectiveSourceId = 0;
            }

            $reportId = $this->insertReport($report, $hash, $domainId, $effectiveSourceId, $policyDomain, $successCount, $failureCount);

            foreach ($report['policies'] as $policy) {
                foreach ($policy['failures'] as $failure) {
                    $this->insertFailure($failure, $reportId, $domainId, (string) $policy['policy_domain']);
                }
            }

            $this->db->transactionCommit();
            $summary['imported']++;
        } catch (\Throwable) {
            $this->db->transactionRollback();

            if ($this->reportExists($hash)) {
                $summary['duplicates']++;

                return;
            }

            $summary['failed']++;
            $retry = true;
        }
    }

    private function reportExists(string $hash): bool
    {
        $query = $this->query()
            ->select('COUNT(*)')
            ->from($this->db->quoteName('#__dmarc_tlsrpt_reports'))
            ->where($this->db->quoteName('report_hash') . ' = :hash')
            ->bind(':hash', $hash);

        return (int) $this->db->setQuery($query)->loadResult() > 0;
    }

    /**
     * @param   array<string, mixed>  $report
     */
    private function firstPolicyDomain(array $report): string
    {
        foreach ($report['policies'] as $policy) {
            if (($policy['policy_domain'] ?? '') !== '') {
                return (string) $policy['policy_domain'];
            }
        }

        return '';
    }

    /**
     * Sum the success and failure session counts across every policy block.
     *
     * @param   array<string, mixed>  $report
     *
     * @return  array{0:int, 1:int}
     */
    private function totals(array $report): array
    {
        $success = 0;
        $failure = 0;

        foreach ($report['policies'] as $policy) {
            $success += (int) ($policy['success_count'] ?? 0);
            $failure += (int) ($policy['failure_count'] ?? 0);
        }

        return [$success, $failure];
    }

    /**
     * @param   array<string, mixed>  $report
     */
    private function insertReport(array $report, string $hash, int $domainId, ?int $sourceId, string $policyDomain, int $successCount, int $failureCount): int
    {
        $row = (object) [
            'domain_id'     => $domainId,
            'source_id'     => $sourceId ?: 0,
            'org_name'      => (string) $report['org_name'],
            'contact_info'  => (string) $report['contact_info'],
            'report_id'     => (string) $report['report_id'],
            'report_hash'   => $hash,
            'date_begin'    => (int) $report['date_begin'],
            'date_end'      => (int) $report['date_end'],
            'policy_domain' => $policyDomain,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'created'       => Factory::getDate()->toSql(),
        ];

        $this->db->insertObject('#__dmarc_tlsrpt_reports', $row, 'id');

        return (int) $row->id;
    }

    /**
     * @param   array<string, mixed>  $failure
     */
    private function insertFailure(array $failure, int $reportId, int $domainId, string $policyDomain): void
    {
        $row = (object) [
            'report_id'            => $reportId,
            'domain_id'            => $domainId,
            'policy_domain'        => $policyDomain,
            'result_type'          => (string) $failure['result_type'],
            'sending_mta_ip'       => (string) $failure['sending_mta_ip'],
            'receiving_mx_host'    => (string) $failure['receiving_mx_host'],
            'receiving_ip'         => (string) $failure['receiving_ip'],
            'failed_session_count' => (int) $failure['failed_session_count'],
            'additional_info'      => (string) $failure['additional_info'],
            'failure_reason_code'  => (string) $failure['failure_reason_code'],
        ];

        $this->db->insertObject('#__dmarc_tlsrpt_failures', $row);
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
