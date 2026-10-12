<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * Turns the aggregated facts about a domain into a prioritised, actionable list
 * of recommendations: what the administrator should do next, why it is safe (or
 * not yet safe) and in which order. The engine is pure: it takes already
 * computed metrics plus the component thresholds and returns machine readable
 * action items. Localised wording is applied by the view, and persisted status
 * (done / snoozed / ignored) is merged by the model, so nothing here depends on
 * Joomla and the whole decision logic is unit testable.
 *
 * Severity scale: 3 = act now, 2 = plan soon, 1 = review, 0 = informational.
 *
 * @since  1.0.0
 */
final class ActionCenter
{
    /**
     * Build the recommendation list for one domain.
     *
     * @param   array<string, mixed>  $metrics       Aggregated per-domain metrics.
     * @param   array<string, mixed>  $config        Component thresholds.
     * @param   array<int, array<string, mixed>>  $dnsFindings  Findings from DnsDiagnostics.
     *
     * @return  array<int, array<string, mixed>>  Items sorted by priority, highest first.
     */
    public static function generate(array $metrics, array $config, array $dnsFindings = []): array
    {
        $quarantineThreshold = (float) ($config['quarantine_pass_threshold'] ?? 95);
        $rejectThreshold     = (float) ($config['reject_pass_threshold'] ?? 99);

        $domainId = (int) ($metrics['domain_id'] ?? 0);
        $domain   = (string) ($metrics['domain'] ?? '');
        $total    = (int) ($metrics['total'] ?? 0);
        $policy   = strtolower((string) ($metrics['current_policy'] ?? ''));
        $passRate = $total > 0 ? round(($metrics['dmarc_pass'] ?? 0) / $total * 100, 1) : 0.0;

        // The configured enforcement target caps the rollout: a domain deliberately
        // kept at p=none or p=quarantine must not be nudged past its chosen target.
        $rank       = ['none' => 0, 'quarantine' => 1, 'reject' => 2];
        $target     = strtolower((string) ($metrics['expected_policy'] ?? 'reject'));
        $targetRank = $rank[$target] ?? 2;
        $policyRank = $rank[$policy === '' ? 'none' : $policy] ?? 0;

        $items = [];

        if ($total === 0) {
            $items[] = self::item('no_data', 'data', 1, $domainId, [$domain]);

            return self::finalise($items, $domainId, $dnsFindings);
        }

        // Messages that would be rejected under p=reject: the still-failing
        // senders. Advancement must not be presented as "safe" while these
        // remain. The rollout decision reflects the CURRENT reporting window,
        // not the accumulated lifetime: a single historical failure keeps the
        // lifetime count above zero forever and would permanently block the
        // next policy step. When a rolling window is available its volume, pass
        // rate and would-be-rejected count are used; otherwise (sparse or
        // legacy data without a window) the lifetime totals are the fallback.
        $windowTotal = (int) ($metrics['window_total'] ?? 0);
        $hasWindow   = $windowTotal > 0 && ($metrics['pass_rate_window'] ?? null) !== null;

        $rolloutTotal    = $hasWindow ? $windowTotal : $total;
        $rolloutPassRate = $hasWindow ? (float) $metrics['pass_rate_window'] : $passRate;
        $rejectUnderP    = $hasWindow
            ? (int) ($metrics['window_reject_under_p'] ?? 0)
            : (int) ($metrics['reject_under_p'] ?? 0);

        // Policy rollout guidance along none -> quarantine -> reject, stopping at
        // the configured target so the Action Center never over-recommends.
        $policyPct = (int) ($metrics['current_policy_pct'] ?? 100);

        if ($policy === 'reject') {
            if ($policyPct >= 100) {
                $items[] = self::item('enforced', 'policy', 0, $domainId, [$domain]);
            } else {
                // p=reject with pct<100 only subjects that share of mail to the
                // policy; the rest falls through to quarantine, so this is not
                // full enforcement and must not be reported as "done".
                $items[] = self::item('partial_enforcement', 'policy', 2, $domainId, [$domain, $policyPct]);
            }
        } elseif ($policy === 'quarantine' && $policyPct < 100) {
            // A quarantine policy applied to only part of the mail stream has not
            // been fully exercised. Raise pct to 100 before advancing to reject
            // or treating quarantine as the reached target, whatever the target.
            $items[] = self::item('partial_quarantine', 'policy', 2, $domainId, [$domain, $policyPct]);
        } elseif ($policyRank >= $targetRank) {
            $items[] = self::item('at_target', 'policy', 0, $domainId, [$domain, $target]);
        } elseif ($policy === '' || $policy === 'none') {
            if ($rolloutPassRate < $quarantineThreshold) {
                $items[] = self::item('stay_at_none', 'policy', 2, $domainId, [$domain, $rolloutPassRate, $quarantineThreshold]);
            } elseif ($rejectUnderP > 0) {
                // Threshold met, but legitimate-looking mail still fails: resolve
                // those senders before tightening instead of calling it safe.
                $items[] = self::item('resolve_before_quarantine', 'policy', 2, $domainId, [$domain, $rolloutPassRate, $rejectUnderP]);
            } else {
                $items[] = self::item('move_to_quarantine', 'policy', 3, $domainId, [$domain, $rolloutPassRate]);
            }
        } elseif ($policy === 'quarantine') {
            if ($rolloutPassRate < $rejectThreshold) {
                $items[] = self::item('stay_at_quarantine', 'policy', 2, $domainId, [$domain, $rolloutPassRate, $rejectThreshold]);
            } elseif ($rejectUnderP > 0) {
                $items[] = self::item('resolve_before_reject', 'policy', 2, $domainId, [$domain, $rolloutPassRate, $rejectUnderP]);
            } else {
                $items[] = self::item('move_to_reject', 'policy', 3, $domainId, [$domain, $rolloutPassRate]);
            }
        }

        // The same still-failing senders are also surfaced as an explicit
        // tightening risk so the admin sees the volume at stake, measured over
        // the same current window as the rollout decision above.
        if ($rejectUnderP > 0 && $policy !== 'reject') {
            $share    = $rolloutTotal > 0 ? round($rejectUnderP / $rolloutTotal * 100, 1) : 0.0;
            $severity = $share >= 2 ? 3 : 2;
            $items[]  = self::item('would_be_rejected', 'policy', $severity, $domainId, [$rejectUnderP, $share]);
        }

        // Unaligned senders with meaningful volume: fix authentication first.
        foreach ((array) ($metrics['unaligned_sources'] ?? []) as $source) {
            $ip    = (string) ($source['ip'] ?? '');
            $count = (int) ($source['count'] ?? 0);

            if ($ip === '' || $count <= 0) {
                continue;
            }

            $share    = round($count / $total * 100, 1);
            $severity = $share >= 5 ? 3 : ($share >= 1 ? 2 : 1);
            $items[]  = self::item('align_source', 'alignment', $severity, $domainId, [$ip, $count, $share], $ip);
        }

        // Newly observed sending sources need a human decision.
        foreach ((array) ($metrics['new_sources'] ?? []) as $source) {
            $ip    = (string) ($source['ip'] ?? '');
            $count = (int) ($source['count'] ?? 0);

            if ($ip === '') {
                continue;
            }

            $items[] = self::item('new_source', 'source', 2, $domainId, [$ip, $count], $ip);
        }

        // A drop in the pass rate of the latest reporting window against the one
        // immediately before it (window-to-window, not against all history).
        $previous = $metrics['pass_rate_prev'] ?? null;
        $window   = $metrics['pass_rate_window'] ?? null;
        $current  = $window !== null ? (float) $window : $passRate;

        if ($previous !== null && $current < ((float) $previous - 5)) {
            $items[] = self::item('pass_rate_drop', 'alignment', 3, $domainId, [(float) $previous, $current]);
        }

        return self::finalise($items, $domainId, $dnsFindings);
    }

    /**
     * @param   array<int, array<string, mixed>>  $items
     * @param   array<int, array<string, mixed>>  $dnsFindings
     *
     * @return  array<int, array<string, mixed>>
     */
    private static function finalise(array $items, int $domainId, array $dnsFindings): array
    {
        foreach ($dnsFindings as $finding) {
            $code     = (string) ($finding['code'] ?? '');
            $severity = (int) ($finding['severity'] ?? 1);
            $params   = (array) ($finding['params'] ?? []);

            if ($code === '') {
                continue;
            }

            $items[] = self::item($code, 'dns', $severity, $domainId, $params);
        }

        usort(
            $items,
            static fn (array $a, array $b): int => ($b['severity'] <=> $a['severity'])
                ?: strcmp((string) $a['action_key'], (string) $b['action_key'])
        );

        return $items;
    }

    /**
     * @param   array<int, mixed>  $params
     *
     * @return  array<string, mixed>
     */
    private static function item(
        string $code,
        string $category,
        int $severity,
        int $domainId,
        array $params = [],
        string $suffix = ''
    ): array {
        $key = $code . ':' . $domainId . ($suffix !== '' ? ':' . $suffix : '');

        return [
            'action_key' => $key,
            'code'       => $code,
            'category'   => $category,
            'severity'   => $severity,
            'domain_id'  => $domainId,
            'params'     => array_values($params),
        ];
    }
}
