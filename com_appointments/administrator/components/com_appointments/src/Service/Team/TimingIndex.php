<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\AvailabilityWindow;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\VendorAutoloader;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Component;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Component\VCalendar;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Property;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Reader;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Recur\EventIterator;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Recur\NoInstancesException;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Settings;
use Advans\Component\Appointments\Vendor\Sabre\VObject\TimeZoneUtil;

defined('_JEXEC') or die;

/**
 * The time skeleton of calendar objects (work package E), see
 * {@see TimingIndexInterface}. Pure: no database, no key, no clock.
 *
 * The skeleton is written, never copied: every kept line is rebuilt from a
 * validated value, so no text of the client can reach it (no property group,
 * no parameter, no free-text time zone name, no VTIMEZONE of the client):
 *
 * - Date values: `YYYYMMDD` or `YYYYMMDDTHHMMSS[Z]`, checked as real dates.
 *   `VALUE=DATE` is the only parameter kept besides `TZID` and `RANGE`.
 * - `TZID` is replaced by the IANA identifier the bundled time zone tables
 *   resolve it to with certainty (Windows and Lotus names, `X-LIC-LOCATION`,
 *   `X-MICROSOFT-CDO-TZID` of the client's VTIMEZONE). A zone that cannot be
 *   resolved with certainty is dropped, so the time counts as floating, in the
 *   zone of the schedule that asks. No VTIMEZONE is kept: the expansion uses
 *   PHP's zone data, and the redacted copy writes its own VTIMEZONE from it.
 * - An RRULE is checked part by part against RFC 5545 and rebuilt. A rule the
 *   recurrence library cannot expand in bounded time is refused (400) instead
 *   of being dropped, because dropping it would hide busy time.
 * - Enumerations (STATUS, TRANSP, X-MICROSOFT-CDO-BUSYSTATUS) only with a
 *   defined value; RDATE of type PERIOD is dropped.
 *
 * The expansion fails closed: a rule that needs more than {@see self::MAX_SKIP_STEPS}
 * steps before the window or {@see self::MAX_STEPS} instances inside it, or
 * any error of the library, makes the rest of the window busy. A series with
 * RRULE and RDATE is expanded for both (the library would read only RDATE). An
 * override with `RANGE=THISANDFUTURE` (which the library does not apply) makes
 * the window busy from that instance on.
 *
 * @since  1.4.0
 */
final class TimingIndex implements TimingIndexInterface
{
    /** Instances inside the window, per object and expansion. */
    public const MAX_STEPS = 20000;

    /** Steps before the window, per object and expansion (an old daily series is cheap, a minutely one is not). */
    public const MAX_SKIP_STEPS = 100000;

    /** Largest text extract() reads; the object store refuses anything above 1 MiB before. */
    private const MAX_BYTES = 2097152;

    /** Values in one RDATE or EXDATE line, and in one RRULE part. */
    private const MAX_VALUES = 1000;

    private const PRODID = '-//Advans IT Solutions GmbH//Appointments timing//EN';

    private const EVENT_PROPERTIES = ['DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID',
        'STATUS', 'TRANSP', 'X-MICROSOFT-CDO-BUSYSTATUS'];

    private const DATE_PROPERTIES = ['DTSTART', 'DTEND', 'RDATE', 'EXDATE', 'RECURRENCE-ID'];

    private const ENUMERATIONS = [
        'STATUS' => ['TENTATIVE', 'CONFIRMED', 'CANCELLED'],
        'TRANSP' => ['OPAQUE', 'TRANSPARENT'],
        'X-MICROSOFT-CDO-BUSYSTATUS' => ['FREE', 'TENTATIVE', 'BUSY', 'OOF', 'WORKINGELSEWHERE'],
    ];

    private const FREQUENCIES = ['SECONDLY', 'MINUTELY', 'HOURLY', 'DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];

    /** Order in which a rebuilt RRULE lists its parts. */
    private const RRULE_PARTS = ['FREQ', 'UNTIL', 'COUNT', 'INTERVAL', 'BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYDAY',
        'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS', 'WKST'];

    /** Inclusive integer ranges of the numeric RRULE list parts; `signed` allows the negative range too. */
    private const RRULE_RANGES = [
        'BYSECOND' => [0, 60, false], 'BYMINUTE' => [0, 59, false], 'BYHOUR' => [0, 23, false],
        'BYMONTHDAY' => [1, 31, true], 'BYYEARDAY' => [1, 366, true], 'BYWEEKNO' => [1, 53, true],
        'BYMONTH' => [1, 12, false], 'BYSETPOS' => [1, 366, true],
    ];

    private const WEEKDAYS = ['MO', 'TU', 'WE', 'TH', 'FR', 'SA', 'SU'];

    /** A fixed DTSTAMP for the redacted copy: RFC 5545 requires one, a changing one would change the ETag. */
    private const REDACTED_STAMP = '20000101T000000Z';

    /** Range of the transitions the redacted copy's VTIMEZONE lists (fixed, so the copy is stable). */
    private const ZONE_FROM = '1970-01-01 00:00:00';
    private const ZONE_TO = '2051-01-01 00:00:00';

    /**
     * @var  array<string, true>|null
     */
    private static $identifiers = null;

    /**
     * The timing index needs no other service; the parameter keeps the
     * constructor signature that {@see TeamServices} uses for every service.
     */
    public function __construct(?TeamServices $services = null)
    {
    }

    public function extract(string $ics): string
    {
        if (\strlen($ics) > self::MAX_BYTES) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400);
        }

        $calendar = $this->read($ics, Reader::OPTION_FORGIVING);
        $events = $calendar->select('VEVENT');

        if ($events === []) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400);
        }

        $body = '';

        foreach ($events as $event) {
            $lines = $this->eventLines($calendar, $event);

            if ($lines !== null) {
                $body .= "BEGIN:VEVENT\r\n" . $lines . "END:VEVENT\r\n";
            }
        }

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:" . self::PRODID . "\r\n" . $body . "END:VCALENDAR\r\n";
    }

    public function expand(string $skeleton, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, \DateTimeZone $floatingZone): array
    {
        $utc = new \DateTimeZone('UTC');
        $fromUtc = $fromUtc->setTimezone($utc);
        $toUtc = $toUtc->setTimezone($utc);

        if ($toUtc <= $fromUtc) {
            return [];
        }

        $events = $this->read($skeleton, 0)->select('VEVENT');

        if ($events === []) {
            return [];
        }

        $masters = [];
        $overrides = [];

        foreach ($events as $event) {
            if (isset($event->{'RECURRENCE-ID'})) {
                $overrides[] = $event;
            } else {
                $masters[] = $event;
            }
        }

        $runs = [$events];
        $master = $masters[0] ?? null;

        if ($master !== null && isset($master->RRULE) && isset($master->RDATE)) {
            // The library reads only RDATE when both are present: expand each on its own.
            $byRule = clone $master;
            $byRule->remove('RDATE');
            $byDate = clone $master;
            $byDate->remove('RRULE');
            $runs = [array_merge([$byRule], $overrides), array_merge([$byDate], $overrides)];
        }

        $windows = [];

        foreach ($runs as $run) {
            $windows = array_merge($windows, $this->iterate($run, $fromUtc, $toUtc, $floatingZone));
        }

        foreach ($overrides as $override) {
            $id = $override->{'RECURRENCE-ID'};

            if (isset($id['RANGE']) && strtoupper((string) $id['RANGE']) === 'THISANDFUTURE') {
                try {
                    $at = \DateTimeImmutable::createFromInterface($id->getDateTime($floatingZone))->setTimezone($utc);
                } catch (\Throwable $e) {
                    $at = $fromUtc;
                }

                if ($at < $toUtc) {
                    $windows[] = new AvailabilityWindow(max($at, $fromUtc), $toUtc);
                }
            }
        }

        $unique = [];

        foreach ($windows as $window) {
            $unique[$window->getStart()->getTimestamp() . '/' . $window->getEnd()->getTimestamp()] = $window;
        }

        ksort($unique, SORT_NATURAL);

        return array_values($unique);
    }

    public function lastEnd(string $skeleton, \DateTimeZone $floatingZone): ?\DateTimeImmutable
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function redacted(string $skeleton, string $uidSeed, string $summary): string
    {
        // Our own skeleton format: read it once to make sure it is one, then insert lines.
        $this->read($skeleton, 0);
        $uid = 'busy-' . hash('sha256', 'com_appointments/redacted/v1|' . $uidSeed);
        $added = "BEGIN:VEVENT\r\nUID:" . $uid . "\r\nDTSTAMP:" . self::REDACTED_STAMP . "\r\n"
            . IcsWriter::fold('SUMMARY:' . IcsWriter::escapeText($summary)) . "\r\nCLASS:CONFIDENTIAL\r\n";
        $copy = str_replace("BEGIN:VEVENT\r\n", $added, $skeleton);
        preg_match_all('/;TZID=([A-Za-z0-9_+\/-]+)[;:]/', str_replace("\r\n ", '', $skeleton), $matches);
        $zones = array_values(array_unique($matches[1]));
        sort($zones, SORT_STRING);
        $timezones = '';

        foreach ($zones as $zone) {
            $timezones .= self::vtimezone($zone);
        }

        return str_replace("PRODID:" . self::PRODID . "\r\n", "PRODID:" . self::PRODID . "\r\n" . $timezones, $copy);
    }

    /**
     * Addresses from ORGANIZER and ATTENDEE (`mailto:`, percent-decoded, without
     * a query) and every address found in SUMMARY, LOCATION and DESCRIPTION, in
     * every component of the object (alarms included), sorted.
     */
    public function emails(string $ics): array
    {
        if (\strlen($ics) > self::MAX_BYTES) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400);
        }

        $found = [];
        $pending = [$this->read($ics, Reader::OPTION_FORGIVING)];

        while ($pending !== []) {
            $component = array_pop($pending);

            foreach ($component->children() as $child) {
                if ($child instanceof Component) {
                    $pending[] = $child;

                    continue;
                }

                if (!$child instanceof Property) {
                    continue;
                }

                if (\in_array($child->name, ['ORGANIZER', 'ATTENDEE'], true)) {
                    $value = trim((string) $child->getValue());

                    if (stripos($value, 'mailto:') === 0) {
                        $address = rawurldecode(explode('?', substr($value, 7), 2)[0]);

                        if (self::looksLikeAddress($address)) {
                            $found[self::normalise($address)] = true;
                        }
                    }
                } elseif (\in_array($child->name, ['SUMMARY', 'LOCATION', 'DESCRIPTION'], true)) {
                    foreach (self::addressesIn((string) $child->getValue()) as $address) {
                        $found[$address] = true;
                    }
                }
            }
        }

        $addresses = array_keys($found);
        sort($addresses, SORT_STRING);

        return $addresses;
    }

    /**
     * Every address in a free text, normalised.
     *
     * @return  string[]
     */
    public static function addressesIn(string $text): array
    {
        if ($text === '' || preg_match('//u', $text) !== 1) {
            return [];
        }

        preg_match_all('/[\p{L}\p{N}.!#$%&\'*+\/=?^_`{|}~-]{1,64}@[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?(?:\.[\p{L}\p{N}](?:[\p{L}\p{N}-]{0,61}[\p{L}\p{N}])?)+/u', $text, $matches);
        $out = [];

        foreach ($matches[0] as $match) {
            $match = trim($match, '.');

            if (self::looksLikeAddress($match)) {
                $out[self::normalise($match)] = true;
            }
        }

        return array_keys($out);
    }

    /** The form addresses are compared in: trimmed, lower case. */
    public static function normalise(string $address): string
    {
        return mb_strtolower(trim($address), 'UTF-8');
    }

    /**
     * A VTIMEZONE for an IANA zone from PHP's zone data: one STANDARD or
     * DAYLIGHT part per kind of transition between 1970 and 2050, the onsets as
     * DTSTART and RDATE in local time. Always the same text for the same zone
     * data, so the redacted copy keeps its ETag.
     */
    public static function vtimezone(string $name): string
    {
        $zone = new \DateTimeZone($name);
        $utc = new \DateTimeZone('UTC');
        $from = (new \DateTimeImmutable(self::ZONE_FROM, $utc))->getTimestamp();
        $to = (new \DateTimeImmutable(self::ZONE_TO, $utc))->getTimestamp();
        $transitions = $zone->getTransitions($from, $to) ?: [];
        $groups = [];

        for ($i = 1, $n = \count($transitions); $i < $n; $i++) {
            $previous = (int) $transitions[$i - 1]['offset'];
            $offset = (int) $transitions[$i]['offset'];
            $local = gmdate('Ymd\THis', (int) $transitions[$i]['ts'] + $previous);
            $kind = $transitions[$i]['isdst'] ? 'DAYLIGHT' : 'STANDARD';
            $groups[$kind . '|' . $previous . '|' . $offset][] = $local;
        }

        if ($groups === []) {
            $offset = (int) (($transitions[0]['offset'] ?? null) ?? $zone->getOffset(new \DateTimeImmutable(self::ZONE_FROM, $utc)));
            $groups['STANDARD|' . $offset . '|' . $offset] = ['19700101T000000'];
        }

        $lines = ['BEGIN:VTIMEZONE', 'TZID:' . $name];

        foreach ($groups as $key => $onsets) {
            [$kind, $previous, $offset] = explode('|', $key);
            $lines[] = 'BEGIN:' . $kind;
            $lines[] = 'DTSTART:' . $onsets[0];

            if (\count($onsets) > 1) {
                $lines[] = 'RDATE:' . implode(',', \array_slice($onsets, 1));
            }

            $lines[] = 'TZOFFSETFROM:' . self::offset((int) $previous);
            $lines[] = 'TZOFFSETTO:' . self::offset((int) $offset);
            $lines[] = 'END:' . $kind;
        }

        $lines[] = 'END:VTIMEZONE';

        return implode("\r\n", array_map([IcsWriter::class, 'fold'], $lines)) . "\r\n";
    }

    private static function offset(int $seconds): string
    {
        $sign = $seconds < 0 ? '-' : '+';
        $seconds = abs($seconds);
        $text = sprintf('%s%02d%02d', $sign, intdiv($seconds, 3600), intdiv($seconds % 3600, 60));

        return $seconds % 60 === 0 ? $text : $text . sprintf('%02d', $seconds % 60);
    }

    private function read(string $text, int $options): VCalendar
    {
        if (!VendorAutoloader::register() && !class_exists(Reader::class)) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_E_LIBRARY_MISSING', 503);
        }

        try {
            $calendar = Reader::read($text, $options);
        } catch (\Throwable $e) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400, $e);
        }

        if (!$calendar instanceof VCalendar) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400);
        }

        return $calendar;
    }

    /**
     * The rebuilt lines of one VEVENT, null when it has no usable DTSTART.
     *
     * @throws  TeamException  400 for an RRULE outside RFC 5545 or one the library cannot expand.
     */
    private function eventLines(VCalendar $calendar, Component $event): ?string
    {
        $lines = [];
        $seen = [];

        foreach ($event->children() as $property) {
            if (!$property instanceof Property) {
                continue;
            }

            $name = strtoupper((string) $property->name);

            if (!\in_array($name, self::EVENT_PROPERTIES, true)) {
                continue;
            }

            $multiple = \in_array($name, ['RDATE', 'EXDATE'], true);

            if (!$multiple && isset($seen[$name])) {
                continue;
            }

            if (\in_array($name, self::DATE_PROPERTIES, true)) {
                $line = $this->dateLine($calendar, $property, $name);
            } elseif ($name === 'RRULE') {
                $line = 'RRULE:' . self::rule((string) $property->getValue());
            } elseif ($name === 'DURATION') {
                $line = $this->durationLine($property);
            } else {
                $value = strtoupper(trim((string) $property->getValue()));
                $line = \in_array($value, self::ENUMERATIONS[$name], true) ? $name . ':' . $value : null;
            }

            if ($line !== null) {
                $seen[$name] = true;
                $lines[] = IcsWriter::fold($line) . "\r\n";
            }
        }

        return isset($seen['DTSTART']) ? implode('', $lines) : null;
    }

    private function dateLine(VCalendar $calendar, Property $property, string $name): ?string
    {
        $type = isset($property['VALUE']) ? strtoupper((string) $property['VALUE']) : '';

        if ($type !== '' && $type !== 'DATE' && $type !== 'DATE-TIME') {
            return null;
        }

        $values = [];

        foreach ($property->getParts() as $part) {
            if (!\is_scalar($part)) {
                return null;
            }

            $values[] = strtoupper(trim((string) $part));
        }

        if ($values === [] || \count($values) > self::MAX_VALUES
            || (\count($values) > 1 && !\in_array($name, ['RDATE', 'EXDATE'], true))) {
            return null;
        }

        // Some clients write an all-day date without VALUE=DATE.
        $date = $type === 'DATE' || ($type === '' && \count(preg_grep('/^\d{8}$/D', $values)) === \count($values));
        $utc = 0;

        foreach ($values as $value) {
            if (!self::validDateValue($value, $date)) {
                return null;
            }

            $utc += str_ends_with($value, 'Z') ? 1 : 0;
        }

        if ($utc !== 0 && $utc !== \count($values)) {
            return null;
        }

        $parameters = $date ? ';VALUE=DATE' : '';

        if (!$date && $utc === 0 && isset($property['TZID'])) {
            $zone = $this->canonicalZone($calendar, (string) $property['TZID']);
            $parameters .= $zone === null ? '' : ';TZID=' . $zone;
        }

        if ($name === 'RECURRENCE-ID' && isset($property['RANGE']) && strtoupper((string) $property['RANGE']) === 'THISANDFUTURE') {
            $parameters .= ';RANGE=THISANDFUTURE';
        }

        return $name . $parameters . ':' . implode(',', $values);
    }

    private static function validDateValue(string $value, bool $date): bool
    {
        if (!preg_match($date ? '/^(\d{4})(\d{2})(\d{2})$/D' : '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/D', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[1] >= 1900 && (int) $m[1] <= 9998
            && ($date || ((int) $m[4] <= 23 && (int) $m[5] <= 59 && (int) $m[6] <= 60));
    }

    private function durationLine(Property $property): ?string
    {
        $value = strtoupper(trim((string) $property->getValue()));

        if (!preg_match('/^\+?P(?:\d{1,6}W|(?:\d{1,6}D)?(?:T(?:\d{1,6}H)?(?:\d{1,6}M)?(?:\d{1,6}S)?)?)$/D', $value)
            || preg_match('/^\+?PT?$/D', $value)) {
            return null;
        }

        return 'DURATION:' . ltrim($value, '+');
    }

    /**
     * An RRULE value checked against RFC 5545 and rebuilt in a fixed order.
     * Refused besides what RFC 5545 forbids: parts unknown to the library,
     * BYYEARDAY together with BYDAY and BYWEEKNO together with an ordinal BYDAY
     * (the library searches those without end when no day matches).
     *
     * @throws  TeamException  400
     */
    public static function rule(string $value): string
    {
        $parts = [];

        foreach (explode(';', strtoupper(trim($value))) as $part) {
            if ($part === '') {
                continue;
            }

            $pair = explode('=', $part, 2);

            if (\count($pair) !== 2 || !\in_array($pair[0], self::RRULE_PARTS, true) || isset($parts[$pair[0]])) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400);
            }

            $values = explode(',', $pair[1]);

            if (\count($values) > self::MAX_VALUES || \in_array('', $values, true)) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400);
            }

            $parts[$pair[0]] = $values;
        }

        $single = static function (string $key) use ($parts): ?string {
            if (!isset($parts[$key])) {
                return null;
            }

            if (\count($parts[$key]) !== 1) {
                throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400);
            }

            return $parts[$key][0];
        };
        $frequency = $single('FREQ');
        $ok = $frequency !== null && \in_array($frequency, self::FREQUENCIES, true);
        $interval = $single('INTERVAL');
        $count = $single('COUNT');
        $until = $single('UNTIL');
        $start = $single('WKST');
        $ok = $ok && ($interval === null || (preg_match('/^\d{1,4}$/D', $interval) && (int) $interval >= 1 && (int) $interval <= 1000))
            && ($count === null || (preg_match('/^\d{1,6}$/D', $count) && (int) $count >= 1))
            && ($until === null || self::validDateValue($until, \strlen($until) === 8))
            && !($count !== null && $until !== null)
            && ($start === null || \in_array($start, self::WEEKDAYS, true));

        foreach (self::RRULE_RANGES as $key => [$low, $high, $signed]) {
            foreach ($parts[$key] ?? [] as $item) {
                $number = preg_match('/^[+-]?\d{1,3}$/D', $item) ? (int) $item : null;
                $absolute = $number === null ? -1 : abs($number);
                $ok = $ok && $number !== null && $absolute >= $low && $absolute <= $high && ($signed || $number >= 0)
                    && !($signed && $number === 0);
            }
        }

        $ordinal = false;

        foreach ($parts['BYDAY'] ?? [] as $day) {
            if (!preg_match('/^([+-]?(?:[1-9]|[1-4]\d|5[0-3]))?(MO|TU|WE|TH|FR|SA|SU)$/D', $day, $m)) {
                $ok = false;
            }

            $ordinal = $ordinal || (($m[1] ?? '') !== '');
        }

        // RFC 5545 3.3.10 and the library's limits.
        $ok = $ok
            && (!isset($parts['BYWEEKNO']) || $frequency === 'YEARLY')
            && (!isset($parts['BYYEARDAY']) || !\in_array($frequency, ['DAILY', 'WEEKLY', 'MONTHLY'], true))
            && (!isset($parts['BYMONTHDAY']) || $frequency !== 'WEEKLY')
            && (!$ordinal || \in_array($frequency, ['MONTHLY', 'YEARLY'], true))
            && !(isset($parts['BYYEARDAY']) && isset($parts['BYDAY']))
            && !(isset($parts['BYWEEKNO']) && $ordinal);

        if (!$ok) {
            throw new TeamException('COM_APPOINTMENTS_TEAM_E_TIMING_INVALID', 400);
        }

        $rebuilt = [];

        foreach (self::RRULE_PARTS as $key) {
            if (isset($parts[$key])) {
                $rebuilt[] = $key . '=' . implode(',', $parts[$key]);
            }
        }

        return implode(';', $rebuilt);
    }

    /** The IANA identifier a TZID resolves to with certainty, or null (the time is then floating). */
    private function canonicalZone(VCalendar $calendar, string $tzid): ?string
    {
        if ($tzid === '' || \strlen($tzid) > 255) {
            return null;
        }

        try {
            $name = TimeZoneUtil::getTimeZone($tzid, $calendar, true)->getName();
        } catch (\Throwable $e) {
            return null;
        }

        self::$identifiers ??= array_fill_keys(\DateTimeZone::listIdentifiers(), true);

        return isset(self::$identifiers[$name]) ? $name : null;
    }

    /**
     * Busy windows of one expansion run.
     *
     * @param   Component[]  $events
     *
     * @return  AvailabilityWindow[]
     */
    private function iterate(array $events, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, \DateTimeZone $floatingZone): array
    {
        $utc = new \DateTimeZone('UTC');
        $windows = [];
        $reached = $fromUtc;
        // The library's own limit counts the same steps; ours below is the one that decides.
        $limited = property_exists(Settings::class, 'maxRecurrences');
        $previous = $limited ? Settings::$maxRecurrences : null;

        if ($limited) {
            Settings::$maxRecurrences = self::MAX_SKIP_STEPS + self::MAX_STEPS + 1;
        }

        try {
            try {
                $iterator = new EventIterator($events, null, $floatingZone);
            } catch (NoInstancesException $e) {
                // Every instance excluded, or UNTIL before DTSTART: nothing is busy.
                return [];
            }

            $skipped = 0;
            $inside = 0;

            while ($iterator->valid()) {
                $start = \DateTimeImmutable::createFromInterface($iterator->getDtStart());

                if ($start >= $toUtc) {
                    break;
                }

                $end = \DateTimeImmutable::createFromInterface($iterator->getDtEnd());

                if ($end > $fromUtc) {
                    if (++$inside > self::MAX_STEPS) {
                        throw new \OverflowException('Too many instances in the window.');
                    }

                    $reached = max($reached, $start->setTimezone($utc));
                    $instance = $iterator->getEventObject();

                    if ($this->blocks($instance)) {
                        $window = $this->window($instance, $start, $end, $floatingZone);

                        if ($window !== null && $window->getEnd() > $fromUtc) {
                            $windows[] = $window;
                        }
                    }
                } elseif (++$skipped > self::MAX_SKIP_STEPS) {
                    throw new \OverflowException('Too many steps before the window.');
                }

                $iterator->next();
            }
        } catch (\Throwable $e) {
            // Too many steps or a rule the library cannot expand: fail closed.
            $windows[] = new AvailabilityWindow($reached, $toUtc);
        } finally {
            if ($limited) {
                Settings::$maxRecurrences = $previous;
            }
        }

        return $windows;
    }

    private function blocks(Component $event): bool
    {
        foreach (['STATUS' => 'CANCELLED', 'TRANSP' => 'TRANSPARENT', 'X-MICROSOFT-CDO-BUSYSTATUS' => 'FREE'] as $name => $free) {
            if (isset($event->{$name}) && strtoupper((string) $event->{$name}->getValue()) === $free) {
                return false;
            }
        }

        return true;
    }

    /**
     * The busy window of one instance in UTC. An all-day instance covers whole
     * days of the floating zone, counted in calendar days, so a day with a
     * daylight saving change is 23 or 25 hours long, never shifted by one hour.
     */
    private function window(Component $event, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $floatingZone): ?AvailabilityWindow
    {
        $utc = new \DateTimeZone('UTC');

        if (isset($event->DTSTART) && method_exists($event->DTSTART, 'hasTime') && !$event->DTSTART->hasTime()) {
            $days = max(1, (int) round(($end->getTimestamp() - $start->getTimestamp()) / 86400));
            $start = $start->setTimezone($floatingZone)->setTime(0, 0);
            $end = $start->modify('+' . $days . ' days');
        }

        $start = $start->setTimezone($utc);
        $end = $end->setTimezone($utc);

        return $end > $start ? new AvailabilityWindow($start, $end) : null;
    }

    private static function looksLikeAddress(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && \strlen($value) <= 254 && preg_match('//u', $value) === 1
            && preg_match('/^[^@\s<>()\[\],;:"\\\\]+@[^@\s<>()\[\],;:"\\\\]+\.[^@\s<>()\[\],;:"\\\\.]+$/u', $value) === 1;
    }
}
