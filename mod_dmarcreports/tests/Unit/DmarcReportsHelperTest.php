<?php
/**
 * @package     DMARC Reports Dashboard Module Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Module\DmarcReports\Tests\Unit;

use Advans\Module\DmarcReports\Administrator\Helper\DmarcReportsHelper;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure, Joomla-free helpers in DmarcReportsHelper.
 */
final class DmarcReportsHelperTest extends TestCase
{
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
}
