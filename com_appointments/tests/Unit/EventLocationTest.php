<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\EventLocation;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/EventLocation.php';
require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/IcsWriter.php';

final class EventLocationTest extends TestCase
{
    /** German strings of the language files, so the test reads like the mail. */
    private const STRINGS = [
        'COM_APPOINTMENTS_MAIL_LOCATION_PHONE_HOST' => 'Telefon, Sie werden angerufen',
        'COM_APPOINTMENTS_MAIL_LOCATION_PHONE_INVITEE' => 'Telefon, Sie rufen an',
        'COM_APPOINTMENTS_LOCATION_PHONE_HOST' => 'Telefon: Gastgeber ruft an',
        'COM_APPOINTMENTS_LOCATION_PHONE_INVITEE' => 'Telefon: Gast ruft an',
        'COM_APPOINTMENTS_LOCATION_TYPE_PHONE_HOST' => 'Telefon, Sie werden angerufen',
        'COM_APPOINTMENTS_LOCATION_TYPE_PHONE_INVITEE' => 'Telefon, Sie rufen an',
    ];

    public static function translate(string $key): string
    {
        return self::STRINGS[$key] ?? $key;
    }

    public static function cases(): array
    {
        return [
            'invitee is called' => ['phone_host_calls', '', EventLocation::INVITEE_MAIL, 'Telefon, Sie werden angerufen'],
            'invitee calls' => ['phone_invitee_calls', '', EventLocation::INVITEE_MAIL, 'Telefon, Sie rufen an'],
            'host and guests, host calls' => ['phone_host_calls', '', EventLocation::NEUTRAL, 'Telefon: Gastgeber ruft an'],
            'host and guests, invitee calls' => ['phone_invitee_calls', '  ', EventLocation::NEUTRAL, 'Telefon: Gast ruft an'],
            'confirmation page download' => ['phone_host_calls', '', EventLocation::INVITEE_SITE, 'Telefon, Sie werden angerufen'],
            'operator text wins' => ['phone_invitee_calls', ' +41 00 000 00 00 ', EventLocation::INVITEE_MAIL, '+41 00 000 00 00'],
            'in person keeps its address' => ['in_person', 'Hauptstrasse 1', EventLocation::INVITEE_MAIL, 'Hauptstrasse 1'],
            'custom text without text' => ['custom_text', '', EventLocation::INVITEE_MAIL, ''],
            'conference is left to the writer' => ['conference', '', EventLocation::NEUTRAL, ''],
            'unknown type' => ['other', '', EventLocation::NEUTRAL, ''],
        ];
    }

    #[DataProvider('cases')]
    public function testText(string $type, string $stored, array $labels, string $expected): void
    {
        self::assertSame($expected, EventLocation::text($type, $stored, $labels, [self::class, 'translate']));
    }

    public function testEveryPhoneTypeHasALabelInEveryAudience(): void
    {
        foreach ([EventLocation::INVITEE_MAIL, EventLocation::NEUTRAL, EventLocation::INVITEE_SITE] as $labels) {
            self::assertSame(['phone_host_calls', 'phone_invitee_calls'], array_keys($labels));
        }
    }

    /** The label reaches LOCATION escaped; a conference keeps its join address. */
    public function testInvitationLocation(): void
    {
        $booking = ['uid' => 'uid-1', 'sequence' => 0, 'state' => 1, 'start_utc' => '2026-11-20 09:00:00',
            'end_utc' => '2026-11-20 09:30:00', 'invitee_email' => 'invitee@example.test'];
        $event = ['title' => 'Call', 'host_email' => 'host@example.test', 'location_type' => 'phone_host_calls', 'location_text' => ''];
        $now = new \DateTimeImmutable('2026-11-16 10:00:00', new \DateTimeZone('UTC'));
        $event['location'] = EventLocation::text($event['location_type'], $event['location_text'], EventLocation::INVITEE_MAIL, [self::class, 'translate']);
        $ics = str_replace("\r\n ", '', (new IcsWriter())->attachment($booking, $event, $now));

        self::assertStringContainsString("\r\nLOCATION:Telefon\\, Sie werden angerufen\r\n", $ics);

        $event = ['location_type' => 'conference', 'location' => EventLocation::text('conference', '', EventLocation::INVITEE_MAIL, [self::class, 'translate'])] + $event;
        $ics = str_replace("\r\n ", '', (new IcsWriter())->attachment($booking + ['conference_url' => 'https://example.test/room'], $event, $now));

        self::assertStringContainsString("\r\nLOCATION:https://example.test/room\r\n", $ics);
    }
}
