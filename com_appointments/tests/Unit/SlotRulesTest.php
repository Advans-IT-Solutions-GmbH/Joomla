<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\SlotRules;
use PHPUnit\Framework\TestCase;

/**
 * A configuration that cannot produce slots has to be refused where it is
 * built. The alternative is an event type that silently offers nothing and an
 * operator who cannot see why.
 */
final class SlotRulesTest extends TestCase
{
    public function testMinutesBecomeSeconds(): void
    {
        $rules = new SlotRules(20, 30, 10, 15, 90);

        $this->assertSame(1200, $rules->getDurationSeconds());
        $this->assertSame(1800, $rules->getSlotStepSeconds());
        $this->assertSame(600, $rules->getBufferBeforeSeconds());
        $this->assertSame(900, $rules->getBufferAfterSeconds());
        $this->assertSame(5400, $rules->getMinimumNoticeSeconds());
    }

    public function testADurationOfZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlotRules(0, 30);
    }

    public function testASlotStepOfZeroIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlotRules(20, 0);
    }

    public function testANegativeBufferIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlotRules(20, 30, -10);
    }

    public function testARollingHorizonOfZeroNeedsAFixedRange(): void
    {
        // Otherwise the event type is bookable neither for a day nor for ever,
        // and the engine would quietly return nothing.
        $this->expectException(\InvalidArgumentException::class);

        new SlotRules(20, 30, 0, 0, 0, 0);
    }

    public function testAReversedFixedHorizonIsRejected(): void
    {
        $utc = new \DateTimeZone('UTC');

        $this->expectException(\InvalidArgumentException::class);

        new SlotRules(
            20,
            30,
            0,
            0,
            0,
            0,
            new \DateTimeImmutable('2026-07-01', $utc),
            new \DateTimeImmutable('2026-06-01', $utc)
        );
    }

    public function testAWeeklyCapBelowTheDailyCapIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SlotRules(20, 30, 0, 0, 0, 30, null, null, 5, 3);
    }

    public function testUnlimitedCapsAreAccepted(): void
    {
        $rules = new SlotRules(20, 30, 0, 0, 0, 30, null, null, 0, 0);

        $this->assertSame(0, $rules->getMaxPerDay());
        $this->assertSame(0, $rules->getMaxPerWeek());
    }

    public function testAnEventTypeRowBecomesRules(): void
    {
        $rules = SlotRules::fromEventType([
            'duration_minutes'      => 20,
            'slot_step_minutes'     => 30,
            'buffer_before_minutes' => 5,
            'buffer_after_minutes'  => 5,
            'min_notice_minutes'    => 120,
            'horizon_days'          => 30,
            'horizon_start_utc'     => null,
            'horizon_end_utc'       => null,
            'max_per_day'           => 3,
            'max_per_week'          => 10,
        ]);

        $this->assertSame(1200, $rules->getDurationSeconds());
        $this->assertSame(30, $rules->getHorizonDays());
        $this->assertSame(3, $rules->getMaxPerDay());
        $this->assertSame(10, $rules->getMaxPerWeek());
        $this->assertNull($rules->getHorizonStart());
    }

    public function testAnEventTypeRowWithAFixedHorizonBecomesRules(): void
    {
        $rules = SlotRules::fromEventType([
            'duration_minutes'  => 60,
            'slot_step_minutes' => 60,
            'horizon_days'      => 0,
            'horizon_start_utc' => '2026-06-01 00:00:00',
            'horizon_end_utc'   => '2026-07-01 00:00:00',
        ]);

        $this->assertSame('2026-06-01 00:00:00', $rules->getHorizonStart()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-01 00:00:00', $rules->getHorizonEnd()->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $rules->getHorizonStart()->getTimezone()->getName());
    }

    public function testAZeroDateFromTheDatabaseCountsAsNoDate(): void
    {
        // A MySQL zero date is not a date, and reading it as one would put the
        // horizon in the year zero.
        $this->expectException(\InvalidArgumentException::class);

        SlotRules::fromEventType([
            'duration_minutes'  => 60,
            'slot_step_minutes' => 60,
            'horizon_days'      => 0,
            'horizon_start_utc' => '0000-00-00 00:00:00',
            'horizon_end_utc'   => '0000-00-00 00:00:00',
        ]);
    }
}
