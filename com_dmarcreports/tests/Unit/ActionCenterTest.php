<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\ActionCenter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the recommendation engine driving the action center.
 *
 * @since  1.0.0
 */
class ActionCenterTest extends TestCase
{
    private const CONFIG = [
        'quarantine_pass_threshold' => 98,
        'reject_pass_threshold'     => 99,
    ];

    /**
     * @param   array<int, array<string, mixed>>  $items
     */
    private function codes(array $items): array
    {
        return array_map(static fn (array $i): string => $i['code'], $items);
    }

    private function find(array $items, string $code): ?array
    {
        foreach ($items as $item) {
            if ($item['code'] === $code) {
                return $item;
            }
        }

        return null;
    }

    public function testNoDataProducesSingleInformationalItem(): void
    {
        $items = ActionCenter::generate(
            ['domain_id' => 1, 'domain' => 'example.org', 'total' => 0],
            self::CONFIG
        );

        $this->assertSame(['no_data'], $this->codes($items));
    }

    public function testHighPassRateAtNoneRecommendsQuarantineAsActNow(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'      => 1,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'none',
                'dmarc_pass'     => 990,
            ],
            self::CONFIG
        );

        $item = $this->find($items, 'move_to_quarantine');
        $this->assertNotNull($item);
        $this->assertSame(3, $item['severity']);
        $this->assertSame('move_to_quarantine:1', $item['action_key']);
    }

    public function testLowPassRateAtNoneRecommendsStaying(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'      => 1,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'none',
                'dmarc_pass'     => 800,
            ],
            self::CONFIG
        );

        $this->assertContains('stay_at_none', $this->codes($items));
        $this->assertNotContains('move_to_quarantine', $this->codes($items));
    }

    public function testQuarantineWithHighPassRateRecommendsReject(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'      => 2,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'quarantine',
                'dmarc_pass'     => 995,
            ],
            self::CONFIG
        );

        $item = $this->find($items, 'move_to_reject');
        $this->assertNotNull($item);
        $this->assertSame(3, $item['severity']);
    }

    public function testRejectPolicyIsInformationalOnly(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'      => 3,
                'domain'         => 'example.org',
                'total'          => 500,
                'current_policy' => 'reject',
                'dmarc_pass'     => 500,
            ],
            self::CONFIG
        );

        $item = $this->find($items, 'enforced');
        $this->assertNotNull($item);
        $this->assertSame(0, $item['severity']);
    }

    public function testRejectWithPartialPercentageIsNotFullEnforcement(): void
    {
        // p=reject but pct=10: only a tenth of mail is subject to the policy,
        // so this must surface as plan-soon partial enforcement, never as the
        // informational "Enforcement reached".
        $items = ActionCenter::generate(
            [
                'domain_id'          => 3,
                'domain'             => 'example.org',
                'total'              => 500,
                'current_policy'     => 'reject',
                'current_policy_pct' => 10,
                'dmarc_pass'         => 500,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('partial_enforcement', $codes);
        $this->assertNotContains('enforced', $codes);

        $item = $this->find($items, 'partial_enforcement');
        $this->assertSame(2, $item['severity']);
        $this->assertSame(10, $item['params'][1]);
    }

    public function testMisconfiguredRejectBelowQuarantineIsClampedUp(): void
    {
        // Operator error: the reject bar is configured below the quarantine bar.
        // The engine must raise reject to the quarantine bar so a quarantine
        // domain is not advanced to reject on a pass rate that only clears the
        // lower (invalid) threshold.
        $items = ActionCenter::generate(
            [
                'domain_id'      => 7,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'quarantine',
                'dmarc_pass'     => 950,
            ],
            [
                'quarantine_pass_threshold' => 98,
                'reject_pass_threshold'     => 90,
            ]
        );

        $codes = $this->codes($items);
        $this->assertContains('stay_at_quarantine', $codes);
        $this->assertNotContains('move_to_reject', $codes);

        $item = $this->find($items, 'stay_at_quarantine');
        $this->assertSame(98.0, $item['params'][2]);
    }

    public function testEmptyThresholdsFallBackToSafeDefaults(): void
    {
        // com_config persists an emptied number option as "". A bare (float) cast
        // would turn that into 0 and disable the safety line, so an 80 % pass
        // rate at p=none would be (wrongly) advanced to quarantine. The engine
        // must treat an empty or non-numeric threshold as the declared default
        // (95 / 99) and therefore keep the domain at none.
        $items = ActionCenter::generate(
            [
                'domain_id'      => 9,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'none',
                'dmarc_pass'     => 800,
            ],
            [
                'quarantine_pass_threshold' => '',
                'reject_pass_threshold'     => 'not-a-number',
            ]
        );

        $codes = $this->codes($items);
        $this->assertContains('stay_at_none', $codes);
        $this->assertNotContains('move_to_quarantine', $codes);

        $item = $this->find($items, 'stay_at_none');
        $this->assertSame(95.0, $item['params'][2]);
    }

    public function testOutOfRangeThresholdFallsBackToSafeDefault(): void
    {
        // A legacy or hand-edited param outside the declared 50-100 range must
        // not skew the rollout: a threshold of 0 (e.g. the value a pre-fix
        // caller produced by casting "" / "abc" to float) would wrongly advance
        // an 80 % domain. The engine clamps back to the 95 default and stays.
        $items = ActionCenter::generate(
            [
                'domain_id'      => 10,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'none',
                'dmarc_pass'     => 800,
            ],
            [
                'quarantine_pass_threshold' => 0.0,
                'reject_pass_threshold'     => 150,
            ]
        );

        $codes = $this->codes($items);
        $this->assertContains('stay_at_none', $codes);
        $this->assertNotContains('move_to_quarantine', $codes);

        $item = $this->find($items, 'stay_at_none');
        $this->assertSame(95.0, $item['params'][2]);
    }

    public function testDecimalOrExponentThresholdFallsBackToSafeDefault(): void
    {
        // The rollout thresholds are whole numbers (the form range rule rejects
        // "50.5" / "1e2"). A legacy or crafted save could still have persisted a
        // decimal or exponent string; is_numeric() would accept it, so a 50.5
        // quarantine threshold would wrongly advance an 80 % domain. The engine
        // applies the same plain-integer check and falls back to the 95 default.
        $items = ActionCenter::generate(
            [
                'domain_id'      => 11,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'none',
                'dmarc_pass'     => 800,
            ],
            [
                'quarantine_pass_threshold' => '50.5',
                'reject_pass_threshold'     => '1e2',
            ]
        );

        $codes = $this->codes($items);
        $this->assertContains('stay_at_none', $codes);
        $this->assertNotContains('move_to_quarantine', $codes);

        $item = $this->find($items, 'stay_at_none');
        $this->assertSame(95.0, $item['params'][2]);
    }

    public function testRejectWithFullPercentageIsEnforced(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'          => 3,
                'domain'             => 'example.org',
                'total'              => 500,
                'current_policy'     => 'reject',
                'current_policy_pct' => 100,
                'dmarc_pass'         => 500,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('enforced', $codes);
        $this->assertNotContains('partial_enforcement', $codes);
    }

    public function testPolicyStricterThanTargetIsReportedAtTargetNotPartial(): void
    {
        // Target is quarantine but the domain publishes p=reject at only 10%.
        // The rollout goal (quarantine) is already exceeded, so this must report
        // the target as reached, never demand completing the reject rollout to
        // 100% of a policy stricter than the operator asked for.
        $items = ActionCenter::generate(
            [
                'domain_id'          => 9,
                'domain'             => 'example.org',
                'total'              => 1000,
                'current_policy'     => 'reject',
                'current_policy_pct' => 10,
                'expected_policy'    => 'quarantine',
                'dmarc_pass'         => 999,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('at_target', $codes);
        $this->assertNotContains('partial_enforcement', $codes);

        $item = $this->find($items, 'at_target');
        $this->assertSame(0, $item['severity']);
        $this->assertSame('quarantine', $item['params'][1]);
    }

    public function testQuarantineTargetWithPartialPercentageIsNotAtTarget(): void
    {
        // Target is quarantine and the policy is quarantine, but only 10% of
        // mail is quarantined: this is not the reached target, it needs pct=100.
        $items = ActionCenter::generate(
            [
                'domain_id'          => 6,
                'domain'             => 'example.org',
                'total'              => 1000,
                'current_policy'     => 'quarantine',
                'current_policy_pct' => 10,
                'expected_policy'    => 'quarantine',
                'dmarc_pass'         => 999,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('partial_quarantine', $codes);
        $this->assertNotContains('at_target', $codes);

        $item = $this->find($items, 'partial_quarantine');
        $this->assertSame(2, $item['severity']);
        $this->assertSame(10, $item['params'][1]);
    }

    public function testRejectTargetWithPartialQuarantineDoesNotAdvanceToReject(): void
    {
        // Target is reject and the policy is quarantine at only 25%: quarantine
        // must be exercised on all mail before advancing, so no move_to_reject.
        $items = ActionCenter::generate(
            [
                'domain_id'          => 7,
                'domain'             => 'example.org',
                'total'              => 1000,
                'current_policy'     => 'quarantine',
                'current_policy_pct' => 25,
                'expected_policy'    => 'reject',
                'dmarc_pass'         => 999,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('partial_quarantine', $codes);
        $this->assertNotContains('move_to_reject', $codes);
    }

    public function testQuarantineTargetWithFullPercentageStaysAtTarget(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'          => 8,
                'domain'             => 'example.org',
                'total'              => 1000,
                'current_policy'     => 'quarantine',
                'current_policy_pct' => 100,
                'expected_policy'    => 'quarantine',
                'dmarc_pass'         => 999,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('at_target', $codes);
        $this->assertNotContains('partial_quarantine', $codes);
    }

    public function testQuarantineTargetStopsRolloutAtQuarantine(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'       => 4,
                'domain'          => 'example.org',
                'total'           => 1000,
                'current_policy'  => 'quarantine',
                'expected_policy' => 'quarantine',
                'dmarc_pass'      => 999,
            ],
            self::CONFIG
        );

        $item = $this->find($items, 'at_target');
        $this->assertNotNull($item);
        $this->assertSame(0, $item['severity']);
        $this->assertSame('quarantine', $item['params'][1]);
        $this->assertNotContains('move_to_reject', $this->codes($items));
    }

    public function testNoneTargetStopsRolloutAtNone(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'       => 5,
                'domain'          => 'example.org',
                'total'           => 1000,
                'current_policy'  => 'none',
                'expected_policy' => 'none',
                'dmarc_pass'      => 999,
            ],
            self::CONFIG
        );

        $this->assertContains('at_target', $this->codes($items));
        $this->assertNotContains('move_to_quarantine', $this->codes($items));
    }

    public function testUnalignedSourceWithHighVolumeIsActNow(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'         => 1,
                'domain'            => 'example.org',
                'total'             => 1000,
                'current_policy'    => 'none',
                'dmarc_pass'        => 990,
                'unaligned_sources' => [['ip' => '203.0.113.9', 'count' => 80]],
            ],
            self::CONFIG
        );

        $item = $this->find($items, 'align_source');
        $this->assertNotNull($item);
        $this->assertSame(3, $item['severity']);
        $this->assertSame('align_source:1:203.0.113.9', $item['action_key']);
        $this->assertSame(['203.0.113.9', 80, 8.0], $item['params']);
    }

    public function testAlignSourceShareUsesCurrentWindowTotal(): void
    {
        // The failing volume and its share are measured over the current window,
        // not lifetime: 40 failing of a 500-message window is an 8% share even
        // though lifetime volume is far larger, so a sender aligned long ago no
        // longer skews the alignment recommendation.
        $items = ActionCenter::generate(
            [
                'domain_id'         => 1,
                'domain'            => 'example.org',
                'total'             => 5000,
                'current_policy'    => 'none',
                'dmarc_pass'        => 4950,
                'window_total'      => 500,
                'pass_rate_window'  => 99.0,
                'unaligned_sources' => [['ip' => '203.0.113.9', 'count' => 40]],
            ],
            self::CONFIG
        );

        $item = $this->find($items, 'align_source');
        $this->assertNotNull($item);
        $this->assertSame(3, $item['severity']);
        $this->assertSame(['203.0.113.9', 40, 8.0], $item['params']);
    }

    public function testWouldBeRejectedIsFlaggedWhenNotYetEnforcing(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'      => 1,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'none',
                'dmarc_pass'     => 990,
                'reject_under_p' => 50,
            ],
            self::CONFIG
        );

        $item = $this->find($items, 'would_be_rejected');
        $this->assertNotNull($item);
        $this->assertSame(3, $item['severity']);
    }

    public function testPassRateDropIsDetected(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'      => 1,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'quarantine',
                'dmarc_pass'     => 850,
                'pass_rate_prev' => 97.0,
            ],
            self::CONFIG
        );

        $this->assertContains('pass_rate_drop', $this->codes($items));
    }

    public function testPassRateDropUsesWindowRateNotOverallRate(): void
    {
        // Overall pass rate is high (990/1000), but the latest window dropped to
        // 80%, well below the previous window's 97%. The drop must be flagged
        // from the window rate, not hidden by the accumulated overall rate.
        $items = ActionCenter::generate(
            [
                'domain_id'        => 1,
                'domain'           => 'example.org',
                'total'            => 1000,
                'current_policy'   => 'quarantine',
                'dmarc_pass'       => 990,
                'pass_rate_prev'   => 97.0,
                'pass_rate_window' => 80.0,
            ],
            self::CONFIG
        );

        $this->assertContains('pass_rate_drop', $this->codes($items));
    }

    public function testThresholdMetAdvancesWithFailingSendersSurfacedSeparately(): void
    {
        // Pass rate clears the quarantine threshold while 10 messages still fail.
        // The configured threshold is the safety line, so the move is recommended
        // at act-now severity; the residual failing mail is surfaced separately as
        // a would_be_rejected warning rather than silently blocking the advance.
        $items = ActionCenter::generate(
            [
                'domain_id'      => 1,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'none',
                'dmarc_pass'     => 990,
                'reject_under_p' => 10,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('move_to_quarantine', $codes);
        $this->assertNotContains('resolve_before_quarantine', $codes);

        $move = $this->find($items, 'move_to_quarantine');
        $this->assertSame(3, $move['severity']);

        $warning = $this->find($items, 'would_be_rejected');
        $this->assertNotNull($warning);
        $this->assertSame(10, $warning['params'][0]);
    }

    public function testRejectThresholdMetAdvancesWithFailingSendersSurfacedSeparately(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'      => 2,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'quarantine',
                'dmarc_pass'     => 995,
                'reject_under_p' => 5,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('move_to_reject', $codes);
        $this->assertNotContains('resolve_before_reject', $codes);

        $warning = $this->find($items, 'would_be_rejected');
        $this->assertNotNull($warning);
        $this->assertSame(5, $warning['params'][0]);
    }

    public function testFullyAlignedDomainStillGetsSafeAdvanceAdvice(): void
    {
        // With no failing senders the advice stays the confident act-now move.
        $items = ActionCenter::generate(
            [
                'domain_id'      => 3,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'none',
                'dmarc_pass'     => 1000,
                'reject_under_p' => 0,
            ],
            self::CONFIG
        );

        $item = $this->find($items, 'move_to_quarantine');
        $this->assertNotNull($item);
        $this->assertSame(3, $item['severity']);
    }

    public function testDnsFindingsAreMergedAndSortedBySeverity(): void
    {
        $items = ActionCenter::generate(
            [
                'domain_id'      => 1,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'reject',
                'dmarc_pass'     => 1000,
            ],
            self::CONFIG,
            [['code' => 'dns_dmarc_missing', 'severity' => 3, 'params' => ['example.org']]]
        );

        $this->assertContains('dns_dmarc_missing', $this->codes($items));

        // The highest severity item must sort to the front.
        $severities = array_map(static fn (array $i): int => $i['severity'], $items);
        $sorted     = $severities;
        rsort($sorted);
        $this->assertSame($sorted, $severities);
    }

    public function testRolloutUsesCurrentWindowNotLifetimeTotals(): void
    {
        // Lifetime history shows failures (reject_under_p = 40, 96% pass) that
        // would permanently block advancement, but the current reporting window
        // is fully aligned. The rollout must advance based on the window.
        $items = ActionCenter::generate(
            [
                'domain_id'             => 1,
                'domain'                => 'example.org',
                'total'                 => 1000,
                'current_policy'        => 'none',
                'dmarc_pass'            => 960,
                'reject_under_p'        => 40,
                'window_total'          => 500,
                'window_reject_under_p' => 0,
                'pass_rate_window'      => 100.0,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('move_to_quarantine', $codes);
        $this->assertNotContains('resolve_before_quarantine', $codes);
        $this->assertNotContains('would_be_rejected', $codes);
    }

    public function testRolloutFlagsFailuresFromCurrentWindow(): void
    {
        // Lifetime totals are spotless, but the current window still has failing
        // senders. The window pass rate clears the threshold, so the advance is
        // recommended while the window failures are sized as a separate warning.
        $items = ActionCenter::generate(
            [
                'domain_id'             => 1,
                'domain'                => 'example.org',
                'total'                 => 1000,
                'current_policy'        => 'none',
                'dmarc_pass'            => 1000,
                'reject_under_p'        => 0,
                'window_total'          => 500,
                'window_reject_under_p' => 25,
                'pass_rate_window'      => 99.0,
            ],
            self::CONFIG
        );

        $codes = $this->codes($items);
        $this->assertContains('move_to_quarantine', $codes);
        $this->assertNotContains('resolve_before_quarantine', $codes);

        $wouldBe = $this->find($items, 'would_be_rejected');
        $this->assertNotNull($wouldBe);
        // Share is measured over the window total (25 / 500 = 5%), not lifetime.
        $this->assertSame(5.0, $wouldBe['params'][1]);
    }

    public function testMtaStsFindingsFoldIntoTheDashboardActionCenter(): void
    {
        // The dashboard Action Center (and the dashboard module that consumes it)
        // must surface the MTA-STS/TLS-RPT publish guidance, not only the
        // drill-down. DashboardModel::domainFindings() passes the self-tagged
        // "mtasts" findings as the third argument, exactly as the drill-down
        // does, so they must be folded in, tagged to the domain and category.
        $items = ActionCenter::generate(
            [
                'domain_id'      => 7,
                'domain'         => 'example.org',
                'total'          => 1000,
                'current_policy' => 'reject',
                'dmarc_pass'     => 1000,
            ],
            self::CONFIG,
            [
                ['code' => 'mtasts_publish', 'severity' => 2, 'category' => 'mtasts', 'params' => ['example.org']],
                ['code' => 'tlsrpt_publish', 'severity' => 1, 'category' => 'mtasts', 'params' => ['example.org']],
            ]
        );

        $mtasts = $this->find($items, 'mtasts_publish');
        $this->assertNotNull($mtasts);
        $this->assertSame('mtasts', $mtasts['category']);
        $this->assertSame('mtasts_publish:7', $mtasts['action_key']);

        $tlsrpt = $this->find($items, 'tlsrpt_publish');
        $this->assertNotNull($tlsrpt);
        $this->assertSame('mtasts', $tlsrpt['category']);
        $this->assertSame('tlsrpt_publish:7', $tlsrpt['action_key']);
    }
}
