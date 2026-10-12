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

    public function testThresholdMetWithFailingSendersDowngradesQuarantineAdvice(): void
    {
        // Pass rate clears the quarantine threshold, but 10 messages still fail.
        // The engine must not call the move "safe"; it downgrades to a resolve-
        // first recommendation at plan-soon severity instead of act-now.
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
        $this->assertContains('resolve_before_quarantine', $codes);
        $this->assertNotContains('move_to_quarantine', $codes);

        $item = $this->find($items, 'resolve_before_quarantine');
        $this->assertSame(2, $item['severity']);
        $this->assertSame(10, $item['params'][2]);
    }

    public function testThresholdMetWithFailingSendersDowngradesRejectAdvice(): void
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
        $this->assertContains('resolve_before_reject', $codes);
        $this->assertNotContains('move_to_reject', $codes);
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
        // senders. The engine must downgrade and size the risk from the window.
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
        $this->assertContains('resolve_before_quarantine', $codes);
        $this->assertNotContains('move_to_quarantine', $codes);

        $wouldBe = $this->find($items, 'would_be_rejected');
        $this->assertNotNull($wouldBe);
        // Share is measured over the window total (25 / 500 = 5%), not lifetime.
        $this->assertSame(5.0, $wouldBe['params'][1]);
    }
}
