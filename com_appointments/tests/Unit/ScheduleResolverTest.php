<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow;
use Advans\Component\Appointments\Administrator\Helper\ScheduleResolver;
use PHPUnit\Framework\TestCase;

/**
 * The clock changes of Europe/Zurich in 2026, taken from the time-zone database
 * and used by the daylight-saving tests:
 *
 * - Sunday 29 March, 01:00 UTC: 02:00 CET becomes 03:00 CEST. Local 02:00 to
 *   03:00 never happens.
 * - Sunday 25 October, 01:00 UTC: 03:00 CEST becomes 02:00 CET. Local 02:00 to
 *   03:00 happens twice, and the second pass is 01:00 to 02:00 UTC.
 */
final class ScheduleResolverTest extends TestCase
{
    private const ZONE = 'Europe/Zurich';

    /**
     * @param  AvailabilityWindow[]  $windows
     *
     * @return string[]  `Y-m-d H:i` to `H:i`, in UTC.
     */
    private function asStrings(array $windows): array
    {
        return array_map(
            static function (AvailabilityWindow $window): string {
                return $window->getStart()->format('Y-m-d H:i') . '-' . $window->getEnd()->format('Y-m-d H:i');
            },
            $windows
        );
    }

    /**
     * @return string[]  The local wall-clock times the windows cover, hour by hour.
     */
    private function localHours(array $windows, string $zone = self::ZONE): array
    {
        $hours = [];

        foreach ($windows as $window) {
            $cursor = $window->getStart();

            while ($cursor < $window->getEnd()) {
                $hours[] = $cursor->setTimezone(new \DateTimeZone($zone))->format('H:i T');
                $cursor  = $cursor->modify('+1 hour');
            }
        }

        return $hours;
    }

    private function resolveDate(string $date, array $weekly, array $overrides = []): array
    {
        return (new ScheduleResolver(self::ZONE, $weekly, $overrides))->windowsForDate($date);
    }

    /**
     * The windows and the periods the schedule makes unavailable, as the slot
     * engine receives them: whole windows plus what has to be taken out of
     * them.
     *
     * @return array{0:string[], 1:string[]}
     */
    private function resolveRange(ScheduleResolver $resolver, string $fromUtc, string $toUtc): array
    {
        $utc  = new \DateTimeZone('UTC');
        $from = new \DateTimeImmutable($fromUtc, $utc);
        $to   = new \DateTimeImmutable($toUtc, $utc);

        return [
            $this->asStrings($resolver->resolve($from, $to)),
            $this->asStrings($resolver->unavailablePeriods($from, $to)),
        ];
    }

    public function testAnUnknownTimeZoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver('Mars/Olympus_Mons', []);
    }

    /**
     * @dataProvider zonesThatAreNotIanaNames
     */
    public function testAZoneThatIsNotAnIanaNameIsRejected(string $zone): void
    {
        // PHP accepts all of these and gives each a fixed offset with no
        // transitions, so a schedule on one of them would keep one offset all
        // year and offer every slot an hour out for half of it. The schedule
        // form offers only IANA names, but the constructor is public and also
        // sees imported and hand-edited rows.
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver($zone, []);
    }

    /**
     * The comment on each case says why the name is a trap rather than a
     * convenience.
     *
     * @return array<string, array{0:string}>
     */
    public static function zonesThatAreNotIanaNames(): array
    {
        return [
            // An abbreviation has no daylight-saving rules.
            'abbreviation'     => ['CET'],
            // A summer-time abbreviation is a fixed offset all year.
            'summer time name' => ['CEST'],
            // An offset is not a zone.
            'bare offset'      => ['+01:00'],
            // A backwards-compatible name, which PHP does not list either.
            'legacy name'      => ['EST'],
        ];
    }

    public function testTheZoneOfASwissScheduleIsAccepted(): void
    {
        // The counter-test to the one above: the names the form really offers
        // have to keep working, including the ones without a region.
        $this->assertSame(self::ZONE, (new ScheduleResolver(self::ZONE, []))->getTimezone()->getName());
        $this->assertSame('UTC', (new ScheduleResolver('UTC', []))->getTimezone()->getName());
    }

    public function testADateBelowTheSupportedYearRangeIsRejected(): void
    {
        // The year 1 exists in the calendar but not in the range the DATE
        // column documents, so a rule carrying it cannot have come from a
        // schedule that was saved through the component.
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver(
            self::ZONE,
            [],
            [['specific_date' => '0001-01-01', 'start_time' => '10:00', 'end_time' => '11:00']]
        );
    }

    public function testAWeekdayOutsideTheIsoRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver(self::ZONE, [['weekday' => 8, 'start_time' => '10:00', 'end_time' => '11:00']]);
    }

    public function testADateThatDoesNotExistIsRejected(): void
    {
        // 2026-02-31 has the right shape. Accepting it would either move the
        // override to the 3rd of March or, because the key never matches a real
        // date, drop it without a word.
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver(
            self::ZONE,
            [],
            [['specific_date' => '2026-02-31', 'start_time' => '10:00', 'end_time' => '11:00']]
        );
    }

    public function testADateWithTheWrongShapeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver(
            self::ZONE,
            [],
            [['specific_date' => '31.02.2026', 'start_time' => '10:00', 'end_time' => '11:00']]
        );
    }

    public function testALeapDayIsAccepted(): void
    {
        // 2028 is a leap year, so the 29th of February exists and must not be
        // caught by the check above.
        $resolver = new ScheduleResolver(
            self::ZONE,
            [],
            [['specific_date' => '2028-02-29', 'start_time' => '10:00', 'end_time' => '11:00']]
        );

        $this->assertSame(
            ['2028-02-29 09:00-2028-02-29 10:00'],
            $this->asStrings($resolver->windowsForDate('2028-02-29'))
        );
    }

    public function testAnUnusableTimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver(self::ZONE, [['weekday' => 2, 'start_time' => '25:00', 'end_time' => '26:00']]);
    }

    public function testAWhollySkippedDateOffersNeitherDaytimeNorOvernightOverrides(): void
    {
        foreach ([['10:00', '12:00'], ['22:00', '02:00']] as [$start, $end]) {
            $resolver = new ScheduleResolver('Pacific/Apia', [], [
                ['specific_date' => '2011-12-30', 'start_time' => $start, 'end_time' => $end],
            ]);

            $this->assertSame([], $resolver->windowsForDate('2011-12-30'));
            [$windows, $unavailable] = $this->resolveRange($resolver, '2011-12-29 00:00', '2012-01-01 00:00');
            $this->assertSame([], $windows);
            $this->assertSame([], $unavailable);
        }
    }

    public function testAWeeklyRuleOnASkippedDateDoesNotMoveToTheNextDate(): void
    {
        $resolver = new ScheduleResolver('Pacific/Apia', [
            ['weekday' => 5, 'start_time' => '10:00', 'end_time' => '12:00'],
            ['weekday' => 6, 'start_time' => '14:00', 'end_time' => '16:00'],
        ]);

        $this->assertSame([], $resolver->windowsForDate('2011-12-30'));
        $this->assertSame(
            ['2011-12-31 00:00-2011-12-31 02:00'],
            $this->asStrings($resolver->windowsForDate('2011-12-31'))
        );
    }

    public function testAnOvernightRuleEndsAtTheTransitionIntoASkippedDate(): void
    {
        $resolver = new ScheduleResolver('Pacific/Apia', [
            ['weekday' => 4, 'start_time' => '22:00', 'end_time' => '02:00'],
        ]);

        $this->assertSame(
            ['2011-12-30 08:00-2011-12-30 10:00'],
            $this->asStrings($resolver->windowsForDate('2011-12-29'))
        );
    }

    public function testAWeekdayRuleBecomesAUtcWindow(): void
    {
        // Tuesday 2 June 2026, summer time, so local 10:00 is 08:00 UTC.
        $windows = $this->resolveDate('2026-06-02', [
            ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00'],
        ]);

        $this->assertSame(['2026-06-02 08:00-2026-06-02 10:00'], $this->asStrings($windows));
    }

    public function testAWeekdayWithoutARuleIsUnavailable(): void
    {
        $windows = $this->resolveDate('2026-06-03', [
            ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00'],
        ]);

        $this->assertSame([], $windows);
    }

    public function testADateSpecificRuleReplacesTheWeekdayRulesEntirely(): void
    {
        $windows = $this->resolveDate(
            '2026-06-02',
            [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00']],
            [['specific_date' => '2026-06-02', 'start_time' => '15:00', 'end_time' => '16:00']]
        );

        // The weekday window is gone, not extended.
        $this->assertSame(['2026-06-02 13:00-2026-06-02 14:00'], $this->asStrings($windows));
    }

    public function testAnUnavailableDateYieldsNothing(): void
    {
        $windows = $this->resolveDate(
            '2026-06-02',
            [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00']],
            [['specific_date' => '2026-06-02', 'start_time' => '00:00', 'end_time' => '00:00', 'is_unavailable' => 1]]
        );

        $this->assertSame([], $windows);
    }

    public function testAClosedAllDayRowWinsOverOtherRowsForTheSameDate(): void
    {
        $windows = $this->resolveDate(
            '2026-06-02',
            [['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00']],
            [
                ['specific_date' => '2026-06-02', 'start_time' => '15:00', 'end_time' => '16:00'],
                ['specific_date' => '2026-06-02', 'start_time' => '00:00', 'end_time' => '00:00', 'is_unavailable' => 1],
            ]
        );

        $this->assertSame([], $windows);
    }

    public function testAWindowCrossingMidnightStaysOneWindow(): void
    {
        $windows = $this->resolveDate('2026-06-02', [
            ['weekday' => 2, 'start_time' => '22:00', 'end_time' => '02:00'],
        ]);

        // 22:00 local Tuesday to 02:00 local Wednesday, as one window.
        $this->assertSame(['2026-06-02 20:00-2026-06-03 00:00'], $this->asStrings($windows));
    }

    public function testTouchingRulesOfOneDayAreMerged(): void
    {
        $windows = $this->resolveDate('2026-06-02', [
            ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '11:00'],
            ['weekday' => 2, 'start_time' => '11:00', 'end_time' => '12:00'],
        ]);

        $this->assertSame(['2026-06-02 08:00-2026-06-02 10:00'], $this->asStrings($windows));
    }

    public function testSpringForwardShortensAWindowThatSpansTheGap(): void
    {
        $windows = $this->resolveDate('2026-03-29', [
            ['weekday' => 7, 'start_time' => '01:00', 'end_time' => '05:00'],
        ]);

        // Four nominal hours, three real ones: 01:00 CET is 00:00 UTC and
        // 05:00 CEST is 03:00 UTC.
        $this->assertSame(['2026-03-29 00:00-2026-03-29 03:00'], $this->asStrings($windows));
        $this->assertSame(
            ['01:00 CET', '03:00 CEST', '04:00 CEST'],
            $this->localHours($windows)
        );
    }

    public function testSpringForwardDropsARuleThatLiesInsideTheGap(): void
    {
        $windows = $this->resolveDate('2026-03-29', [
            ['weekday' => 7, 'start_time' => '02:15', 'end_time' => '02:45'],
        ]);

        // Neither bound ever happened, so there is no availability at all.
        $this->assertSame([], $windows);
    }

    public function testSpringForwardMovesAStartInsideTheGapToTheTransition(): void
    {
        $windows = $this->resolveDate('2026-03-29', [
            ['weekday' => 7, 'start_time' => '02:30', 'end_time' => '04:00'],
        ]);

        // The window starts when the clocks moved, 03:00 CEST, not at a local
        // 03:30 that PHP would have produced by shifting the nominal 02:30.
        $this->assertSame(['2026-03-29 01:00-2026-03-29 02:00'], $this->asStrings($windows));
        $this->assertSame(['03:00 CEST'], $this->localHours($windows));
    }

    public function testAutumnBackReportsTheRepeatedHourAsUnavailable(): void
    {
        $resolver = new ScheduleResolver(self::ZONE, [
            ['weekday' => 7, 'start_time' => '01:00', 'end_time' => '05:00'],
        ]);

        [$windows, $unavailable] = $this->resolveRange($resolver, '2026-10-24 00:00', '2026-10-26 00:00');

        // The window stays whole, four nominal hours and five real ones, so the
        // slot grid keeps the phase the rule began with. The second pass over
        // 02:00 to 03:00 is reported separately and removed by the engine.
        $this->assertSame(['2026-10-24 23:00-2026-10-25 04:00'], $windows);
        $this->assertSame(['2026-10-25 01:00-2026-10-25 02:00'], $unavailable);
    }

    public function testAutumnBackReadsAnAmbiguousStartAsTheFirstOccurrence(): void
    {
        $windows = $this->resolveDate('2026-10-25', [
            ['weekday' => 7, 'start_time' => '02:30', 'end_time' => '05:00'],
        ]);

        // 02:30 happened twice. PHP resolves it to the second pass, 01:30 UTC;
        // the component uses the first, 00:30 UTC.
        $this->assertSame(['2026-10-25 00:30-2026-10-25 04:00'], $this->asStrings($windows));
        $this->assertSame(
            '02:30 CEST',
            $windows[0]->getStart()->setTimezone(new \DateTimeZone(self::ZONE))->format('H:i T')
        );
    }

    public function testABackwardTransitionAtLocalMidnightIsStillFound(): void
    {
        // America/Santiago puts the clocks back at local 2026-04-05 00:00,
        // which becomes 2026-04-04 23:00. The hour that happens twice is
        // therefore the wall clock 23:00 on Saturday the 4th, and the
        // transition instant is the moment the 5th would have begun.
        //
        // Bounds computed as noon plus and minus twelve hours missed it from
        // both sides: for the 4th the instant was exactly the computed end of
        // the day, which the comparison excluded, and for the 5th it lay before
        // the computed start. The repeated hour was reported for neither date,
        // so an ambiguous start could be offered twice.
        //
        // The expectations are arithmetic: Saturday begins at local 00:00 under
        // -03, which is 03:00 UTC, and the next local midnight falls under -04,
        // which is 04:00 UTC the following day, so the local Saturday is 25
        // hours long. The second pass over the repeated hour is the hour that
        // begins at the transition.
        $resolver = new ScheduleResolver('America/Santiago', [
            ['weekday' => 6, 'start_time' => '00:00', 'end_time' => '00:00'],
        ]);

        [$windows, $unavailable] = $this->resolveRange($resolver, '2026-04-03 00:00', '2026-04-06 00:00');

        $this->assertSame(['2026-04-04 03:00-2026-04-05 04:00'], $windows);
        $this->assertSame(['2026-04-05 03:00-2026-04-05 04:00'], $unavailable);
    }

    public function testResolveSelectsOverlappingWindowsWithoutClippingThem(): void
    {
        $resolver = new ScheduleResolver(self::ZONE, [
            ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00'],
            ['weekday' => 4, 'start_time' => '14:00', 'end_time' => '17:00'],
        ]);

        $utc     = new \DateTimeZone('UTC');
        $windows = $resolver->resolve(
            // The range begins inside the Tuesday window and ends inside the
            // Thursday one, and neither window is cut: the slot engine anchors
            // its grid to the window start, so a clipped window would move the
            // offered times off the schedule's own grid.
            new \DateTimeImmutable('2026-06-02 09:00', $utc),
            new \DateTimeImmutable('2026-06-04 13:00', $utc)
        );

        $this->assertSame(
            ['2026-06-02 08:00-2026-06-02 10:00', '2026-06-04 12:00-2026-06-04 15:00'],
            $this->asStrings($windows)
        );
    }

    public function testWindowsOfTwoDatesAreNotMerged(): void
    {
        $resolver = new ScheduleResolver(self::ZONE, [
            ['weekday' => 2, 'start_time' => '00:00', 'end_time' => '00:00'],
            ['weekday' => 3, 'start_time' => '00:00', 'end_time' => '00:00'],
        ]);

        $utc     = new \DateTimeZone('UTC');
        $windows = $resolver->resolve(
            new \DateTimeImmutable('2026-06-02 00:00', $utc),
            new \DateTimeImmutable('2026-06-04 00:00', $utc)
        );

        // Two days of round-the-clock availability stay two windows, each
        // starting at its own local midnight. Merged into one, the start of
        // that window would depend on which date the resolution began with,
        // and the slot grid would move with the queried range.
        $this->assertSame(
            ['2026-06-01 22:00-2026-06-02 22:00', '2026-06-02 22:00-2026-06-03 22:00'],
            $this->asStrings($windows)
        );
    }

    public function testResolveLeavesOutWindowsThatDoNotOverlapTheRange(): void
    {
        $resolver = new ScheduleResolver(self::ZONE, [
            ['weekday' => 2, 'start_time' => '10:00', 'end_time' => '12:00'],
        ]);

        $utc     = new \DateTimeZone('UTC');
        $windows = $resolver->resolve(
            new \DateTimeImmutable('2026-06-02 10:00', $utc),
            new \DateTimeImmutable('2026-06-02 20:00', $utc)
        );

        // The window ends exactly where the range begins, and touching is not
        // overlapping.
        $this->assertSame([], $windows);
    }

    public function testAClosedDateIsReportedAsUnavailableForItsWholeDay(): void
    {
        $resolver = new ScheduleResolver(
            self::ZONE,
            [['weekday' => 2, 'start_time' => '22:00', 'end_time' => '02:00']],
            // Wednesday 3 June is closed, so nothing of it is bookable,
            // including the hours the Tuesday night rule reaches into it.
            [['specific_date' => '2026-06-03', 'start_time' => '00:00', 'end_time' => '00:00', 'is_unavailable' => 1]]
        );

        [$windows, $unavailable] = $this->resolveRange($resolver, '2026-06-02 00:00', '2026-06-04 00:00');

        // The window is whole, and the closed Wednesday is reported as a period
        // to remove: local midnight to local midnight, 22:00 UTC to 22:00 UTC.
        $this->assertSame(['2026-06-02 20:00-2026-06-03 00:00'], $windows);
        $this->assertSame(['2026-06-02 22:00-2026-06-03 22:00'], $unavailable);
    }

    public function testADateWithItsOwnTimesReportsTheRestOfThatDayAsUnavailable(): void
    {
        $resolver = new ScheduleResolver(
            self::ZONE,
            [['weekday' => 2, 'start_time' => '22:00', 'end_time' => '02:00']],
            // Wednesday has its own times, and a date-specific entry replaces
            // the availability of its date entirely.
            [['specific_date' => '2026-06-03', 'start_time' => '09:00', 'end_time' => '10:00']]
        );

        [$windows, $unavailable] = $this->resolveRange($resolver, '2026-06-02 00:00', '2026-06-04 00:00');

        $this->assertSame(
            ['2026-06-02 20:00-2026-06-03 00:00', '2026-06-03 07:00-2026-06-03 08:00'],
            $windows
        );
        // Everything of Wednesday its own single interval does not cover.
        $this->assertSame(
            ['2026-06-02 22:00-2026-06-03 07:00', '2026-06-03 08:00-2026-06-03 22:00'],
            $unavailable
        );
    }

    public function testAnOverrideThatCrossesMidnightKeepsItsOwnSpillover(): void
    {
        $resolver = new ScheduleResolver(
            self::ZONE,
            [],
            [['specific_date' => '2026-06-02', 'start_time' => '22:00', 'end_time' => '02:00']]
        );

        [$windows, $unavailable] = $this->resolveRange($resolver, '2026-06-02 00:00', '2026-06-04 00:00');

        // The spillover belongs to the date that governs it, so the window runs
        // from 22:00 Tuesday to 02:00 Wednesday local, and the part of Tuesday
        // before it is what the override takes away.
        $this->assertSame(['2026-06-02 20:00-2026-06-03 00:00'], $windows);
        $this->assertSame(['2026-06-01 22:00-2026-06-02 20:00'], $unavailable);
    }

    public function testResolveIncludesAWindowThatStartedOnThePreviousDay(): void
    {
        $resolver = new ScheduleResolver(self::ZONE, [
            ['weekday' => 2, 'start_time' => '22:00', 'end_time' => '02:00'],
        ]);

        $utc     = new \DateTimeZone('UTC');
        $windows = $resolver->resolve(
            new \DateTimeImmutable('2026-06-02 23:00', $utc),
            new \DateTimeImmutable('2026-06-03 06:00', $utc)
        );

        $this->assertSame(['2026-06-02 20:00-2026-06-03 00:00'], $this->asStrings($windows));
    }

    public function testTheTimeZoneIsReportedForTheEngine(): void
    {
        $resolver = new ScheduleResolver(self::ZONE, []);

        $this->assertSame(self::ZONE, $resolver->getTimezone()->getName());
    }
}
