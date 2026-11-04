<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/DavTestKit.php';

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStore;
use PHPUnit\Framework\TestCase;

/**
 * The pure rules of the object store: addresses, object limits, ETag
 * preconditions, the journal collapse and the end of a series for the
 * retention.
 */
final class DavObjectRulesTest extends TestCase
{
    private static function ics(string $events): string
    {
        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//t//EN\r\n{$events}END:VCALENDAR\r\n";
    }

    private static function event(string $uid, string $extra = '', string $start = '20260101T090000Z', string $end = '20260101T100000Z'): string
    {
        return "BEGIN:VEVENT\r\nUID:{$uid}\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:{$start}\r\nDTEND:{$end}\r\n{$extra}END:VEVENT\r\n";
    }

    public function testObjectAddresses(): void
    {
        foreach (['a.ics', 'A-b_c.d@e+f=g.ics', str_repeat('x', 251) . '.ics'] as $uri) {
            self::assertTrue(CalendarObjectStore::validUri($uri), $uri);
        }

        foreach (['', '.ics', '..ics', 'a.ICS', 'a.txt', 'a b.ics', 'a/b.ics', '../a.ics', "a\0.ics", 'ä.ics', '%2e.ics', str_repeat('x', 252) . '.ics'] as $uri) {
            self::assertFalse(CalendarObjectStore::validUri($uri), $uri);
        }
    }

    public function testAnObjectIsOneVcalendarOfVeventsWithOneUid(): void
    {
        self::assertSame('u1', CalendarObjectStore::inspect(self::ics(self::event('u1'))));
        self::assertSame('u1', CalendarObjectStore::inspect(self::ics(
            "BEGIN:VTIMEZONE\r\nTZID:Europe/Zurich\r\nBEGIN:STANDARD\r\nDTSTART:19701025T030000\r\nTZOFFSETFROM:+0200\r\nTZOFFSETTO:+0100\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
            . self::event('u1', "RRULE:FREQ=WEEKLY;COUNT=3\r\n") . self::event('u1', "RECURRENCE-ID:20260108T090000Z\r\n", '20260108T110000Z', '20260108T120000Z')
        )));

        $invalid = [
            'no event'       => self::ics(''),
            'two UIDs'       => self::ics(self::event('u1') . self::event('u2')),
            'a VTODO'        => self::ics(self::event('u1') . "BEGIN:VTODO\r\nUID:u1\r\nDTSTAMP:20260101T000000Z\r\nEND:VTODO\r\n"),
            'no UID'         => self::ics("BEGIN:VEVENT\r\nDTSTAMP:20260101T000000Z\r\nDTSTART:20260101T090000Z\r\nEND:VEVENT\r\n"),
            'not iCalendar'  => 'hello',
            'a vCard'        => "BEGIN:VCARD\r\nVERSION:4.0\r\nFN:x\r\nEND:VCARD\r\n",
        ];

        foreach ($invalid as $case => $ics) {
            try {
                CalendarObjectStore::inspect($ics);
                self::fail("Accepted: {$case}");
            } catch (TeamException $e) {
                self::assertSame(400, $e->getCode(), $case);
            }
        }

        try {
            CalendarObjectStore::inspect(str_repeat('x', CalendarObjectStore::MAX_BYTES + 1));
            self::fail('Accepted an object above the limit');
        } catch (TeamException $e) {
            self::assertSame(413, $e->getCode());
        }
    }

    public function testEtagPreconditions(): void
    {
        self::assertTrue(CalendarObjectStore::etagMatches('*', 'abc'));
        self::assertFalse(CalendarObjectStore::etagMatches('*', null));
        self::assertTrue(CalendarObjectStore::etagMatches('"abc"', 'abc'));
        self::assertTrue(CalendarObjectStore::etagMatches('W/"abc"', 'abc'));
        self::assertFalse(CalendarObjectStore::etagMatches('"abd"', 'abc'));
        self::assertFalse(CalendarObjectStore::etagMatches('"abc"', null));
    }

    public function testTheJournalCollapsesToWhatTheClientMustDo(): void
    {
        $rows = [
            ['uri' => 'new.ics', 'operation' => TeamStates::CHANGE_ADDED],
            ['uri' => 'old.ics', 'operation' => TeamStates::CHANGE_MODIFIED],
            ['uri' => 'gone.ics', 'operation' => TeamStates::CHANGE_DELETED],
            ['uri' => 'brief.ics', 'operation' => TeamStates::CHANGE_ADDED],
            ['uri' => 'new.ics', 'operation' => TeamStates::CHANGE_MODIFIED],
            ['uri' => 'brief.ics', 'operation' => TeamStates::CHANGE_DELETED],
            ['uri' => 'back.ics', 'operation' => TeamStates::CHANGE_DELETED],
            ['uri' => 'back.ics', 'operation' => TeamStates::CHANGE_ADDED],
        ];

        self::assertSame(
            ['added' => ['new.ics'], 'modified' => ['old.ics', 'back.ics'], 'deleted' => ['gone.ics']],
            CalendarObjectStore::collapse($rows)
        );
    }

    public function testTheEndOfASeriesForTheRetention(): void
    {
        self::assertEquals(new \DateTimeImmutable('2026-01-01 10:00:00', new \DateTimeZone('UTC')), CalendarObjectStore::lastEnd(self::ics(self::event('a'))));
        self::assertEquals(
            new \DateTimeImmutable('2026-01-15 10:00:00', new \DateTimeZone('UTC')),
            CalendarObjectStore::lastEnd(self::ics(self::event('a', "RRULE:FREQ=WEEKLY;COUNT=3\r\n")))
        );
        self::assertEquals(
            new \DateTimeImmutable('2026-01-08 12:00:00', new \DateTimeZone('UTC')),
            CalendarObjectStore::lastEnd(self::ics(self::event('a', "RRULE:FREQ=WEEKLY;COUNT=2\r\n") . self::event('a', "RECURRENCE-ID:20260108T090000Z\r\n", '20260108T110000Z', '20260108T120000Z')))
        );
        self::assertEquals(
            new \DateTimeImmutable('2026-01-02 00:00:00', new \DateTimeZone('UTC')),
            CalendarObjectStore::lastEnd(self::ics("BEGIN:VEVENT\r\nUID:d\r\nDTSTAMP:20260101T000000Z\r\nDTSTART;VALUE=DATE:20260101\r\nEND:VEVENT\r\n"))
        );

        self::assertEquals(
            new \DateTimeImmutable('2036-12-13 10:00:00', new \DateTimeZone('UTC')),
            CalendarObjectStore::lastEnd(self::ics(self::event('a', "RRULE:FREQ=DAILY;COUNT=4000\r\n"))),
            'A finite series beyond the 3500 occurrences of vObject still ends.'
        );
        self::assertNull(CalendarObjectStore::lastEnd(self::ics(self::event('a', "RRULE:FREQ=DAILY\r\n"))), 'An open series is never deleted.');
        self::assertNull(CalendarObjectStore::lastEnd('garbage'));
        self::assertNull(CalendarObjectStore::lastEnd(self::ics(self::event('a', "RRULE:FREQ=SECONDLY;COUNT=100000\r\n"))), 'Too many occurrences are kept.');
    }
}
