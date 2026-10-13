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

    public function testAnUnknownTimeZoneIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver('Mars/Olympus_Mons', []);
    }

    public function testAWeekdayOutsideTheIsoRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver(self::ZONE, [['weekday' => 8, 'start_time' => '10:00', 'end_time' => '11:00']]);
    }

    public function testAnUnusableTimeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ScheduleResolver(self::ZONE, [['weekday' => 2, 'start_time' => '25:00', 'end_time' => '26:00']]);
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

    public function testAutumnBackOffersTheRepeatedHourOnce(): void
    {
        $windows = $this->resolveDate('2026-10-25', [
            ['weekday' => 7, 'start_time' => '01:00', 'end_time' => '05:00'],
        ]);

        // Four nominal hours, five real ones, with the second pass over
        // 02:00 to 03:00 removed.
        $this->assertSame(
            ['2026-10-24 23:00-2026-10-25 01:00', '2026-10-25 02:00-2026-10-25 04:00'],
            $this->asStrings($windows)
        );

        $hours = $this->localHours($windows);

        $this->assertSame(['01:00 CEST', '02:00 CEST', '03:00 CET', '04:00 CET'], $hours);
        // The wall-clock hour 02:00 appears exactly once.
        $this->assertSame(1, \count(array_filter($hours, static fn (string $h): bool => str_starts_with($h, '02:00'))));
    }

    public function testAutumnBackReadsAnAmbiguousStartAsTheFirstOccurrence(): void
    {
        $windows = $this->resolveDate('2026-10-25', [
            ['weekday' => 7, 'start_time' => '02:30', 'end_time' => '05:00'],
        ]);

        // 02:30 happened twice. PHP resolves it to the second pass, 01:30 UTC;
        // the component uses the first, 00:30 UTC, and the second pass is then
        // removed as usual.
        $this->assertSame(
            ['2026-10-25 00:30-2026-10-25 01:00', '2026-10-25 02:00-2026-10-25 04:00'],
            $this->asStrings($windows)
        );
        $this->assertSame(
            '02:30 CEST',
            $windows[0]->getStart()->setTimezone(new \DateTimeZone(self::ZONE))->format('H:i T')
        );
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
