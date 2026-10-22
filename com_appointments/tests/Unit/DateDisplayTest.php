<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use PHPUnit\Framework\TestCase;

final class DateDisplayTest extends TestCase
{
    private const GERMAN = [
        'MONDAY' => 'Montag', 'TUESDAY' => 'Dienstag', 'WEDNESDAY' => 'Mittwoch', 'THURSDAY' => 'Donnerstag',
        'FRIDAY' => 'Freitag', 'SATURDAY' => 'Samstag', 'SUNDAY' => 'Sonntag',
        'MON' => 'Mo', 'TUE' => 'Di', 'WED' => 'Mi', 'THU' => 'Do', 'FRI' => 'Fr', 'SAT' => 'Sa', 'SUN' => 'So',
        'OCTOBER' => 'Oktober', 'MARCH' => 'März',
    ];

    private function german(): DateDisplay
    {
        return new DateDisplay(static fn (string $key): string => self::GERMAN[$key] ?? $key);
    }

    public function testGermanMonthDayAndTimeInTheVisitorsZone(): void
    {
        // 07:00 UTC is 09:00 in Zurich on 22 October 2026 (summer time).
        $moment = (new \DateTimeImmutable('2026-10-22 07:00:00', new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone('Europe/Zurich'));
        $dates = $this->german();
        self::assertSame('Oktober 2026', $dates->format($moment, 'F Y'));
        self::assertSame('Donnerstag, 22. Oktober 2026', $dates->format($moment, 'l, j. F Y'));
        self::assertSame('09:00', $dates->format($moment, 'H:i'));
        self::assertSame('Do', $dates->format($moment, 'D'));
    }

    public function testEnglishAndFrenchPatternsUseTheirOwnOrder(): void
    {
        $moment = new \DateTimeImmutable('2026-03-05 14:30:00', new \DateTimeZone('UTC'));
        $english = new DateDisplay(static fn (string $key): string => ucfirst(strtolower($key)));
        self::assertSame('Thursday, 5 March 2026', $english->format($moment, 'l, j F Y'));
        $french = new DateDisplay(static fn (string $key): string => ['THURSDAY' => 'jeudi', 'MARCH' => 'mars'][$key] ?? $key);
        self::assertSame('jeudi 5 mars 2026 à 14:30', $french->format($moment, 'l j F Y \à H:i'));
    }

    public function testEscapesAndMultibyteTextArePreserved(): void
    {
        $moment = new \DateTimeImmutable('2026-03-05 14:30:00', new \DateTimeZone('UTC'));
        $dates = $this->german();
        self::assertSame('März – l', $dates->format($moment, 'F – \l'));
        self::assertSame('Y', $dates->format($moment, '\Y'));
    }

    public function testRepeatedHourGetsTheOffsetForSlotsOfAnotherZone(): void
    {
        // A UTC schedule seen from Zurich on 25 October 2026, when the clocks go back at 01:00 UTC.
        $zurich = new \DateTimeZone('Europe/Zurich');
        $dates = $this->german();
        $label = static fn (string $utc): string => $dates->time(
            (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($zurich), 'H:i', '%1$s (UTC%2$s)'
        );
        self::assertSame('01:30', $label('2026-10-24 23:30:00'));
        self::assertSame('02:00 (UTC+02:00)', $label('2026-10-25 00:00:00'));
        self::assertSame('02:30 (UTC+02:00)', $label('2026-10-25 00:30:00'));
        self::assertSame('02:00 (UTC+01:00)', $label('2026-10-25 01:00:00'));
        self::assertSame('02:59 (UTC+01:00)', $label('2026-10-25 01:59:00'));
        self::assertSame('03:00', $label('2026-10-25 02:00:00'));
        // Spring forward skips an hour and repeats nothing; UTC never repeats.
        self::assertSame('03:00', $label('2026-03-29 01:00:00'));
        self::assertFalse($dates->isRepeated(new \DateTimeImmutable('2026-10-25 00:30:00', new \DateTimeZone('UTC'))));
    }

    public function testSummaryRangeNamesTheEndDateOnlyAcrossMidnight(): void
    {
        $zurich = new \DateTimeZone('Europe/Zurich');
        $dates = $this->german();
        $range = static fn (string $start, string $end): string => $dates->range(
            (new \DateTimeImmutable($start, new \DateTimeZone('UTC')))->setTimezone($zurich),
            (new \DateTimeImmutable($end, new \DateTimeZone('UTC')))->setTimezone($zurich),
            'H:i', 'l, j. F Y', '%1$s (UTC%2$s)', '%1$s – %2$s', '%1$s, %2$s'
        );
        // Confirmation and manage summaries share this helper.
        self::assertSame('09:00 – 09:30', $range('2026-10-22 07:00:00', '2026-10-22 07:30:00'));
        self::assertSame('23:30 – Freitag, 23. Oktober 2026, 00:30', $range('2026-10-22 21:30:00', '2026-10-22 22:30:00'));
        // Across midnight in Zurich although the UTC date stays the same, and into the repeated hour.
        self::assertSame('23:30 – Sonntag, 25. Oktober 2026, 02:30 (UTC+02:00)', $range('2026-10-24 21:30:00', '2026-10-25 00:30:00'));
    }

    public function testWeekdayHeadingsStartOnMonday(): void
    {
        $days = $this->german()->weekdays();
        self::assertCount(7, $days);
        self::assertSame(['short' => 'Mo', 'full' => 'Montag'], $days[0]);
        self::assertSame(['short' => 'So', 'full' => 'Sonntag'], $days[6]);
    }
}
