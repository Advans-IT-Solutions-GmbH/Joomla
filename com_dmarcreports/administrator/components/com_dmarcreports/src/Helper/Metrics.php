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

        foreach ($rows as &$row) {
            $row                       = array_map(static fn ($v) => is_numeric($v) ? $v + 0 : $v, $row);
            $latest                    = $this->latestPolicy((int) $row['id']);
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

        $metrics['pass_rate_window'] = $anchor > 0
            ? $this->windowPassRate($domainId, $anchor - $window, $anchor)
            : null;
        $metrics['pass_rate_prev'] = $anchor > 0
            ? $this->windowPassRate($domainId, $anchor - (2 * $window), $anchor - $window)
            : null;

        return $metrics;
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
        $query = $this->query()
            ->select(
                [
                    'rep.date_begin AS day_ts',
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
            ->group($this->db->quoteName('rep.date_begin'))
            ->order($this->db->quoteName('rep.date_begin') . ' DESC');

        // Take the newest 90 days, then restore chronological order for display.
        $query->setLimit(90);
        $rows = array_reverse($this->db->setQuery($query)->loadAssocList() ?: []);

        return array_map(
            static fn (array $r): array => [
                'day'   => gmdate('Y-m-d', (int) $r['day_ts']),
                'total' => (int) $r['total'],
                'pass'  => (int) $r['pass'],
            ],
            $rows
        );
    }

    /**
     * Volume-weighted DMARC pass rate across every record whose report window
     * begins in the half-open interval (startTs, endTs], or null when no mail
     * falls in that window. Bucketing by the report's own start time keeps the
     * two compared windows non-overlapping regardless of how many reporters
     * contribute, so the comparison stays like-for-like.
     */
    private function windowPassRate(int $domainId, int $startTs, int $endTs): ?float
    {
        $query = $this->query()
            ->select(
                [
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
            ->where($this->db->quoteName('rep.date_begin') . ' > :startTs')
            ->where($this->db->quoteName('rep.date_begin') . ' <= :endTs')
            ->bind(':domainId', $domainId, ParameterType::INTEGER)
            ->bind(':startTs', $startTs, ParameterType::INTEGER)
            ->bind(':endTs', $endTs, ParameterType::INTEGER);

        $row   = $this->db->setQuery($query)->loadAssoc() ?: [];
        $total = (int) ($row['total'] ?? 0);

        return $total === 0 ? null : self::rate((int) ($row['pass'] ?? 0), $total);
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

    private static function rate(int $pass, int $total): float
    {
        return $total > 0 ? round($pass / $total * 100, 1) : 0.0;
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
