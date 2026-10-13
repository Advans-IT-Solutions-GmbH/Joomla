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

    public function testAWeeklyCapBelowTheDailyCapIsAccepted(): void
    {
        // The two caps are independent. Five a day and three a week means at
        // most three bookings in that week, whichever days they fall on, which
        // is a configuration an operator may well want.
        $rules = new SlotRules(20, 30, 0, 0, 0, 30, null, null, 5, 3);

        $this->assertSame(5, $rules->getMaxPerDay());
        $this->assertSame(3, $rules->getMaxPerWeek());
    }

    public function testABookingIsWidenedByTheRulesOfItsOwnEventType(): void
    {
        $rules  = new SlotRules(20, 30, 10, 15);
        $busy   = $rules->expandBooking(
            \Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow::fromUtcStrings(
                '2026-06-02 10:00:00',
                '2026-06-02 10:20:00'
            )
        );

        $this->assertSame('2026-06-02 09:50:00', $busy->getStart()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-06-02 10:35:00', $busy->getEnd()->format('Y-m-d H:i:s'));
    }

    public function testUnlimitedCapsAreAccepted(): void
    {
        $rules = new SlotRules(20, 30, 0, 0, 0, 30, null, null, 0, 0);

        $this->assertSame(0, $rules->getMaxPerDay());
        $this->assertSame(0, $rules->getMaxPerWeek());
    }

    /**
     * @dataProvider unusableHorizonRows
     */
    public function testAFixedHorizonThatCannotBeReadIsRefused(string $column, string $value): void
    {
        // The fixed horizon decides from when until when the event type can be
        // booked at all. `new DateTimeImmutable()` would read 2026-02-31 as the
        // 3rd of March and move the horizon by three days without a word, so a
        // value that cannot be read is refused instead of guessed at.
        $row = [
            'duration_minutes'  => 30,
            'slot_step_minutes' => 30,
            'horizon_days'      => 0,
            'horizon_start_utc' => '2026-06-01 00:00:00',
            'horizon_end_utc'   => '2026-07-01 00:00:00',
        ];

        $row[$column] = $value;

        $this->expectException(\InvalidArgumentException::class);

        SlotRules::fromEventType($row);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function unusableHorizonRows(): array
    {
        return [
            'impossible start' => ['horizon_start_utc', '2026-02-31 00:00:00'],
            'impossible end'   => ['horizon_end_utc', '2026-02-31 00:00:00'],
            'relative text'    => ['horizon_start_utc', 'tomorrow'],
            'year below range' => ['horizon_start_utc', '0001-01-01 00:00:00'],
            'other format'     => ['horizon_end_utc', '01.07.2026 00:00:00'],
        ];
    }

    public function testAnEmptyFixedHorizonStaysEmpty(): void
    {
        // An empty column and a database zero date are absent, not unusable, so
        // they keep meaning "no fixed horizon" and the rolling one applies.
        $rules = SlotRules::fromEventType([
            'duration_minutes'  => 30,
            'slot_step_minutes' => 30,
            'horizon_days'      => 30,
            'horizon_start_utc' => '',
            'horizon_end_utc'   => '0000-00-00 00:00:00',
        ]);

        $this->assertNull($rules->getHorizonStart());
        $this->assertNull($rules->getHorizonEnd());
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
