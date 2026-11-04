<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\VendorAutoloader;
use Advans\Component\Appointments\Administrator\Service\Team\TimingIndex;
use PHPUnit\Framework\TestCase;

$teamServiceDir = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team';
require_once $teamServiceDir . '/TimingIndexInterface.php';
require_once $teamServiceDir . '/TimingIndex.php';

/**
 * The time skeleton (work package E): what it keeps, how it expands, the
 * free/busy copy and the e-mail addresses. Times in Europe/Zurich around both
 * daylight saving changes of 2027 (28 March, 31 October).
 */
final class TimingIndexTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        self::sabre();
    }

    /**
     * The bundled, prefixed sabre/vobject once work package A ships it; until then
     * the Composer dev copy, aliased to the prefixed names the code uses.
     */
    public static function sabre(): void
    {
        if (VendorAutoloader::register()) {
            return;
        }

        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;
        spl_autoload_register(static function (string $class): void {
            if (!str_starts_with($class, VendorAutoloader::PREFIX)) {
                return;
            }

            $original = substr($class, strlen(VendorAutoloader::PREFIX));

            if (class_exists($original) || interface_exists($original) || trait_exists($original)) {
                class_alias($original, $class);
            }
        });

        // `instanceof` and `catch` never autoload: alias the classes they name up front.
        foreach (['Component', 'Property', 'Component\\VCalendar', 'Component\\VEvent', 'Recur\\NoInstancesException',
            'Recur\\MaxInstancesExceededException'] as $name) {
            class_exists(VendorAutoloader::PREFIX . 'Sabre\\VObject\\' . $name);
        }
    }

    private static function calendar(string ...$components): string
    {
        return implode("\r\n", array_merge(['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Test//Test//EN'], $components, ['END:VCALENDAR'])) . "\r\n";
    }

    private static function event(string ...$lines): string
    {
        return implode("\r\n", array_merge(['BEGIN:VEVENT', 'UID:secret-uid@example.test', 'DTSTAMP:20270101T000000Z'], $lines, ['END:VEVENT']));
    }

    private static function zurich(): string
    {
        return implode("\r\n", [
            'BEGIN:VTIMEZONE', 'TZID:Europe/Zurich', 'X-LIC-LOCATION:Europe/Zurich', 'X-SECRET:hidden-zone-note',
            'BEGIN:DAYLIGHT', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'DTSTART:19700329T020000',
            'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'DTSTART:19701025T030000',
            'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'END:STANDARD',
            'END:VTIMEZONE',
        ]);
    }

    private static function utc(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /**
     * @param   AvailabilityWindow[]  $windows
     *
     * @return  string[]
     */
    private static function spans(array $windows): array
    {
        return array_map(static fn (AvailabilityWindow $w): string => $w->getStart()->format('Y-m-d\TH:i\Z') . '/' . $w->getEnd()->format('Y-m-d\TH:i\Z'), $windows);
    }

    private function expand(string $ics, string $from, string $to, string $zone = 'Europe/Zurich'): array
    {
        $index = new TimingIndex();

        return self::spans($index->expand($index->extract($ics), self::utc($from), self::utc($to), new \DateTimeZone($zone)));
    }

    public function testSkeletonKeepsOnlyTheTimesWithoutUidOrText(): void
    {
        $skeleton = (new TimingIndex())->extract(self::calendar(self::zurich(), self::event(
            'DTSTART;TZID=Europe/Zurich;X-NOTE=private-parameter:20270322T100000',
            'DTEND;TZID=Europe/Zurich:20270322T110000',
            'RRULE:FREQ=WEEKLY;COUNT=3',
            'SUMMARY:Board meeting about the merger',
            'DESCRIPTION:Confidential agenda',
            'LOCATION:Room 4',
            'ORGANIZER;CN=Boss:mailto:boss@example.test',
            'ATTENDEE:mailto:someone@example.test',
            'CATEGORIES:private-category',
            'X-CUSTOM:private-x-property',
            'STATUS:confirmed',
            'TRANSP:OPAQUE',
            'X-MICROSOFT-CDO-BUSYSTATUS:BUSY',
            'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:private-alarm', 'TRIGGER:-PT15M', 'END:VALARM'
        )));
        $unfolded = str_replace("\r\n ", '', $skeleton);

        foreach (['UID', 'secret-uid', 'DTSTAMP', 'SUMMARY', 'merger', 'DESCRIPTION', 'Confidential', 'LOCATION', 'Room 4',
            'ORGANIZER', 'ATTENDEE', 'example.test', 'CATEGORIES', 'private', 'X-CUSTOM', 'VALARM', 'X-NOTE', 'TZNAME',
            'X-SECRET', 'hidden-zone-note', 'VTIMEZONE'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $unfolded, $forbidden . ' must not reach the skeleton');
        }

        foreach (['DTSTART;TZID=Europe/Zurich:20270322T100000', 'DTEND;TZID=Europe/Zurich:20270322T110000',
            'RRULE:FREQ=WEEKLY;COUNT=3', 'STATUS:CONFIRMED', 'TRANSP:OPAQUE', 'X-MICROSOFT-CDO-BUSYSTATUS:BUSY'] as $kept) {
            self::assertStringContainsString($kept, $unfolded);
        }

        // Every property line of the VEVENT is on the list of the contract.
        preg_match('/BEGIN:VEVENT\r\n(.*?)END:VEVENT/s', $unfolded, $match);
        foreach (array_filter(explode("\r\n", $match[1])) as $line) {
            $name = preg_split('/[;:]/', $line)[0];
            self::assertContains($name, ['DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID',
                'STATUS', 'TRANSP', 'X-MICROSOFT-CDO-BUSYSTATUS'], $line);
        }
    }

    public function testClientTextNeverReachesTheSkeletonThroughZonesOrGroups(): void
    {
        $zone = implode("\r\n", ['BEGIN:VTIMEZONE', 'TZID:Lunch with lawyer', 'X-LIC-LOCATION:Europe/Zurich',
            'BEGIN:STANDARD', 'DTSTART:19701025T030000', 'TZOFFSETFROM:Call Dr Meier', 'TZOFFSETTO:+0100', 'END:STANDARD', 'END:VTIMEZONE']);
        $skeleton = (new TimingIndex())->extract(self::calendar($zone, self::event(
            'SECRETGROUP.DTSTART;TZID=Lunch with lawyer:20270115T100000', 'DTEND;TZID=Lunch with lawyer:20270115T110000')));

        foreach (['Lunch', 'lawyer', 'Meier', 'SECRETGROUP', 'VTIMEZONE'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $skeleton, $forbidden);
        }

        self::assertStringContainsString("DTSTART;TZID=Europe/Zurich:20270115T100000\r\n", $skeleton);
        self::assertSame(['2027-01-15T09:00Z/2027-01-15T10:00Z'], $this->expand(self::calendar($zone, self::event(
            'SECRETGROUP.DTSTART;TZID=Lunch with lawyer:20270115T100000', 'DTEND;TZID=Lunch with lawyer:20270115T110000')),
            '2027-01-01 00:00:00', '2027-02-01 00:00:00', 'UTC'));
    }

    public function testRulesOutsideTheStandardOrTheLibraryAreRefused(): void
    {
        foreach (['FREQ=WEEKLY;BYHOUR=25', 'FREQ=YEARLY;BYYEARDAY=1;BYDAY=1MO', 'FREQ=YEARLY;BYYEARDAY=1;BYDAY=SU;INTERVAL=400',
            'FREQ=YEARLY;BYWEEKNO=2;BYDAY=1MO', 'FREQ=WEEKLY;BYDAY=1MO', 'FREQ=DAILY;INTERVAL=0', 'FREQ=FORTNIGHTLY',
            'FREQ=DAILY;X-NAME=1', 'FREQ=DAILY;COUNT=3;UNTIL=20270101', 'FREQ=MONTHLY;BYMONTHDAY=0', 'FREQ=DAILY;BYMINUTE=60',
            'FREQ=DAILY;FREQ=WEEKLY', 'COUNT=3', 'FREQ=MONTHLY;BYDAY=MONDAY', 'FREQ=DAILY;UNTIL=20270231'] as $rule) {
            try {
                TimingIndex::rule($rule);
                self::fail('Accepted: ' . $rule);
            } catch (TeamException $e) {
                self::assertSame(400, $e->getCode(), $rule);
            }
        }

        self::assertSame('FREQ=MONTHLY;INTERVAL=2;BYDAY=-1FR', TimingIndex::rule('byday=-1FR;freq=monthly;interval=2'));
        self::assertSame('FREQ=YEARLY;UNTIL=20301231T235959Z;BYMONTHDAY=-1;BYMONTH=3',TimingIndex::rule('FREQ=YEARLY;BYMONTHDAY=-1;BYMONTH=3;UNTIL=20301231T235959Z'));

        try {
            (new TimingIndex())->extract(self::calendar(self::event('DTSTART:20270101T090000Z', 'RRULE:FREQ=DAILY;BYHOUR=25')));
            self::fail('An object with an endless rule was accepted.');
        } catch (TeamException $e) {
            self::assertSame(400, $e->getCode());
        }
    }

    public function testRuleAndExtraDatesAreBothExpanded(): void
    {
        $ics = self::calendar(self::event('DTSTART:20270105T100000Z', 'DTEND:20270105T110000Z', 'RRULE:FREQ=DAILY;COUNT=2',
            'RDATE:20270120T100000Z'));

        self::assertSame(['2027-01-05T10:00Z/2027-01-05T11:00Z', '2027-01-06T10:00Z/2027-01-06T11:00Z', '2027-01-20T10:00Z/2027-01-20T11:00Z'],
            $this->expand($ics, '2027-01-01 00:00:00', '2027-02-01 00:00:00'));
    }

    public function testASeriesWithoutInstancesIsNotBusy(): void
    {
        $ics = self::calendar(self::event('DTSTART:20270105T100000Z', 'DTEND:20270105T110000Z', 'EXDATE:20270105T100000Z'));

        self::assertSame([], $this->expand($ics, '2027-01-01 00:00:00', '2027-02-01 00:00:00'));
    }

    public function testThisAndFutureOverrideFailsClosedFromItsInstance(): void
    {
        $ics = self::calendar(
            self::event('DTSTART;TZID=Europe/Zurich:20270104T100000', 'DTEND;TZID=Europe/Zurich:20270104T110000', 'RRULE:FREQ=DAILY;COUNT=5'),
            self::event('RECURRENCE-ID;RANGE=THISANDFUTURE;TZID=Europe/Zurich:20270106T100000',
                'DTSTART;TZID=Europe/Zurich:20270106T140000', 'DTEND;TZID=Europe/Zurich:20270106T150000')
        );

        self::assertContains('2027-01-06T09:00Z/2027-01-31T00:00Z', $this->expand($ics, '2027-01-01 00:00:00', '2027-01-31 00:00:00'));
    }

    public function testAnOldDailySeriesStillExpandsExactly(): void
    {
        $ics = self::calendar(self::event('DTSTART:19900101T120000Z', 'DTEND:19900101T130000Z', 'RRULE:FREQ=DAILY'));

        self::assertSame(['2027-01-10T12:00Z/2027-01-10T13:00Z', '2027-01-11T12:00Z/2027-01-11T13:00Z'],
            $this->expand($ics, '2027-01-10 00:00:00', '2027-01-12 00:00:00'));
    }

    public function testLastEndOfSingleFiniteAndOpenSeries(): void
    {
        $index = new TimingIndex();
        $end = static fn (string $ics, string $zone = 'Europe/Zurich'): ?string => ($index->lastEnd($index->extract($ics), new \DateTimeZone($zone)))?->format('Y-m-d\TH:i\Z');

        self::assertSame('2027-01-15T10:00Z', $end(self::calendar(self::event('DTSTART:20270115T090000Z', 'DTEND:20270115T100000Z'))));
        self::assertSame('2027-01-15T10:30Z', $end(self::calendar(self::event('DTSTART:20270115T090000Z', 'DURATION:PT90M'))));
        self::assertSame('2027-03-28T22:00Z', $end(self::calendar(self::event('DTSTART;VALUE=DATE:20270328'))), 'An all-day date lasts its local day');
        self::assertSame('2027-01-07T10:00Z', $end(self::calendar(self::event('DTSTART:20270105T090000Z', 'DTEND:20270105T100000Z', 'RRULE:FREQ=DAILY;COUNT=3'))));
        self::assertSame('2027-02-01T10:00Z', $end(self::calendar(self::event('DTSTART:20270105T090000Z', 'DTEND:20270105T100000Z',
            'RRULE:FREQ=DAILY;UNTIL=20270201T090000Z'))));
        self::assertSame('2027-03-01T10:00Z', $end(self::calendar(self::event('DTSTART:20270105T090000Z', 'DTEND:20270105T100000Z',
            'RRULE:FREQ=DAILY;COUNT=2', 'RDATE:20270301T090000Z'))));
        self::assertSame('2027-02-10T15:00Z', $end(self::calendar(
            self::event('DTSTART:20270105T090000Z', 'DTEND:20270105T100000Z', 'RRULE:FREQ=WEEKLY;COUNT=2'),
            self::event('RECURRENCE-ID:20270112T090000Z', 'DTSTART:20270210T140000Z', 'DTEND:20270210T150000Z')
        )), 'An override moved later extends the end');
        self::assertNull($end(self::calendar(self::event('DTSTART:20270105T090000Z', 'DTEND:20270105T100000Z', 'RRULE:FREQ=DAILY'))));
        self::assertNull($end(self::calendar(self::event('DTSTART:20000101T000000Z', 'RRULE:FREQ=MINUTELY;COUNT=999999'))), 'Beyond the step limit: never old');
    }

    public function testInvalidInputIsRefusedWith400(): void
    {
        foreach (['not a calendar', "BEGIN:VCARD\r\nVERSION:3.0\r\nFN:A\r\nEND:VCARD\r\n",
            self::calendar(implode("\r\n", ['BEGIN:VTODO', 'UID:x', 'DTSTAMP:20270101T000000Z', 'END:VTODO']))] as $input) {
            try {
                (new TimingIndex())->extract($input);
                self::fail('Accepted: ' . substr($input, 0, 20));
            } catch (TeamException $e) {
                self::assertSame(400, $e->getCode());
            }
        }
    }

    public function testWeeklySeriesKeepsItsLocalTimeAcrossBothChanges(): void
    {
        $spring = self::calendar(self::zurich(), self::event('DTSTART;TZID=Europe/Zurich:20270322T100000',
            'DTEND;TZID=Europe/Zurich:20270322T110000', 'RRULE:FREQ=WEEKLY;COUNT=3'));
        self::assertSame(['2027-03-22T09:00Z/2027-03-22T10:00Z', '2027-03-29T08:00Z/2027-03-29T09:00Z', '2027-04-05T08:00Z/2027-04-05T09:00Z'],
            $this->expand($spring, '2027-03-01 00:00:00', '2027-05-01 00:00:00'));

        $autumn = self::calendar(self::zurich(), self::event('DTSTART;TZID=Europe/Zurich:20271025T100000',
            'DTEND;TZID=Europe/Zurich:20271025T110000', 'RRULE:FREQ=WEEKLY'));
        self::assertSame(['2027-10-25T08:00Z/2027-10-25T09:00Z', '2027-11-01T09:00Z/2027-11-01T10:00Z'],
            $this->expand($autumn, '2027-10-20 00:00:00', '2027-11-05 00:00:00'));
    }

    public function testExceptionsOverridesAndCancelledInstances(): void
    {
        $ics = self::calendar(self::zurich(),
            self::event('DTSTART;TZID=Europe/Zurich:20270104T100000', 'DTEND;TZID=Europe/Zurich:20270104T110000',
                'RRULE:FREQ=DAILY;COUNT=5', 'EXDATE;TZID=Europe/Zurich:20270105T100000'),
            self::event('RECURRENCE-ID;TZID=Europe/Zurich:20270106T100000', 'DTSTART;TZID=Europe/Zurich:20270106T140000',
                'DTEND;TZID=Europe/Zurich:20270106T150000'),
            self::event('RECURRENCE-ID;TZID=Europe/Zurich:20270107T100000', 'DTSTART;TZID=Europe/Zurich:20270107T100000',
                'DTEND;TZID=Europe/Zurich:20270107T110000', 'STATUS:CANCELLED')
        );

        self::assertSame(['2027-01-04T09:00Z/2027-01-04T10:00Z', '2027-01-06T13:00Z/2027-01-06T14:00Z', '2027-01-08T09:00Z/2027-01-08T10:00Z'],
            $this->expand($ics, '2027-01-01 00:00:00', '2027-01-31 00:00:00'));
    }

    public function testAllDayEventCoversTheLocalDayOfTheScheduleZone(): void
    {
        $ics = self::calendar(self::event('DTSTART;VALUE=DATE:20270328', 'DTEND;VALUE=DATE:20270329'));

        // The day of the spring change has 23 hours.
        self::assertSame(['2027-03-27T23:00Z/2027-03-28T22:00Z'], $this->expand($ics, '2027-03-20 00:00:00', '2027-04-05 00:00:00'));
        self::assertSame(['2027-03-28T00:00Z/2027-03-29T00:00Z'], $this->expand($ics, '2027-03-20 00:00:00', '2027-04-05 00:00:00', 'UTC'));

        $twoDays = self::calendar(self::event('DTSTART;VALUE=DATE:20271030', 'DTEND;VALUE=DATE:20271101'));
        self::assertSame(['2027-10-29T22:00Z/2027-10-31T23:00Z'], $this->expand($twoDays, '2027-10-20 00:00:00', '2027-11-05 00:00:00'));
    }

    public function testFloatingAndUnresolvableZonesUseTheScheduleZone(): void
    {
        $floating = self::calendar(self::event('DTSTART:20270115T090000', 'DTEND:20270115T100000'));
        self::assertSame(['2027-01-15T08:00Z/2027-01-15T09:00Z'], $this->expand($floating, '2027-01-01 00:00:00', '2027-02-01 00:00:00'));
        self::assertSame(['2027-01-15T09:00Z/2027-01-15T10:00Z'], $this->expand($floating, '2027-01-01 00:00:00', '2027-02-01 00:00:00', 'UTC'));

        $unknown = self::calendar(self::event('DTSTART;TZID=Custom/Nowhere:20270115T090000', 'DTEND;TZID=Custom/Nowhere:20270115T100000'));
        self::assertStringNotContainsString('Custom/Nowhere', (new TimingIndex())->extract($unknown));
        self::assertSame(['2027-01-15T08:00Z/2027-01-15T09:00Z'], $this->expand($unknown, '2027-01-01 00:00:00', '2027-02-01 00:00:00'));

        $windows = self::calendar(self::event('DTSTART;TZID="W. Europe Standard Time":20270115T100000',
            'DTEND;TZID="W. Europe Standard Time":20270115T110000'));
        self::assertSame(['2027-01-15T09:00Z/2027-01-15T10:00Z'], $this->expand($windows, '2027-01-01 00:00:00', '2027-02-01 00:00:00', 'UTC'));

        $utc = self::calendar(self::event('DTSTART:20270115T090000Z', 'DURATION:PT90M'));
        self::assertSame(['2027-01-15T09:00Z/2027-01-15T10:30Z'], $this->expand($utc, '2027-01-01 00:00:00', '2027-02-01 00:00:00'));
    }

    public function testFreeInstancesDoNotBlock(): void
    {
        foreach (['TRANSP:TRANSPARENT', 'STATUS:CANCELLED', 'X-MICROSOFT-CDO-BUSYSTATUS:FREE'] as $free) {
            $ics = self::calendar(self::event('DTSTART:20270115T090000Z', 'DTEND:20270115T100000Z', $free));
            self::assertSame([], $this->expand($ics, '2027-01-01 00:00:00', '2027-02-01 00:00:00'), $free);
        }

        $tentative = self::calendar(self::event('DTSTART:20270115T090000Z', 'DTEND:20270115T100000Z', 'STATUS:TENTATIVE'));
        self::assertSame(['2027-01-15T09:00Z/2027-01-15T10:00Z'], $this->expand($tentative, '2027-01-01 00:00:00', '2027-02-01 00:00:00'));
    }

    public function testOnlyInstancesTouchingTheWindowAreReturned(): void
    {
        $ics = self::calendar(self::event('DTSTART:20270101T090000Z', 'DTEND:20270101T100000Z', 'RRULE:FREQ=DAILY'));

        self::assertSame(['2027-01-10T09:00Z/2027-01-10T10:00Z', '2027-01-11T09:00Z/2027-01-11T10:00Z'],
            $this->expand($ics, '2027-01-10 09:30:00', '2027-01-11 10:00:00'));
        self::assertSame([], $this->expand($ics, '2026-12-01 00:00:00', '2026-12-31 00:00:00'));
    }

    public function testAnExpensiveRuleFailsClosed(): void
    {
        $ics = self::calendar(self::event('DTSTART:20000101T000000Z', 'DTEND:20000101T000100Z', 'RRULE:FREQ=MINUTELY;INTERVAL=2'));

        self::assertSame(['2027-01-10T00:00Z/2027-01-11T00:00Z'], $this->expand($ics, '2027-01-10 00:00:00', '2027-01-11 00:00:00'));
    }

    public function testRedactedCopyHasTheTimesAndNothingPersonal(): void
    {
        $index = new TimingIndex();
        $skeleton = $index->extract(self::calendar(self::event('DTSTART:20270115T090000Z', 'DTEND:20270115T100000Z', 'SUMMARY:Private')));
        $copy = $index->redacted($skeleton, 'calendar-3/event.ics', 'Busy');

        self::assertStringContainsString('SUMMARY:Busy', $copy);
        self::assertStringContainsString('CLASS:CONFIDENTIAL', $copy);
        self::assertStringContainsString('DTSTART:20270115T090000Z', $copy);
        self::assertStringNotContainsString('Private', $copy);
        self::assertStringNotContainsString('secret-uid', $copy);
        preg_match('/UID:(\S+)/', $copy, $uid);
        self::assertSame('busy-' . hash('sha256', 'com_appointments/redacted/v1|calendar-3/event.ics'), $uid[1]);
        self::assertSame($copy, $index->redacted($skeleton, 'calendar-3/event.ics', 'Busy'), 'A stable copy keeps its ETag');

        // A zone of the skeleton gets a VTIMEZONE written from PHP's zone data, never the client's.
        $zoned = $index->redacted($index->extract(self::calendar(self::zurich(), self::event('DTSTART;TZID=Europe/Zurich:20270615T090000',
            'DTEND;TZID=Europe/Zurich:20270615T100000'))), 'x', 'Busy');
        $unfolded = str_replace("\r\n ", '', $zoned);
        self::assertStringContainsString("BEGIN:VTIMEZONE\r\nTZID:Europe/Zurich\r\n", $unfolded);
        self::assertStringContainsString("TZOFFSETFROM:+0100\r\nTZOFFSETTO:+0200\r\n", $unfolded);
        self::assertStringContainsString('20270328T020000', $unfolded);
        self::assertStringNotContainsString('X-LIC-LOCATION', $unfolded);
        self::assertStringContainsString("TZOFFSETFROM:+0000\r\nTZOFFSETTO:+0000\r\n", TimingIndex::vtimezone('UTC'));
    }

    public function testEmailsFromParticipantsAndFreeText(): void
    {
        $ics = self::calendar(self::event('DTSTART:20270115T090000Z', 'ORGANIZER;CN=Host:mailto:Host@Example.test',
            'ATTENDEE;CN=Guest:MAILTO:guest%2Bcal@example.test', 'ATTENDEE:urn:uuid:not-an-address',
            'SUMMARY:Call with Foo.Bar@Example.org.', 'DESCRIPTION:Also anna@example.ch\\, and again host@example.test',
            'BEGIN:VALARM', 'ACTION:EMAIL', 'ATTENDEE:mailto:alarm@example.test', 'SUMMARY:x', 'DESCRIPTION:y', 'TRIGGER:-PT5M', 'END:VALARM'));

        self::assertSame(['alarm@example.test', 'anna@example.ch', 'foo.bar@example.org', 'guest+cal@example.test', 'host@example.test'],
            (new TimingIndex())->emails($ics));
    }
}
