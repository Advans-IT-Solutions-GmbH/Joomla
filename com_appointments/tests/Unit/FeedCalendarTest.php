<?php

use Advans\Component\Appointments\Administrator\Helper\FeedCalendar;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use PHPUnit\Framework\TestCase;

final class FeedCalendarTest extends TestCase
{
    public function testMalformedCredentialsNeverAuthenticate(): void
    {
        $token = str_repeat('a', 64);
        self::assertTrue(FeedCalendar::verify($token, $token));
        foreach (['', 'abc', str_repeat('g', 64), strtoupper($token), $token . "\n", str_repeat('0', 63), str_repeat('0', 65)] as $wrong) {
            self::assertFalse(FeedCalendar::verify($token, $wrong));
        }
        self::assertFalse(FeedCalendar::verify('', str_repeat('0', 64)));
        self::assertFalse(FeedCalendar::verify('bad', str_repeat('0', 64)));
    }

    public function testRollingHorizonMatchesCalendarDaysAcrossDst(): void
    {
        $now = new DateTimeImmutable('2026-03-28 12:00:00', new DateTimeZone('UTC'));
        $event = ['duration_minutes' => 30, 'slot_step_minutes' => 30, 'horizon_days' => 2];
        self::assertSame('2026-03-30 22:00:00',
            FeedCalendar::horizon($event, 'Europe/Zurich', $now)->format('Y-m-d H:i:s'));
        $later = $now->modify('+1 hour');
        self::assertEquals(
            FeedCalendar::horizon($event, 'Europe/Zurich', $now),
            FeedCalendar::horizon($event, 'Europe/Zurich', $later)
        );
    }

    public function testFixedHorizonCanEndInThePastForHistory(): void
    {
        $now = new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('UTC'));
        $end = FeedCalendar::horizon(['duration_minutes' => 30, 'slot_step_minutes' => 30, 'horizon_days' => 0,
            'horizon_start_utc' => '2026-08-01 00:00:00', 'horizon_end_utc' => '2026-09-01 00:00:00'], 'UTC', $now);
        self::assertSame('2026-09-01 00:00:00', $end->format('Y-m-d H:i:s'));
    }

    public function testStrongEtagIncludesMetadataAndConfiguredWindow(): void
    {
        $a = FeedCalendar::etag('calendar', [2 => '2026-10-10 00:00:00', 1 => '2026-10-11 00:00:00']);
        self::assertMatchesRegularExpression('/^"[a-f0-9]{64}"$/D', $a);
        self::assertSame($a, FeedCalendar::etag('calendar', [1 => '2026-10-11 00:00:00', 2 => '2026-10-10 00:00:00']));
        self::assertNotSame($a, FeedCalendar::etag('changed title', [2 => '2026-10-10 00:00:00', 1 => '2026-10-11 00:00:00']));
        self::assertNotSame($a, FeedCalendar::etag('calendar', [2 => '2026-10-09 00:00:00', 1 => '2026-10-11 00:00:00']));
        self::assertTrue(FeedCalendar::matches($a, $a));
        self::assertTrue(FeedCalendar::matches('"other", W/' . $a, $a));
        self::assertTrue(FeedCalendar::matches('*', $a));
        self::assertFalse(FeedCalendar::matches('', $a));
        self::assertFalse(FeedCalendar::matches(trim($a, '"'), $a));
        self::assertFalse(FeedCalendar::matches('"other"', $a));
    }

    public function testEtagChangesWhenCancellationAgesOutWithoutDatabaseChanges(): void
    {
        $now = new DateTimeImmutable('2026-10-01 12:00:00', new DateTimeZone('UTC'));
        $end = $now->modify('+31 days');
        $entries = [['booking' => [
            'id' => 1, 'uid' => 'cancelled@example.test', 'sequence' => 1, 'state' => 2,
            'start_utc' => '2026-09-02 10:00:00', 'end_utc' => '2026-09-02 10:30:00',
            'modified_utc' => '2026-09-01 12:00:00',
        ], 'event' => ['title' => 'Calendar', 'host_email' => 'host@example.test']]];
        $writer = new IcsWriter();
        $before = $writer->feed($entries, 'Calendar', $now, $end);
        $after = $writer->feed($entries, 'Calendar', $now->modify('+1 second'), $end);
        self::assertStringContainsString('STATUS:CANCELLED', $before);
        self::assertStringNotContainsString('BEGIN:VEVENT', $after);
        self::assertNotSame(FeedCalendar::etag($before, []), FeedCalendar::etag($after, []));
        self::assertSame($before, $writer->feed($entries, 'Calendar', $now, $end));
    }
}
