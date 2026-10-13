<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow;
use Advans\Component\Appointments\Administrator\Helper\BookingGuard;
use Advans\Component\Appointments\Administrator\Helper\BusyPeriod;
use Advans\Component\Appointments\Administrator\Helper\ScheduleResolver;
use Advans\Component\Appointments\Administrator\Helper\SlotEngine;
use Advans\Component\Appointments\Administrator\Helper\SlotRules;
use PHPUnit\Framework\TestCase;

final class BookingGuardTest extends TestCase
{
    public function testRechecksGridBusyPeriodsCapsAndNotice(): void
    {
        $utc = new \DateTimeZone('UTC');
        $now = new \DateTimeImmutable('2026-11-02 08:00:00', $utc);
        $start = new \DateTimeImmutable('2026-11-02 10:00:00', $utc);
        $resolver = new ScheduleResolver('UTC', [['weekday' => 1, 'start_time' => '10:00', 'end_time' => '12:00']]);
        $engine = new SlotEngine($resolver, new SlotRules(20, 30, 0, 0, 30, 30, null, null, 1));
        $guard = new BookingGuard();
        self::assertTrue($guard->accepts($engine, $start, $now, [], []));
        self::assertFalse($guard->accepts($engine, $start->modify('+1 minute'), $now, [], []));
        self::assertFalse($guard->accepts($engine, $start, $start->modify('-29 minutes'), [], []));
        self::assertFalse($guard->accepts($engine, $start, $now, [], [$start->modify('+1 hour')]));
        $busy = BusyPeriod::forBooking(new AvailabilityWindow($start->modify('-10 minutes'), $start->modify('+10 minutes')), new SlotRules(20, 30));
        self::assertFalse($guard->accepts($engine, $start, $now, [$busy], []));
        self::assertFalse($guard->accepts($engine, $start, $start, [], []));
    }
}
