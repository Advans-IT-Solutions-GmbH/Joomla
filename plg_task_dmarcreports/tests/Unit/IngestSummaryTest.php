<?php
/**
 * @package     DMARC Reports Ingestion Task Plugin Tests
 * @subpackage  Unit
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Task\DmarcReports\Tests\Unit;

use Advans\Plugin\Task\DmarcReports\Support\IngestSummary;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Advans\Plugin\Task\DmarcReports\Support\IngestSummary
 */
final class IngestSummaryTest extends TestCase
{
    public function testEmptyResultsYieldZeroedTotals(): void
    {
        $this->assertSame(
            ['imported' => 0, 'duplicates' => 0, 'failed' => 0, 'blocked' => 0, 'errors' => 0],
            IngestSummary::aggregate([])
        );
    }

    public function testSumsPerSourceSummaries(): void
    {
        $results = [
            ['status' => 'ok', 'summary' => ['imported' => 3, 'duplicates' => 1, 'failed' => 0]],
            ['status' => 'ok', 'summary' => ['imported' => 2, 'duplicates' => 0, 'failed' => 4]],
        ];

        $totals = IngestSummary::aggregate($results);

        $this->assertSame(5, $totals['imported']);
        $this->assertSame(1, $totals['duplicates']);
        $this->assertSame(4, $totals['failed']);
        $this->assertSame(0, $totals['errors']);
    }

    public function testCountsErrorStatusAndBlocked(): void
    {
        $results = [
            ['status' => IngestSummary::STATUS_ERROR, 'message' => 'boom', 'summary' => []],
            ['status' => 'blocked', 'blocked' => 1, 'summary' => []],
            ['status' => 'ok', 'summary' => ['imported' => 1]],
        ];

        $totals = IngestSummary::aggregate($results);

        $this->assertSame(1, $totals['errors']);
        $this->assertSame(1, $totals['blocked']);
        $this->assertSame(1, $totals['imported']);
    }

    public function testToleratesMissingKeys(): void
    {
        $totals = IngestSummary::aggregate([['status' => 'ok'], ['summary' => null]]);

        $this->assertSame(0, $totals['imported']);
        $this->assertSame(0, $totals['errors']);
    }
}
