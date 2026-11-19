<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\RescheduleSlots;
use PHPUnit\Framework\TestCase;

final class RescheduleSlotsTest extends TestCase
{
    public function testTheRepeatedAutumnClockTimeKeepsTheOtherUtcInstant(): void
    {
        $utc = new \DateTimeZone('UTC');
        $zone = new \DateTimeZone('Europe/Zurich');
        $current = new \DateTimeImmutable('2027-10-31 00:30:00', $utc);
        $repeated = new \DateTimeImmutable('2027-10-31 01:30:00', $utc);

        self::assertSame($current->setTimezone($zone)->format('Y-m-d H:i:s'), $repeated->setTimezone($zone)->format('Y-m-d H:i:s'));
        self::assertNotSame($current->getTimestamp(), $repeated->getTimestamp());
        self::assertSame(
            ['2027-10-31' => [$repeated]],
            RescheduleSlots::byLocalDay([$current, $repeated], $current, $zone)
        );
    }
}
