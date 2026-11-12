<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\CalendarNotice;
use Advans\Component\Appointments\Administrator\Helper\DefaultTemplates;
use Advans\Component\Appointments\Administrator\Helper\NotificationSwitch;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/administrator/components/com_appointments/src/Helper/Team/CalendarRole.php';

/**
 * Booking mails to the users of a target calendar: who gets which template, never twice, and
 * free/busy never anything but the time. The user's own switch keeps every other account parameter.
 */
final class CalendarNoticeTest extends TestCase
{
    private const HOST = 10;

    /** Host (write), a reader, a writer, a manager, an external writer and a free/busy user. */
    private static function members(): array
    {
        return [
            ['user_id' => 21, 'email' => 'reader@example.test', 'role' => 2, 'backend' => false],
            ['user_id' => 22, 'email' => 'writer@example.test', 'role' => 3, 'backend' => true],
            ['user_id' => 23, 'email' => 'manager@example.test', 'role' => 4, 'backend' => true],
            ['user_id' => 24, 'email' => 'external@example.test', 'role' => 3, 'backend' => false],
            ['user_id' => 25, 'email' => 'busy@example.test', 'role' => 1, 'backend' => true],
            ['user_id' => self::HOST, 'email' => 'host@example.test', 'role' => 3, 'backend' => true],
        ];
    }

    /** @return array<int, string> user id => template */
    private static function templates(array $plan): array
    {
        return array_column($plan, 'purpose', 'user_id');
    }

    public function testEveryRoleGetsItsTemplateOnANewBookingAndTheHostComesFirst(): void
    {
        $plan = CalendarNotice::plan('confirmation', self::HOST, self::members());

        self::assertSame(self::HOST, $plan[0]['user_id']);
        self::assertTrue($plan[0]['host']);
        self::assertSame([
            self::HOST => 'calendar_booked', 21 => 'calendar_booked', 22 => 'calendar_booked', 23 => 'calendar_booked',
            24 => 'calendar_booked', 25 => 'calendar_busy',
        ], self::templates($plan));
    }

    public function testOnlyContentRolesWithPermissionGetTheAdministratorLink(): void
    {
        $backend = array_column(CalendarNotice::plan('reschedule', self::HOST, self::members()), 'backend', 'user_id');

        self::assertSame([self::HOST => true, 21 => false, 22 => true, 23 => true, 24 => false, 25 => false], $backend);
        // An erased booking has nothing to open.
        self::assertSame([], array_filter(array_column(CalendarNotice::plan('erasure_notice', self::HOST, self::members()), 'backend')));
    }

    public function testFreeBusyGetsAMailOnlyWhenTheBusyPictureChanges(): void
    {
        $busy = static fn (string $purpose): ?string => self::templates(CalendarNotice::plan($purpose, self::HOST, self::members()))[25] ?? null;

        self::assertSame('calendar_busy', $busy('pending'));
        self::assertNull($busy('approved'));
        self::assertSame('calendar_busy_moved', $busy('reschedule'));
        self::assertSame('calendar_free', $busy('cancellation'));
        self::assertSame('calendar_free', $busy('erasure_notice'));
        self::assertNull($busy('reminder'));
    }

    public function testEveryPlanEntryIsAMailWithoutInvitationOfAKnownTemplate(): void
    {
        foreach (DefaultTemplates::SENT as $purpose) {
            foreach (CalendarNotice::plan($purpose, self::HOST, self::members()) as $entry) {
                self::assertArrayHasKey($entry['purpose'], DefaultTemplates::CALENDAR_KEYS, $purpose);
                self::assertSame($entry['content'], !\in_array($entry['purpose'], ['calendar_busy', 'calendar_busy_moved', 'calendar_free'], true));
            }
        }
    }

    public function testTheReminderGoesToTheHostAlone(): void
    {
        self::assertSame([self::HOST => 'calendar_reminder'], self::templates(CalendarNotice::plan('reminder', self::HOST, self::members())));
    }

    public function testNobodyGetsAMailTwice(): void
    {
        $members = self::members();
        // The host appears again through a second row, another account shares the writer's address in other case.
        $members[] = ['user_id' => self::HOST, 'email' => 'host@example.test', 'role' => 4, 'backend' => true];
        $members[] = ['user_id' => 30, 'email' => ' WRITER@example.test ', 'role' => 2];
        // An account whose address already gets the booking person's mail.
        $members[] = ['user_id' => 31, 'email' => 'Invitee@Example.test', 'role' => 4];
        $plan = CalendarNotice::plan('confirmation', self::HOST, $members, ['invitee@example.test', 'guest@example.test']);
        $addresses = array_map(static fn (array $entry): string => mb_strtolower(trim($entry['email'])), $plan);

        self::assertSame(array_values(array_unique(array_column($plan, 'user_id'))), array_column($plan, 'user_id'));
        self::assertSame(array_values(array_unique($addresses)), $addresses);
        self::assertNotContains(30, array_column($plan, 'user_id'));
        self::assertNotContains(31, array_column($plan, 'user_id'));
        self::assertCount(6, $plan);
    }

    public function testTheHostWinsAnAddressItSharesWithAnotherAccount(): void
    {
        $members = [
            ['user_id' => 40, 'email' => 'host@example.test', 'role' => 1],
            ['user_id' => self::HOST, 'email' => 'host@example.test', 'role' => 3],
        ];

        self::assertSame([self::HOST => 'calendar_booked'], self::templates(CalendarNotice::plan('confirmation', self::HOST, $members)));
    }

    public function testASwitchedOffUserGetsNothingAndInvalidEntriesAreSkipped(): void
    {
        $members = self::members();
        $members[0]['muted'] = true;
        $members[5]['muted'] = true;
        $members[] = ['user_id' => 50, 'email' => 'not-an-address', 'role' => 3];
        $members[] = ['user_id' => 51, 'email' => 'unknown-role@example.test', 'role' => 9];
        $members[] = ['user_id' => 0, 'email' => 'nobody@example.test', 'role' => 3];
        $ids = array_column(CalendarNotice::plan('cancellation', self::HOST, $members), 'user_id');

        self::assertSame([22, 23, 24, 25], $ids);
    }

    public function testFreeBusyTextFillsOnlyTheTimeAndTheCalendar(): void
    {
        $template = [
            'subject' => "Busy {calendar_title} {invitee_name}\r\n{event_title}",
            'body' => "From {start} to {end} {timezone}\n{invitee_email} {invitee_phone} {guest_emails} {host_name} {event_title} {custom_fields.1} {conference_url} {state_label}",
        ];
        $text = CalendarNotice::freeBusyText($template, [
            '{calendar_title}' => 'Team', '{start}' => '2027-01-04 10:00', '{end}' => '2027-01-04 10:30', '{timezone}' => 'UTC',
            '{invitee_name}' => 'Private Person', '{invitee_email}' => 'private@example.test', '{event_title}' => 'Secret topic',
        ]);

        self::assertSame('Busy Team', $text['subject']);
        self::assertSame("From 2027-01-04 10:00 to 2027-01-04 10:30 UTC\n       ", $text['body']);
        self::assertStringNotContainsString('Private', $text['subject'] . $text['body']);
        self::assertStringNotContainsString('{', $text['subject'] . $text['body']);
    }

    public function testTheSwitchKeepsEveryOtherParameterAndIsOnByDefault(): void
    {
        $params = '{"language":"de-DE","timezone":"Europe\/Zurich"}';

        self::assertSame([], NotificationSwitch::mutedFrom($params));
        self::assertSame([], NotificationSwitch::mutedFrom(''));
        self::assertSame([], NotificationSwitch::mutedFrom('not json'));

        $off = NotificationSwitch::withSwitch($params, 7, false);
        $decoded = json_decode($off, true);
        self::assertSame('de-DE', $decoded['language']);
        self::assertSame('Europe/Zurich', $decoded['timezone']);
        self::assertSame([7], NotificationSwitch::mutedFrom($off));

        $twice = NotificationSwitch::withSwitch(NotificationSwitch::withSwitch($off, 3, false), 7, false);
        self::assertSame([3, 7], NotificationSwitch::mutedFrom($twice));

        $on = NotificationSwitch::withSwitch($twice, 3, true);
        self::assertSame([7], NotificationSwitch::mutedFrom($on));
        self::assertSame(['language' => 'de-DE', 'timezone' => 'Europe/Zurich'], json_decode(NotificationSwitch::withSwitch($on, 7, true), true));
        self::assertSame('{}', NotificationSwitch::withSwitch('', 5, true));
    }

    public function testOtherCalendarUsersGetOnlyWhatTheCalendarCopyShows(): void
    {
        $booking = [
            'id' => 5, 'uid' => 'u', 'start_utc' => '2027-01-04 10:00:00', 'end_utc' => '2027-01-04 10:30:00', 'state' => 2,
            'invitee_name' => 'Name', 'invitee_email' => 'a@example.test', 'guest_emails' => 'g@example.test',
            'invitee_phone' => '+41 44 000 00 00', 'custom_fields' => '{"1":"answer"}', 'cancel_reason' => 'private reason',
            'consent_utc' => '2027-01-01 00:00:00', 'invitee_language' => 'de-DE', 'conference_room' => 'room',
        ];
        $content = CalendarNotice::contentBooking($booking);

        self::assertSame(['id', 'uid', 'start_utc', 'end_utc', 'state', 'invitee_name', 'invitee_email', 'guest_emails'], array_keys($content));
        self::assertStringNotContainsString('private reason', json_encode($content));
    }

    public function testTheSwitchKeepsTheJsonTypeOfEveryOtherParameter(): void
    {
        $params = '{"other_plugin":{"options":{}},"numbered":{"1":"a","2":"b"},"list":[1,2]}';
        $off = NotificationSwitch::withSwitch($params, 7, false);

        self::assertSame('{"other_plugin":{"options":{}},"numbered":{"1":"a","2":"b"},"list":[1,2],"' . NotificationSwitch::PARAM . '":[7]}', $off);
        self::assertSame($params, NotificationSwitch::withSwitch($off, 7, true));
    }

    public function testTheSwitchIgnoresForeignValuesAndRefusesUnreadableParameters(): void
    {
        self::assertSame([2, 4], NotificationSwitch::mutedFrom('{"' . NotificationSwitch::PARAM . '":["4",2,0,-1,"x",{"a":1},2]}'));
        self::assertSame([], NotificationSwitch::mutedFrom('{"' . NotificationSwitch::PARAM . '":"7"}'));

        $this->expectException(\UnexpectedValueException::class);
        NotificationSwitch::withSwitch('[1,2]', 1, false);
    }
}
