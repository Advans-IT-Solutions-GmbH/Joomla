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
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\VendorAutoloader;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Component;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Component\VCalendar;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Property;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Reader;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Recur\EventIterator;
use Advans\Component\Appointments\Vendor\Sabre\VObject\Settings;
use Advans\Component\Appointments\Vendor\Sabre\VObject\TimeZoneUtil;

defined('_JEXEC') or die;

/**
 * The time skeleton of calendar objects (work package E), see
 * {@see TimingIndexInterface}. Pure: no database, no key, no clock.
 *
 * Choices this class makes inside the contract:
 *
 * - Parameters are kept only where they change the meaning of a time: `TZID`
 *   and `VALUE` (DATE or DATE-TIME) on the date properties, `RANGE` on
 *   RECURRENCE-ID. Every other parameter, including any `X-` parameter, is
 *   dropped, so no free text of the client reaches the skeleton through a
 *   parameter. Enumerated values (STATUS, TRANSP, X-MICROSOFT-CDO-BUSYSTATUS)
 *   are kept only when they are one of the defined values; an RRULE is rebuilt
 *   from its known parts.
 * - A `TZID` that the bundled time zone tables cannot resolve with certainty is
 *   removed, so the time is read as floating, in the zone of the schedule that
 *   asks. A guess would silently shift a busy time by hours.
 * - A VTIMEZONE is kept only when a kept TZID refers to it, with its TZID, the
 *   zone hints clients use (`X-LIC-LOCATION`, `X-MICROSOFT-CDO-TZID`) and the
 *   offsets and rules of its STANDARD and DAYLIGHT parts.
 * - RDATE values of type PERIOD are dropped: the recurrence expansion cannot
 *   read them.
 * - An expansion that needs more than {@see self::MAX_STEPS} recurrence steps
 *   fails closed: from the last instance it reached to the end of the window
 *   everything counts as busy. A slot is never offered because a rule was too
 *   expensive to expand.
 *
 * @since  1.4.0
 */
final class TimingIndex implements TimingIndexInterface
{
    /** Recurrence steps per object and expansion, counted from the series start. */
    public const MAX_STEPS = 20000;

    /** Largest text extract() reads; the object store refuses anything above 1 MiB before. */
    private const MAX_BYTES = 2097152;

    private const EVENT_PROPERTIES = ['DTSTART', 'DTEND', 'DURATION', 'RRULE', 'RDATE', 'EXDATE', 'RECURRENCE-ID',
        'STATUS', 'TRANSP', 'X-MICROSOFT-CDO-BUSYSTATUS'];

    private const DATE_PROPERTIES = ['DTSTART', 'DTEND', 'RDATE', 'EXDATE', 'RECURRENCE-ID'];

    private const ENUMERATIONS = [
        'STATUS' => ['TENTATIVE', 'CONFIRMED', 'CANCELLED'],
        'TRANSP' => ['OPAQUE', 'TRANSPARENT'],
        'X-MICROSOFT-CDO-BUSYSTATUS' => ['FREE', 'TENTATIVE', 'BUSY', 'OOF', 'WORKINGELSEWHERE'],
    ];

    private const RRULE_PARTS = ['FREQ', 'UNTIL', 'COUNT', 'INTERVAL', 'BYSECOND', 'BYMINUTE', 'BYHOUR', 'BYDAY',
        'BYMONTHDAY', 'BYYEARDAY', 'BYWEEKNO', 'BYMONTH', 'BYSETPOS', 'WKST'];

    private const ZONE_PROPERTIES = ['DTSTART', 'TZOFFSETFROM', 'TZOFFSETTO', 'RRULE', 'RDATE'];

    /** A fixed DTSTAMP for the redacted copy: RFC 5545 requires one, a changing one would change the ETag. */
    private const REDACTED_STAMP = '20000101T000000Z';

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

        $zones = [];
        $body = '';

        foreach ($events as $event) {
            $lines = $this->eventLines($calendar, $event, $zones);

            if ($lines !== '') {
                $body .= "BEGIN:VEVENT\r\n" . $lines . "END:VEVENT\r\n";
            }
        }

        $timezones = '';

        foreach ($calendar->select('VTIMEZONE') as $timezone) {
            $tzid = isset($timezone->TZID) ? (string) $timezone->TZID->getValue() : '';

            if (isset($zones[$tzid])) {
                unset($zones[$tzid]);
                $timezones .= $this->timezoneLines($timezone);
            }
        }

        return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Advans IT Solutions GmbH//Appointments timing//EN\r\n"
            . $timezones . $body . "END:VCALENDAR\r\n";
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

        $windows = [];
        $steps = 0;
        $reached = $fromUtc;
        // The library's own limit counts the same steps; ours below is the one that decides.
        $limited = property_exists(Settings::class, 'maxRecurrences');
        $previous = $limited ? Settings::$maxRecurrences : null;

        if ($limited) {
            Settings::$maxRecurrences = self::MAX_STEPS + 1;
        }

        try {
            try {
                $iterator = new EventIterator($events, null, $floatingZone);
            } catch (\InvalidArgumentException $e) {
                // No master and no instance (NoInstancesException extends it): nothing is busy.
                return [];
            }

            while ($iterator->valid()) {
                $start = \DateTimeImmutable::createFromInterface($iterator->getDtStart());

                if ($start >= $toUtc) {
                    break;
                }

                $reached = max($reached, $start->setTimezone($utc));
                $end = \DateTimeImmutable::createFromInterface($iterator->getDtEnd());

                if ($end > $fromUtc) {
                    $instance = $iterator->getEventObject();

                    if ($this->blocks($instance)) {
                        $window = $this->window($instance, $start, $end, $floatingZone);

                        if ($window !== null && $window->getEnd() > $fromUtc) {
                            $windows[] = $window;
                        }
                    }
                }

                if (++$steps > self::MAX_STEPS) {
                    throw new \OverflowException('Too many recurrence steps.');
                }

                $iterator->next();
            }
        } catch (\Throwable $e) {
            // Too many recurrence steps or a rule the library cannot expand: fail closed.
            $windows[] = new AvailabilityWindow($reached, $toUtc);
        } finally {
            if ($limited) {
                Settings::$maxRecurrences = $previous;
            }
        }

        return $windows;
    }

    public function lastEnd(string $skeleton, \DateTimeZone $floatingZone): ?\DateTimeImmutable
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function redacted(string $skeleton, string $uidSeed, string $summary): string
    {
        $calendar = $this->read($skeleton, 0);
        $uid = 'busy-' . hash('sha256', 'com_appointments/redacted/v1|' . $uidSeed);

        foreach ($calendar->select('VEVENT') as $event) {
            $event->add('UID', $uid);
            $event->add('DTSTAMP', self::REDACTED_STAMP);
            $event->add('SUMMARY', $summary);
            $event->add('CLASS', 'CONFIDENTIAL');
        }

        return $calendar->serialize();
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
     * The kept lines of one VEVENT, empty when it has no usable DTSTART.
     *
     * @param   array<string, true>  $zones  TZIDs referenced so far, extended here.
     */
    private function eventLines(VCalendar $calendar, Component $event, array &$zones): string
    {
        $lines = '';
        $seen = [];
        $referenced = [];

        foreach ($event->children() as $property) {
            if (!$property instanceof Property || !\in_array($property->name, self::EVENT_PROPERTIES, true)) {
                continue;
            }

            $name = $property->name;
            $single = !\in_array($name, ['RDATE', 'EXDATE'], true);

            if ($single && isset($seen[$name])) {
                continue;
            }

            $tzid = $this->clean($calendar, $property);

            if ($tzid === false) {
                continue;
            }

            if ($tzid !== null) {
                $referenced[$tzid] = true;
            }

            $seen[$name] = true;
            $lines .= $property->serialize();
        }

        if (!isset($seen['DTSTART'])) {
            return '';
        }

        $zones += $referenced;

        return $lines;
    }

    /**
     * Strip a property down to its time meaning, in place.
     *
     * @return  string|false|null  The kept TZID, null without one, false to drop the property.
     */
    private function clean(VCalendar $calendar, Property $property): string|false|null
    {
        $name = $property->name;
        $isDate = \in_array($name, self::DATE_PROPERTIES, true);

        foreach (array_keys($property->parameters()) as $parameter) {
            $parameter = strtoupper((string) $parameter);
            $keep = ($isDate && \in_array($parameter, ['TZID', 'VALUE'], true))
                || ($name === 'RECURRENCE-ID' && $parameter === 'RANGE');

            if (!$keep) {
                unset($property[$parameter]);
            }
        }

        if (isset($property['VALUE']) && !\in_array(strtoupper((string) $property['VALUE']), ['DATE', 'DATE-TIME'], true)) {
            return false;
        }

        if (isset($property['RANGE']) && strtoupper((string) $property['RANGE']) !== 'THISANDFUTURE') {
            unset($property['RANGE']);
        }

        if (isset(self::ENUMERATIONS[$name])) {
            $value = strtoupper(trim((string) $property->getValue()));

            if (!\in_array($value, self::ENUMERATIONS[$name], true)) {
                return false;
            }

            $property->setValue($value);

            return null;
        }

        if ($name === 'DURATION') {
            $value = strtoupper(trim((string) $property->getValue()));

            if (!preg_match('/^[+-]?P(?:\d{1,6}W|(?:\d{1,6}D)?(?:T(?:\d{1,6}H)?(?:\d{1,6}M)?(?:\d{1,6}S)?)?)$/D', $value)
                || preg_match('/^[+-]?PT?$/D', $value)) {
                return false;
            }

            $property->setValue($value);

            return null;
        }

        if ($name === 'RRULE') {
            return $this->cleanRule($property) ? null : false;
        }

        $tzid = null;

        if (isset($property['TZID'])) {
            $tzid = (string) $property['TZID'];

            if (!$this->resolvable($calendar, $tzid)) {
                unset($property['TZID']);
                $tzid = null;
            }
        }

        try {
            // Every kept date value must be readable by the expansion later.
            $property->getDateTimes(new \DateTimeZone('UTC'));
        } catch (\Throwable $e) {
            return false;
        }

        return $tzid;
    }

    private function cleanRule(Property $property): bool
    {
        if (!method_exists($property, 'getParts') || !method_exists($property, 'setParts')) {
            return false;
        }

        $parts = [];

        foreach ($property->getParts() as $key => $value) {
            $key = strtoupper((string) $key);

            if (!\in_array($key, self::RRULE_PARTS, true)) {
                continue;
            }

            $values = \is_array($value) ? $value : [$value];

            foreach ($values as $item) {
                if (!\is_scalar($item) || !preg_match('/^[A-Za-z0-9+\-]{1,32}$/D', (string) $item)) {
                    return false;
                }
            }

            $parts[$key] = \is_array($value) ? array_map('strval', $value) : (string) $value;
        }

        if (!isset($parts['FREQ'])) {
            return false;
        }

        $property->setParts($parts);

        return true;
    }

    private function resolvable(VCalendar $calendar, string $tzid): bool
    {
        if ($tzid === '' || \strlen($tzid) > 128 || preg_match('/[\x00-\x1f\x7f"]/', $tzid)) {
            return false;
        }

        try {
            TimeZoneUtil::getTimeZone($tzid, $calendar, true);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function timezoneLines(Component $timezone): string
    {
        $lines = 'BEGIN:VTIMEZONE' . "\r\n";

        foreach ($timezone->children() as $child) {
            if ($child instanceof Property) {
                $value = (string) $child->getValue();
                $keep = $child->name === 'TZID'
                    || ($child->name === 'X-LIC-LOCATION' && preg_match('/^[A-Za-z0-9_+\/-]{1,64}$/D', $value))
                    || ($child->name === 'X-MICROSOFT-CDO-TZID' && preg_match('/^\d{1,4}$/D', $value));

                if ($keep) {
                    foreach (array_keys($child->parameters()) as $parameter) {
                        unset($child[$parameter]);
                    }

                    $lines .= $child->serialize();
                }

                continue;
            }

            if ($child instanceof Component && \in_array($child->name, ['STANDARD', 'DAYLIGHT'], true)) {
                $lines .= 'BEGIN:' . $child->name . "\r\n";

                foreach ($child->children() as $property) {
                    if ($property instanceof Property && \in_array($property->name, self::ZONE_PROPERTIES, true)) {
                        foreach (array_keys($property->parameters()) as $parameter) {
                            if (strtoupper((string) $parameter) !== 'VALUE') {
                                unset($property[$parameter]);
                            }
                        }

                        if ($property->name !== 'RRULE' || $this->cleanRule($property)) {
                            $lines .= $property->serialize();
                        }
                    }
                }

                $lines .= 'END:' . $child->name . "\r\n";
            }
        }

        return $lines . 'END:VTIMEZONE' . "\r\n";
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

    private static function looksLikeAddress(string $value): bool
    {
        $value = trim($value);

        return $value !== '' && \strlen($value) <= 254 && preg_match('//u', $value) === 1
            && preg_match('/^[^@\s<>()\[\],;:"\\\\]+@[^@\s<>()\[\],;:"\\\\]+\.[^@\s<>()\[\],;:"\\\\.]+$/u', $value) === 1;
    }

    /** The form the blind index hashes: trimmed, lower case. */
    public static function normalise(string $address): string
    {
        return mb_strtolower(trim($address), 'UTF-8');
    }
}
