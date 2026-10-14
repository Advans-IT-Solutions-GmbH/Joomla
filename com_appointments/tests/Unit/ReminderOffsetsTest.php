<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\ReminderOffsets;
use PHPUnit\Framework\TestCase;

final class ReminderOffsetsTest extends TestCase
{
    public function testOffsetsAreSortedDeduplicatedAndMayBeDisabled(): void
    {
        self::assertSame([1440, 60], ReminderOffsets::parse('60, 1440,60'));
        self::assertSame([], ReminderOffsets::parse(' '));
    }

    public function testInvalidOffsetsFailExplicitly(): void
    {
        foreach (['0', '-1', '1.5', '60,', '525601', '1e3', '92233720368547758070'] as $value) {
            try {
                ReminderOffsets::parse($value);
                self::fail('Invalid reminder offset accepted.');
            } catch (\InvalidArgumentException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }

    public function testDueOffsetsAreIndependentIdempotentAndOnlyBeforeStart(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC'));
        self::assertSame([1440, 60], ReminderOffsets::due('2026-01-01 11:00:00', $now, [1440, 60], []));
        self::assertSame([60], ReminderOffsets::due('2026-01-01 11:00:00', $now, [1440, 60], [1440 => '2026-01-01 09:00:00']));
        self::assertSame([], ReminderOffsets::due('2026-01-01 10:00:00', $now, [1440, 60], []));
        self::assertSame([], ReminderOffsets::due('2026-01-01 09:00:00', $now, [1440, 60], []));
        self::assertSame([], ReminderOffsets::due('2026-01-02 10:00:01', $now, [1440, 60], []));
    }

    public function testMarkersContainOnlyOffsetsAndStrictUtcDates(): void
    {
        self::assertSame([], ReminderOffsets::markers(null));
        self::assertSame([60 => '2026-01-01 10:00:00'], ReminderOffsets::markers('{"60":"2026-01-01 10:00:00"}'));
        foreach (['null', '"sent"', '{"0":"2026-01-01 10:00:00"}', '{"060":"2026-01-01 10:00:00"}',
            '{"525601":"2026-01-01 10:00:00"}', '{"60":"2026-02-31 10:00:00"}', '{"email":"private"}'] as $value) {
            try {
                ReminderOffsets::markers($value);
                self::fail('Invalid reminder delivery record accepted.');
            } catch (\UnexpectedValueException $e) {
                self::assertNotEmpty($e->getMessage());
            }
        }
    }
}
