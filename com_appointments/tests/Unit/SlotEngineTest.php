<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow;
use Advans\Component\Appointments\Administrator\Helper\BusyPeriod;
use Advans\Component\Appointments\Administrator\Helper\ScheduleResolver;
use Advans\Component\Appointments\Administrator\Helper\SlotEngine;
use Advans\Component\Appointments\Administrator\Helper\SlotRules;
use PHPUnit\Framework\TestCase;

/**
 * The engine is a pure function of its inputs, so every test states "now"
 * explicitly. A test that let the engine read the real clock would pass or fail
 * depending on the day it runs, which is worse than no test.
 *
 * Reference dates, all in Europe/Zurich:
 *
 * - Tuesday 2 June 2026 and Tuesday 9 June 2026, ISO weeks 23 and 24.
 * - Thursday 4 June 2026, also ISO week 23, used for the weekly cap.
 * - Sunday 29 March 2026: local 02:00 to 03:00 never happens.
 * - Sunday 25 October 2026: local 02:00 to 03:00 happens twice.
 */
final class SlotEngineTest extends TestCase
{
    private const ZONE = 'Europe/Zurich';

    private function utc(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment, new \DateTimeZone('UTC'));
    }

    private function window(string $start, string $end): AvailabilityWindow
    {
        return AvailabilityWindow::fromUtcStrings($start, $end);
    }

    /**
     * A window given in the schedule's own time zone, which is how a booking or
     * an absence reads to the operator.
     */
    private function localWindow(string $start, string $end): AvailabilityWindow
    {
        $zone = new \DateTimeZone(self::ZONE);
        $utc  = new \DateTimeZone('UTC');

        return new AvailabilityWindow(
            (new \DateTimeImmutable($start, $zone))->setTimezone($utc),
            (new \DateTimeImmutable($end, $zone))->setTimezone($utc)
        );
    }

    /**
     * @param  \DateTimeImmutable[]  $slots
     *
     * @return string[]  Local wall-clock times, which is what a visitor sees.
     */
    private function asLocal(array $slots): array
    {
        $zone = new \DateTimeZone(self::ZONE);

        return array_map(
            static function (\DateTimeImmutable $slot) use ($zone): string {
                return $slot->setTimezone($zone)->format('Y-m-d H:i');
            },
            $slots
        );
    }

    private function engine(array $weekly, SlotRules $rules, array $overrides = []): SlotEngine
    {
        return new SlotEngine(new ScheduleResolver(self::ZONE, $weekly, $overrides), $rules);
    }

    /**
     * Tuesday 10:00 to 12:00, the window most of these tests use.
     */
    private function tuesdayMorning(): array
    {
        return [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00']];
    }

    public function testTheInjectedClockMayCarryAnyTimeZone(): void
    {
        // The engine is handed instants, and the zone an argument carries is a
        // presentation detail of the caller. A Europe/Zurich clock used to
        // reach AvailabilityWindow through the minimum notice, which accepts
        // only UTC, so the engine threw instead of answering.
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(20, 30, 0, 0, 30));
        $zone   = new \DateTimeZone(self::ZONE);

        $local = $engine->findSlots(
            new \DateTimeImmutable('2026-06-02 10:30', $zone),
            new \DateTimeImmutable('2026-06-02 00:00', $zone),
            new \DateTimeImmutable('2026-06-03 00:00', $zone)
        );

        // The same question asked in UTC has to give the same answer, because
        // it is the same question. Both are the same three instants.
        $utc = $engine->findSlots(
            $this->utc('2026-06-02 08:30'),
            $this->utc('2026-06-01 22:00'),
            $this->utc('2026-06-02 22:00')
        );

        // 10:30 plus half an hour of notice leaves 11:00 and 11:30.
        $this->assertSame(['2026-06-02 11:00', '2026-06-02 11:30'], $this->asLocal($local));
        $this->assertSame($this->asLocal($utc), $this->asLocal($local));
    }

    public function testAMeetingMayNotRunIntoAClosedDayJustPastTheRange(): void
    {
        // The range given to the engine is a range of *start* times, and a
        // meeting occupies time after its start. A Tuesday rule from 22:00 to
        // 02:00, a closed Wednesday, and a range that ends at Wednesday 00:00:
        // the closed day only touches the end of that range, so asking the
        // schedule over the range of starts alone never sees it, and a meeting
        // from 23:30 to 00:30 was offered on a day the operator closed.
        $engine = $this->engine(
            [['weekday' => 2, 'start_time' => '22:00', 'end_time' => '02:00']],
            new SlotRules(60, 30),
            [['specific_date' => '2026-06-03', 'is_unavailable' => 1]]
        );

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->localWindow('2026-06-02 22:00', '2026-06-03 00:00')->getStart(),
            $this->localWindow('2026-06-02 22:00', '2026-06-03 00:00')->getEnd()
        );

        // 23:00 is the last start whose meeting still ends on the Tuesday.
        $this->assertSame(
            ['2026-06-02 22:00', '2026-06-02 22:30', '2026-06-02 23:00'],
            $this->asLocal($slots)
        );
    }

    public function testATwentyMinuteMeetingOnAThirtyMinuteGrid(): void
    {
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(20, 30));

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00')
        );

        $this->assertSame(
            ['2026-06-02 10:00', '2026-06-02 10:30', '2026-06-02 11:00', '2026-06-02 11:30'],
            $this->asLocal($slots)
        );
    }

    public function testABufferBeforeMovesTheFirstSlotInward(): void
    {
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(20, 30, 10));

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00')
        );

        // 10:00 would need the window to begin at 09:50.
        $this->assertSame(
            ['2026-06-02 10:30', '2026-06-02 11:00', '2026-06-02 11:30'],
            $this->asLocal($slots)
        );
    }

    public function testBuffersMoveBothTheFirstAndTheLastSlotInward(): void
    {
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(20, 30, 10, 20));

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00')
        );

        // 10:00 needs 09:50, and 11:30 would run to 12:10 with its buffer.
        $this->assertSame(['2026-06-02 10:30', '2026-06-02 11:00'], $this->asLocal($slots));
    }

    public function testABookingIsRemovedTogetherWithItsBuffers(): void
    {
        $rules  = new SlotRules(20, 30, 10, 10);
        $engine = $this->engine($this->tuesdayMorning(), $rules);

        // The caller hands the booking over already widened by the buffers of
        // its own event type, which is what expandBooking() is for.
        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00'),
            [BusyPeriod::forBooking($this->localWindow('2026-06-02 11:00', '2026-06-02 11:20'), $rules)]
        );

        // The booking takes 10:50 to 11:30 out of the day, and the grid is
        // still anchored to the window start, so 11:30 is offered and not
        // something off-grid after the booking.
        $this->assertSame(['2026-06-02 10:30', '2026-06-02 11:30'], $this->asLocal($slots));
    }

    public function testAnAbsenceAndABookingWithBuffersDifferInWhatTheyBlock(): void
    {
        // The same obstacle, once as an absence handed over as it stands and
        // once as a booking of an event type with ten minutes of buffer on each
        // side. The engine widens nothing itself, so the booking blocks more.
        $thisType   = new SlotRules(30, 30);
        $bufferType = new SlotRules(30, 30, 10, 10);
        $engine     = $this->engine($this->tuesdayMorning(), $thisType);
        $obstacle   = $this->localWindow('2026-06-02 11:00', '2026-06-02 11:30');

        $withAbsence = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00'),
            [BusyPeriod::reserved($obstacle)]
        );

        $withBooking = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00'),
            [BusyPeriod::forBooking($obstacle, $bufferType)]
        );

        // The absence takes exactly its half hour.
        $this->assertSame(
            ['2026-06-02 10:00', '2026-06-02 10:30', '2026-06-02 11:30'],
            $this->asLocal($withAbsence)
        );
        // The booking takes 10:50 to 11:40, which also removes the 10:30 and
        // the 11:30 start.
        $this->assertSame(['2026-06-02 10:00'], $this->asLocal($withBooking));
    }

    public function testACandidatesBufferMayNotFallIntoAnAbsence(): void
    {
        // An absence is time the host is not there, so neither a meeting nor
        // the preparation around one can lie inside it.
        $engine   = $this->engine($this->tuesdayMorning(), new SlotRules(30, 30, 0, 20));
        $obstacle = $this->localWindow('2026-06-02 11:00', '2026-06-02 11:30');

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00'),
            [BusyPeriod::reserved($obstacle)]
        );

        // 10:30 would end at 11:00 and then reach to 11:20 with its buffer,
        // which is inside the absence.
        $this->assertSame(['2026-06-02 10:00'], $this->asLocal($slots));
    }

    public function testALongBufferMayNotReachOverSomebodyElsesMeeting(): void
    {
        // The case a free-piece check alone cannot see: the other booking has
        // no buffer of its own, so the free pieces end exactly at its meeting,
        // and this event type's hour of buffer after a meeting would be placed
        // on top of it.
        $thisType  = new SlotRules(30, 30, 0, 60);
        $otherType = new SlotRules(30, 30);
        $engine    = $this->engine($this->tuesdayMorning(), $thisType);

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00'),
            [BusyPeriod::forBooking($this->localWindow('2026-06-02 11:00', '2026-06-02 11:30'), $otherType)]
        );

        // 10:00 would run to 11:30 with its buffer and 10:30 to 12:00, both
        // over the other meeting. Nothing is left.
        $this->assertSame([], $this->asLocal($slots));
    }

    public function testABookingOfAnotherEventTypeKeepsItsOwnBuffer(): void
    {
        // The host also serves an event type with a long buffer after a
        // meeting. That meeting's own buffer has to be respected while slots of
        // this event type, which has no buffer at all, are computed. The engine
        // therefore never applies its own rules to a period it is given.
        $otherType = new SlotRules(30, 30, 0, 60);
        $thisType  = new SlotRules(30, 30);
        $engine    = $this->engine($this->tuesdayMorning(), $thisType);

        $other = BusyPeriod::forBooking($this->localWindow('2026-06-02 10:00', '2026-06-02 10:30'), $otherType);

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00'),
            [$other]
        );

        // The other meeting occupies 10:00 to 11:30 including its hour of
        // buffer, so only 11:30 is left of the window.
        $this->assertSame(['2026-06-02 11:30'], $this->asLocal($slots));
    }

    public function testTheSlotAtTheMinimumNoticeBoundaryIsOffered(): void
    {
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(20, 30, 0, 0, 60));

        // 07:30 UTC plus 60 minutes is 08:30 UTC, which is the 10:30 slot.
        $slots = $engine->findSlots(
            $this->utc('2026-06-02 07:30'),
            $this->utc('2026-06-02 00:00'),
            $this->utc('2026-06-03 00:00')
        );

        $this->assertSame(
            ['2026-06-02 10:30', '2026-06-02 11:00', '2026-06-02 11:30'],
            $this->asLocal($slots)
        );
    }

    public function testOneMinutePastTheBoundaryDropsThatSlot(): void
    {
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(20, 30, 0, 0, 60));

        // One minute later the boundary falls just after the 10:30 slot.
        $slots = $engine->findSlots(
            $this->utc('2026-06-02 07:31'),
            $this->utc('2026-06-02 00:00'),
            $this->utc('2026-06-03 00:00')
        );

        $this->assertSame(['2026-06-02 11:00', '2026-06-02 11:30'], $this->asLocal($slots));
    }

    public function testTheLastDayOfARollingHorizonIsFullyBookable(): void
    {
        $everyDay = [];

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $everyDay[] = ['weekday' => $weekday, 'start_time' => '10:00', 'end_time' => '11:00'];
        }

        $engine = $this->engine($everyDay, new SlotRules(60, 60, 0, 0, 0, 30));

        $slots = $this->asLocal($engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-07-20 00:00')
        ));

        // Today is 2 June, so the thirtieth day after it is 2 July.
        $this->assertContains('2026-07-02 10:00', $slots);
        $this->assertNotContains('2026-07-03 10:00', $slots);
        $this->assertSame('2026-07-02 10:00', end($slots));
    }

    public function testRollingHorizonsCountGregorianDatesIncludingASkippedDate(): void
    {
        $zone   = new \DateTimeZone('Pacific/Apia');
        $weekly = [];

        for ($day = 1; $day <= 7; $day++) {
            $weekly[] = ['weekday' => $day, 'start_time' => '10:00', 'end_time' => '11:00'];
        }

        $expected = ['2011-12-29 10:00', '2011-12-29 10:30'];

        foreach ([1, 2, 3] as $days) {
            if ($days === 2) {
                array_push($expected, '2011-12-31 10:00', '2011-12-31 10:30');
            } elseif ($days === 3) {
                array_push($expected, '2012-01-01 10:00', '2012-01-01 10:30');
            }

            $engine = new SlotEngine(new ScheduleResolver('Pacific/Apia', $weekly), new SlotRules(30, 30, 0, 0, 0, $days));
            $slots = $engine->findSlots(
                $this->utc('2011-12-29 10:00'),
                $this->utc('2011-12-29 10:00'),
                $this->utc('2012-01-02 12:00')
            );

            $this->assertSame($expected, array_map(
                static fn (\DateTimeImmutable $slot): string => $slot->setTimezone($zone)->format('Y-m-d H:i'),
                $slots
            ));
        }
    }

    public function testRollingHorizonUsesTheFirstOccurrenceOfARepeatedMidnight(): void
    {
        $zone   = new \DateTimeZone('America/Havana');
        $engine = new SlotEngine(new ScheduleResolver('America/Havana', [
            ['weekday' => 6, 'start_time' => '22:00', 'end_time' => '02:00'],
        ]), new SlotRules(30, 30, 0, 0, 0, 1));
        $slots = $engine->findSlots(
            $this->utc('2026-10-30 04:00'),
            $this->utc('2026-10-30 04:00'),
            $this->utc('2026-11-02 12:00')
        );

        $this->assertSame(
            ['2026-10-31 22:00', '2026-10-31 22:30', '2026-10-31 23:00', '2026-10-31 23:30'],
            array_map(
                static fn (\DateTimeImmutable $slot): string => $slot->setTimezone($zone)->format('Y-m-d H:i'),
                $slots
            )
        );
    }

    public function testFixedHorizonBoundsMayCarryAnyTimeZone(): void
    {
        $zone = new \DateTimeZone(self::ZONE);
        $rules = new SlotRules(
            30, 30, 0, 0, 0, 0,
            new \DateTimeImmutable('2026-06-02 10:30:00', $zone),
            new \DateTimeImmutable('2026-06-02 11:30:00', $zone)
        );
        $engine = $this->engine($this->tuesdayMorning(), $rules);

        $this->assertSame(['2026-06-02 10:30', '2026-06-02 11:00'], $this->asLocal($engine->findSlots(
            $this->utc('2026-06-01 06:00'),
            $this->utc('2026-06-01 06:00'),
            $this->utc('2026-06-30 00:00')
        )));
    }

    public function testAFixedHorizonRangeIsHonoured(): void
    {
        $engine = $this->engine(
            $this->tuesdayMorning(),
            new SlotRules(
                60,
                60,
                0,
                0,
                0,
                0,
                $this->utc('2026-06-02 08:30'),
                $this->utc('2026-06-02 09:30')
            )
        );

        $slots = $engine->findSlots(
            $this->utc('2026-06-01 06:00'),
            $this->utc('2026-06-01 06:00'),
            $this->utc('2026-06-30 00:00')
        );

        // The grid of the window is 10:00 and 11:00 local, that is 08:00 and
        // 09:00 UTC, and only the second one starts inside the fixed horizon.
        // The horizon must not move the grid: a window cut at 08:30 UTC would
        // offer 10:30, a time this schedule never contained.
        $this->assertSame(['2026-06-02 11:00'], $this->asLocal($slots));
    }

    public function testADayThatReachedTheDailyCapDisappearsCompletely(): void
    {
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(20, 30, 0, 0, 0, 30, null, null, 2));

        $slots = $this->asLocal($engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-11 00:00'),
            [],
            [
                $this->utc('2026-06-02 08:00'),
                $this->utc('2026-06-02 08:30'),
            ]
        ));

        // Not merely the next start: the whole day is gone, while the following
        // Tuesday is untouched.
        $this->assertSame(
            ['2026-06-09 10:00', '2026-06-09 10:30', '2026-06-09 11:00', '2026-06-09 11:30'],
            $slots
        );
    }

    public function testAWeekThatReachedTheWeeklyCapLosesItsRemainingDays(): void
    {
        $engine = $this->engine(
            [
                ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '11:00'],
                ['weekday' => 4, 'start_time' => '10:00', 'end_time' => '11:00'],
            ],
            new SlotRules(60, 60, 0, 0, 0, 30, null, null, 0, 1)
        );

        $slots = $this->asLocal($engine->findSlots(
            $this->utc('2026-06-01 06:00'),
            $this->utc('2026-06-01 06:00'),
            $this->utc('2026-06-11 00:00'),
            [],
            // One booking on Tuesday 2 June, ISO week 23.
            [$this->utc('2026-06-02 08:00')]
        ));

        // Thursday 4 June is in the same week and disappears as well; Tuesday
        // 9 June is week 24 and stays. The week starts on Monday in the
        // schedule's own zone.
        $this->assertSame(['2026-06-09 10:00'], $slots);
    }

    public function testAWindowCrossingMidnightYieldsSlotsOnBothSides(): void
    {
        $engine = $this->engine(
            [['weekday' => 2, 'start_time' => '22:00', 'end_time' => '02:00']],
            new SlotRules(60, 60)
        );

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-04 06:00')
        );

        $this->assertSame(
            ['2026-06-02 22:00', '2026-06-02 23:00', '2026-06-03 00:00', '2026-06-03 01:00'],
            $this->asLocal($slots)
        );
    }

    public function testSpringForwardOffersNoSlotAtALocalTimeThatDoesNotExist(): void
    {
        $engine = $this->engine(
            [['weekday' => 7, 'start_time' => '01:00', 'end_time' => '05:00']],
            new SlotRules(60, 60)
        );

        $slots = $this->asLocal($engine->findSlots(
            $this->utc('2026-03-28 00:00'),
            $this->utc('2026-03-28 00:00'),
            $this->utc('2026-03-30 00:00')
        ));

        $this->assertSame(
            ['2026-03-29 01:00', '2026-03-29 03:00', '2026-03-29 04:00'],
            $slots
        );
        $this->assertNotContains('2026-03-29 02:00', $slots);
    }

    public function testAutumnBackOffersTheRepeatedHourOnce(): void
    {
        $engine = $this->engine(
            [['weekday' => 7, 'start_time' => '01:00', 'end_time' => '05:00']],
            new SlotRules(60, 60)
        );

        $slots = $engine->findSlots(
            $this->utc('2026-10-24 00:00'),
            $this->utc('2026-10-24 00:00'),
            $this->utc('2026-10-26 00:00')
        );

        $local = $this->asLocal($slots);

        $this->assertSame(
            ['2026-10-25 01:00', '2026-10-25 02:00', '2026-10-25 03:00', '2026-10-25 04:00'],
            $local
        );

        // Five real hours, four slots: the wall-clock hour that happened twice
        // is offered once, and the two instants are an hour apart in UTC.
        $this->assertSame('2026-10-24 23:00', $slots[0]->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 00:00', $slots[1]->format('Y-m-d H:i'));
        $this->assertSame('2026-10-25 02:00', $slots[2]->format('Y-m-d H:i'));
    }

    public function testTheGridKeepsItsPhaseAcrossTheAutumnTransition(): void
    {
        // A step that does not divide an hour makes the phase visible. The grid
        // starts at 01:00 local, and after the repeated hour is skipped it has
        // to continue on the same phase rather than restarting at the
        // transition.
        $engine = $this->engine(
            [['weekday' => 7, 'start_time' => '01:00', 'end_time' => '05:00']],
            new SlotRules(40, 40)
        );

        $slots = $this->asLocal($engine->findSlots(
            $this->utc('2026-10-24 00:00'),
            $this->utc('2026-10-24 00:00'),
            $this->utc('2026-10-26 00:00')
        ));

        $this->assertSame(
            [
                '2026-10-25 01:00',
                '2026-10-25 01:40',
                '2026-10-25 02:20',
                // The repeated hour is skipped, and the next start is still on
                // the twenty-minute phase the rule began with.
                '2026-10-25 03:20',
                '2026-10-25 04:00',
            ],
            $slots
        );
    }

    public function testTheGridKeepsItsPhaseNextToAGovernedDay(): void
    {
        // The Tuesday night rule reaches into a closed Wednesday. Cutting the
        // window would be the easy answer and would move the grid; the engine
        // instead removes the closed day as an unavailable period, so the
        // offered times stay on the rule's own phase.
        $engine = $this->engine(
            [['weekday' => 2, 'start_time' => '22:00', 'end_time' => '02:00']],
            new SlotRules(50, 50),
            [['specific_date' => '2026-06-03', 'start_time' => '00:00', 'end_time' => '00:00', 'is_unavailable' => 1]]
        );

        $slots = $this->asLocal($engine->findSlots(
            $this->utc('2026-06-02 00:00'),
            $this->utc('2026-06-02 00:00'),
            $this->utc('2026-06-04 00:00')
        ));

        // 22:00 and 22:50 fit before midnight; 23:40 would run into the closed
        // Wednesday and is dropped.
        $this->assertSame(['2026-06-02 22:00', '2026-06-02 22:50'], $slots);
    }

    public function testADateSpecificOverrideReplacesTheWeekdayRules(): void
    {
        $engine = $this->engine(
            $this->tuesdayMorning(),
            new SlotRules(60, 60),
            [['specific_date' => '2026-06-02', 'start_time' => '15:00', 'end_time' => '16:00']]
        );

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00')
        );

        $this->assertSame(['2026-06-02 15:00'], $this->asLocal($slots));
    }

    public function testAnOverlappingNightCannotAddStartsToAnOverrideGrid(): void
    {
        foreach ([false, true] as $previousOverride) {
            $night = ['start_time' => '22:00', 'end_time' => '02:00'];
            $weekly = $previousOverride ? [] : [array_merge($night, ['weekday' => 2])];
            $overrides = [['specific_date' => '2026-06-03', 'start_time' => '00:15', 'end_time' => '02:00']];

            if ($previousOverride) {
                $overrides[] = array_merge($night, ['specific_date' => '2026-06-02']);
            }

            $engine = $this->engine($weekly, new SlotRules(15, 30), $overrides);
            $now = $this->utc('2026-06-02 00:00');
            $wide = $this->asLocal($engine->findSlots($now, $now, $this->utc('2026-06-04 00:00')));
            $narrow = $this->asLocal($engine->findSlots(
                $now,
                $this->utc('2026-06-02 22:00'),
                $this->utc('2026-06-03 00:00')
            ));
            $expected = ['2026-06-03 00:15', '2026-06-03 00:45', '2026-06-03 01:15', '2026-06-03 01:45'];

            $this->assertSame($expected, array_values(array_filter(
                $wide,
                static fn (string $slot): bool => str_starts_with($slot, '2026-06-03 ')
            )));
            $this->assertSame($expected, $narrow);
        }
    }

    public function testAnOverrideGridPreservesMeetingsStartingBeforeMidnight(): void
    {
        $engine = $this->engine(
            [['weekday' => 2, 'start_time' => '22:10', 'end_time' => '02:00']],
            new SlotRules(45, 30),
            [['specific_date' => '2026-06-03', 'start_time' => '00:00', 'end_time' => '02:00']]
        );
        $now = $this->utc('2026-06-02 00:00');

        $this->assertSame(
            [
                '2026-06-02 22:10', '2026-06-02 22:40', '2026-06-02 23:10', '2026-06-02 23:40',
                '2026-06-03 00:00', '2026-06-03 00:30', '2026-06-03 01:00',
            ],
            $this->asLocal($engine->findSlots($now, $now, $this->utc('2026-06-04 00:00')))
        );
    }

    public function testAnOverrideGridKeepsItsOwnPhaseAcrossRepeatedTime(): void
    {
        $engine = $this->engine(
            [['weekday' => 6, 'start_time' => '22:00', 'end_time' => '05:00']],
            new SlotRules(20, 40),
            [['specific_date' => '2026-10-25', 'start_time' => '01:15', 'end_time' => '05:00']]
        );
        $now = $this->utc('2026-10-24 00:00');
        $slots = $this->asLocal($engine->findSlots(
            $now,
            $this->utc('2026-10-24 22:00'),
            $this->utc('2026-10-25 05:00')
        ));

        $this->assertSame(
            ['2026-10-25 01:15', '2026-10-25 01:55', '2026-10-25 02:35', '2026-10-25 03:35', '2026-10-25 04:15'],
            $slots
        );
    }

    public function testAnUnavailableDateYieldsNoSlots(): void
    {
        $engine = $this->engine(
            $this->tuesdayMorning(),
            new SlotRules(60, 60),
            [['specific_date' => '2026-06-02', 'start_time' => '00:00', 'end_time' => '00:00', 'is_unavailable' => 1]]
        );

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00')
        );

        $this->assertSame([], $slots);
    }

    public function testAMeetingLongerThanTheWindowYieldsNothing(): void
    {
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(180, 30));

        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00')
        );

        $this->assertSame([], $slots);
    }

    public function testAnEmptyRangeYieldsNothing(): void
    {
        $engine = $this->engine($this->tuesdayMorning(), new SlotRules(20, 30));

        // The whole window lies before the requested range.
        $slots = $engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 12:00'),
            $this->utc('2026-06-02 13:00')
        );

        $this->assertSame([], $slots);
    }

    public function testTheOfferedTimesOfADayDoNotDependOnTheQueriedRange(): void
    {
        // A schedule that is open around the clock, and a step that does not
        // divide a day. The booking page asks for a month, the booking guard
        // re-checks a single day, and both have to see the same times for that
        // day: a slot the visitor was offered must still exist when the
        // submission is validated.
        $roundTheClock = [];

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $roundTheClock[] = ['weekday' => $weekday, 'start_time' => '00:00', 'end_time' => '00:00'];
        }

        $engine = $this->engine($roundTheClock, new SlotRules(50, 50));

        $now        = $this->utc('2026-06-01 00:00');
        $narrowFrom = $this->utc('2026-06-10 00:00');
        $narrowTo   = $this->utc('2026-06-11 00:00');

        $asUtc = static function (array $slots): array {
            return array_map(
                static function (\DateTimeImmutable $slot): string {
                    return $slot->format('Y-m-d H:i');
                },
                $slots
            );
        };

        $broad  = $engine->findSlots($now, $this->utc('2026-06-02 00:00'), $this->utc('2026-06-20 00:00'));
        $narrow = $engine->findSlots($now, $narrowFrom, $narrowTo);

        // The narrow answer has to be exactly the broad answer restricted to the
        // narrow range. Anything else means a start the visitor was offered no
        // longer exists when the submission is re-checked, or the other way
        // round.
        $restricted = array_values(
            array_filter(
                $broad,
                static function (\DateTimeImmutable $slot) use ($narrowFrom, $narrowTo): bool {
                    return $slot >= $narrowFrom && $slot < $narrowTo;
                }
            )
        );

        $this->assertNotSame([], $restricted);
        $this->assertSame($asUtc($restricted), $asUtc($narrow));
    }

    public function testSlotsAreOrderedAndFreeOfDuplicates(): void
    {
        // Two rules that overlap must not offer the same start twice.
        $engine = $this->engine(
            [
                ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00'],
                ['weekday' => 2, 'start_time' => '11:00', 'end_time' => '13:00'],
            ],
            new SlotRules(60, 60)
        );

        $slots = $this->asLocal($engine->findSlots(
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-02 06:00'),
            $this->utc('2026-06-03 06:00')
        ));

        $this->assertSame(
            ['2026-06-02 10:00', '2026-06-02 11:00', '2026-06-02 12:00'],
            $slots
        );
        $this->assertSame($slots, array_values(array_unique($slots)));
    }
}
