<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Service\Team\BookingCalendarBridge;
use PHPUnit\Framework\TestCase;

$teamServiceDir = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team';
require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Helper/IcsWriter.php';
require_once $teamServiceDir . '/BookingCalendarBridgeInterface.php';
require_once $teamServiceDir . '/BookingCalendarBridge.php';

/**
 * The pure parts of the booking bridge (work package E): the busy cache
 * intervals, the cache window and the calendar copy of a booking.
 */
final class TimingBridgeTest extends TestCase
{
    public function testCalendarIntervalsMergeAndBookingIntervalsStaySeparate(): void
    {
        $compact = BookingCalendarBridge::compact([
            [300, 400, 0], [100, 200, 0], [150, 250, 0], [250, 260, 0], [500, 500, 0],
            [120, 180, 7], [120, 180, 9], [50, 40, 0],
        ], 0, 1000);

        self::assertSame([[100, 260, 0], [120, 180, 7], [120, 180, 9], [300, 400, 0]], $compact);
    }

    public function testTooManyIntervalsBlockTheWholeWindow(): void
    {
        $intervals = [];

        for ($i = 0; $i <= BookingCalendarBridge::MAX_INTERVALS; $i++) {
            $intervals[] = [$i * 10, $i * 10 + 5, $i + 1];
        }

        self::assertSame([[0, 99, 0]], BookingCalendarBridge::compact($intervals, 0, 99));
    }

    public function testCacheWindowCoversWholeDaysAndRefusesLongRequests(): void
    {
        $utc = new \DateTimeZone('UTC');
        [$start, $end] = BookingCalendarBridge::cacheWindow(new \DateTimeImmutable('2027-03-10 12:00:00', $utc), new \DateTimeImmutable('2027-03-20 00:00:00', $utc));

        self::assertSame('2027-03-09 00:00:00', $start->format('Y-m-d H:i:s'));
        self::assertSame('2027-06-11 00:00:00', $end->format('Y-m-d H:i:s'));
        self::assertNull(BookingCalendarBridge::cacheWindow(new \DateTimeImmutable('2027-01-01 00:00:00', $utc), new \DateTimeImmutable('2029-06-01 00:00:00', $utc)));
    }

    public function testCalendarCopyIsTheMailEntryWithoutMethod(): void
    {
        $booking = ['id' => 7, 'uid' => str_repeat('ab', 32), 'sequence' => 2, 'state' => 0,
            'start_utc' => '2027-03-29 08:00:00', 'end_utc' => '2027-03-29 08:30:00', 'modified_utc' => '2027-03-01 10:00:00',
            'invitee_name' => 'Invitee', 'invitee_email' => 'invitee@example.test', 'guest_emails' => "guest@example.test",
            'conference_url' => null];
        $event = ['title' => 'Consultation', 'description' => '<p>First</p><p>Second</p>', 'location_type' => 'custom_text',
            'location_text' => 'Office', 'host_email' => 'host@example.test', 'host_name' => 'Host'];
        $ics = BookingCalendarBridge::objectIcs($booking, $event);

        self::assertStringNotContainsString('METHOD:', $ics);
        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        self::assertStringContainsString("\r\nUID:" . str_repeat('ab', 32) . "\r\n", $ics);
        self::assertStringContainsString("\r\nSEQUENCE:2\r\n", $ics);
        self::assertStringContainsString("\r\nSTATUS:TENTATIVE\r\n", $ics);
        self::assertStringContainsString("\r\nDTSTART:20270329T080000Z\r\n", $ics);
        self::assertStringContainsString('ATTENDEE:mailto:guest@example.test', $ics);
        self::assertStringContainsString('DESCRIPTION:First\nSecond', $ics);
        self::assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
    }
}
