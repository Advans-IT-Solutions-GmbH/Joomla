<?php
/**
 * @package     DMARC Reports Dashboard Module Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\DmarcReports\Tests\Unit;

use Advans\Module\DmarcReports\Administrator\Helper\DmarcReportsHelper;
use Joomla\Registry\Registry;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure, Joomla-free helpers in DmarcReportsHelper.
 */
final class DmarcReportsHelperTest extends TestCase
{
    /**
     * A component Dashboard model double returning fixed totals and actions.
     */
    private function dashboardModel(array $totals, array $actions): object
    {
        return new class ($totals, $actions) {
            public function __construct(private array $totals, private array $actions)
            {
            }

            public function getTotals(): array
            {
                return $this->totals;
            }

            public function getActions(): array
            {
                return $this->actions;
            }
        };
    }

    public function testSummariseTotalsReturnsZeroesForEmptyInput(): void
    {
        $totals = DmarcReportsHelper::summariseTotals([]);

        $this->assertSame(0, $totals['domains']);
        $this->assertSame(0, $totals['total']);
        $this->assertSame(0, $totals['dmarc_pass']);
        $this->assertSame(0, $totals['reject_under_p']);
        $this->assertSame(0.0, $totals['pass_rate']);
    }

    public function testSummariseTotalsAggregatesRowsAndComputesPassRate(): void
    {
        $totals = DmarcReportsHelper::summariseTotals([
            ['total' => 80, 'dmarc_pass' => 60, 'reject_under_p' => 10],
            ['total' => 20, 'dmarc_pass' => 15, 'reject_under_p' => 5],
        ]);

        $this->assertSame(2, $totals['domains']);
        $this->assertSame(100, $totals['total']);
        $this->assertSame(75, $totals['dmarc_pass']);
        $this->assertSame(15, $totals['reject_under_p']);
        $this->assertSame(75.0, $totals['pass_rate']);
    }

    public function testSummariseTotalsTreatsMissingKeysAsZero(): void
    {
        $totals = DmarcReportsHelper::summariseTotals([
            ['total' => 10],
            [],
        ]);

        $this->assertSame(2, $totals['domains']);
        $this->assertSame(10, $totals['total']);
        $this->assertSame(0, $totals['dmarc_pass']);
        $this->assertSame(0.0, $totals['pass_rate']);
    }

    public function testRejectRateIsZeroWithoutMessages(): void
    {
        $this->assertSame(0.0, DmarcReportsHelper::rejectRate(['total' => 0, 'reject_under_p' => 5]));
        $this->assertSame(0.0, DmarcReportsHelper::rejectRate([]));
    }

    public function testRejectRateComputesRoundedPercentage(): void
    {
        $this->assertSame(25.0, DmarcReportsHelper::rejectRate(['total' => 200, 'reject_under_p' => 50]));
        $this->assertSame(33.3, DmarcReportsHelper::rejectRate(['total' => 3, 'reject_under_p' => 1]));
    }

    public function testLimitActionsHidesListForZeroOrNegativeLimit(): void
    {
        $items = [['code' => 'a'], ['code' => 'b']];

        $this->assertSame([], DmarcReportsHelper::limitActions($items, 0));
        $this->assertSame([], DmarcReportsHelper::limitActions($items, -3));
    }

    public function testLimitActionsKeepsOnlyTheFirstItems(): void
    {
        $items = [['code' => 'a'], ['code' => 'b'], ['code' => 'c']];

        $limited = DmarcReportsHelper::limitActions($items, 2);

        $this->assertCount(2, $limited);
        $this->assertSame('a', $limited[0]['code']);
        $this->assertSame('b', $limited[1]['code']);
    }

    public function testLimitActionsReturnsAllWhenLimitExceedsCount(): void
    {
        $items = [['code' => 'a']];

        $this->assertCount(1, DmarcReportsHelper::limitActions($items, 10));
    }

    public function testPresentDashboardBuildsOkPayloadFromModel(): void
    {
        $model = $this->dashboardModel(
            ['domains' => 2, 'total' => 100, 'dmarc_pass' => 80, 'reject_under_p' => 20, 'pass_rate' => 80.0],
            [
                ['code' => 'move_to_quarantine', 'severity' => 3, 'domain' => 'example.org'],
                ['code' => 'align_source', 'severity' => 2, 'domain' => 'example.net'],
            ]
        );

        $dashboard = (new DmarcReportsHelper())->presentDashboard($model, new Registry(['action_limit' => 5]));

        $this->assertSame('ok', $dashboard['state']);
        $this->assertSame(2, $dashboard['totals']['domains']);
        $this->assertSame(100, $dashboard['totals']['total']);
        $this->assertSame(80.0, $dashboard['totals']['pass_rate']);
        $this->assertSame(20.0, $dashboard['rejectRate']);
        $this->assertSame(2, $dashboard['activeCount']);
        $this->assertStringContainsString('com_dmarcreports', $dashboard['link']);
    }

    public function testPresentDashboardReusesActionPresenterForEachRow(): void
    {
        $model = $this->dashboardModel(
            ['total' => 10, 'domains' => 1],
            [['code' => 'move_to_quarantine', 'severity' => 3, 'domain' => 'example.org']]
        );

        $dashboard = (new DmarcReportsHelper())->presentDashboard($model, new Registry(['action_limit' => 5]));

        $this->assertCount(1, $dashboard['actions']);
        $row = $dashboard['actions'][0];
        $this->assertSame('danger', $row['severityClass']);
        $this->assertSame(3, $row['severity']);
        $this->assertSame('example.org', $row['domain']);
        $this->assertArrayHasKey('title', $row);
        $this->assertArrayHasKey('text', $row);
        $this->assertArrayHasKey('severityLabel', $row);
    }

    public function testPresentDashboardTruncatesActionsButKeepsFullActiveCount(): void
    {
        $model = $this->dashboardModel(
            ['total' => 10, 'domains' => 1],
            [
                ['code' => 'a', 'severity' => 3, 'domain' => 'a.example'],
                ['code' => 'b', 'severity' => 2, 'domain' => 'b.example'],
                ['code' => 'c', 'severity' => 1, 'domain' => 'c.example'],
            ]
        );

        $dashboard = (new DmarcReportsHelper())->presentDashboard($model, new Registry(['action_limit' => 2]));

        $this->assertCount(2, $dashboard['actions']);
        $this->assertSame(3, $dashboard['activeCount']);
    }

    public function testPresentDashboardHidesActionsWhenLimitIsZeroButReportsActiveCount(): void
    {
        $model = $this->dashboardModel(
            ['total' => 10, 'domains' => 1],
            [['code' => 'a', 'severity' => 3, 'domain' => 'a.example']]
        );

        $dashboard = (new DmarcReportsHelper())->presentDashboard($model, new Registry(['action_limit' => 0]));

        $this->assertSame([], $dashboard['actions']);
        $this->assertSame(1, $dashboard['activeCount']);
    }
}
