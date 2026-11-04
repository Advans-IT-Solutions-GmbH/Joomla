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
use Advans\Component\Appointments\Administrator\Helper\BusyPeriod;
use Advans\Component\Appointments\Administrator\Helper\IcsWriter;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

/**
 * Booking page and internet calendars (work package E), see
 * {@see BookingCalendarBridgeInterface}.
 *
 * Who may connect what:
 *
 * - Busy times of a calendar block an event type's slots only while the host of
 *   the event type has an effective grant on it (any role, free/busy included)
 *   and the calendar blocks availability. The booking page shows free times by
 *   intent; it never shows more about a calendar than its host could see.
 * - A booking is written only into a target calendar the host can write. A
 *   target the host lost access to is emptied of the booking's object.
 *
 * Busy cache (`#__appointments_calendar_busy`, one row per calendar): the busy
 * intervals of a window, per floating zone (a calendar can serve schedules in
 * different zones; all-day and floating events depend on it), as JSON
 * `{"v":1,"zones":{"<zone>":[[start, end, booking id or 0], ...]}}` in Unix
 * seconds, encrypted with `encrypt(key(TEAM_TIMING), json,
 * associatedData('busy', calendarId, synctoken, window_start_utc,
 * window_end_utc))`. A row is used only while its sync token equals the
 * calendar's and its window covers the request.
 *
 * Nothing here throws to the caller: an error is written to the Joomla log
 * (class and code only) and the booking flow continues.
 *
 * @since  1.4.0
 */
final class BookingCalendarBridge implements BookingCalendarBridgeInterface
{
    /** A new cache window covers at least this many days from the requested start. */
    private const WINDOW_DAYS = 92;

    /** Requests longer than this are computed, never cached. */
    private const MAX_WINDOW_DAYS = 800;

    /** Floating zones kept per cache row. */
    private const MAX_ZONES = 4;

    /** Intervals per zone; beyond this the whole window counts as busy (fail closed). */
    public const MAX_INTERVALS = 200000;

    public function __construct(private readonly TeamServices $services)
    {
    }

    public function busyFor(object $eventType, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, \DateTimeZone $scheduleZone, bool $readOnly = false): array
    {
        $utc = new \DateTimeZone('UTC');
        $fromUtc = $fromUtc->setTimezone($utc);
        $toUtc = $toUtc->setTimezone($utc);

        if ($toUtc <= $fromUtc || !$this->services->enabled()) {
            return [];
        }

        $periods = [];

        try {
            [$host, $calendars] = $this->busyCalendars((int) ($eventType->id ?? 0));
        } catch (\Throwable $e) {
            $this->failure('busy', 0, $e);

            return [];
        }

        foreach ($calendars as $calendarId) {
            try {
                $calendar = $this->services->calendars()->get($calendarId);

                if (!$calendar || (int) $calendar->state !== 1 || (int) $calendar->blocks_availability !== 1
                    || $this->services->grants()->accessFor($host, $calendarId) === null) {
                    continue;
                }

                $intervals = $this->intervals($calendarId, (int) $calendar->synctoken, $fromUtc, $toUtc, $scheduleZone, $readOnly);
                $own = $this->ownBookings($intervals, $host);

                foreach ($intervals as [$start, $end, $booking]) {
                    if ($end <= $fromUtc->getTimestamp() || $start >= $toUtc->getTimestamp() || isset($own[$booking])) {
                        continue;
                    }

                    $periods[] = BusyPeriod::reserved(new AvailabilityWindow(
                        (new \DateTimeImmutable('@' . $start))->setTimezone($utc),
                        (new \DateTimeImmutable('@' . $end))->setTimezone($utc)
                    ));
                }
            } catch (\Throwable $e) {
                $this->failure('busy', $calendarId, $e);
            }
        }

        return $periods;
    }

    public function lockForBooking(int $eventTypeId): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function removeOrphans(int $limit): int
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function onBookingSaved(array $booking): void
    {
        $bookingId = (int) ($booking['id'] ?? 0);

        if ($bookingId < 1 || !$this->services->enabled()) {
            return;
        }

        try {
            $row = $this->booking($bookingId);

            if ($row === null || $row['anonymised_utc'] !== null || (int) $row['state'] === 2) {
                $this->remove($bookingId);

                return;
            }

            $event = $this->event((int) $row['eventtype_id']);
            $target = (int) ($event['calendar_id'] ?? 0);
            $writable = $target > 0 && (int) $event['host_user_id'] === (int) $row['host_user_id']
                && $this->canWrite((int) $row['host_user_id'], $target);
            $present = $this->calendarsHolding($bookingId);

            if (!$writable || array_diff($present, [$target]) !== []) {
                $this->remove($bookingId);
            }

            if (!$writable) {
                return;
            }

            $this->services->objects()->putSealed($target, self::objectIcs($row, $event), $bookingId);
            $this->log(AuditEvent::BOOKING_WRITTEN, $target, $bookingId);
        } catch (\Throwable $e) {
            $this->failure('write', $bookingId, $e);
        }
    }

    public function onBookingCancelled(array $booking): void
    {
        $bookingId = (int) ($booking['id'] ?? 0);

        if ($bookingId > 0 && $this->services->enabled()) {
            $this->remove($bookingId);
        }
    }

    public function onBookingErased(int $bookingId): void
    {
        if ($bookingId > 0 && $this->services->enabled()) {
            $this->remove($bookingId);
        }
    }

    /**
     * The calendar copy of a booking: the iCalendar of the booking mails without
     * METHOD (RFC 4791 forbids it in a calendar object), same UID and SEQUENCE.
     *
     * @param   array<string, mixed>  $booking
     * @param   array<string, mixed>  $event
     */
    public static function objectIcs(array $booking, array $event): string
    {
        $stamp = UtcTimestamp::parse((string) ($booking['modified_utc'] ?? ''));

        if ($stamp === null) {
            throw new \InvalidArgumentException('Invalid booking modification time.');
        }

        $event['description'] = self::plainText((string) ($event['description'] ?? ''));
        $ics = (new IcsWriter())->attachment($booking, $event, $stamp);
        $object = preg_replace('/\r\nMETHOD:[A-Z]+\r\n/', "\r\n", $ics, 1, $count);

        if (!\is_string($object) || $count !== 1) {
            throw new \UnexpectedValueException('The booking calendar has no method line.');
        }

        return $object;
    }

    /**
     * Merge, cap and order the intervals of one zone. Calendar intervals
     * (booking id 0) are merged where they overlap or touch; booking intervals
     * stay separate, because the host filter needs their booking id.
     *
     * @param   list<array{0: int, 1: int, 2: int}>  $intervals
     *
     * @return  list<array{0: int, 1: int, 2: int}>
     */
    public static function compact(array $intervals, int $windowStart, int $windowEnd): array
    {
        $plain = [];
        $bookings = [];

        foreach ($intervals as [$start, $end, $booking]) {
            if ($end <= $start) {
                continue;
            }

            if ($booking > 0) {
                $bookings[] = [$start, $end, $booking];
            } else {
                $plain[] = [$start, $end, 0];
            }
        }

        usort($plain, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        $merged = [];

        foreach ($plain as $interval) {
            $last = \count($merged) - 1;

            if ($last >= 0 && $interval[0] <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $interval[1]);
            } else {
                $merged[] = $interval;
            }
        }

        $all = array_merge($merged, $bookings);

        if (\count($all) > self::MAX_INTERVALS) {
            return [[$windowStart, $windowEnd, 0]];
        }

        usort($all, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);

        return $all;
    }

    /**
     * The window a new cache row covers for a request, or null when the request
     * is too long to cache.
     *
     * @return  array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public static function cacheWindow(\DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): ?array
    {
        $start = $fromUtc->setTime(0, 0)->modify('-1 day');
        $end = max($toUtc, $fromUtc->modify('+' . self::WINDOW_DAYS . ' days'))->setTime(0, 0)->modify('+1 day');

        if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_WINDOW_DAYS * 86400) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * The host and the calendars of an event type, read from the database and
     * not from the caller's row: the public event row does not carry them.
     *
     * @return  array{0: int, 1: int[]}  Host user id; calendar ids ascending, so that two requests take cache rows in one order.
     */
    private function busyCalendars(int $eventId): array
    {
        if ($eventId < 1) {
            return [0, []];
        }

        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName(['host_user_id', 'calendar_id']))
            ->from($db->quoteName('#__appointments_eventtypes'))
            ->where($db->quoteName('id') . ' = ' . $eventId);
        $event = $db->setQuery($query)->loadAssoc();

        if (!$event) {
            return [0, []];
        }

        $query = $this->query()->select($db->quoteName('calendar_id'))
            ->from($db->quoteName('#__appointments_eventtype_calendars'))
            ->where($db->quoteName('eventtype_id') . ' = ' . $eventId);
        $ids = array_map('intval', $db->setQuery($query)->loadColumn());
        $ids[] = (int) $event['calendar_id'];
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids);

        return [(int) $event['host_user_id'], $ids];
    }

    /**
     * @return  list<array{0: int, 1: int, 2: int}>
     */
    private function intervals(int $calendarId, int $synctoken, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, \DateTimeZone $zone, bool $readOnly): array
    {
        $zoneName = $zone->getName();
        $cached = $this->readCache($calendarId, $synctoken);

        if ($cached !== null && $cached['start'] <= $fromUtc && $cached['end'] >= $toUtc && isset($cached['zones'][$zoneName])) {
            return $cached['zones'][$zoneName];
        }

        $window = self::cacheWindow($fromUtc, $toUtc);

        if ($window === null) {
            return $this->compute($calendarId, $fromUtc, $toUtc, $zone);
        }

        $zones = [];

        if ($cached !== null && $cached['start'] <= $fromUtc && $cached['end'] >= $toUtc) {
            // Same sync token and window, only this zone is new: keep the others.
            $window = [$cached['start'], $cached['end']];
            $zones = \array_slice($cached['zones'], -(self::MAX_ZONES - 1), null, true);
        }

        $zones[$zoneName] = $this->compute($calendarId, $window[0], $window[1], $zone);

        if (!$readOnly) {
            $this->writeCache($calendarId, $synctoken, $window[0], $window[1], $zones);
        }

        return $zones[$zoneName];
    }

    /**
     * @return  list<array{0: int, 1: int, 2: int}>
     */
    private function compute(int $calendarId, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $zone): array
    {
        $intervals = [];
        $timing = $this->services->timing();

        foreach ($this->services->objects()->skeletons($calendarId) as $item) {
            try {
                $windows = $timing->expand((string) $item['skeleton'], $start, $end, $zone);
            } catch (\Throwable $e) {
                // One unreadable object must not take the booking page down.
                $this->failure('expand', $calendarId, $e);

                continue;
            }

            $booking = (int) ($item['booking_id'] ?? 0);

            foreach ($windows as $window) {
                $intervals[] = [$window->getStart()->getTimestamp(), $window->getEnd()->getTimestamp(), $booking];
            }

            if (\count($intervals) > self::MAX_INTERVALS * 2) {
                return [[$start->getTimestamp(), $end->getTimestamp(), 0]];
            }
        }

        return self::compact($intervals, $start->getTimestamp(), $end->getTimestamp());
    }

    /**
     * @return  array{start: \DateTimeImmutable, end: \DateTimeImmutable, zones: array<string, list<array{0: int, 1: int, 2: int}>>}|null
     */
    private function readCache(int $calendarId, int $synctoken): ?array
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName(['synctoken', 'window_start_utc', 'window_end_utc', 'payload']))
            ->from($db->quoteName('#__appointments_calendar_busy'))
            ->where($db->quoteName('calendar_id') . ' = ' . $calendarId);
        $row = $db->setQuery($query)->loadAssoc();

        if (!$row || (int) $row['synctoken'] !== $synctoken) {
            return null;
        }

        $start = UtcTimestamp::parse((string) $row['window_start_utc']);
        $end = UtcTimestamp::parse((string) $row['window_end_utc']);

        if ($start === null || $end === null || $end <= $start) {
            return null;
        }

        $json = CipherSuite::decrypt($this->timingKey(), (string) $row['payload'],
            self::cacheData($calendarId, $synctoken, (string) $row['window_start_utc'], (string) $row['window_end_utc']));
        $data = $json === null ? null : json_decode($json, true);

        if (!\is_array($data) || ($data['v'] ?? null) !== 1 || !\is_array($data['zones'] ?? null)) {
            return null;
        }

        $zones = [];

        foreach ($data['zones'] as $name => $intervals) {
            if (!\is_string($name) || !\is_array($intervals)) {
                return null;
            }

            foreach ($intervals as $interval) {
                if (!\is_array($interval) || \count($interval) !== 3 || !\is_int($interval[0]) || !\is_int($interval[1]) || !\is_int($interval[2])) {
                    return null;
                }
            }

            $zones[$name] = array_values($intervals);
        }

        return ['start' => $start, 'end' => $end, 'zones' => $zones];
    }

    /**
     * @param   array<string, list<array{0: int, 1: int, 2: int}>>  $zones
     */
    private function writeCache(int $calendarId, int $synctoken, \DateTimeImmutable $start, \DateTimeImmutable $end, array $zones): void
    {
        $db = $this->services->db();
        $from = $start->format('Y-m-d H:i:s');
        $to = $end->format('Y-m-d H:i:s');
        $payload = CipherSuite::encrypt($this->timingKey(), json_encode(['v' => 1, 'zones' => $zones], JSON_THROW_ON_ERROR),
            self::cacheData($calendarId, $synctoken, $from, $to));
        $values = [
            'synctoken' => (string) $synctoken,
            'window_start_utc' => $db->quote($from),
            'window_end_utc' => $db->quote($to),
            'payload' => 'UNHEX(' . $db->quote(bin2hex($payload)) . ')',
            'built_utc' => $db->quote($this->services->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')),
        ];

        try {
            $query = $this->query()->update($db->quoteName('#__appointments_calendar_busy'))
                ->where($db->quoteName('calendar_id') . ' = ' . $calendarId);

            foreach ($values as $column => $value) {
                $query->set($db->quoteName($column) . ' = ' . $value);
            }

            $db->setQuery($query)->execute();

            if ($db->getAffectedRows() < 1) {
                $db->setQuery('INSERT IGNORE INTO ' . $db->quoteName('#__appointments_calendar_busy')
                    . ' (' . implode(', ', $db->quoteName(array_merge(['calendar_id'], array_keys($values)))) . ')'
                    . ' VALUES (' . $calendarId . ', ' . implode(', ', $values) . ')')->execute();
            }
        } catch (\Throwable $e) {
            // The cache is an optimisation; the computed intervals are still returned.
            $this->failure('cache', $calendarId, $e);
        }
    }

    private static function cacheData(int $calendarId, int $synctoken, string $start, string $end): string
    {
        return CipherSuite::associatedData('busy', $calendarId, $synctoken, $start, $end);
    }

    private function timingKey(): string
    {
        return $this->services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_TIMING);
    }

    /**
     * Booking ids among the intervals that belong to the asking host: those
     * already block through the bookings table, with the buffers of their own
     * event type.
     *
     * @param   list<array{0: int, 1: int, 2: int}>  $intervals
     *
     * @return  array<int, true>
     */
    private function ownBookings(array $intervals, int $host): array
    {
        $ids = [];

        foreach ($intervals as $interval) {
            if ($interval[2] > 0) {
                $ids[$interval[2]] = $interval[2];
            }
        }

        if ($ids === [] || $host < 1) {
            return [];
        }

        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName('id'))
            ->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')')
            ->where($db->quoteName('host_user_id') . ' = ' . $host);

        return array_fill_keys(array_map('intval', $db->setQuery($query)->loadColumn()), true);
    }

    private function canWrite(int $host, int $calendarId): bool
    {
        $access = $this->services->grants()->accessFor($host, $calendarId);

        return $access !== null && $access->role->canWrite();
    }

    /**
     * @return  int[]  Calendars that hold an object of the booking.
     */
    private function calendarsHolding(int $bookingId): array
    {
        $db = $this->services->db();
        $query = $this->query()->select('DISTINCT ' . $db->quoteName('calendar_id'))
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('booking_id') . ' = ' . $bookingId);

        return array_map('intval', $db->setQuery($query)->loadColumn());
    }

    private function remove(int $bookingId): void
    {
        try {
            if ($this->services->objects()->deleteForBooking($bookingId) > 0) {
                $this->log(AuditEvent::BOOKING_REMOVED, null, $bookingId);
            }
        } catch (\Throwable $e) {
            $this->failure('remove', $bookingId, $e);
        }
    }

    /**
     * @return  array<string, mixed>|null
     */
    private function booking(int $bookingId): ?array
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName(['id', 'eventtype_id', 'host_user_id', 'uid', 'sequence', 'state',
            'start_utc', 'end_utc', 'modified_utc', 'invitee_name', 'invitee_email', 'guest_emails', 'conference_url', 'anonymised_utc']))
            ->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('id') . ' = ' . $bookingId);

        return $db->setQuery($query)->loadAssoc() ?: null;
    }

    /**
     * @return  array<string, mixed>
     */
    private function event(int $eventId): array
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName(['e.id', 'e.title', 'e.description', 'e.location_type', 'e.location_text',
            'e.calendar_id', 'e.host_user_id']))
            ->select($db->quoteName('u.email', 'host_email'))->select($db->quoteName('u.name', 'host_name'))
            ->from($db->quoteName('#__appointments_eventtypes', 'e'))
            ->join('INNER', $db->quoteName('#__users', 'u'), $db->quoteName('u.id') . ' = ' . $db->quoteName('e.host_user_id'))
            ->where($db->quoteName('e.id') . ' = ' . $eventId);
        $event = $db->setQuery($query)->loadAssoc();

        if (!$event) {
            throw new \UnexpectedValueException('The booking event type is missing.');
        }

        return $event;
    }

    private static function plainText(string $description): string
    {
        $description = (string) preg_replace('/<\s*(?:br\s*\/?|\/p|\/div|\/li)\s*>/i', "\n", $description);

        return trim(html_entity_decode(strip_tags($description), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function log(string $event, ?int $calendarId, int $bookingId): void
    {
        try {
            $this->services->audit()->log($event, ['calendar_id' => $calendarId, 'object_ref' => 'booking:' . $bookingId]);
        } catch (\Throwable $e) {
            $this->failure('log', $bookingId, $e);
        }
    }

    private function failure(string $step, int $id, \Throwable $error): void
    {
        try {
            Log::add('Internet calendars: booking bridge step ' . $step . ' failed for #' . $id
                . ' (' . \get_class($error) . ', ' . (int) $error->getCode() . ').', Log::WARNING, 'com_appointments');
        } catch (\Throwable $ignored) {
            // Logging must never fail a booking either.
        }
    }

    private function query()
    {
        $db = $this->services->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
