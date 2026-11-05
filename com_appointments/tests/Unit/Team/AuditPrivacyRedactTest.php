<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Plugin\Privacy\Appointments\Team\TeamPrivacy;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/privacy/appointments/src/Team/TeamPrivacy.php';

/**
 * The privacy export of a calendar entry keeps only the requester's part.
 */
final class AuditPrivacyRedactTest extends TestCase
{
    private const ICS = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:meeting-joan@example.test\r\n"
        . "DTSTART:20261110T090000Z\r\nDTEND:20261110T100000Z\r\nSUMMARY:Review with Joan (joan@example.test)\r\n"
        . "LOCATION:Room 2\r\nDESCRIPTION:Joan's salary\\, phone 079 000 00 00\r\n"
        . "ORGANIZER;CN=\"Boss: Big\":mailto:boss@example.test\r\n"
        . "ATTENDEE;CN=\"Joan Doe\";PARTSTAT=ACCEPTED:mailto:joan@example.test\r\n"
        . "ATTENDEE;CN=\"An Requester\":mailto:an@example.test\r\n"
        . "ATTENDEE;CN=\"Müller\":mailto:müller@example.test\r\n"
        . "X-ALT-DESC;FMTTYPE=text/html:<b>secret</b>\r\nATTACH;ENCODING=BASE64;VALUE=BINARY:QUJD\r\n"
        . "END:VEVENT\r\nEND:VCALENDAR\r\n";

    public function testOnlyTimesTitlePlaceAndOwnLineRemain(): void
    {
        $out = TeamPrivacy::redact(self::ICS, ' AN@example.test ', 'another person');

        self::assertStringContainsString('DTSTART:20261110T090000Z', $out);
        self::assertStringContainsString('LOCATION:Room 2', $out);
        self::assertStringContainsString('ATTENDEE:mailto:an@example.test', $out);
        self::assertStringContainsString('SUMMARY:Review with Joan ([redacted])', $out);

        foreach (['joan@', 'Joan Doe', 'boss@', 'Boss', 'salary', '079', 'secret', 'QUJD', 'UID', 'müller', 'Müller'] as $leak) {
            self::assertStringNotContainsString($leak, $out, $leak);
        }

        self::assertSame(3, substr_count($out, ':another person'));
    }

    public function testAnAddressContainingTheRequesterIsNotTheRequester(): void
    {
        // an@example.test is a substring of joan@example.test: compared exactly, not as a substring.
        $out = TeamPrivacy::redact("ATTENDEE;CN=Joan:mailto:joan@example.test\r\n", 'an@example.test');

        self::assertStringNotContainsString('Joan', $out);
        self::assertStringNotContainsString('joan@', $out);
    }

    public function testOtherAddressesInTheOwnLineAreReplaced(): void
    {
        $out = TeamPrivacy::redact("ATTENDEE;SENT-BY=\"mailto:boss@example.test\";DELEGATED-FROM=\"mailto:joan@example.test\":mailto:an@example.test\r\n", 'an@example.test');

        self::assertStringContainsString('mailto:an@example.test', $out);
        self::assertStringNotContainsString('boss@', $out);
        self::assertStringNotContainsString('joan@', $out);
    }

    public function testParametersAreWhitelistedAndEncodedAddressesRedacted(): void
    {
        $out = TeamPrivacy::redact("SUMMARY;X-PRIVATE-NOTE=\"Joan is ill\";LANGUAGE=de:Meeting\r\n"
            . "DTSTART;TZID=Europe/Zurich:20261110T090000\r\n"
            . "ATTENDEE;PARTSTAT=ACCEPTED;SENT-BY=\"mailto:boss%40example.test\":mailto:an%40example.test\r\n"
            . "LOCATION:Call joan%40example.test\r\n", 'an@example.test');

        self::assertStringContainsString('SUMMARY:Meeting', $out);
        self::assertStringContainsString('DTSTART;TZID=Europe/Zurich:20261110T090000', $out);
        self::assertStringContainsString('ATTENDEE;PARTSTAT=ACCEPTED:mailto:an%40example.test', $out);
        self::assertStringContainsString('LOCATION:Call [redacted]', $out);
        self::assertStringNotContainsString('ill', $out);
        self::assertStringContainsString('DTSTART;TZID=[redacted]:x', TeamPrivacy::redact('DTSTART;TZID=staff@example.test:x', 'an@example.test'));
        self::assertSame('SUMMARY:With [redacted] and [redacted]', TeamPrivacy::redact("SUMMARY:With jo'an@example.test and jo{an}@example.test", 'an@example.test'));
        self::assertSame('SUMMARY:Ask [redacted] and [redacted] and an@example.test',
            TeamPrivacy::redact('SUMMARY:Ask joan%40example%2Etest and jo/an@example.test and an@example.test', 'an@example.test'));
        self::assertStringNotContainsString('boss', $out);
    }

    public function testLargeSitesAreSearchedWithinHardLimits(): void
    {
        // 10,000 calendars of 300 entries each: far more than one request may load.
        $used = 0;
        $searched = [];

        foreach (range(1, 10000) as $id) {
            $bytes = $id === 3 ? TeamPrivacy::MAX_CALENDAR_BYTES + 1 : 300 * 4096;
            // Calendars 5 to 9 cannot be opened in this session: they are not charged.
            $opens = $id < 5 || $id > 9;

            if (!TeamPrivacy::fits(300, $bytes, $used) || !$opens) {
                continue;
            }

            $used += 300;
            $searched[] = $id;
        }

        self::assertNotContains(3, $searched, 'A calendar above the byte limit is never loaded');
        self::assertLessThanOrEqual(TeamPrivacy::MAX_OBJECTS, $used);
        self::assertCount(intdiv(TeamPrivacy::MAX_OBJECTS, 300), $searched);
        self::assertContains(10, $searched, 'Calendars that cannot be opened use none of the budget');
        self::assertFalse(TeamPrivacy::fits(5, 10, 0, 100, 4), 'Nothing above the entry limit');
        self::assertTrue(TeamPrivacy::fits(0, 0, TeamPrivacy::MAX_OBJECTS), 'An empty calendar always fits');
    }

    public function testAlarmsAndOtherComponentsAreLeftOutWhole(): void
    {
        $out = TeamPrivacy::redact("BEGIN:VEVENT\r\nSUMMARY:Meeting\r\nATTENDEE:mailto:an@example.test\r\nBEGIN:VALARM\r\nACTION:EMAIL\r\nSUMMARY:Remind Joan about her salary\r\n"
            . "DESCRIPTION:private\r\nEND:VALARM\r\nLOCATION:Room 2\r\nEND:VEVENT\r\nBEGIN:VTODO\r\nSUMMARY:Secret task\r\nEND:VTODO", 'an@example.test');

        self::assertSame("BEGIN:VEVENT\r\nSUMMARY:Meeting\r\nATTENDEE:mailto:an@example.test\r\nLOCATION:Room 2\r\nEND:VEVENT", $out);
    }

    public function testOnlyTheEventsThatMentionTheRequesterAreExported(): void
    {
        $out = TeamPrivacy::redact("BEGIN:VCALENDAR\r\nBEGIN:VEVENT\r\nSUMMARY:Series\r\nATTENDEE:mailto:an@example.test\r\nEND:VEVENT\r\n"
            . "BEGIN:VEVENT\r\nRECURRENCE-ID:20261111T090000Z\r\nSUMMARY:Exception with Joan only\r\nATTENDEE:mailto:joan@example.test\r\nEND:VEVENT\r\nEND:VCALENDAR", 'an@example.test');

        self::assertStringContainsString('SUMMARY:Series', $out);
        self::assertStringNotContainsString('Exception', $out);
        self::assertStringNotContainsString('RECURRENCE-ID', $out);
        self::assertSame(1, substr_count($out, 'BEGIN:VEVENT'));
    }

    public function testFoldedLinesAreUnfoldedFirst(): void
    {
        $out = TeamPrivacy::redact("BEGIN:VEVENT\r\nATTENDEE;CN=\"Long\r\n Name\":mailto:x@example.test\r\nDESCRIPTION:a\r\n b an@example.test\r\nEND:VEVENT", 'an@example.test', 'other');

        self::assertSame("BEGIN:VEVENT\r\nATTENDEE:other\r\nEND:VEVENT", $out);
    }
}
