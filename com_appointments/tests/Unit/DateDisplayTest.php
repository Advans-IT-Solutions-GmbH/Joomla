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

    public function testWeekdayHeadingsStartOnMonday(): void
    {
        $days = $this->german()->weekdays();
        self::assertCount(7, $days);
        self::assertSame(['short' => 'Mo', 'full' => 'Montag'], $days[0]);
        self::assertSame(['short' => 'So', 'full' => 'Sonntag'], $days[6]);
    }
}
