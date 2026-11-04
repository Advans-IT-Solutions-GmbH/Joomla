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
            'X-SECRET', 'hidden-zone-note'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $unfolded, $forbidden . ' must not reach the skeleton');
        }

        foreach (['DTSTART;TZID=Europe/Zurich:20270322T100000', 'DTEND;TZID=Europe/Zurich:20270322T110000',
            'RRULE:FREQ=WEEKLY;COUNT=3', 'STATUS:CONFIRMED', 'TRANSP:OPAQUE', 'X-MICROSOFT-CDO-BUSYSTATUS:BUSY',
            'BEGIN:VTIMEZONE', 'TZID:Europe/Zurich', 'X-LIC-LOCATION:Europe/Zurich', 'TZOFFSETTO:+0200'] as $kept) {
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
