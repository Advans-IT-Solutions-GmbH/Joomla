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
 * rates, the share that would be rejected under p=reject, top sending IPs,
 * unaligned senders, newly seen sources and a daily timeline.
 *
 * @since  1.0.0
 */
final class Metrics
{
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

        $latestPolicy = $this->latestPolicy($domainId);

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
            'top_sources'       => $this->topSources($domainId),
            'unaligned_sources' => $this->unalignedSources($domainId),
            'new_sources'       => $this->newSources($domainId),
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
        $anchor = $this->latestReportEnd($domainId);
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

        $window     = 7 * 86400;
        $anchors    = $this->latestReportEndAll($ids);
        $unaligned  = $this->unalignedSourcesAll($ids);
        $newSources = $this->newSourcesAll($ids);
        $current    = $this->windowTotalsAll($ids, $window, 0);
        $previous   = $this->windowTotalsAll($ids, 2 * $window, $window);

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
                'unaligned_sources'  => $unaligned[$domainId] ?? [],
                'new_sources'        => $anchor > 0 ? ($newSources[$domainId] ?? []) : [],
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
     * @return array<int, array<string, mixed>>
     */
    private function topSources(int $domainId, int $limit = 20): array
    {
        $count = $this->db->quoteName('count');

        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('source_ip'),
                    'COALESCE(SUM(' . $count . '), 0) AS total',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('dkim_aligned') . ' = 1 OR '
                        . $this->db->quoteName('spf_aligned') . ' = 1 THEN ' . $count . ' ELSE 0 END), 0) AS pass',
                    'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('dkim_aligned') . ' = 0 AND '
                        . $this->db->quoteName('spf_aligned') . ' = 0 THEN ' . $count . ' ELSE 0 END), 0) AS fail',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_records'))
            ->where($this->db->quoteName('domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->group($this->db->quoteName('source_ip'))
            ->order('total DESC');

        $query->setLimit($limit);
        $rows = $this->db->setQuery($query)->loadAssocList() ?: [];

        foreach ($rows as &$row) {
            $row['total']   = (int) $row['total'];
            $row['pass']    = (int) $row['pass'];
            $row['fail']    = (int) $row['fail'];
            $row['aligned'] = $row['fail'] === 0 ? 1 : 0;
        }

        return $rows;
    }

    /**
     * Sources with mail that fails DMARC alignment, ranked by failing volume.
     * This is queried independently of the display-oriented top-source table so
     * a heavy failing sender ranked below the top N still reaches the Action
     * Center and can earn an alignment recommendation.
     *
     * @return array<int, array<string, mixed>>
     */
    private function unalignedSources(int $domainId, int $limit = 20): array
    {
        $count = $this->db->quoteName('count');
        $fail  = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('dkim_aligned') . ' = 0 AND '
            . $this->db->quoteName('spf_aligned') . ' = 0 THEN ' . $count . ' ELSE 0 END), 0)';

        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('source_ip'),
                    $fail . ' AS fail',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_records'))
            ->where($this->db->quoteName('domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->group($this->db->quoteName('source_ip'))
            ->having($fail . ' > 0')
            ->order('fail DESC');

        $query->setLimit($limit);
        $rows = $this->db->setQuery($query)->loadAssocList() ?: [];

        return array_map(
            static fn (array $r): array => ['ip' => $r['source_ip'], 'count' => (int) $r['fail']],
            $rows
        );
    }

    /**
     * Sources that appear in the current reporting window but never in any
     * earlier report. A domain commonly receives several overlapping reports
     * from different organisations for the same window, so detection spans every
     * report whose range begins inside the window rather than the single newest
     * report id; otherwise a source first seen in a contemporaneous report from
     * another reporter would be missed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function newSources(int $domainId): array
    {
        $anchor = $this->latestReportEnd($domainId);

        if ($anchor === 0) {
            return [];
        }

        $windowStart = $anchor - (7 * 86400);

        $prior = (string) $this->query()
            ->select('1')
            ->from($this->db->quoteName('#__dmarc_reports', 'prep'))
            ->join(
                'INNER',
                $this->db->quoteName('#__dmarc_records', 'prec'),
                $this->db->quoteName('prec.report_id') . ' = ' . $this->db->quoteName('prep.id')
            )
            ->where($this->db->quoteName('prep.domain_id') . ' = :domainIdPrev')
            ->where($this->db->quoteName('prec.source_ip') . ' = ' . $this->db->quoteName('cur.source_ip'))
            ->where($this->db->quoteName('prep.date_begin') . ' <= :windowStartPrev');

        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('cur.source_ip'),
                    'COALESCE(SUM(' . $this->db->quoteName('cur.count') . '), 0) AS total',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports', 'rep'))
            ->join(
                'INNER',
                $this->db->quoteName('#__dmarc_records', 'cur'),
                $this->db->quoteName('cur.report_id') . ' = ' . $this->db->quoteName('rep.id')
            )
            ->where($this->db->quoteName('rep.domain_id') . ' = :domainId')
            ->where($this->db->quoteName('rep.date_begin') . ' > :windowStart')
            ->where($this->db->quoteName('rep.date_begin') . ' <= :anchor')
            ->where('NOT EXISTS (' . $prior . ')')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->bind(':domainIdPrev', $domainId, ParameterType::INTEGER)
            ->bind(':windowStart', $windowStart, ParameterType::INTEGER)
            ->bind(':windowStartPrev', $windowStart, ParameterType::INTEGER)
            ->bind(':anchor', $anchor, ParameterType::INTEGER)
            ->group($this->db->quoteName('cur.source_ip'))
            ->order('total DESC');

        $query->setLimit(10);
        $rows = $this->db->setQuery($query)->loadAssocList() ?: [];

        return array_map(
            static fn (array $r): array => ['ip' => $r['source_ip'], 'count' => (int) $r['total']],
            $rows
        );
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
     * The policy disposition and percentage from the most recent stored report,
     * ordered by end date then id. The percentage matters for the rollout
     * guidance: p=reject with pct<100 only subjects that share of mail to the
     * policy, so it must not be presented as full enforcement.
     *
     * @return  array{p: string, pct: int}
     */
    private function latestPolicy(int $domainId): array
    {
        $query = $this->query()
            ->select([$this->db->quoteName('policy_p'), $this->db->quoteName('policy_pct')])
            ->from($this->db->quoteName('#__dmarc_reports'))
            ->where($this->db->quoteName('domain_id') . ' = :domainId')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->order($this->db->quoteName('date_end') . ' DESC, ' . $this->db->quoteName('id') . ' DESC');

        $query->setLimit(1);

        $row = $this->db->setQuery($query)->loadAssoc();

        if (!$row) {
            return ['p' => '', 'pct' => 100];
        }

        return ['p' => (string) $row['policy_p'], 'pct' => (int) $row['policy_pct']];
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
     * The latest published policy disposition and percentage for every supplied
     * domain, keyed by domain id, resolved in a single groupwise-maximum query
     * (newest by date_end, ties broken by the highest id). Portable across MySQL
     * and PostgreSQL: it joins each report to the per-domain maximum date_end and
     * resolves id ties in PHP rather than using a window function.
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
                ]
            )
            ->from($this->db->quoteName('#__dmarc_reports', 'r'))
            ->join(
                'INNER',
                '(' . $inner . ') AS ' . $this->db->quoteName('m'),
                $this->db->quoteName('m.domain_id') . ' = ' . $this->db->quoteName('r.domain_id')
                    . ' AND ' . $this->db->quoteName('r.date_end') . ' = ' . $this->db->quoteName('m.max_end')
            )
            ->where($this->db->quoteName('r.domain_id') . ' IN (' . $in . ')')
            ->order($this->db->quoteName('r.domain_id') . ' ASC, ' . $this->db->quoteName('r.id') . ' ASC');

        $map = [];

        // Rows arrive ordered by id ascending within each domain, so the last
        // row written for a domain is the highest id sharing the newest end
        // date, mirroring the per-domain "date_end DESC, id DESC" tiebreak.
        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $map[(int) $row['domain_id']] = [
                'p'   => (string) $row['policy_p'],
                'pct' => (int) $row['policy_pct'],
            ];
        }

        return $map;
    }

    /**
     * Unaligned sending sources for every supplied domain, keyed by domain id,
     * resolved in a single grouped query and sliced to the top $limit per domain
     * (ordered by failing volume) in PHP.
     *
     * @param   array<int, int>  $domainIds
     *
     * @return  array<int, array<int, array{ip: string, count: int}>>
     */
    private function unalignedSourcesAll(array $domainIds, int $limit = 20): array
    {
        if ($domainIds === []) {
            return [];
        }

        $count = $this->db->quoteName('count');
        $fail  = 'COALESCE(SUM(CASE WHEN ' . $this->db->quoteName('dkim_aligned') . ' = 0 AND '
            . $this->db->quoteName('spf_aligned') . ' = 0 THEN ' . $count . ' ELSE 0 END), 0)';

        // Collapse the full history to one failing-volume total per
        // (domain, source_ip), keeping only groups that actually failed
        // alignment.
        $grouped = (string) $this->query()
            ->select(
                [
                    $this->db->quoteName('domain_id'),
                    $this->db->quoteName('source_ip'),
                    $fail . ' AS fail',
                ]
            )
            ->from($this->db->quoteName('#__dmarc_records'))
            ->where($this->db->quoteName('domain_id') . ' IN (' . $this->intList($domainIds) . ')')
            ->group($this->db->quoteName('domain_id') . ', ' . $this->db->quoteName('source_ip'))
            ->having($fail . ' > 0');

        // Rank each domain's failing sources by volume so the outer query keeps
        // only the top $limit per domain. Ranking and slicing in the database
        // bounds both the result transfer and PHP memory to $limit rows per
        // domain, instead of materialising every distinct source ever seen; the
        // source_ip tiebreak makes the ranking deterministic for equal volumes.
        $ranked = (string) $this->query()
            ->select(
                [
                    $this->db->quoteName('domain_id'),
                    $this->db->quoteName('source_ip'),
                    $this->db->quoteName('fail'),
                    'ROW_NUMBER() OVER (PARTITION BY ' . $this->db->quoteName('domain_id')
                        . ' ORDER BY ' . $this->db->quoteName('fail') . ' DESC, '
                        . $this->db->quoteName('source_ip') . ' ASC) AS rn',
                ]
            )
            ->from('(' . $grouped . ') AS ' . $this->db->quoteName('g'));

        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('domain_id'),
                    $this->db->quoteName('source_ip'),
                    $this->db->quoteName('fail'),
                ]
            )
            ->from('(' . $ranked . ') AS ' . $this->db->quoteName('r'))
            ->where($this->db->quoteName('rn') . ' <= ' . (int) max(1, $limit))
            ->order($this->db->quoteName('domain_id') . ' ASC, ' . $this->db->quoteName('fail') . ' DESC');

        $map = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $domainId = (int) $row['domain_id'];

            $map[$domainId][] = ['ip' => $row['source_ip'], 'count' => (int) $row['fail']];
        }

        return $map;
    }

    /**
     * Newly observed sending sources for every supplied domain, keyed by domain
     * id, resolved in a single grouped query and sliced to the top $limit per
     * domain in PHP. Mirrors newSources() for a single domain, but anchors each
     * domain's current window on its own newest report through a derived
     * per-domain anchor table so one query serves all domains at once.
     *
     * @param   array<int, int>  $domainIds
     *
     * @return  array<int, array<int, array{ip: string, count: int}>>
     */
    private function newSourcesAll(array $domainIds, int $limit = 10): array
    {
        if ($domainIds === []) {
            return [];
        }

        $in     = $this->intList($domainIds);
        $window = 7 * 86400;

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

        $prior = (string) $this->query()
            ->select('1')
            ->from($this->db->quoteName('#__dmarc_reports', 'prep'))
            ->join(
                'INNER',
                $this->db->quoteName('#__dmarc_records', 'prec'),
                $this->db->quoteName('prec.report_id') . ' = ' . $this->db->quoteName('prep.id')
            )
            ->where($this->db->quoteName('prep.domain_id') . ' = ' . $this->db->quoteName('rep.domain_id'))
            ->where($this->db->quoteName('prec.source_ip') . ' = ' . $this->db->quoteName('cur.source_ip'))
            ->where($this->db->quoteName('prep.date_begin') . ' <= (' . $this->db->quoteName('a.anchor') . ' - ' . $window . ')');

        $query = $this->query()
            ->select(
                [
                    $this->db->quoteName('rep.domain_id'),
                    $this->db->quoteName('cur.source_ip'),
                    'COALESCE(SUM(' . $this->db->quoteName('cur.count') . '), 0) AS total',
                ]
            )
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
            ->where($this->db->quoteName('rep.date_begin') . ' > (' . $this->db->quoteName('a.anchor') . ' - ' . $window . ')')
            ->where($this->db->quoteName('rep.date_begin') . ' <= ' . $this->db->quoteName('a.anchor'))
            ->where('NOT EXISTS (' . $prior . ')')
            ->group($this->db->quoteName('rep.domain_id') . ', ' . $this->db->quoteName('cur.source_ip'))
            ->order($this->db->quoteName('rep.domain_id') . ' ASC, total DESC');

        $map = [];

        foreach ($this->db->setQuery($query)->loadAssocList() ?: [] as $row) {
            $domainId = (int) $row['domain_id'];

            if (\count($map[$domainId] ?? []) >= $limit) {
                continue;
            }

            $map[$domainId][] = ['ip' => $row['source_ip'], 'count' => (int) $row['total']];
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
