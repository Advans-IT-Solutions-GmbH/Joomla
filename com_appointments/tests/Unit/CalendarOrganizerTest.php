<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\CalendarOrganizer;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/CalendarOrganizer.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/IcsWriter.php';

final class CalendarOrganizerTest extends TestCase
{
    public function testTheOptionFirstThenTheSenderNeverTheHost(): void
    {
        self::assertSame(['email' => 'calendar@example.test', 'name' => 'Site'],
            CalendarOrganizer::forHost('host@example.test', 'calendar@example.test', 'host@example.test', 'Site'));
        self::assertSame(['email' => 'mail@example.test', 'name' => 'Site'],
            CalendarOrganizer::forHost('host@example.test', '', 'mail@example.test', 'Site'), 'Empty option: the sender');
        self::assertSame(['email' => 'mail@example.test', 'name' => 'Site'],
            CalendarOrganizer::forHost('host@example.test', ' HOST@example.test ', 'mail@example.test', 'Site'), 'The host\'s own address does not count, in any case');
        self::assertSame(['email' => 'mail@example.test', 'name' => 'Site'],
            CalendarOrganizer::forHost('host@example.test', 'not an address', 'mail@example.test', 'Site'), 'An invalid option does not count');
    }

    /** The sender is the host's own address (one mailbox for site and host) and no option: none. */
    public function testNoDistinctAddress(): void
    {
        self::assertNull(CalendarOrganizer::forHost('host@example.test', '', 'Host@Example.test', 'Site'));
        self::assertNull(CalendarOrganizer::forHost('host@example.test', 'host@example.test', 'host@example.test', 'Site'));
        self::assertSame(1, CalendarOrganizer::hostsWithout(['host@example.test', 'other@example.test'], '', 'host@example.test'));
        self::assertSame(0, CalendarOrganizer::hostsWithout(['host@example.test', 'other@example.test'], 'calendar@example.test', 'host@example.test'));
    }

    /** Without one the host's copy is published: no self-invitation, no host attendee, STATUS kept. */
    public function testHostCopyWithoutOrganiserIsPublished(): void
    {
        $writer = new IcsWriter();
        $booking = ['uid' => 'u', 'sequence' => 1, 'state' => 2, 'start_utc' => '2026-11-24 13:00:00', 'end_utc' => '2026-11-24 13:20:00',
            'invitee_email' => 'invitee@example.test'];
        $event = ['title' => 'T', 'host_email' => 'host@example.test', 'publish' => true];
        $ics = $writer->attachment($booking, $event, new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC')));

        self::assertSame('PUBLISH', $writer->method($booking, $event));
        self::assertStringContainsString("METHOD:PUBLISH\r\n", $ics);
        self::assertStringContainsString("STATUS:CANCELLED\r\n", $ics);
        self::assertStringNotContainsString('PARTSTAT', $ics);
        self::assertSame('CANCEL', $writer->method($booking), 'Every other copy keeps its method');
    }
}
