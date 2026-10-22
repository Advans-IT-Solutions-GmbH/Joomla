<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\DecimalTotal;
use PHPUnit\Framework\TestCase;

/**
 * The message total of an import spans several reports, each bounded by
 * PHP_INT_MAX, and must stay exact beyond it.
 *
 * @since  1.1.0
 */
class DecimalTotalTest extends TestCase
{
    public function testSmallNumbersAddUp(): void
    {
        $this->assertSame('6', DecimalTotal::add(1, DecimalTotal::add(2, 3)));
        $this->assertSame('0', DecimalTotal::add('0', 0));
    }

    public function testTwoReportsBeyondTheIntegerLimitStayExact(): void
    {
        // PHP_INT_MAX + 1 on a 64-bit host: an integer sum would become a float
        // and print as a negative number after a cast.
        $total = DecimalTotal::add('0', PHP_INT_MAX);
        $total = DecimalTotal::add($total, 1);

        $this->assertSame('9223372036854775808', $total);
    }

    public function testManyLargeReportsStayExact(): void
    {
        $total = '0';

        for ($i = 0; $i < 3; $i++) {
            $total = DecimalTotal::add($total, PHP_INT_MAX);
        }

        $this->assertSame('27670116110564327421', $total);
    }

    public function testInvalidInputCountsAsZero(): void
    {
        $this->assertSame('5', DecimalTotal::add('-3', 5));
        $this->assertSame('5', DecimalTotal::add('abc', '005'));
    }
}