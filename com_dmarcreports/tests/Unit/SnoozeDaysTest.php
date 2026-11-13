<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\ActionStatus;
use PHPUnit\Framework\TestCase;

/**
 * The snooze length comes from the request. A crafted value must stay within
 * one day and one year, so the date calculation cannot overflow.
 *
 * @since  1.2.0
 */
class SnoozeDaysTest extends TestCase
{
    public function testKeepsTheOfferedLength(): void
    {
        $this->assertSame(7, ActionStatus::snoozeDays(7));
    }

    public function testRaisesZeroAndNegativeValuesToOneDay(): void
    {
        $this->assertSame(1, ActionStatus::snoozeDays(0));
        $this->assertSame(1, ActionStatus::snoozeDays(-5));
    }

    public function testCapsHugeValuesAtOneYear(): void
    {
        $this->assertSame(ActionStatus::MAX_SNOOZE_DAYS, ActionStatus::snoozeDays(PHP_INT_MAX));
        $this->assertSame(365, ActionStatus::MAX_SNOOZE_DAYS);
    }
}
