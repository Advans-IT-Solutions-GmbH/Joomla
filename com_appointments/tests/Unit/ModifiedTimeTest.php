<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\ModifiedTime;
use PHPUnit\Framework\TestCase;

final class ModifiedTimeTest extends TestCase
{
    public function testSameSecondAndEarlierClockStillInvalidateOldLinks(): void
    {
        $now = new \DateTimeImmutable('2026-11-02 12:00:00', new \DateTimeZone('UTC'));
        self::assertSame('2026-11-02 12:00:01', ModifiedTime::next('2026-11-02 12:00:00', $now));
        self::assertSame('2026-11-02 12:00:01', ModifiedTime::next('2026-11-02 12:00:00', $now->modify('-1 minute')));
        self::assertSame('2026-11-02 12:01:00', ModifiedTime::next('2026-11-02 12:00:00', $now->modify('+1 minute')));
        self::assertSame('2026-11-02 12:00:01', ModifiedTime::next('2026-11-02 12:00:00', $now->modify('+500000 microseconds')));
    }
}
