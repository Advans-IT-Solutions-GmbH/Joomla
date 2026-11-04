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
 * Errors: writing never fails a booking (logged, class and code only; a copy
 * left behind is removed later by {@see self::removeOrphans()}). Reading fails
 * closed: an object that cannot be expanded makes the window busy, and so does
 * a calendar that cannot be read at all, on the booking page. Inside the
 * locked booking transaction such a calendar is left out, because a calendar
 * error never fails a booking and the transaction only books a time the page
 * offered. Only {@see self::lockForBooking()} lets a database error of its
 * lock through.
 *
 * Bookings of different hosts that share a calendar are serialised by
 * {@see self::lockForBooking()} and see each other through the bookings table,
 * not through the calendar copy, which is written after the commit.
 *
 * @since  1.4.0
 */
final class BookingCalendarBridge implements BookingCalendarBridgeInterface
{
    /** A new cache window reaches at least this many days ahead of today. */
    private const WINDOW_DAYS = 400;

    /** Work per calendar and computation, in seconds, before the rest of the window counts as busy. */
    private const MAX_SECONDS = 10;

    /** Orphaned calendar copies removed after each booking change. */
    private const ORPHAN_BATCH = 20;

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

        // A calendar that cannot be read: the booking page offers nothing in the
        // window (fail closed); the locked booking transaction proceeds without it,
        // because a calendar error never fails a booking (it only ever books a time
        // the page showed).
        $unreadable = $readOnly ? [] : [BusyPeriod::reserved(new AvailabilityWindow($fromUtc, $toUtc))];

        try {
            [$host, $calendars] = $this->busyCalendars((int) ($eventType->id ?? 0));
        } catch (\Throwable $e) {
            $this->failure('busy', 0, $e);

            return $unreadable;
        }

        $readable = [];
        $periods = [];

        foreach ($calendars as $calendarId) {
            try {
                $calendar = $this->services->calendars()->get($calendarId);

                if ($calendar && (int) $calendar->state === 1 && (int) $calendar->blocks_availability === 1
                    && $this->services->grants()->accessFor($host, $calendarId) !== null) {
                    $readable[$calendarId] = (int) $calendar->synctoken;
                }
            } catch (\Throwable $e) {
                $this->failure('busy', $calendarId, $e);
                $periods = array_merge($periods, $unreadable);
            }
        }

        if ($readable === []) {
            return $periods;
        }

        try {
            foreach ($this->otherHostsBookings(array_keys($readable), $host, $fromUtc, $toUtc) as $window) {
                $periods[] = BusyPeriod::reserved($window);
            }
        } catch (\Throwable $e) {
            $this->failure('busy', 0, $e);
            $periods = array_merge($periods, $unreadable);
        }

        foreach ($readable as $calendarId => $synctoken) {
            try {
                $intervals = $this->intervals($calendarId, $synctoken, $fromUtc, $toUtc, $scheduleZone, $readOnly);
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
                $periods = array_merge($periods, $unreadable);
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

            // A cancellation or erasure that committed while the copy was written has
            // already removed the old copy; do not leave the new one behind.
            $after = $this->booking($bookingId);

            if ($after === null || $after['anonymised_utc'] !== null || (int) $after['state'] === 2) {
                $this->remove($bookingId);
            }
        } catch (\Throwable $e) {
            $this->failure('write', $bookingId, $e);
        }

        $this->removeOrphans(self::ORPHAN_BATCH);
    }

    /**
     * Removal needs no key and runs even while the feature is switched off, so a
     * calendar copy never outlives its booking.
     */
    public function onBookingCancelled(array $booking): void
    {
        $bookingId = (int) ($booking['id'] ?? 0);

        if ($bookingId > 0) {
            $this->remove($bookingId);
        }

        $this->removeOrphans(self::ORPHAN_BATCH);
    }

    public function onBookingErased(int $bookingId): void
    {
        if ($bookingId > 0) {
            $this->remove($bookingId);
        }

        $this->removeOrphans(self::ORPHAN_BATCH);
    }

    /**
     * Remove calendar copies whose booking is cancelled, anonymised or gone: the
     * retry of a removal that failed earlier. Called after every booking change
     * and meant for the Scheduler as well. Needs no key.
     *
     * @return  int  Bookings whose copies were removed.
     */
    public function removeOrphans(int $limit): int
    {
        $removed = 0;

        try {
            $db = $this->services->db();
            $query = $this->query()->select('DISTINCT ' . $db->quoteName('o.booking_id'))
                ->from($db->quoteName('#__appointments_calendar_objects', 'o'))
                ->join('LEFT', $db->quoteName('#__appointments_bookings', 'b'), $db->quoteName('b.id') . ' = ' . $db->quoteName('o.booking_id'))
                ->where($db->quoteName('o.booking_id') . ' IS NOT NULL')
                ->where('(' . $db->quoteName('b.id') . ' IS NULL OR ' . $db->quoteName('b.state') . ' = 2 OR '
                    . $db->quoteName('b.anonymised_utc') . ' IS NOT NULL)')
                ->setLimit(max(1, min(500, $limit)));

            foreach (array_map('intval', $db->setQuery($query)->loadColumn()) as $bookingId) {
                if ($bookingId > 0 && $this->remove($bookingId)) {
                    $removed++;
                }
            }
        } catch (\Throwable $e) {
            $this->failure('orphans', 0, $e);
        }

        return $removed;
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
     * The window a new cache row covers: from the day before today (or the
     * request, if earlier) to a year and a month ahead (or the request, if
     * later), so that a visitor moving through the months of a long booking
     * horizon does not rebuild the row on every request. Null when even the
     * request alone is too long to cache.
     *
     * @return  array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public static function cacheWindow(\DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc, \DateTimeImmutable $nowUtc): ?array
    {
        $start = min($fromUtc, $nowUtc->modify('-1 day'))->setTime(0, 0);
        $end = max($toUtc, $nowUtc->modify('+' . self::WINDOW_DAYS . ' days'))->setTime(0, 0)->modify('+1 day');

        if ($end->getTimestamp() - $start->getTimestamp() > self::MAX_WINDOW_DAYS * 86400) {
            $start = $fromUtc->setTime(0, 0)->modify('-1 day');
            $end = $toUtc->setTime(0, 0)->modify('+2 days');
        }

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

        $window = self::cacheWindow($fromUtc, $toUtc, $this->services->now()->setTimezone(new \DateTimeZone('UTC')));

        if ($window === null) {
            return $this->compute($calendarId, $fromUtc, $toUtc, $zone)[0];
        }

        $zones = [];

        if ($cached !== null && $cached['start'] <= $fromUtc && $cached['end'] >= $toUtc) {
            // Same sync token and window, only this zone is new: keep the others.
            $window = [$cached['start'], $cached['end']];
            $zones = \array_slice($cached['zones'], -(self::MAX_ZONES - 1), null, true);
        }

        [$intervals, $complete] = $this->compute($calendarId, $window[0], $window[1], $zone);
        $zones[$zoneName] = $intervals;

        // A result with an unreadable object is never cached: the cause may be
        // passing (a library missing during an update), and it is busy anyway.
        if (!$readOnly && $complete) {
            $this->writeCache($calendarId, $synctoken, $window[0], $window[1], $zones);
        }

        return $intervals;
    }

    /**
     * Busy intervals of every object in a window. Fails closed: an object that
     * cannot be expanded makes the whole window busy (and the result is not
     * complete); more than {@see self::MAX_SECONDS} of work makes the rest of
     * the window busy (the result stays complete, so it is cached and not
     * recomputed by every visitor).
     *
     * @return  array{0: list<array{0: int, 1: int, 2: int}>, 1: bool}  Intervals, and whether every object was read.
     */
    private function compute(int $calendarId, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $zone): array
    {
        $intervals = [];
        $complete = true;
        $timing = $this->services->timing();
        $began = microtime(true);
        $whole = [$start->getTimestamp(), $end->getTimestamp(), 0];

        foreach ($this->services->objects()->skeletons($calendarId) as $item) {
            if (microtime(true) - $began > self::MAX_SECONDS) {
                $this->failure('budget', $calendarId, new \OverflowException('Busy time budget exceeded.'));

                return [[$whole], true];
            }

            try {
                $windows = $timing->expand((string) $item['skeleton'], $start, $end, $zone);
            } catch (\Throwable $e) {
                $this->failure('expand', $calendarId, $e);
                $intervals[] = $whole;
                $complete = false;

                continue;
            }

            $booking = (int) ($item['booking_id'] ?? 0);

            foreach ($windows as $window) {
                $intervals[] = [$window->getStart()->getTimestamp(), $window->getEnd()->getTimestamp(), $booking];
            }

            if (\count($intervals) > self::MAX_INTERVALS * 2) {
                return [[$whole], $complete];
            }
        }

        return [self::compact($intervals, $start->getTimestamp(), $end->getTimestamp()), $complete];
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

    /** @return  bool  Whether copies were removed. */
    private function remove(int $bookingId): bool
    {
        try {
            if ($this->services->objects()->deleteForBooking($bookingId) > 0) {
                $this->log(AuditEvent::BOOKING_REMOVED, null, $bookingId);

                return true;
            }
        } catch (\Throwable $e) {
            $this->failure('remove', $bookingId, $e);
        }

        return false;
    }

    /**
     * Inside the booking transaction, right after the host lock and the event
     * type row and before any plain read: lock the rows of every calendar the
     * event type writes into or reads busy times from, ascending. Two hosts
     * whose event types share a calendar are serialised by it, and the
     * transaction's snapshot (taken at its first plain read, after this lock)
     * already holds the other host's committed booking. Only locking reads, so
     * the snapshot is not taken here. Does nothing while the feature is off.
     *
     * A database error of the lock itself (lock wait timeout, deadlock) is not
     * caught: like an error of the host lock it ends the booking transaction,
     * which must never continue after InnoDB rolled it back.
     */
    public function lockForBooking(int $eventTypeId): void
    {
        if ($eventTypeId < 1 || !$this->services->enabled()) {
            return;
        }

        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName('calendar_id'))->from($db->quoteName('#__appointments_eventtypes'))
            ->where($db->quoteName('id') . ' = ' . $eventTypeId);
        $ids = [(int) $db->setQuery((string) $query . ' FOR UPDATE')->loadResult()];
        $query = $this->query()->select($db->quoteName('calendar_id'))->from($db->quoteName('#__appointments_eventtype_calendars'))
            ->where($db->quoteName('eventtype_id') . ' = ' . $eventTypeId);
        $ids = array_merge($ids, array_map('intval', $db->setQuery((string) $query . ' LOCK IN SHARE MODE')->loadColumn()));
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return;
        }

        sort($ids);
        $query = $this->query()->select($db->quoteName('id'))->from($db->quoteName('#__appointments_calendars'))
            ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')')->order($db->quoteName('id'));
        $db->setQuery((string) $query . ' FOR UPDATE')->loadColumn();
    }

    /**
     * Bookings of other hosts whose event type writes into one of these
     * calendars, read from the bookings table: they block even before their
     * calendar copy exists or when writing it failed.
     *
     * @param   int[]  $calendarIds
     *
     * @return  AvailabilityWindow[]
     */
    private function otherHostsBookings(array $calendarIds, int $host, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName(['b.start_utc', 'b.end_utc']))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('e.calendar_id') . ' IN (' . implode(',', array_map('intval', $calendarIds)) . ')')
            ->where($db->quoteName('b.host_user_id') . ' <> ' . $host)
            ->where($db->quoteName('b.state') . ' IN (0,1)')
            ->where($db->quoteName('b.start_utc') . ' < ' . $db->quote($toUtc->format('Y-m-d H:i:s')))
            ->where($db->quoteName('b.end_utc') . ' > ' . $db->quote($fromUtc->format('Y-m-d H:i:s')));
        $windows = [];

        foreach ($db->setQuery($query)->loadAssocList() as $row) {
            $start = UtcTimestamp::parse((string) $row['start_utc']);
            $end = UtcTimestamp::parse((string) $row['end_utc']);

            if ($start !== null && $end !== null && $end > $start) {
                $windows[] = new AvailabilityWindow($start, $end);
            }
        }

        return $windows;
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
