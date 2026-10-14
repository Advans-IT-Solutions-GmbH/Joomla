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

        // Resolve each policy block to its own monitored domain. RFC 8460 lets
        // one report bundle policies for several policy domains, so a single
        // report-wide domain id would misattribute every secondary domain's
        // sessions to the first one and hide them from their own drill-down.
        if ($forcedDomainId > 0 && !$this->boundDomainMatchesReport($forcedDomainId, $report)) {
            // A mailbox bound to one monitored domain still delivers untrusted
            // content: a TLS-RPT report whose policy domain belongs to an
            // unrelated tenant would otherwise be attributed to this source and
            // skew its TLS metrics. Only honour the binding when every policy
            // domain shares an organizational domain with the bound one, exactly
            // as the DMARC path validates at Ingestor::boundDomainMatchesPolicy().
            $summary['skipped']++;

            return;
        }

        $policyDomainIds = $this->resolvePolicyDomains($report, $forcedDomainId);

        // Refuse to store a partially resolved report. If even one policy block
        // cannot be filed under a monitored domain (not monitored yet, or the
        // operator lacks the privilege to auto-create it), committing the report
        // now would persist it under its report-wide fingerprint while silently
        // dropping that policy's sessions. Replaying the same report after the
        // missing domain is added would then be treated as a duplicate, so the
        // skipped sessions could never be imported. Defer the whole report
        // instead so a later run imports every policy once all domains resolve.
        foreach ($report['policies'] as $index => $policy) {
            if ((int) ($policyDomainIds[$index] ?? 0) <= 0) {
                $summary['skipped']++;

                return;
            }
        }

        $primaryDomainId = 0;
        $primaryDomain   = '';

        foreach ($report['policies'] as $index => $policy) {
            if (($policyDomainIds[$index] ?? 0) > 0) {
                $primaryDomainId = (int) $policyDomainIds[$index];
                $primaryDomain   = (string) $policy['policy_domain'];
                break;
            }
        }

        if ($primaryDomainId <= 0) {
            // No policy carried a usable domain, or an operator without
            // core.create may not auto-register any of them. Skip rather than
            // storing an orphan report.
            $summary['skipped']++;

            return;
        }

        [$successCount, $failureCount] = $this->totals($report, $policyDomainIds);

        $this->db->transactionStart();

        try {
            // Lock every monitored domain this report writes to, not only the
            // primary one: the loop below inserts policy and failure rows for
            // each resolved secondary domain, so a concurrent delete of any of
            // them must not interleave into an orphan row. Acquiring the locks
            // in ascending id order matches DomainLock::lockMany()'s index-order
            // acquisition in the delete cascade, so the two paths cannot
            // deadlock. Abort the whole report if any parent disappeared.
            $lockDomainIds = array_values(array_unique(array_filter(
                array_map('intval', $policyDomainIds),
                static fn ($id) => $id > 0
            )));
            sort($lockDomainIds, SORT_NUMERIC);

            foreach ($lockDomainIds as $lockDomainId) {
                if (!DomainLock::lock($this->db, $lockDomainId)) {
                    $this->db->transactionRollback();
                    $summary['skipped']++;

                    return;
                }
            }

            $effectiveSourceId = $sourceId;

            if ($effectiveSourceId !== null && $effectiveSourceId > 0
                && !SourceLock::lock($this->db, $effectiveSourceId)) {
                $effectiveSourceId = 0;
            }

            $reportId = $this->insertReport($report, $hash, $primaryDomainId, $effectiveSourceId, $primaryDomain, $successCount, $failureCount);

            // Persist one row per policy block so a report bundling several
            // policies (for example an STS and a TLSA/no-policy result) keeps
            // each policy's type and its own success/failure counts, and link
            // every failure detail to the policy it was reported under rather
            // than only to the aggregate report row. Each policy is filed under
            // its own resolved domain so a multi-domain report drills down
            // correctly for every tenant it covers.
            foreach ($report['policies'] as $index => $policy) {
                $policyDomainId = (int) ($policyDomainIds[$index] ?? 0);

                if ($policyDomainId <= 0) {
                    // This policy's domain is not monitored and could not be
                    // auto-created; skip its rows rather than filing them under
                    // an unrelated domain. The report is still stored for the
                    // domains that did resolve.
                    continue;
                }

                $policyId = $this->insertPolicy($policy, $reportId, $policyDomainId);

                foreach ($policy['failures'] as $failure) {
                    $this->insertFailure($failure, $reportId, $policyId, $policyDomainId, (string) $policy['policy_domain']);
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
     * Resolve each policy block to its monitored-domain id, keyed parallel to
     * $report['policies']. A bound mailbox attributes every policy to the bound
     * tenant (already validated to share its organizational domain); otherwise
     * each policy's own domain is resolved and, where permitted, auto-created.
     * An unresolved or empty domain yields 0 so the caller skips that policy's
     * rows rather than filing them under an unrelated domain.
     *
     * @param   array<string, mixed>  $report
     *
     * @return  array<int, int>
     */
    private function resolvePolicyDomains(array $report, int $forcedDomainId): array
    {
        $ids = [];

        foreach ($report['policies'] as $index => $policy) {
            $policyDomain = (string) ($policy['policy_domain'] ?? '');

            if ($policyDomain === '') {
                $ids[$index] = 0;

                continue;
            }

            $ids[$index] = $forcedDomainId > 0
                ? $forcedDomainId
                : DomainRegistry::resolve($this->db, $policyDomain, $this->allowAutoCreate);
        }

        return $ids;
    }

    /**
     * Whether a mailbox's forced domain binding may accept this report. Returns
     * true only when the report carries at least one policy domain and every
     * non-empty policy domain shares an organizational domain with the bound
     * one (identical, or either a subdomain of the other). Empty policy domains
     * carry no cross-tenant claim and are ignored. Mirrors the DMARC path's
     * {@see Ingestor::boundDomainMatchesPolicy()}.
     *
     * @param   array<string, mixed>  $report
     */
    private function boundDomainMatchesReport(int $domainId, array $report): bool
    {
        $bound = $this->boundDomainName($domainId);

        if ($bound === '') {
            // The bound domain row was deleted between configuration and this
            // run; treat it as no match so the report is skipped rather than
            // stored under a tenant that no longer exists.
            return false;
        }

        $matched = false;

        foreach ($report['policies'] as $policy) {
            $policyDomain = strtolower(trim((string) ($policy['policy_domain'] ?? '')));

            if ($policyDomain === '') {
                continue;
            }

            if (!self::domainsShareOrg($bound, $policyDomain)) {
                return false;
            }

            $matched = true;
        }

        return $matched;
    }

    /**
     * The canonical name of a monitored domain, lower-cased, or '' when the row
     * no longer exists.
     */
    private function boundDomainName(int $domainId): string
    {
        $query = $this->query()
            ->select($this->db->quoteName('domain'))
            ->from($this->db->quoteName('#__dmarc_domains'))
            ->where($this->db->quoteName('id') . ' = :id')
            ->bind(':id', $domainId, ParameterType::INTEGER);

        return strtolower(trim((string) $this->db->setQuery($query)->loadResult()));
    }

    /**
     * Whether two lower-cased domains share an organizational domain: identical,
     * or either one a subdomain of the other. The leading dot in the suffix test
     * prevents a sibling such as evil-example.com from matching example.com.
     */
    private static function domainsShareOrg(string $bound, string $policyDomain): bool
    {
        if ($bound === $policyDomain) {
            return true;
        }

        return str_ends_with($policyDomain, '.' . $bound)
            || str_ends_with($bound, '.' . $policyDomain);
    }

    /**
     * Sum the success and failure session counts across every stored policy
     * block with overflow-safe accumulation. Only policies that resolved to a
     * monitored domain (their id in $policyDomainIds is positive) are counted,
     * so the report row's totals match the rows actually persisted. Individual
     * counts are already bounded to the storable integer range by
     * {@see TlsRptParser}, but their sum could still pass PHP_INT_MAX; saturating
     * there keeps the stored BIGINT UNSIGNED total non-negative instead of
     * wrapping to a float or a negative value.
     *
     * @param   array<string, mixed>  $report
     * @param   array<int, int>       $policyDomainIds  Resolved domain id per policy.
     *
     * @return  array{0:int, 1:int}
     */
    private function totals(array $report, array $policyDomainIds): array
    {
        $success = 0;
        $failure = 0;

        foreach ($report['policies'] as $index => $policy) {
            if ((int) ($policyDomainIds[$index] ?? 0) <= 0) {
                continue;
            }

            $success = self::addClamped($success, (int) ($policy['success_count'] ?? 0));
            $failure = self::addClamped($failure, (int) ($policy['failure_count'] ?? 0));
        }

        return [$success, $failure];
    }

    /**
     * Add a non-negative increment to a running total, saturating at PHP_INT_MAX
     * rather than overflowing.
     */
    private static function addClamped(int $running, int $add): int
    {
        if ($add <= 0) {
            return $running;
        }

        return $add > PHP_INT_MAX - $running ? PHP_INT_MAX : $running + $add;
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
     * Persist one policy block and return its new row id so the failures it
     * contains can reference it.
     *
     * @param   array<string, mixed>  $policy
     */
    private function insertPolicy(array $policy, int $reportId, int $domainId): int
    {
        $row = (object) [
            'report_id'     => $reportId,
            'domain_id'     => $domainId,
            'policy_type'   => (string) ($policy['policy_type'] ?? ''),
            'policy_domain' => (string) ($policy['policy_domain'] ?? ''),
            'success_count' => (int) ($policy['success_count'] ?? 0),
            'failure_count' => (int) ($policy['failure_count'] ?? 0),
        ];

        $this->db->insertObject('#__dmarc_tlsrpt_policies', $row, 'id');

        return (int) $row->id;
    }

    /**
     * @param   array<string, mixed>  $failure
     */
    private function insertFailure(array $failure, int $reportId, int $policyId, int $domainId, string $policyDomain): void
    {
        $row = (object) [
            'report_id'            => $reportId,
            'policy_id'            => $policyId,
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
