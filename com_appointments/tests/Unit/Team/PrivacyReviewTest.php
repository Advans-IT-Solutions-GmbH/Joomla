<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/DavTestKit.php';
require_once __DIR__ . '/../../../administrator/components/com_appointments/src/Service/Team/PrivacyReview.php';

use Advans\Component\Appointments\Administrator\Service\Team\PrivacyReview;
use PHPUnit\Framework\TestCase;

/**
 * A privacy request never deletes an entry of a calendar's own: the decision
 * «remove only the address» takes the address out and leaves the entry and every
 * other person in it as they are.
 */
final class PrivacyReviewTest extends TestCase
{
    private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\nBEGIN:VEVENT\r\nUID:own-1\r\n"
        . "DTSTAMP:20261101T000000Z\r\nDTSTART:20261120T100000Z\r\nDTEND:20261120T110000Z\r\n"
        . "SUMMARY:Follow-up with anna@example.test\r\n"
        . "ORGANIZER;CN=Host:mailto:host@example.test\r\n"
        . "ATTENDEE;CN=\"Anna: guest\";PARTSTAT=ACCEPTED:mailto:Anna%40Example.test\r\n"
        . "ATTENDEE:mailto:other@example.test\r\n"
        . "ATTENDEE:mailto:xanna@example.test\r\n"
        . "DESCRIPTION:Notes for anna@example.test.com and anna@example.test.\r\n"
        . "BEGIN:VALARM\r\nACTION:EMAIL\r\nATTENDEE:mailto:anna@example.test\r\nEND:VALARM\r\n"
        . "END:VEVENT\r\nEND:VCALENDAR\r\n";

    public function testOnlyTheAddressLeavesTheEntry(): void
    {
        $changed = PrivacyReview::withoutAddress(self::ICS, 'ANNA@example.test', '[removed]');

        // The address is gone in every form, also percent-encoded and inside an alarm.
        self::assertDoesNotMatchRegularExpression('/(?<![a-z.])anna(@|%40)example\.test(?![a-z.])/i', $changed);
        self::assertStringContainsString('SUMMARY:Follow-up with [removed]', $changed);
        // Everything else stays: the entry, its times, the organiser and the other attendees,
        // and addresses that only contain the requested one.
        self::assertStringContainsString('UID:own-1', $changed);
        self::assertStringContainsString('DTSTART:20261120T100000Z', $changed);
        self::assertStringContainsString('ORGANIZER;CN=Host:mailto:host@example.test', $changed);
        self::assertStringContainsString('ATTENDEE:mailto:other@example.test', $changed);
        self::assertStringContainsString('ATTENDEE:mailto:xanna@example.test', $changed);
        self::assertStringContainsString('anna@example.test.com', $changed);
        self::assertStringContainsString("END:VCALENDAR\r\n", $changed);
    }

    public function testOtherAddressesThatContainTheRequestedOneStay(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:own-3\r\nATTENDEE:mailto:x!anna@example.test\r\n"
            . "DESCRIPTION:éanna@example.test\\, anna@example.test.com\\, anna@sub.example.test and .anna@example.test.\r\n"
            . "ATTENDEE;SENT-BY=\"mailto:anna@example.test\":mailto:other@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $changed = str_replace("\r\n ", '', PrivacyReview::withoutAddress($ics, 'anna@example.test', '[removed]'));

        // Punctuation and Unicode before the name make another, complete address.
        self::assertStringContainsString('ATTENDEE:mailto:x!anna@example.test', $changed);
        self::assertStringContainsString('éanna@example.test', $changed);
        self::assertStringContainsString('anna@example.test.com', $changed);
        self::assertStringContainsString('anna@sub.example.test', $changed);
        // The requested address goes as a whole, also between dots and inside a parameter.
        self::assertStringContainsString('and .[removed].', $changed);
        self::assertStringContainsString('SENT-BY="mailto:[removed]":mailto:other@example.test', $changed);
    }
    public function testAnOrganiserAddressAndLongLines(): void
    {
        $ics = "BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nUID:own-2\r\nORGANIZER:mailto:anna@example.test\r\n"
            . 'DESCRIPTION:' . str_repeat('ä', 60) . " anna@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
        $changed = PrivacyReview::withoutAddress($ics, 'anna@example.test', 'removed; by request');

        self::assertStringNotContainsString('ORGANIZER', $changed);
        // The placeholder is iCalendar text, and long lines are folded without splitting a character.
        self::assertStringContainsString('removed\\; by request', str_replace("\r\n ", '', $changed));

        foreach (explode("\r\n", $changed) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line));
            self::assertSame(1, preg_match('//u', $line), 'valid UTF-8 after folding');
        }
    }

    public function testAnUnknownAddressChangesNothingOfSubstance(): void
    {
        $changed = PrivacyReview::withoutAddress(self::ICS, 'nobody@example.test', '[removed]');

        self::assertSame(str_replace("\r\n", "\n", self::ICS), str_replace("\r\n", "\n", $changed));
        self::assertSame(self::ICS, PrivacyReview::withoutAddress(self::ICS, 'not an address', '[removed]'));
    }

    public function testThePreviewShowsWhereTheAddressIs(): void
    {
        self::assertSame(['text', 'attendee'], PrivacyReview::roles(self::ICS, 'anna@example.test'));
        self::assertSame(['organizer'], PrivacyReview::roles(self::ICS, 'host@example.test'));
        self::assertSame([], PrivacyReview::roles(self::ICS, 'nobody@example.test'));
        self::assertSame(['summary' => 'Follow-up with anna@example.test', 'start' => '2026-11-20 10:00'], PrivacyReview::overview(self::ICS));
    }
}
