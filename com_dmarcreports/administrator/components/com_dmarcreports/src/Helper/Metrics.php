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
 * Aggregates stored DMARC records into the numbers the dashboard, the
 * drill-down view and the Action Center work with: volume, SPF/DKIM pass
 * rates, the share that would be rejected under p=reject, the sending sources
 * with their DKIM/SPF figures, the sources the Action Center flags and a daily
 * timeline.
 *
 * @since  1.0.0
 */
final class Metrics
{
    public const DASHBOARD_FLAGGED_SOURCE_LIMIT = 200;
    public const DETAIL_FLAGGED_SOURCE_LIMIT = 500;

    private DatabaseInterface $db;

    public function __construct(?DatabaseInterface $db = null)
    {
        $this->db = $db ?? Factory::getContainer()->get(DatabaseInterface::class);
    }

    /**
     * One summary row per monitored domain for the dashboard list.
     *
     * @return  array<int, array<string, mixed>>
     */
    public function domainSummaries(): array
    {
        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('d.id'),
                    $this->db->quoteName('d.domain'),
                    $this->db->quoteName('d.title'),
                    $this->db->quoteName('d.expected_policy'),
                    'COALESCE(SUM(' . $this->db->quoteName('r.count') . '), 0) AS total',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('r.dkim_aligned') . ' = 1 OR '
                        . $this->db->quoteName('r.spf_aligned') . ' = 1 THEN ' . $this->db->quoteName('r.count')
                        . ' ELSE 0 END), 0) AS dmarc_pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('r.dkim_aligned') . ' = 1 THEN '
                        . $this->db->quoteName('r.count') . ' ELSE 0 END), 0) AS dkim_pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('r.spf_aligned') . ' = 1 THEN '
                        . $this->db->quoteName('r.count') . ' ELSE 0 END), 0) AS spf_pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('r.dkim_aligned') . ' = 0 AND '
                        . $this->db->quoteName('r.spf_aligned') . ' = 0 THEN ' . $this->db->quoteName('r.count')
                        . ' ELSE 0 END), 0) AS reject_under_p',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_domains', 'd'))
            ->join('LEFT', $this->db->quoteName('#__dmarc_records', 'r'),
                $this->db->quoteName('r.domain_id') . ' = ' . $this->db->quoteName('d.id'))
            ->where($this->db->quoteName('d.published') . ' = 1')
            ->group($this->db->quoteName('d.id') . ', ' . $this->db->quoteName('d.domain') . ', '
                . $this->db->quoteName('d.title') . ', ' . $this->db->quoteName('d.expected_policy'))
            ->order($this->db->quoteName('d.domain') . ' ASC');

        $rows = $this->db->setQuery($query)->loadAssocList() ?: [];

        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);

        // Resolve the latest policy for every domain in a single grouped query
        // rather than one query per row, so the dashboard does not fan out as the
        // number of monitored domains grows.
        $policies = $this->latestPolicyAll($ids);

        foreach ($rows as &$row) {
            $row                       = array_map(static fn ($v) => is_numeric($v) ? $v + 0 : $v, $row);
            $latest                    = $policies[(int) $row['id']] ?? ['p' => '', 'pct' => 100];
            $row['current_policy']     = $latest['p'];
            $row['current_policy_pct'] = $latest['pct'];
            $row['pass_rate']          = self::rate((int) $row['dmarc_pass'], (int) $row['total']);
        }

        return $rows;
    }

    /**
     * The full metric bundle for one domain, shaped for the Action Center and
     * the drill-down view.
     *
     * @return  array<string, mixed>
     */
    public function domainMetrics(int $domainId): array
    {
        $domain = $this->domainRow($domainId);

        $latestPolicy = $this->latestPolicy($domainId, (string) ($domain['domain'] ?? ''));

        $anchor  = $this->latestReportEnd($domainId);
        $flagged = $this->flaggedSources($domainId, $anchor, self::DETAIL_FLAGGED_SOURCE_LIMIT);

        $metrics = [
            'domain_id'          => $domainId,
            'domain'             => $domain['domain'] ?? '',
            'expected_policy'    => $domain['expected_policy'] ?? 'reject',
            'current_policy'     => $latestPolicy['p'],
            'current_policy_pct' => $latestPolicy['pct'],
            'total'             => 0,
            'dmarc_pass'        => 0,
            'dkim_pass'         => 0,
            'spf_pass'          => 0,
            'reject_under_p'    => 0,
            'top_sources'       => $this->sourceTable($domainId, $anchor, $flagged),
            'flagged_sources'   => $flagged,
            'timeline'          => $this->timeline($domainId),
            'pass_rate_prev'    => null,
        ];

        $totals = $this->totals($domainId);
        $metrics = array_merge($metrics, $totals);

        $metrics['pass_rate']        = self::rate((int) $metrics['dmarc_pass'], (int) $metrics['total']);

        // Compare two equal-length, non-overlapping time windows anchored on the
        // newest report, using volume-weighted rates aggregated across every
        // report in each window. This avoids false drop alerts when adjacent
        // individual reports come from different reporters or overlapping spans.
        $window = 7 * 86400;

        // The rollout guidance must reflect the current reporting window, not
        // the accumulated lifetime: a single historical failure would otherwise
        // keep the lifetime reject-under-p count above zero forever and block
        // the Action Center from ever recommending the next policy step. The
        // window volume, pass rate and would-be-rejected count are therefore
        // exposed alongside the lifetime totals for the Action Center to prefer.
        $current = $anchor > 0
            ? $this->windowTotals($domainId, $anchor - $window, $anchor)
            : ['total' => 0, 'pass' => 0, 'reject_under_p' => 0];

        $metrics['window_total']          = $current['total'];
        $metrics['window_reject_under_p'] = $current['reject_under_p'];
        $metrics['pass_rate_window']      = $current['total'] > 0
            ? self::rate($current['pass'], $current['total'])
            : null;
        $metrics['pass_rate_prev'] = $anchor > 0
            ? $this->windowPassRate($domainId, $anchor - (2 * $window), $anchor - $window)
            : null;

        return $metrics;
    }

    /**
     * Lean Action Center metric bundles for every supplied domain summary,
     * keyed by domain id. Built from the per-domain summary rows the dashboard
     * already fetched in one grouped query (domainSummaries), plus a small,
     * fixed set of additional grouped queries covering the aligned-source lists
     * and the rolling-window figures. Those figures depend on each domain's own
     * newest report, but are resolved for all domains at once through a derived
     * per-domain anchor table rather than one query per domain, so the Action
     * Center no longer fans out to roughly six queries per domain. The number of
     * queries is constant regardless of how many domains are monitored. The
     * drill-down-only data (top sources, timeline) and the lifetime totals,
     * latest policy and domain row are deliberately not recomputed here.
     *
     * @param   array<int, array<string, mixed>>  $summaries  Rows from domainSummaries().
     *
     * @return  array<int, array<string, mixed>>
     */
    public function actionMetricsForAll(array $summaries): array
    {
        $ids = [];

        foreach ($summaries as $summary) {
            $id = (int) ($summary['id'] ?? 0);

            if ($id > 0) {
                $ids[] = $id;
            }
        }

        if ($ids === []) {
            return [];
        }

        $window   = 7 * 86400;
        $anchors  = $this->latestReportEndAll($ids);
        $flagged  = $this->flaggedSourcesAll($ids);
        $current  = $this->windowTotalsAll($ids, $window, 0);
        $previous = $this->windowTotalsAll($ids, 2 * $window, $window);

        $empty  = ['total' => 0, 'pass' => 0, 'reject_under_p' => 0];
        $result = [];

        foreach ($summaries as $summary) {
            $domainId = (int) ($summary['id'] ?? 0);

            if ($domainId <= 0) {
                continue;
            }

            $anchor = $anchors[$domainId] ?? 0;
            $cur    = $current[$domainId] ?? $empty;
            $prev   = $previous[$domainId] ?? $empty;

            $metrics = [
                'domain_id'          => $domainId,
                'domain'             => (string) ($summary['domain'] ?? ''),
                'expected_policy'    => (string) ($summary['expected_policy'] ?? 'reject'),
                'current_policy'     => (string) ($summary['current_policy'] ?? ''),
                'current_policy_pct' => (int) ($summary['current_policy_pct'] ?? 100),
                'total'              => (int) ($summary['total'] ?? 0),
                'dmarc_pass'         => (int) ($summary['dmarc_pass'] ?? 0),
                'reject_under_p'     => (int) ($summary['reject_under_p'] ?? 0),
                'flagged_sources'    => $anchor > 0 ? ($flagged[$domainId] ?? []) : [],
                'pass_rate_prev'     => null,
            ];

            $metrics['pass_rate']             = self::rate($metrics['dmarc_pass'], $metrics['total']);
            $metrics['window_total']          = $cur['total'];
            $metrics['window_reject_under_p'] = $cur['reject_under_p'];
            $metrics['pass_rate_window']      = $cur['total'] > 0
                ? self::rate($cur['pass'], $cur['total'])
                : null;
            $metrics['pass_rate_prev'] = ($anchor > 0 && $prev['total'] > 0)
                ? self::rate($prev['pass'], $prev['total'])
                : null;

            $result[$domainId] = $metrics;
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    /**
     * TLS-RPT (RFC 8460) summary for one domain: the stored report count, the
     * successful and failed session totals and the most recent failure rows,
     * grouped by result type. Returns zeroes and empty lists when no TLS-RPT
     * report has been ingested yet, so the drill-down view degrades cleanly.
     *
     * @return  array{reports:int, success:int, failure:int, failures:array<int, array<string, mixed>>}
     */
    public function tlsRptSummary(int $domainId): array
    {
        // Reports and session totals are derived from the per-policy rows, each
        // of which carries its own resolved domain. RFC 8460 lets one report
        // bundle policies for several policy domains, so counting from the
        // policy rows (rather than the single report-level domain) attributes
        // every policy to its own domain's drill-down instead of only the
        // report's primary domain.
        $summary = $this->query()
            ->select(
                [
                    'COUNT(DISTINCT ' . $this->db->quoteName('report_id') . ') AS reports',
                    'COALESCE(SUM(' . $this->db->quoteName('success_count') . '), 0) AS success',
                    'COALESCE(SUM(' . $this->db->quoteName('failure_count') . '), 0) AS failure',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_tlsrpt_policies'))
            ->where($this->db->quoteName('domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER);

        $row = $this->db->setQuery($summary)->loadAssoc() ?: [];

        $failQuery = $this->query()
            ->select(
                [
                    $this->db->quoteName('result_type'),
                    $this->db->quoteName('receiving_mx_host'),
                    $this->db->quoteName('sending_mta_ip'),
                    'COALESCE(SUM(' . $this->db->quoteName('failed_session_count') . '), 0) AS sessions',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_tlsrpt_failures'))
            ->where($this->db->quoteName('domain_id') . ' = :domainId')
            ->group(
                [
                    $this->db->quoteName('result_type'),
                    $this->db->quoteName('receiving_mx_host'),
                    $this->db->quoteName('sending_mta_ip'),
                ]
            )
            ->order('sessions DESC')
            ->bind(':domainId', $domainId, ParameterType::INTEGER);

        $failQuery->setLimit(20);

        $failures = $this->db->setQuery($failQuery)->loadAssocList() ?: [];

        return [
            'reports'  => (int) ($row['reports'] ?? 0),
            'success'  => (int) ($row['success'] ?? 0),
            'failure'  => (int) ($row['failure'] ?? 0),
            'failures' => array_map(
                static fn (array $f): array => [
                    'result_type'       => (string) ($f['result_type'] ?? ''),
                    'receiving_mx_host' => (string) ($f['receiving_mx_host'] ?? ''),
                    'sending_mta_ip'    => (string) ($f['sending_mta_ip'] ?? ''),
                    'sessions'          => (int) ($f['sessions'] ?? 0),
                ],
                $failures
            ),
        ];
    }

    private function totals(int $domainId): array
    {
        $count = $this->db->quoteName('count');

        $query = $this->query()
            ->select(
                [
                    'COALESCE(SUM(' . $count . '), 0) AS total',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('dkim_aligned') . ' = 1 OR '
                        . $this->db->quoteName('spf_aligned') . ' = 1 THEN ' . $count . ' ELSE 0 END), 0) AS dmarc_pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('dkim_aligned') . ' = 1 THEN ' . $count
                        . ' ELSE 0 END), 0) AS dkim_pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('spf_aligned') . ' = 1 THEN ' . $count
                        . ' ELSE 0 END), 0) AS spf_pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('dkim_aligned') . ' = 0 AND '
                        . $this->db->quoteName('spf_aligned') . ' = 0 THEN ' . $count . ' ELSE 0 END), 0) AS reject_under_p',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_records'))
            ->where($this->db->quoteName('domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadAssoc() ?: [];

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'dmarc_pass'     => (int) ($row['dmarc_pass'] ?? 0),
            'dkim_pass'      => (int) ($row['dkim_pass'] ?? 0),
            'spf_pass'       => (int) ($row['spf_pass'] ?? 0),
            'reject_under_p' => (int) ($row['reject_under_p'] ?? 0),
        ];
    }

    /**
     * The domain view's table of sending sources: the top $limit sources by
     * lifetime volume, followed by every source the Action Center flags (so each
     * flagged item can link to its row even when its volume is small).
     *
     * @param   array<int, array<string, mixed>>  $flagged  Rows from flaggedSources().
     *
     * @return  array<int, array<string, mixed>>
     */
    private function sourceTable(int $domainId, int $anchor, array $flagged, int $limit = 20): array
    {
        $rows  = $this->sourceRows($domainId, $anchor, null, $limit);
        $shown = array_column($rows, 'source_ip');
        $extra = [];

        foreach ($flagged as $source) {
            $ip = (string) ($source['ip'] ?? '');

            if ($ip !== '' && !\in_array($ip, $shown, true)) {
                $extra[] = $ip;
            }
        }

        if ($extra !== []) {
            $rows = array_merge($rows, $this->sourceRows($domainId, $anchor, $extra, \count($extra)));
        }

        return $rows;
    }

    /**
     * The classification of the given sending addresses of a domain, judged on
     * their current figures ({@see SourceClassifier::current()}). Addresses
     * without any record are absent from the result.
     *
     * @param   string[]  $ips
     *
     * @return  array<string, string>  Class constant of {@see SourceClassifier} keyed by address.
     */
    public function classifySources(int $domainId, array $ips): array
    {
        $ips = array_values(array_unique(array_filter(array_map('strval', $ips), static fn (string $ip): bool => $ip !== '')));

        if ($domainId <= 0 || $ips === []) {
            return [];
        }

        $classes = [];

        foreach ($this->sourceRows($domainId, $this->latestReportEnd($domainId), $ips, \count($ips)) as $row) {
            $classes[(string) $row['source_ip']] = SourceClassifier::classify(SourceClassifier::current($row));
        }

        return $classes;
    }

    /**
     * Classify several domains' pending source addresses in one grouped query.
     *
     * @param   array<int, string[]>  $requests
     *
     * @return  array<int, array<string, string>>
     */
    public function classifySourcesAll(array $requests): array
    {
        $domains = [];

        foreach ($requests as $domainId => $ips) {
            $domainId = (int) $domainId;
            $ips = array_values(array_unique(array_filter(array_map('strval', (array) $ips), static fn (string $ip): bool => $ip !== '')));

            if ($domainId > 0 && $ips !== []) {
                $domains[$domainId] = $ips;
            }
        }

        if ($domains === []) {
            return [];
        }

        $domainIds = array_keys($domains);
        $inDomains = $this->intList($domainIds);
        $ips = [];

        foreach ($domains as $domainIps) {
            foreach ($domainIps as $ip) {
                $ips[$ip] = true;
            }
        }

        $inIps = implode(',', array_map(fn (string $ip): string => $this->db->quote($ip), array_keys($ips)));
        $window = 7 * 86400;

        $anchor = (string) $this->query()
            ->select([
                $this->db->quoteName('domain_id'),
                'MAX(' . $this->db->quoteName('date_end') . ') AS anchor',
            ])
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('domain_id') . ' IN (' . $inDomains . ')')
            ->group($this->db->quoteName('domain_id'));

        $select = [
            $this->db->quoteName('rec.domain_id', 'domain_id'),
            $this->db->quoteName('rec.source_ip', 'source_ip'),
        ];

        foreach ($this->figureConditions('rec') as $name => $condition) {
            $select[] = $this->sumWhen($condition, 'rec') . ' AS ' . $name;
            $windowCondition = '(' . $condition . ') AND '
                . $this->db->quoteName('rep.date_end') . ' > (' . $this->db->quoteName('a.anchor') . ' - ' . $window . ')'
                . ' AND ' . $this->db->quoteName('rep.date_end') . ' <= ' . $this->db->quoteName('a.anchor');
            $select[] = $this->sumWhen($windowCondition, 'rec') . ' AS w_' . $name;
        }

        $query = $this->query()
            ->select($select)
            ->from($this->db->quoteName('#__dmarc_records', 'rec'))
            ->join(
                'INNER',
                $this->db->quoteName('#__dmarc_reports', 'rep'),
                $this->db->quoteName('rep.id') . ' = ' . $this->db->quoteName('rec.report_id')
            )
            ->join(
                'INNER',
                '(' . $anchor . ') AS ' . $this->db->quoteName('a'),
                $this->db->quoteName('a.domain_id') . ' = ' . $this->db->quoteName('rec.domain_id')
            )
            ->where($this->db->quoteName('rec.domain_id') . ' IN (' . $inDomains . ')')
            ->where($this->db->quoteName('rec.source_ip') . ' IN (' . $inIps . ')')
            ->group($this->db->quoteName('rec.domain_id') . ', ' . $this->db->quoteName('rec.source_ip'));

        $classes = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            foreach (array_keys($this->figureConditions('rec')) as $name) {
                $row[$name] = (int) $row[$name];
                $row['w_' . $name] = (int) $row['w_' . $name];
            }

            $domainId = (int) $row['domain_id'];
            $ip = (string) $row['source_ip'];
            $classes[$domainId][$ip] = SourceClassifier::classify(SourceClassifier::current($row));
        }

        return $classes;
    }

    /**
     * Per-source figures of a domain: lifetime totals, the same figures for the
     * current reporting window (prefixed `w_`, the seven days up to the newest
     * report end, as the Action Center measures them) and the end of the newest
     * report that listed the source. Restricted to $ips when given, otherwise the
     * top $limit sources by lifetime volume.
     *
     * @param   string[]|null  $ips
     *
     * @return  array<int, array<string, mixed>>
     */
    private function sourceRows(int $domainId, int $anchor, ?array $ips, int $limit): array
    {
        if ($ips === []) {
            return [];
        }

        // The window bounds are integers taken from the database and inlined, so
        // each can appear in several CASE expressions without repeating a bound
        // parameter name.
        $windowStart = $anchor - (7 * 86400);
        $inWindow    = $anchor > 0
            ? $this->db->quoteName('rep.date_end') . ' > ' . (int) $windowStart . ' AND '
                . $this->db->quoteName('rep.date_end') . ' <= ' . (int) $anchor
            : '1 = 0';

        $select = [$this->db->quoteName('rec.source_ip', 'source_ip')];

        foreach ($this->figureConditions('rec') as $name => $condition) {
            $select[] = $this->sumWhen($condition, 'rec') . ' AS ' . $name;
            $select[] = $this->sumWhen('(' . $condition . ') AND ' . $inWindow, 'rec') . ' AS w_' . $name;
        }

        $select[] = 'MAX(' . $this->db->quoteName('rep.date_end') . ') AS last_seen';
        foreach ([
            'policy_domain' => 'rep.policy_domain',
            'policy_adkim' => 'rep.policy_adkim',
            'policy_aspf' => 'rep.policy_aspf',
            'header_from' => 'rec.header_from',
        ] as $name => $column) {
            $select[] = 'MAX(' . $this->db->quoteName($column) . ') AS ' . $name;
            $select[] = 'MAX(CASE WHEN ' . $inWindow . ' THEN ' . $this->db->quoteName($column)
                . ' ELSE NULL END) AS w_' . $name;
        }
        $select[] = $this->policyContextMixedSelect('rep', 'rec', null, 'lifetime_policy_context_mixed');
        $select[] = $this->policyContextMixedSelect('rep', 'rec', $inWindow, 'window_policy_context_mixed');
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.dkim_result') . ' = ' . $this->db->quote('pass')
            . ' AND ' . $this->db->quoteName('rec.dkim_aligned') . ' = 1 AND ' . $inWindow
            . ' THEN ' . $this->db->quoteName('rec.count')
            . ' ELSE 0 END), 0) AS dkim_pass_aligned';
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.spf_result') . ' = ' . $this->db->quote('pass')
            . ' AND ' . $this->db->quoteName('rec.spf_aligned') . ' = 1 AND ' . $inWindow
            . ' THEN ' . $this->db->quoteName('rec.count')
            . ' ELSE 0 END), 0) AS spf_pass_aligned';
        foreach (['none', 'quarantine', 'reject'] as $disposition) {
            $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.disposition') . ' = ' . $this->db->quote($disposition)
                . ' AND ' . $inWindow . ' THEN ' . $this->db->quoteName('rec.count') . ' ELSE 0 END), 0) AS disposition_' . $disposition;
        }
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.dkim_result') . ' = ' . $this->db->quote('pass')
            . ' AND ' . $this->db->quoteName('rec.dkim_aligned') . ' = 1 THEN ' . $this->db->quoteName('rec.count') . ' ELSE 0 END), 0) AS lifetime_dkim_pass_aligned';
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.spf_result') . ' = ' . $this->db->quote('pass')
            . ' AND ' . $this->db->quoteName('rec.spf_aligned') . ' = 1 THEN ' . $this->db->quoteName('rec.count') . ' ELSE 0 END), 0) AS lifetime_spf_pass_aligned';
        foreach (['none', 'quarantine', 'reject'] as $disposition) {
            $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.disposition') . ' = ' . $this->db->quote($disposition)
                . ' THEN ' . $this->db->quoteName('rec.count') . ' ELSE 0 END), 0) AS lifetime_disposition_' . $disposition;
        }
        foreach (['none', 'quarantine', 'reject'] as $disposition) {
            $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.disposition') . ' = ' . $this->db->quote($disposition)
                . ' THEN ' . $this->db->quoteName('rec.count') . ' ELSE 0 END), 0) AS lifetime_disposition_' . $disposition;
        }
        $query = $this->query()
            ->select($select)
            ->from($this->db->quoteName('#__dmarc_records', 'rec'))
            ->join(
                'INNER',
                $this->db->quoteName('#__dmarc_reports', 'rep'),
                $this->db->quoteName('rep.id') . ' = ' . $this->db->quoteName('rec.report_id')
            )
            ->where($this->db->quoteName('rec.domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->group($this->db->quoteName('rec.source_ip'))
            ->order('total DESC, ' . $this->db->quoteName('rec.source_ip') . ' ASC');

        if ($ips !== null) {
            $query->where(
                $this->db->quoteName('rec.source_ip') . ' IN ('
                . implode(',', array_map(fn (string $ip): string => $this->db->quote($ip), array_map('strval', $ips))) . ')'
            );
        }

        $query->setLimit(max(1, $limit));
        $rows = $this->db->setQuery($query)->loadAssocList() ?: [];

        foreach ($rows as &$row) {
            foreach (array_keys($this->figureConditions('rec')) as $name) {
                $row[$name]        = (int) $row[$name];
                $row['w_' . $name] = (int) $row['w_' . $name];
            }

            $row['last_seen'] = (int) $row['last_seen'];
            $row['pass']      = $row['dkim'] + $row['spf_only'];
            $row['class']     = SourceClassifier::classify(SourceClassifier::current($row));
            foreach ([
                'dkim_pass_aligned', 'spf_pass_aligned',
                'disposition_none', 'disposition_quarantine', 'disposition_reject',
            ] as $key) {
                $row[$key] = (int) ($row[$key] ?? 0);
            }
            $fallback = (int) ($row['w_total'] ?? 0) === 0;
            $contextPrefix = $fallback ? '' : 'w_';

            if ($fallback) {
                foreach ([
                    'dkim_pass_aligned' => 'lifetime_dkim_pass_aligned',
                    'spf_pass_aligned' => 'lifetime_spf_pass_aligned',
                    'disposition_none' => 'lifetime_disposition_none',
                    'disposition_quarantine' => 'lifetime_disposition_quarantine',
                    'disposition_reject' => 'lifetime_disposition_reject',
                ] as $key => $lifetimeKey) {
                    $row[$key] = (int) ($row[$lifetimeKey] ?? 0);
                }
            }
            $row['policy_context_mixed'] = (int) ($row[$fallback ? 'lifetime_policy_context_mixed' : 'window_policy_context_mixed'] ?? 0) > 0;
            foreach (['policy_domain', 'policy_adkim', 'policy_aspf', 'header_from'] as $field) {
                $row[$field] = $row['policy_context_mixed']
                    ? ''
                    : (string) ($row[$contextPrefix . $field] ?? '');
            }
            $row['evidence_total'] = $fallback ? (int) $row['total'] : (int) $row['w_total'];
            $row['evidence_window_fallback'] = $fallback;
            $row['evidence_window_start'] = $anchor > 0 ? $anchor - (7 * 86400) : 0;
            $row['evidence_window_end'] = $anchor;
        }

        unset($row);

        return $rows;
    }

    /**
     * Sources in the CURRENT reporting window that need the administrator: mail
     * that passed DMARC only through SPF (without the domain's DKIM signature) or
     * failing mail that was still delivered. Sources whose mail is DKIM-aligned,
     * or whose failing mail the receivers rejected or quarantined, are left out:
     * the software confirms the former itself and the policy already handles the
     * latter. Scoped to the rolling window, so a source that has since been fixed
     * drops out instead of lingering on its history; ranked by SPF-only volume,
     * then delivered failing volume.
     *
     * @return array<int, array<string, mixed>>
     */
    private function flaggedSources(int $domainId, int $anchor, int $limit = self::DETAIL_FLAGGED_SOURCE_LIMIT): array
    {
        if ($anchor === 0) {
            return [];
        }

        $windowStart = $anchor - (7 * 86400);
        $conditions  = $this->figureConditions('rec');
        $select      = [$this->db->quoteName('rec.source_ip', 'source_ip')];

        foreach ($conditions as $name => $condition) {
            $select[] = $this->sumWhen($condition, 'rec') . ' AS ' . $name;
        }

        $select[] = 'MAX(' . $this->db->quoteName('rep.date_end') . ') AS last_seen';
        $select[] = 'MAX(' . $this->db->quoteName('rep.policy_domain') . ') AS policy_domain';
        $select[] = 'MAX(' . $this->db->quoteName('rep.policy_adkim') . ') AS policy_adkim';
        $select[] = 'MAX(' . $this->db->quoteName('rep.policy_aspf') . ') AS policy_aspf';
        $select[] = 'MAX(' . $this->db->quoteName('rec.header_from') . ') AS header_from';
        $select[] = $this->policyContextMixedSelect('rep', 'rec');
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.dkim_result') . ' = ' . $this->db->quote('pass')
            . ' AND ' . $this->db->quoteName('rec.dkim_aligned') . ' = 1 THEN ' . $this->db->quoteName('rec.count') . ' ELSE 0 END), 0) AS dkim_pass_aligned';
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.spf_result') . ' = ' . $this->db->quote('pass')
            . ' AND ' . $this->db->quoteName('rec.spf_aligned') . ' = 1 THEN ' . $this->db->quoteName('rec.count') . ' ELSE 0 END), 0) AS spf_pass_aligned';
        foreach (['none', 'quarantine', 'reject'] as $disposition) {
            $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.disposition') . ' = ' . $this->db->quote($disposition)
                . ' THEN ' . $this->db->quoteName('rec.count') . ' ELSE 0 END), 0) AS disposition_' . $disposition;
        }

        $query = $this->query()
            ->select($select)
            ->from($this->db->quoteName('#__dmarc_reports', 'rep'))
            ->join(
                'INNER',
                $this->db->quoteName('#__dmarc_records', 'rec'),
                $this->db->quoteName('rec.report_id') . ' = ' . $this->db->quoteName('rep.id')
            )
            ->where($this->db->quoteName('rep.domain_id') . ' = :domainId')
            ->where($this->db->quoteName('rep.date_end') . ' > :windowStart')
            ->where($this->db->quoteName('rep.date_end') . ' <= :anchor')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->bind(':windowStart', $windowStart, ParameterType::INTEGER)
            ->bind(':anchor', $anchor, ParameterType::INTEGER)
            ->group($this->db->quoteName('rec.source_ip'))
            ->having($this->flaggedHaving('rec'))
            ->order('spf_only DESC, fail_delivered DESC, ' . $this->db->quoteName('rec.source_ip') . ' ASC');

        if ($limit > 0) {
            $query->setLimit($limit);
        }

        $rows = [];
        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $flagged = self::flaggedRow($row);
            $flagged['evidence_total'] = (int) $flagged['total'];
            $flagged['evidence_window_fallback'] = false;
            $flagged['evidence_window_start'] = $anchor - (7 * 86400);
            $flagged['evidence_window_end'] = $anchor;
            $rows[] = $flagged;
        }

        return $rows;
    }

    /**
     * The message-count conditions every source figure is built from, for the
     * records aliased $alias. The receivers' policy_evaluated results already
     * apply alignment under the domain's adkim/aspf modes, so dkim_aligned = 1
     * means the mail carried a valid signature of the domain (or an aligned
     * subdomain).
     *
     * @return array<string, string>  Figure name => SQL condition.
     */
    private function figureConditions(string $alias): array
    {
        $dkim = $this->db->quoteName($alias . '.dkim_aligned');
        $spf  = $this->db->quoteName($alias . '.spf_aligned');
        $fail = $dkim . ' = 0 AND ' . $spf . ' = 0';

        return [
            'total'          => '1 = 1',
            'dkim'           => $dkim . ' = 1',
            'spf_only'       => $dkim . ' = 0 AND ' . $spf . ' = 1',
            'fail'           => $fail,
            'fail_delivered' => $fail . ' AND ' . $this->db->quoteName($alias . '.disposition') . ' = ' . $this->db->quote('none'),
        ];
    }

    /**
     * Whether this source's report context varies across the evidence rows.
     * When a period condition is supplied, only reports in that period count.
     */
    private function policyContextMixedSelect(
        string $reportAlias,
        string $recordAlias,
        ?string $condition = null,
        string $resultAlias = 'policy_context_mixed'
    ): string {
        $varies = [];

        foreach ([
            $reportAlias . '.policy_domain',
            $reportAlias . '.policy_adkim',
            $reportAlias . '.policy_aspf',
            $recordAlias . '.header_from',
        ] as $column) {
            $value = $this->db->quoteName($column);

            if ($condition !== null) {
                $value = 'CASE WHEN ' . $condition . ' THEN ' . $value . ' ELSE NULL END';
            }

            $varies[] = 'COUNT(DISTINCT ' . $value . ') > 1';
        }

        return 'CASE WHEN ' . implode(' OR ', $varies) . ' THEN 1 ELSE 0 END AS ' . $resultAlias;
    }

    /**
     * SQL summing the message count of the records aliased $alias that match
     * $condition.
     */
    private function sumWhen(string $condition, string $alias): string
    {
        return 'COALESCE(SUM(CASE WHEN ' . $condition . ' THEN ' . $this->db->quoteName($alias . '.count')
            . ' ELSE 0 END), 0)';
    }

    /**
     * HAVING clause keeping only the sources the Action Center flags. Spelt out
     * rather than using the select aliases, which PostgreSQL does not allow in
     * HAVING.
     */
    private function flaggedHaving(string $alias): string
    {
        $conditions = $this->figureConditions($alias);

        return $this->sumWhen($conditions['spf_only'], $alias) . ' > 0 OR '
            . $this->sumWhen($conditions['fail_delivered'], $alias) . ' > 0';
    }

    /**
     * @param   array<string, mixed>  $row
     *
     * @return  array<string, mixed>
     */
    private static function flaggedRow(array $row): array
    {
        $result = [
            'ip'             => (string) $row['source_ip'],
            'total'          => (int) $row['total'],
            'dkim'           => (int) $row['dkim'],
            'spf_only'       => (int) $row['spf_only'],
            'fail'           => (int) $row['fail'],
            'fail_delivered' => (int) $row['fail_delivered'],
            'last_seen'      => (int) $row['last_seen'],
            'policy_domain'  => (string) ($row['policy_domain'] ?? ''),
            'policy_adkim'   => (string) ($row['policy_adkim'] ?? ''),
            'policy_aspf'    => (string) ($row['policy_aspf'] ?? ''),
            'header_from'    => (string) ($row['header_from'] ?? ''),
            'policy_context_mixed' => (int) ($row['policy_context_mixed'] ?? 0) > 0,
        ];

        if ($result['policy_context_mixed']) {
            $result['policy_domain'] = '';
            $result['policy_adkim'] = '';
            $result['policy_aspf'] = '';
            $result['header_from'] = '';
        }

        foreach ([
            'dkim_pass_aligned', 'spf_pass_aligned',
            'disposition_none', 'disposition_quarantine', 'disposition_reject',
        ] as $key) {
            $result[$key] = (int) ($row[$key] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function timeline(int $domainId): array
    {
        // Bucket by UTC calendar day: FLOOR(date_begin / 86400) yields the day
        // index since the epoch, so multiple reporters covering the same day
        // collapse into one row instead of producing duplicate 'day' keys, and
        // the 90-row limit then means 90 distinct days rather than 90 reports.
        // The expression is portable across MySQL and PostgreSQL.
        $dayBucket = 'FLOOR(' . $this->db->quoteName('rep.date_begin') . ' / 86400)';

        $query = $this->query()
            ->select(
                [
                    $dayBucket . ' AS day_bucket',
                    'COALESCE(SUM(' . $this->db->quoteName('rec.count') . '), 0) AS total',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.dkim_aligned') . ' = 1 OR '
                        . $this->db->quoteName('rec.spf_aligned') . ' = 1 THEN ' . $this->db->quoteName('rec.count')
                        . ' ELSE 0 END), 0) AS pass',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports', 'rep'))
            ->join('INNER', $this->db->quoteName('#__dmarc_records', 'rec'),
                $this->db->quoteName('rec.report_id') . ' = ' . $this->db->quoteName('rep.id'))
            ->where($this->db->quoteName('rep.domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->group($dayBucket)
            ->order($dayBucket . ' DESC');

        // Take the newest 90 days, then restore chronological order for display.
        $query->setLimit(90);
        $rows = array_reverse($this->db->setQuery($query)->loadAssocList() ?: []);

        return array_map(
            static fn (array $r): array => [
                'day'   => gmdate('Y-m-d', (int) $r['day_bucket'] * 86400),
                'total' => (int) $r['total'],
                'pass'  => (int) $r['pass'],
            ],
            $rows
        );
    }

    /**
     * Volume-weighted DMARC pass rate across every record whose report window
     * ends in the half-open interval (startTs, endTs], or null when no mail
     * falls in that window. Bucketing by the report's own end time keeps the
     * two compared windows non-overlapping regardless of how many reporters
     * contribute, and matches the date_end anchor the windows are measured from,
     * so the comparison stays like-for-like.
     */
    private function windowPassRate(int $domainId, int $startTs, int $endTs): ?float
    {
        $totals = $this->windowTotals($domainId, $startTs, $endTs);

        return $totals['total'] === 0 ? null : self::rate($totals['pass'], $totals['total']);
    }

    /**
     * Volume, DMARC-passing volume and would-be-rejected volume across every
     * record whose report window ends in the half-open interval
     * (startTs, endTs]. Bucketing by the report's own end time keeps compared
     * windows non-overlapping regardless of how many reporters contribute, and
     * matches the date_end anchor the windows are measured from, so the newest
     * report always falls inside the current window even when it spans more than
     * one window's length. This lets the Action Center reason about the current
     * window rather than the accumulated lifetime.
     *
     * @return array{total: int, pass: int, reject_under_p: int}
     */
    private function windowTotals(int $domainId, int $startTs, int $endTs): array
    {
        $query = $this->query()
            ->select(
                [
                    'COALESCE(SUM(' . $this->db->quoteName('rec.count') . '), 0) AS total',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.dkim_aligned') . ' = 1 OR '
                        . $this->db->quoteName('rec.spf_aligned') . ' = 1 THEN ' . $this->db->quoteName('rec.count')
                        . ' ELSE 0 END), 0) AS pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.dkim_aligned') . ' = 0 AND '
                        . $this->db->quoteName('rec.spf_aligned') . ' = 0 THEN ' . $this->db->quoteName('rec.count')
                        . ' ELSE 0 END), 0) AS reject_under_p',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports', 'rep'))
            ->join('INNER', $this->db->quoteName('#__dmarc_records', 'rec'),
                $this->db->quoteName('rec.report_id') . ' = ' . $this->db->quoteName('rep.id'))
            ->where($this->db->quoteName('rep.domain_id') . ' = :domainId')
            ->where($this->db->quoteName('rep.date_end') . ' > :startTs')
            ->where($this->db->quoteName('rep.date_end') . ' <= :endTs')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->bind(':startTs', $startTs, ParameterType::INTEGER)
            ->bind(':endTs', $endTs, ParameterType::INTEGER);

        $row = $this->db->setQuery($query)->loadAssoc() ?: [];

        return [
            'total'          => (int) ($row['total'] ?? 0),
            'pass'           => (int) ($row['pass'] ?? 0),
            'reject_under_p' => (int) ($row['reject_under_p'] ?? 0),
        ];
    }

    /**
     * The newest report end timestamp for a domain, used to anchor the rolling
     * comparison windows, or 0 when the domain has no reports yet.
     */
    private function latestReportEnd(int $domainId): int
    {
        $query = $this->query()
            ->select('COALESCE(MAX(' . $this->db->quoteName('date_end') . '), 0)')
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER);

        return (int) $this->db->setQuery($query)->loadResult();
    }

    /**
     * The effective current policy disposition and percentage from the most
     * recent stored report, ordered by end date then id. RFC 7489 resolves the
     * disposition from the subdomain policy (sp) when the DMARC record was found
     * at an organisational parent rather than the monitored subdomain itself,
     * falling back to the domain policy (p) otherwise. The percentage matters
     * for the rollout guidance: p=reject with pct<100 only subjects that share
     * of mail to the policy, so it must not be presented as full enforcement.
     *
     * @return  array{p: string, pct: int}
     */
    private function latestPolicy(int $domainId, string $monitoredDomain): array
    {
        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('policy_p'),
                    $this->db->quoteName('policy_pct'),
                    $this->db->quoteName('policy_sp'),
                    $this->db->quoteName('policy_domain'),
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->order($this->db->quoteName('date_end') . ' DESC, ' . $this->db->quoteName('id') . ' DESC');

        $query->setLimit(1);

        $row = $this->db->setQuery($query)->loadAssoc();

        if (!$row) {
            return ['p' => '', 'pct' => 100];
        }

        return [
            'p'   => self::effectivePolicy(
                $monitoredDomain,
                (string) $row['policy_domain'],
                (string) $row['policy_p'],
                (string) $row['policy_sp']
            ),
            'pct' => (int) $row['policy_pct'],
        ];
    }

    /**
     * The policy disposition that actually governs the monitored domain. When a
     * report's published policy domain is an organisational parent of the
     * monitored subdomain, RFC 7489 applies the subdomain policy (sp) to that
     * subdomain, falling back to the domain policy (p) when sp is absent. A
     * report published at the monitored domain itself (or any domain that is not
     * a parent of it) is governed by p.
     */
    private static function effectivePolicy(
        string $monitoredDomain,
        string $policyDomain,
        string $p,
        string $sp
    ): string {
        $monitored = strtolower(trim($monitoredDomain));
        $published = strtolower(trim($policyDomain));
        $sp        = trim($sp);

        if (
            $sp !== ''
            && $published !== ''
            && $monitored !== ''
            && $published !== $monitored
            && str_ends_with($monitored, '.' . $published)
        ) {
            return $sp;
        }

        return $p;
    }

    /**
     * @return array<string, mixed>
     */
    private function domainRow(int $domainId): array
    {
        $query = $this->query()
            ->select('*')
            ->from($this->db->quoteName('#__dmarc_domains'))
            ->where($this->db->quoteName('id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER);

        return $this->db->setQuery($query)->loadAssoc() ?: [];
    }

    /**
     * The newest report end timestamp for every supplied domain, keyed by
     * domain id, resolved in a single grouped query. Domains without any report
     * are absent from the result (the caller treats a missing anchor as 0).
     *
     * @param   array<int, int>  $domainIds
     *
     * @return  array<int, int>
     */
    private function latestReportEndAll(array $domainIds): array
    {
        if ($domainIds === []) {
            return [];
        }

        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('domain_id'),
                    'COALESCE(MAX(' . $this->db->quoteName('date_end') . '), 0) AS anchor',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('domain_id') . ' IN (' . $this->intList($domainIds) . ')')
            ->group($this->db->quoteName('domain_id'));

        $map = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $map[(int) $row['domain_id']] = (int) $row['anchor'];
        }

        return $map;
    }

    /**
     * The latest effective policy disposition and percentage for every supplied
     * domain, keyed by domain id, resolved in a single groupwise-maximum query
     * (newest by date_end, ties broken by the highest id). Portable across MySQL
     * and PostgreSQL: it joins each report to the per-domain maximum date_end and
     * resolves id ties in PHP rather than using a window function. The monitored
     * domain name is joined in so the subdomain policy (sp) can be resolved the
     * same way as the single-domain latestPolicy() path.
     *
     * @param   array<int, int>  $domainIds
     *
     * @return  array<int, array{p: string, pct: int}>
     */
    private function latestPolicyAll(array $domainIds): array
    {
        if ($domainIds === []) {
            return [];
        }

        $in = $this->intList($domainIds);

        $inner = (string) $this->query()
            ->select(
                [
                    $this->db->quoteName('domain_id'),
                    'MAX(' . $this->db->quoteName('date_end') . ') AS max_end',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('domain_id') . ' IN (' . $in . ')')
            ->group($this->db->quoteName('domain_id'));

        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('r.domain_id'),
                    $this->db->quoteName('r.id'),
                    $this->db->quoteName('r.policy_p'),
                    $this->db->quoteName('r.policy_pct'),
                    $this->db->quoteName('r.policy_sp'),
                    $this->db->quoteName('r.policy_domain'),
                    $this->db->quoteName('d.domain', 'monitored_domain'),
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports', 'r'))
            ->join(
                'INNER',
                '(' . $inner . ') AS ' . $this->db->quoteName('m'),
                $this->db->quoteName('m.domain_id') . ' = ' . $this->db->quoteName('r.domain_id')
                    . ' AND ' . $this->db->quoteName('r.date_end') . ' = ' . $this->db->quoteName('m.max_end')
            )
            ->join(
                'INNER',
                $this->db->quoteName('#__dmarc_domains', 'd'),
                $this->db->quoteName('d.id') . ' = ' . $this->db->quoteName('r.domain_id')
            )
            ->where($this->db->quoteName('r.domain_id') . ' IN (' . $in . ')')
            ->order($this->db->quoteName('r.domain_id') . ' ASC, ' . $this->db->quoteName('r.id') . ' ASC');

        $map = [];

        // Rows arrive ordered by id ascending within each domain, so the last
        // row written for a domain is the highest id sharing the newest end
        // date, mirroring the per-domain "date_end DESC, id DESC" tiebreak.
        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $map[(int) $row['domain_id']] = [
                'p'   => self::effectivePolicy(
                    (string) $row['monitored_domain'],
                    (string) $row['policy_domain'],
                    (string) $row['policy_p'],
                    (string) $row['policy_sp']
                ),
                'pct' => (int) $row['policy_pct'],
            ];
        }

        return $map;
    }

    /**
     * The flagged sources of every supplied domain's CURRENT reporting window,
     * keyed by domain id, resolved in a single grouped query and ranked so the
     * query may return every flagged source or apply an optional top $limit per
     * domain. Mirrors flaggedSources() for a single domain, but anchors each
     * domain's window on its own newest
     * report through a derived per-domain anchor table so one query serves all
     * domains at once.
     *
     * @param   array<int, int>  $domainIds
     *
     * @return  array<int, array<int, array<string, mixed>>>
     */
    private function flaggedSourcesAll(array $domainIds, int $limit = self::DASHBOARD_FLAGGED_SOURCE_LIMIT): array
    {
        if ($domainIds === []) {
            return [];
        }

        $in     = $this->intList($domainIds);
        $window = 7 * 86400;

        // Anchor each domain's current window on its own newest report end, so
        // one query serves every domain at once.
        $anchor = (string) $this->query()
            ->select(
                [
                    $this->db->quoteName('domain_id'),
                    'MAX(' . $this->db->quoteName('date_end') . ') AS anchor',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('domain_id') . ' IN (' . $in . ')')
            ->group($this->db->quoteName('domain_id'));

        $conditions = $this->figureConditions('cur');
        $select     = [
            $this->db->quoteName('rep.domain_id', 'domain_id'),
            $this->db->quoteName('cur.source_ip', 'source_ip'),
        ];

        foreach ($conditions as $name => $condition) {
            $select[] = $this->sumWhen($condition, 'cur') . ' AS ' . $name;
        }

        $select[] = 'MAX(' . $this->db->quoteName('rep.date_end') . ') AS last_seen';
        $select[] = 'MAX(' . $this->db->quoteName('a.anchor') . ') AS evidence_window_end';
        $select[] = 'MAX(' . $this->db->quoteName('rep.policy_domain') . ') AS policy_domain';
        $select[] = 'MAX(' . $this->db->quoteName('rep.policy_adkim') . ') AS policy_adkim';
        $select[] = 'MAX(' . $this->db->quoteName('rep.policy_aspf') . ') AS policy_aspf';
        $select[] = 'MAX(' . $this->db->quoteName('cur.header_from') . ') AS header_from';
        $select[] = $this->policyContextMixedSelect('rep', 'cur');
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('cur.dkim_result') . ' = ' . $this->db->quote('pass')
            . ' AND ' . $this->db->quoteName('cur.dkim_aligned') . ' = 1 THEN ' . $this->db->quoteName('cur.count') . ' ELSE 0 END), 0) AS dkim_pass_aligned';
        $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('cur.spf_result') . ' = ' . $this->db->quote('pass')
            . ' AND ' . $this->db->quoteName('cur.spf_aligned') . ' = 1 THEN ' . $this->db->quoteName('cur.count') . ' ELSE 0 END), 0) AS spf_pass_aligned';
        foreach (['none', 'quarantine', 'reject'] as $disposition) {
            $select[] = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('cur.disposition') . ' = ' . $this->db->quote($disposition)
                . ' THEN ' . $this->db->quoteName('cur.count') . ' ELSE 0 END), 0) AS disposition_' . $disposition;
        }

        // Collapse the CURRENT reporting window (reports whose end falls in the
        // seven days up to the newest report, matching windowTotalsAll) to one
        // row per (domain, source_ip), keeping only the flagged sources.
        $grouped = (string) $this->query()
            ->select($select)
            ->from($this->db->quoteName('#__dmarc_reports', 'rep'))
            ->join(
                'INNER',
                $this->db->quoteName('#__dmarc_records', 'cur'),
                $this->db->quoteName('cur.report_id') . ' = ' . $this->db->quoteName('rep.id')
            )
            ->join(
                'INNER',
                '(' . $anchor . ') AS ' . $this->db->quoteName('a'),
                $this->db->quoteName('a.domain_id') . ' = ' . $this->db->quoteName('rep.domain_id')
            )
            ->where($this->db->quoteName('rep.domain_id') . ' IN (' . $in . ')')
            ->where($this->db->quoteName('rep.date_end') . ' > (' . $this->db->quoteName('a.anchor') . ' - ' . $window . ')')
            ->where($this->db->quoteName('rep.date_end') . ' <= ' . $this->db->quoteName('a.anchor'))
            ->group($this->db->quoteName('rep.domain_id') . ', ' . $this->db->quoteName('cur.source_ip'))
            ->having($this->flaggedHaving('cur'));

        $columns = array_merge(
            ['domain_id', 'source_ip'],
            array_keys($conditions),
            [
                'last_seen', 'evidence_window_end', 'policy_domain', 'policy_adkim', 'policy_aspf', 'header_from',
                'policy_context_mixed', 'dkim_pass_aligned', 'spf_pass_aligned',
                'disposition_none', 'disposition_quarantine', 'disposition_reject',
            ]
        );
        $quoted  = array_map(fn (string $column): string => $this->db->quoteName($column), $columns);

        // Optional callers can rank and limit each domain. The normal dashboard
        // path uses no limit, so every SPF-only and delivered-failing source is
        // returned. The source_ip tiebreak makes rankings deterministic.
        if ($limit > 0) {
            $ranked = (string) $this->query()
                ->select(
                    array_merge(
                        $quoted,
                        [
                            'ROW_NUMBER() OVER (PARTITION BY ' . $this->db->quoteName('domain_id')
                                . ' ORDER BY ' . $this->db->quoteName('spf_only') . ' DESC, '
                                . $this->db->quoteName('fail_delivered') . ' DESC, '
                                . $this->db->quoteName('source_ip') . ' ASC) AS rn',
                        ]
                    )
                )
                ->from('(' . $grouped . ') AS ' . $this->db->quoteName('g'));

            $query = $this->query()
                ->select($quoted)
                ->from('(' . $ranked . ') AS ' . $this->db->quoteName('r'))
                ->where($this->db->quoteName('rn') . ' <= ' . (int) $limit)
                ->order($this->db->quoteName('domain_id') . ' ASC, ' . $this->db->quoteName('rn') . ' ASC');
        } else {
            $query = $this->query()
                ->select($quoted)
                ->from('(' . $grouped . ') AS ' . $this->db->quoteName('g'))
                ->order(
                    $this->db->quoteName('domain_id') . ' ASC, '
                    . $this->db->quoteName('spf_only') . ' DESC, '
                    . $this->db->quoteName('fail_delivered') . ' DESC, '
                    . $this->db->quoteName('source_ip') . ' ASC'
                );
        }

        $map = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $flagged = self::flaggedRow($row);
            $end = (int) ($row['evidence_window_end'] ?? 0);
            $flagged['evidence_total'] = (int) $flagged['total'];
            $flagged['evidence_window_fallback'] = false;
            $flagged['evidence_window_start'] = $end > 0 ? $end - (7 * 86400) : 0;
            $flagged['evidence_window_end'] = $end;
            $map[(int) $row['domain_id']][] = $flagged;
        }

        return $map;
    }

    /**
     * Window volume, DMARC-passing volume and would-be-rejected volume for every
     * supplied domain, keyed by domain id, over the half-open interval
     * (anchor - $offsetStart, anchor - $offsetEnd] measured on each report's end
     * date, where the anchor is each domain's own newest report end date. One
     * grouped query joined to a derived per-domain anchor table serves all
     * domains, replacing the former per-domain windowTotals() calls. Bucketing on
     * date_end matches the anchor, so the newest report always falls inside the
     * current window even when it spans more than one window's length. Domains
     * with no reports are absent from the result.
     *
     * @param   array<int, int>  $domainIds
     *
     * @return  array<int, array{total: int, pass: int, reject_under_p: int}>
     */
    private function windowTotalsAll(array $domainIds, int $offsetStart, int $offsetEnd): array
    {
        if ($domainIds === []) {
            return [];
        }

        $in = $this->intList($domainIds);

        $anchor = (string) $this->query()
            ->select(
                [
                    $this->db->quoteName('domain_id'),
                    'MAX(' . $this->db->quoteName('date_end') . ') AS anchor',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('domain_id') . ' IN (' . $in . ')')
            ->group($this->db->quoteName('domain_id'));

        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('rep.domain_id'),
                    'COALESCE(SUM(' . $this->db->quoteName('rec.count') . '), 0) AS total',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.dkim_aligned') . ' = 1 OR '
                        . $this->db->quoteName('rec.spf_aligned') . ' = 1 THEN ' . $this->db->quoteName('rec.count')
                        . ' ELSE 0 END), 0) AS pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('rec.dkim_aligned') . ' = 0 AND '
                        . $this->db->quoteName('rec.spf_aligned') . ' = 0 THEN ' . $this->db->quoteName('rec.count')
                        . ' ELSE 0 END), 0) AS reject_under_p',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports', 'rep'))
            ->join('INNER', $this->db->quoteName('#__dmarc_records', 'rec'),
                $this->db->quoteName('rec.report_id') . ' = ' . $this->db->quoteName('rep.id'))
            ->join(
                'INNER',
                '(' . $anchor . ') AS ' . $this->db->quoteName('a'),
                $this->db->quoteName('a.domain_id') . ' = ' . $this->db->quoteName('rep.domain_id')
            )
            ->where($this->db->quoteName('rep.domain_id') . ' IN (' . $in . ')')
            ->where($this->db->quoteName('rep.date_end') . ' > (' . $this->db->quoteName('a.anchor') . ' - ' . $offsetStart . ')')
            ->where($this->db->quoteName('rep.date_end') . ' <= (' . $this->db->quoteName('a.anchor') . ' - ' . $offsetEnd . ')')
            ->group($this->db->quoteName('rep.domain_id'));

        $map = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $map[(int) $row['domain_id']] = [
                'total'          => (int) $row['total'],
                'pass'           => (int) $row['pass'],
                'reject_under_p' => (int) $row['reject_under_p'],
            ];
        }

        return $map;
    }

    /**
     * A comma-separated list of distinct positive domain ids for safe inlining in
     * grouped and correlated-subquery SQL. The values are cast to integers, so
     * the result cannot carry injection; inlining avoids the bound-parameter name
     * collisions that arise when embedding builder subqueries into an outer query.
     *
     * @param   array<int, int>  $domainIds
     */
    private function intList(array $domainIds): string
    {
        $clean = array_values(array_unique(array_filter(
            array_map('intval', $domainIds),
            static fn (int $id): bool => $id > 0
        )));

        return $clean === [] ? '0' : implode(',', $clean);
    }

    private static function rate(int $pass, int $total): float
    {
        return $total > 0 ? round($pass / $total * 100, 1) : 0.0;
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
