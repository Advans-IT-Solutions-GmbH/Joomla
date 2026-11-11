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
use Advans\Component\Appointments\Administrator\Helper\BookingData;
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
 * offered. Accepted residual, like the PUT race of the specification: a forged
 * booking request for a time the page did not offer passes while a linked
 * calendar cannot be read at all. Only {@see self::lockForBooking()} lets a
 * database error of its lock through.
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

    /** How long a result cut by the time budget is cached. */
    private const PARTIAL_SECONDS = 3600;

    /** Orphaned calendar copies removed after each booking change. */
    private const ORPHAN_BATCH = 20;

    /** Requests to write the copies of future bookings anew, one row per event type (0: all). */
    public const RESTORE_TABLE = '#__appointments_copy_restore';

    /** Passes over a request that end with failed writes before the request is given up (one per run). */
    private const RESTORE_PASSES = 5;

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

    public function onBookingSaved(array $booking): void
    {
        $bookingId = (int) ($booking['id'] ?? 0);

        if ($bookingId < 1) {
            return;
        }

        // While the feature is off a change is not mirrored, so the copy would be stale (old
        // times, a removed guest): it goes now, not only at the next sweep. Removal needs no key.
        // Switched on again, the Scheduler writes the copies of the future bookings anew.
        if (!$this->services->enabled()) {
            $this->requestRestore(0);
            $this->onBookingErased($bookingId);

            return;
        }

        $db = $this->services->db();
        // The booking row stays locked while its copy is written: two refreshes of the same
        // booking (a reschedule, a new conference link, a guest removal) are serialised, and
        // each writes the row as it is at that moment, so an older one never overwrites a newer
        // copy. Lock order as everywhere: booking row, then calendar.
        $db->transactionStart(true);

        try {
            $row = $this->booking($bookingId, true);

            if ($row === null || $row['anonymised_utc'] !== null || (int) $row['state'] === 2) {
                $this->remove($bookingId);
                $db->transactionCommit(true);

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

            if ($writable) {
                $this->services->objects()->putSealed($target, self::objectIcs($row, $event), $bookingId);
                $this->log(AuditEvent::BOOKING_WRITTEN, $target, $bookingId);
            }

            $db->transactionCommit(true);
        } catch (\Throwable $e) {
            $db->transactionRollback(true);
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
     * retry of a removal that failed earlier. While the feature is off, every
     * booking copy goes: changes are not mirrored then, so a copy would be stale
     * (old times, a removed guest) when the feature is switched on again; then
     * the Scheduler writes the copies of the future bookings anew
     * ({@see self::restoreCopies()}). Called after every booking change and by
     * the Scheduler. Needs no key.
     *
     * @return  int  Bookings whose copies were removed.
     */
    public function removeOrphans(int $limit): int
    {
        $removed = 0;

        try {
            $db = $this->services->db();
            $enabled = $this->services->enabled();
            $query = $this->query()->select('DISTINCT ' . $db->quoteName('o.booking_id'))
                ->from($db->quoteName('#__appointments_calendar_objects', 'o'))
                ->join('LEFT', $db->quoteName('#__appointments_bookings', 'b'), $db->quoteName('b.id') . ' = ' . $db->quoteName('o.booking_id'))
                ->where($db->quoteName('o.booking_id') . ' IS NOT NULL')
                ->setLimit(max(1, min(500, $limit)));

            if ($enabled) {
                $query->where('(' . $db->quoteName('b.id') . ' IS NULL OR ' . $db->quoteName('b.state') . ' = 2 OR '
                    . $db->quoteName('b.anonymised_utc') . ' IS NOT NULL)');
            }

            foreach (array_map('intval', $db->setQuery($query)->loadColumn()) as $bookingId) {
                if ($bookingId > 0 && $this->remove($bookingId)) {
                    $removed++;
                }
            }

            // Switched off: every run records that the copies must be written again once the
            // feature is back, also when nothing was left to remove (a copy a device deleted
            // before). The request does nothing while the feature stays off.
            if (!$enabled) {
                $this->requestRestore(0);
            }
        } catch (\Throwable $e) {
            $this->failure('orphans', 0, $e);
        }

        return $removed;
    }

    public function requestRestore(int $eventTypeId): void
    {
        try {
            $db = $this->services->db();
            $now = $this->services->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            // A new request starts the walk through the bookings from the beginning again.
            $db->setQuery('INSERT INTO ' . $db->quoteName(self::RESTORE_TABLE)
                . ' (' . implode(', ', $db->quoteName(['eventtype_id', 'requested_utc', 'token', 'after_booking_id', 'failed', 'passes'])) . ')'
                . ' VALUES (' . max(0, $eventTypeId) . ', ' . $db->quote($now) . ', ' . $db->quote(bin2hex(random_bytes(16))) . ', 0, 0, 0)'
                . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('requested_utc') . ' = VALUES(' . $db->quoteName('requested_utc') . '), '
                . $db->quoteName('token') . ' = VALUES(' . $db->quoteName('token') . '), '
                . $db->quoteName('after_booking_id') . ' = 0, ' . $db->quoteName('failed') . ' = 0, ' . $db->quoteName('passes') . ' = 0')->execute();
        } catch (\Throwable $e) {
            // A booking or a saved event type never fails because of this.
            $this->failure('restore-request', $eventTypeId, $e);
        }
    }

    public function restoreCopies(int $limit): int
    {
        if ($limit < 1 || !$this->services->enabled()) {
            return 0;
        }

        $handled = 0;
        $restored = 0;
        $db = $this->services->db();
        // One worker at a time: two Scheduler runs never walk the same request.
        $lock = $db->getPrefix() . 'appointments_copy_restore';

        try {
            if ((int) $db->setQuery('SELECT GET_LOCK(' . $db->quote($lock) . ', 0)')->loadResult() !== 1) {
                return 0;
            }
        } catch (\Throwable $e) {
            $this->failure('restore-lock', 0, $e);

            return 0;
        }

        try {
            $requests = $db->setQuery($this->query()
                ->select($db->quoteName(['eventtype_id', 'token', 'after_booking_id', 'failed', 'passes']))
                ->from($db->quoteName(self::RESTORE_TABLE))
                ->where($db->quoteName('passes') . ' < ' . self::RESTORE_PASSES)
                ->order($db->quoteName('eventtype_id') . ' ASC'))->loadAssocList() ?: [];
            $now = $this->services->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');

            foreach ($requests as $request) {
                $budget = min(500, $limit - $handled);

                if ($budget < 1) {
                    break;
                }

                $type = (int) $request['eventtype_id'];
                $token = (string) $request['token'];
                $after = (int) $request['after_booking_id'];
                $failed = (int) $request['failed'] === 1;
                $passes = (int) $request['passes'];
                $ids = array_map('intval', $db->setQuery($this->restoreCandidates($type, $after, $now)->setLimit($budget))->loadColumn() ?: []);

                foreach ($ids as $bookingId) {
                    // An entry of the target's own with the booking's UID (an imported mail
                    // attachment, perhaps with notes) is never adopted by a restore: it stays.
                    if ($this->ownEntryInTarget($bookingId)) {
                        // The booking's copies in other calendars still go (the own entry has
                        // no booking id and stays); done only when none is left.
                        $this->remove($bookingId);
                        $handled++;
                        $after = $bookingId;

                        if ($this->calendarsHolding($bookingId) === []) {
                            $restored++;
                        } else {
                            $failed = true;
                        }

                        continue;
                    }

                    $before = $this->copyEtags($bookingId);
                    $this->onBookingSaved(['id' => $bookingId]);
                    $handled++;
                    $after = $bookingId;

                    // Only a copy that is really in place counts; a failed write is retried.
                    if ($this->restored($bookingId, $before)) {
                        $restored++;
                    } else {
                        $failed = true;
                    }
                }

                // Changed only while it is still the request that was read: a new request
                // in the meantime starts again from the first booking.
                $table = $db->quoteName(self::RESTORE_TABLE);
                $where = ' WHERE ' . $db->quoteName('eventtype_id') . ' = ' . $type . ' AND ' . $db->quoteName('token') . ' = ' . $db->quote($token);

                if (\count($ids) === $budget) {
                    // The walk goes on at the next run.
                    $db->setQuery('UPDATE ' . $table . ' SET ' . $db->quoteName('after_booking_id') . ' = ' . $after . ', '
                        . $db->quoteName('failed') . ' = ' . (int) $failed . $where)->execute();
                } elseif ($failed && $passes + 1 < self::RESTORE_PASSES) {
                    // A pass with failed writes: the next run starts again from the first booking,
                    // where only the bookings still without their copy are candidates.
                    $db->setQuery('UPDATE ' . $table . ' SET ' . $db->quoteName('after_booking_id') . ' = 0, ' . $db->quoteName('failed') . ' = 0, '
                        . $db->quoteName('passes') . ' = ' . ($passes + 1) . $where)->execute();
                } elseif ($failed) {
                    // Given up after the last pass: kept as failed (reported by restoreFailed() and
                    // the task), never retried by itself; a new request starts it again.
                    $this->failure('restore-gave-up', $type, new \RuntimeException('Calendar copies could not be written.'));
                    $db->setQuery('UPDATE ' . $table . ' SET ' . $db->quoteName('after_booking_id') . ' = 0, ' . $db->quoteName('failed') . ' = 0, '
                        . $db->quoteName('passes') . ' = ' . self::RESTORE_PASSES . $where)->execute();
                } else {
                    $db->setQuery('DELETE FROM ' . $table . $where)->execute();
                }
            }
        } catch (\Throwable $e) {
            $this->failure('restore', 0, $e);
        } finally {
            try {
                $db->setQuery('SELECT RELEASE_LOCK(' . $db->quote($lock) . ')')->loadResult();
            } catch (\Throwable $e) {
                // The lock ends with the connection anyway.
            }
        }

        return $restored;
    }

    public function restorePending(): int
    {
        try {
            $db = $this->services->db();

            return (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::RESTORE_TABLE)
                . ' WHERE ' . $db->quoteName('passes') . ' < ' . self::RESTORE_PASSES)->loadResult();
        } catch (\Throwable $e) {
            $this->failure('restore-pending', 0, $e);

            return -1;
        }
    }

    public function restoreFailed(): int
    {
        try {
            $db = $this->services->db();

            return (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName(self::RESTORE_TABLE)
                . ' WHERE ' . $db->quoteName('passes') . ' >= ' . self::RESTORE_PASSES)->loadResult();
        } catch (\Throwable $e) {
            $this->failure('restore-failed', 0, $e);

            return -1;
        }
    }

    /**
     * Whether the event type's target calendar holds an entry without a booking id that has
     * the booking's UID. A restore leaves such an entry alone instead of adopting it.
     */
    private function ownEntryInTarget(int $bookingId): bool
    {
        $db = $this->services->db();
        $row = $db->setQuery($this->query()
            ->select($db->quoteName(['b.uid', 'e.calendar_id']))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $bookingId))->loadAssoc();
        $target = (int) ($row['calendar_id'] ?? 0);

        if ($target < 1 || (string) ($row['uid'] ?? '') === '') {
            return false;
        }

        $hash = $this->services->keys()->uidHash($target, (string) $row['uid']);

        return (int) $db->setQuery($this->query()->select('COUNT(*)')
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('calendar_id') . ' = ' . $target)
            ->where($db->quoteName('uid_hash') . ' = ' . $db->quoteBinary($hash))
            ->where($db->quoteName('booking_id') . ' IS NULL'))->loadResult() > 0;
    }

    /**
     * The ETags of a booking's copies per calendar. A write gives a new random ETag, so a
     * changed ETag proves that a refresh was committed.
     *
     * @return  array<int, string>
     */
    private function copyEtags(int $bookingId): array
    {
        $db = $this->services->db();
        $rows = $db->setQuery($this->query()->select($db->quoteName(['calendar_id', 'etag']))
            ->from($db->quoteName('#__appointments_calendar_objects'))
            ->where($db->quoteName('booking_id') . ' = ' . $bookingId))->loadAssocList() ?: [];
        $etags = [];

        foreach ($rows as $row) {
            $etags[(int) $row['calendar_id']] = (string) $row['etag'];
        }

        return $etags;
    }

    /**
     * Whether the write just done for a booking was committed: its copy lies in the event
     * type's target calendar alone, with an ETag other than before the write (`$before`, from
     * {@see self::copyEtags()}). Without a target, or when the target cannot take the
     * booking, it is done when no copy is left. A booking that is no longer active needs no
     * copy and counts as done.
     *
     * @param   array<int, string>  $before
     */
    private function restored(int $bookingId, array $before): bool
    {
        $db = $this->services->db();
        $row = $db->setQuery($this->query()
            ->select($db->quoteName(['b.state', 'b.anonymised_utc', 'b.host_user_id', 'e.calendar_id']))
            ->select($db->quoteName('e.host_user_id', 'event_host'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('LEFT', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $bookingId))->loadAssoc();

        if (!$row || (int) $row['state'] === 2 || $row['anonymised_utc'] !== null) {
            return true;
        }

        $target = (int) ($row['calendar_id'] ?? 0);
        $holding = $this->calendarsHolding($bookingId);

        // As in onBookingSaved(): a booking the target cannot take (no target, another host, no Write
        // access) is done when it has no copy left.
        try {
            $writable = $target > 0 && (int) $row['event_host'] === (int) $row['host_user_id'] && $this->canWrite((int) $row['host_user_id'], $target);
        } catch (\Throwable $e) {
            // Access unknown: not done, the next pass tries again.
            return false;
        }

        if (!$writable) {
            return $holding === [];
        }

        $after = $this->copyEtags($bookingId);

        return $holding === [$target] && isset($after[$target])
            && (!isset($before[$target]) || !hash_equals($before[$target], $after[$target]));
    }

    /**
     * Future, active bookings after `$after` (of one event type, or of all with 0). All of
     * them are written anew, not only those without a copy in the target: a copy that
     * survived a switched-off period (its removal failed) may hold old times or a guest
     * that was removed meanwhile.
     */
    private function restoreCandidates(int $eventTypeId, int $after, string $now)
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName('b.id'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' > ' . $after)
            ->where($db->quoteName('b.state') . ' <> 2')
            ->where($db->quoteName('b.anonymised_utc') . ' IS NULL')
            ->where($db->quoteName('b.end_utc') . ' > ' . $db->quote($now))
            ->order($db->quoteName('b.id') . ' ASC');

        if ($eventTypeId > 0) {
            $query->where($db->quoteName('b.eventtype_id') . ' = ' . $eventTypeId);
        }

        return $query;
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
        $until = null;

        if ($cached !== null && $cached['start'] <= $fromUtc && $cached['end'] >= $toUtc) {
            // Same sync token and window, only this zone is new: keep the others.
            $window = [$cached['start'], $cached['end']];
            $zones = \array_slice($cached['zones'], -(self::MAX_ZONES - 1), null, true);
            $until = $cached['until'];
        }

        [$intervals, $complete, $partial] = $this->compute($calendarId, $window[0], $window[1], $zone);
        $zones[$zoneName] = $intervals;

        // A result cut by the time budget is kept for an hour only, then computed again.
        if ($partial) {
            $retry = $this->services->now()->getTimestamp() + self::PARTIAL_SECONDS;
            $until = $until === null ? $retry : min($until, $retry);
        }

        // A result with an unreadable object is never cached: the cause may be
        // passing (a library missing during an update), and it is busy anyway.
        if (!$readOnly && $complete) {
            $this->writeCache($calendarId, $synctoken, $window[0], $window[1], $zones, $until);
        }

        return $intervals;
    }

    /**
     * Busy intervals of every object in a window. Fails closed: an object that
     * cannot be expanded makes the whole window busy (and the result is not
     * complete); more than {@see self::MAX_SECONDS} of work makes the whole
     * window busy (partial: cached for {@see self::PARTIAL_SECONDS} only, so not
     * every visitor recomputes it, and a large calendar on a slow host is not
     * blocked for long).
     *
     * @return  array{0: list<array{0: int, 1: int, 2: int}>, 1: bool, 2: bool}  Intervals, whether every object was read, whether the budget cut it.
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

                return [[$whole], $complete, true];
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
                return [[$whole], $complete, false];
            }
        }

        return [self::compact($intervals, $start->getTimestamp(), $end->getTimestamp()), $complete, false];
    }

    /**
     * @return  array{start: \DateTimeImmutable, end: \DateTimeImmutable, zones: array<string, list<array{0: int, 1: int, 2: int}>>, until: int|null}|null
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

        $until = isset($data['until']) && \is_int($data['until']) ? $data['until'] : null;

        if ($until !== null && $until <= $this->services->now()->getTimestamp()) {
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

        return ['start' => $start, 'end' => $end, 'zones' => $zones, 'until' => $until];
    }

    /**
     * @param   array<string, list<array{0: int, 1: int, 2: int}>>  $zones
     */
    private function writeCache(int $calendarId, int $synctoken, \DateTimeImmutable $start, \DateTimeImmutable $end, array $zones, ?int $until): void
    {
        $db = $this->services->db();
        $from = $start->format('Y-m-d H:i:s');
        $to = $end->format('Y-m-d H:i:s');
        $payload = CipherSuite::encrypt($this->timingKey(), json_encode(['v' => 1, 'zones' => $zones] + ($until === null ? [] : ['until' => $until]), JSON_THROW_ON_ERROR),
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
     * calendars and who may still write there, read from the bookings table:
     * they block even before their calendar copy exists or when writing it
     * failed.
     *
     * @param   int[]  $calendarIds
     *
     * @return  AvailabilityWindow[]
     */
    private function otherHostsBookings(array $calendarIds, int $host, \DateTimeImmutable $fromUtc, \DateTimeImmutable $toUtc): array
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName(['b.start_utc', 'b.end_utc', 'b.host_user_id', 'e.calendar_id']))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('e.calendar_id') . ' IN (' . implode(',', array_map('intval', $calendarIds)) . ')')
            ->where($db->quoteName('b.host_user_id') . ' <> ' . $host)
            ->where($db->quoteName('b.state') . ' IN (0,1)')
            ->where($db->quoteName('b.start_utc') . ' < ' . $db->quote($toUtc->format('Y-m-d H:i:s')))
            ->where($db->quoteName('b.end_utc') . ' > ' . $db->quote($fromUtc->format('Y-m-d H:i:s')));
        $windows = [];
        $writers = [];

        foreach ($db->setQuery($query)->loadAssocList() as $row) {
            // Only a host who may still write into the calendar puts bookings there; after a
            // revoked grant that host's bookings are none of this calendar's business.
            $writer = (int) $row['host_user_id'] . ':' . (int) $row['calendar_id'];
            $writers[$writer] ??= $this->canWrite((int) $row['host_user_id'], (int) $row['calendar_id']);

            if (!$writers[$writer]) {
                continue;
            }

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
    private function booking(int $bookingId, bool $lock = false): ?array
    {
        $db = $this->services->db();
        $query = $this->query()->select($db->quoteName(array_merge(['id', 'eventtype_id', 'host_user_id', 'sequence', 'state',
            'start_utc', 'end_utc', 'modified_utc', 'anonymised_utc'], BookingData::readColumns())))
            ->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('id') . ' = ' . $bookingId);
        $row = $db->setQuery((string) $query . ($lock ? ' FOR UPDATE' : ''))->loadAssoc() ?: null;

        // Personal fields are encrypted at rest; one that cannot be decrypted is left out of the copy.
        return $row === null ? null : BookingData::forToken($this->services->keyProvider(),
            (string) $this->services->params()->get(BookingData::MIGRATION_OPTION, ''))->openLenient($row);
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
