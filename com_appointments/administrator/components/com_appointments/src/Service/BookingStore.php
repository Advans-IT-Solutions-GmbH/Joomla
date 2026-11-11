<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\BookingGuard;
use Advans\Component\Appointments\Administrator\Helper\SlotTakenException;
use Advans\Component\Appointments\Administrator\Helper\SlotRules;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Administrator\Helper\ModifiedTime;
use Advans\Component\Appointments\Administrator\Helper\SignedLink;
use Advans\Component\Appointments\Administrator\Helper\Anonymiser;
use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Log\Log;

defined('_JEXEC') or die;

final class BookingStore
{
    private ?BookingData $data = null;

    public function __construct(private DatabaseInterface $db)
    {
    }

    public function create(array $publicEvent, array $invitee, \DateTimeImmutable $start, \DateTimeImmutable $now, array $viewLevels, string $language): array
    {
        $db = $this->db;
        $host = (int) $publicEvent['host_user_id'];
        $eventId = (int) $publicEvent['id'];
        // Create outside the transaction. The first transactional read must be the
        // host lock, so a REPEATABLE READ snapshot cannot precede a competing commit.
        (new HostStore($db))->ensure($host, $now);
        // Internet calendar busy times: fill their cache now, because inside the
        // locked transaction they are only read (a cache write there could deadlock).
        (new AvailabilityService($db))->warmCalendars($publicEvent, $start, $start->modify('+1 second'));
        $db->transactionStart();

        try {
            $this->lockHost($host);
            $event = $this->lockedEvent($eventId);

            if (!$event || (int) $event['host_user_id'] !== $host || (int) $event['published'] !== 1
                || !in_array((int) $event['access'], array_map('intval', $viewLevels), true)
                || !in_array($event['language'], ['*', $language], true)) {
                throw new SlotTakenException();
            }

            $this->lockCalendars($eventId);
            $context = (new AvailabilityService($db))->context($event, $start, $start->modify('+1 second'), 0, true);

            if (!(new BookingGuard())->accepts($context['engine'], $start, $now, $context['busy'], $context['caps'])) {
                throw new SlotTakenException();
            }

            $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $rules = SlotRules::fromEventType($event);
            $end = $start->modify('+' . $rules->getDurationSeconds() . ' seconds');

            if (UtcTimestamp::parse($end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')) === null) {
                throw new SlotTakenException();
            }

            $clear = array_merge($invitee, [
                'uid' => bin2hex(random_bytes(32)), 'sequence' => 0, 'eventtype_id' => $eventId, 'host_user_id' => $host,
                'start_utc' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end_utc' => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'state' => (int) $event['requires_approval'] === 1 ? 0 : 1,
                'created_utc' => $time, 'modified_utc' => $time,
            ]);
            // Reserve the identity without personal fields; both writes commit together.
            $skeleton = (object) array_intersect_key($clear, array_flip(['uid', 'sequence', 'eventtype_id', 'host_user_id',
                'start_utc', 'end_utc', 'state', 'created_utc', 'modified_utc']));
            if (!$db->insertObject('#__appointments_bookings', $skeleton, 'id')) {
                throw new \RuntimeException('The booking identity could not be stored.');
            }
            $clear['id'] = $skeleton->id;
            $row = (object) $this->data()->seal($clear);
            if (!$db->updateObject('#__appointments_bookings', $row, 'id', true)) {
                throw new \RuntimeException('The encrypted booking could not be stored.');
            }
            Close\Queue::changed($db, $clear, created: true);
            $db->transactionCommit();
            $this->mirror('saved', $clear);

            return $clear;
        } catch (\Throwable $e) {
            $db->transactionRollback();

            if ($e instanceof SlotTakenException || $this->isSlotDuplicate($e)) {
                throw new SlotTakenException('', 0, $e);
            }

            throw $e;
        }
    }

    public function lockHost(int $host): void
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('user_id'))->from($db->quoteName('#__appointments_hosts'))
            ->where($db->quoteName('user_id') . ' = ' . $host);

        if (!$db->setQuery((string) $query . ' FOR UPDATE')->loadResult()) {
            throw new \RuntimeException('The booking host lock row is missing.');
        }

    }

    public function changeState(int $bookingId, int $state, \DateTimeImmutable $now, ?SignedLink $links = null, string $signature = '', string $reason = ''): array
    {
        if (!in_array($state, [1, 2], true)) {
            throw new \InvalidArgumentException('Unsupported booking state change.');
        }

        if ($links !== null && $state !== 2) {
            throw new \InvalidArgumentException('A public state change can only cancel a booking.');
        }

        if (preg_match('//u', $reason) !== 1 || mb_strlen($reason) > 2000 || str_contains($reason, "\0")) {
            throw new \InvalidArgumentException('Invalid cancellation reason.');
        }

        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('host_user_id'))->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('id') . ' = ' . $bookingId);
        $host = (int) $db->setQuery($query)->loadResult();

        if ($host < 1) {
            throw new \OutOfBoundsException('Booking does not exist.');
        }

        $db->transactionStart();

        try {
            $this->lockHost($host);
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select('*')->from($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . $bookingId);
            $booking = $this->open($db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc() ?: null);

            if ($links !== null && (!$booking || !$links->verify($bookingId, 'cancel', $booking['modified_utc'], $signature))) {
                throw new InvalidBookingLinkException();
            }

            if (!$booking || $booking['anonymised_utc'] !== null
                || ($state === 1 && (int) $booking['state'] !== 0)) {
                throw new \DomainException('The booking cannot make this state transition.');
            }

            if ((int) $booking['state'] === $state) {
                $db->transactionCommit();

                return $booking + ['_changed' => false];
            }

            $booking['state'] = $state;
            $booking['sequence'] = (int) $booking['sequence'] + 1;
            $booking['modified_utc'] = ModifiedTime::next($booking['modified_utc'], $now);
            $booking['cancel_reason'] = $state === 2 && $reason !== '' ? $reason : null;
            $row = (object) $this->data()->sealChange($booking, ['id' => $bookingId, 'state' => $state,
                'sequence' => $booking['sequence'], 'modified_utc' => $booking['modified_utc'], 'cancel_reason' => $booking['cancel_reason']]);
            $db->updateObject('#__appointments_bookings', $row, 'id');
            Close\Queue::changed($db, $booking);
            $db->transactionCommit();

        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }

        $this->mirror($state === 2 ? 'cancelled' : 'saved', $booking);
        $cleanup = $state === 2 ? $this->conference()->destroy($booking) : $booking;

        return $cleanup + ['_changed' => true];
    }

    public function reschedule(int $bookingId, \DateTimeImmutable $start, \DateTimeImmutable $now, ?SignedLink $links = null, string $signature = '', ?string $timezone = null): array
    {
        if ($timezone !== null && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid rescheduled appointment time zone.');
        }
        $db = $this->db;
        $host = $this->bookingHost($bookingId);
        $this->warmForBooking($bookingId, $start);
        $db->transactionStart();

        try {
            $this->lockHost($host);
            $booking = $this->lockedBooking($bookingId);
            if ($links !== null && (!$booking || !$links->verify($bookingId, 'reschedule', $booking['modified_utc'], $signature))) {
                throw new InvalidBookingLinkException();
            }
            if (!$booking || $booking['anonymised_utc'] !== null || !in_array((int) $booking['state'], [0, 1], true)
                || UtcTimestamp::parse($booking['start_utc']) <= $now) {
                throw new \DomainException('The appointment cannot be rescheduled.');
            }
            $event = $this->lockedEvent((int) $booking['eventtype_id']);
            if (!$event || (int) $event['host_user_id'] !== $host || (int) $event['published'] !== 1) {
                throw new SlotTakenException();
            }
            $this->lockCalendars((int) $booking['eventtype_id']);
            $context = (new AvailabilityService($db))->context($event, $start, $start->modify('+1 second'), $bookingId, true);
            if (!(new BookingGuard())->accepts($context['engine'], $start, $now, $context['busy'], $context['caps'])) {
                throw new SlotTakenException();
            }
            $previous = $booking;
            $end = $start->modify('+' . SlotRules::fromEventType($event)->getDurationSeconds() . ' seconds');
            if (UtcTimestamp::parse($end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')) === null) {
                throw new SlotTakenException();
            }
            $booking['start_utc'] = $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $booking['end_utc'] = $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $booking['sequence'] = (int) $booking['sequence'] + 1;
            $booking['modified_utc'] = ModifiedTime::next($booking['modified_utc'], $now);
            $booking['reminder_sent_utc'] = null;
            $booking['invitee_timezone'] = $timezone ?? $booking['invitee_timezone'];
            $booking['conference_state'] = null;
            foreach (['conference_provider', 'conference_url', 'conference_room', 'conference_error'] as $field) {
                $booking[$field] = null;
            }
            $row = (object) ['id' => $bookingId, 'start_utc' => $booking['start_utc'], 'end_utc' => $booking['end_utc'],
                'sequence' => $booking['sequence'], 'modified_utc' => $booking['modified_utc'], 'reminder_sent_utc' => null,
                'invitee_timezone' => $booking['invitee_timezone'], 'conference_state' => null,
                'conference_provider' => null, 'conference_url' => null, 'conference_room' => null, 'conference_error' => null];
            $db->updateObject('#__appointments_bookings', $row, 'id', true);
            Close\Queue::changed($db, $booking);
            $db->transactionCommit();

        } catch (\Throwable $e) {
            $db->transactionRollback();
            if ($this->isSlotDuplicate($e)) {
                throw new SlotTakenException('', 0, $e);
            }
            throw $e;
        }

        // Same UID, new times. A new conference link rewrites the object once more.
        $this->mirror('saved', $booking);
        // _rescheduled_version: the row version this reschedule committed. The snapshots below may
        // already carry a later change of someone else (a stale conference result returns the current row).
        $version = ['_rescheduled_version' => $booking['modified_utc']];
        $cleared = $this->conference()->destroy($previous);
        if (!empty($cleared['_conference_missing']) || !empty($cleared['_conference_error'])) {
            return $version + $cleared;
        }
        $provisioned = $this->conference()->provision($cleared, $now);

        return $version + $provisioned + ['_conference_cleanup_error' => !empty($cleared['_conference_cleanup_error'])];
    }

    public function erase(int $bookingId, \DateTimeImmutable $now, string $path, bool $delete = false, ?int $actor = null, ?SignedLink $links = null, string $signature = '', ?int $retentionDays = null, ?PrivacyCleanup $privacyCleanup = null): array
    {
        if (!in_array($path, ['self_service', 'privacy_request', 'retention', 'admin'], true)
            || ($path === 'admin' ? $actor === null || $actor < 1 : $actor !== null)
            || ($path === 'self_service' && $links === null)
            || ($path === 'retention' && ($retentionDays === null || $retentionDays < 0 || $retentionDays > 65535))
            || ($path !== 'retention' && $retentionDays !== null)
            || ($privacyCleanup !== null && $path !== 'privacy_request')) {
            throw new \InvalidArgumentException('Invalid erasure provenance.');
        }
        $db = $this->db;
        $host = $this->bookingHost($bookingId, $links === null);
        if ($host === 0) {
            return ['changed' => false, 'notification' => null];
        }
        $db->transactionStart();

        try {
            $this->lockHost($host);
            $booking = $this->lockedBooking($bookingId);
            if ($links !== null && (!$booking || !$links->verify($bookingId, 'erase', $booking['modified_utc'], $signature))) {
                throw new InvalidBookingLinkException();
            }
            if (!$booking || $booking['anonymised_utc'] !== null) {
                $db->transactionCommit();

                return ['changed' => false, 'notification' => null];
            }
            if ($path === 'retention') {
                $event = $this->lockedEvent((int) $booking['eventtype_id']);
                if (!$event) {
                    throw new \RuntimeException('The retention booking event type is missing.');
                }
                $days = $event['retention_days'] === null ? $retentionDays : (int) $event['retention_days'];
                $cutoff = $now->setTimezone(new \DateTimeZone('UTC'))->modify('-' . $days . ' days');
                $end = UtcTimestamp::parse($booking['end_utc']);
                if ($end === null) {
                    throw new \RuntimeException('The retention booking end time is invalid.');
                }
                // The candidate may have been rescheduled while this worker waited
                // for the host lock. Never erase its newly future appointment.
                if ($end->format('Y-m-d H:i:s') >= $cutoff->format('Y-m-d H:i:s')) {
                    $db->transactionCommit();

                    return ['changed' => false, 'notification' => null];
                }
            }
            $cleanupKey = $privacyCleanup?->remember($booking, false);
            $anonymiser = new Anonymiser();
            $erased = $anonymiser->anonymise($booking, $now);
            $notification = (int) $booking['state'] !== 2 && (int) $erased['state'] === 2
                ? $anonymiser->cancellationPayload($erased) : null;
            if ($delete) {
                $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
                $db->setQuery($query->delete($db->quoteName('#__appointments_bookings'))
                    ->where($db->quoteName('id') . ' = ' . $bookingId))->execute();
            } else {
                unset($erased['state_active']);
                // Every personal field is null now; sealing keeps any other one encrypted.
                $row = (object) $this->data()->seal($erased);
                $db->updateObject('#__appointments_bookings', $row, 'id', true);
            }
            $audit = (object) ['booking_id' => $bookingId, 'action' => $delete ? 'delete' : 'anonymise',
                'path' => $path, 'actor_user_id' => $actor, 'occurred_utc' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
            $db->insertObject('#__appointments_audit', $audit);
            Close\Queue::changed($db, $erased, true);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }

        $mirrored = $this->mirror('erased', ['id' => $bookingId]);
        $cleanup = $this->conference()->destroy($booking);
        $conferenceFailed = !empty($cleanup['_conference_cleanup_error']) || !empty($cleanup['_conference_error']);
        if ($privacyCleanup !== null && $cleanupKey !== null && $mirrored && !$conferenceFailed) {
            $privacyCleanup->forget($bookingId, $cleanupKey);
        }

        return ['changed' => true, 'notification' => $notification,
            '_calendar_cleanup_error' => !$mirrored,
            '_conference_cleanup_error' => $conferenceFailed];
    }

    /**
     * Removes one additional guest's address from a booking and nothing else: the booking, the
     * invitee's data and the other guests stay. The guest is identified either by the signed
     * guest link ($links + $signature, path `self_service`) or by the address of a formal
     * privacy request ($address, path `privacy_request`), always by exact per-line comparison.
     *
     * The row version (`modified_utc`) is deliberately left unchanged: the signed links of the
     * invitee and of the other guests stay valid. A used guest link cannot be replayed anyway,
     * because the address it was signed for is no longer among the guests.
     *
     * The audit row holds the booking id, the action `remove_guest` and the path, never the
     * address.
     *
     * @return array{changed: bool, guest: ?string}
     */
    public function removeGuest(int $bookingId, \DateTimeImmutable $now, string $path, ?string $address = null,
        ?SignedLink $links = null, string $signature = '', ?PrivacyCleanup $privacyCleanup = null): array
    {
        if (!in_array($path, ['self_service', 'privacy_request'], true)
            || ($path === 'self_service' ? $links === null || $address !== null : $links !== null || $address === null)
            || ($privacyCleanup !== null && $path !== 'privacy_request')) {
            throw new \InvalidArgumentException('Invalid guest removal provenance.');
        }
        $db = $this->db;
        $host = $this->bookingHost($bookingId, $links === null);
        if ($host === 0) {
            return ['changed' => false, 'guest' => null];
        }
        $copyGeneration = '';
        $db->transactionStart();

        try {
            // Only the booking row: a removal changes one column and no availability, so it takes no
            // host lock. While a mail delivery holds the row (NotificationService::send), the removal
            // waits without stalling the host's other bookings, and no lock is held in the other order.
            $booking = $this->lockedBooking($bookingId);
            $guests = PrivacyAddress::guestList($booking['guest_emails'] ?? null);
            if ($links !== null) {
                $address = $booking && $booking['anonymised_utc'] === null
                    ? $links->guestFor($bookingId, $guests, (string) $booking['modified_utc'], $signature) : null;
                if ($address === null) {
                    throw new InvalidBookingLinkException();
                }
            }
            if (!$booking || $booking['anonymised_utc'] !== null || !PrivacyAddress::isGuest($booking['guest_emails'], (string) $address)) {
                $db->transactionCommit();

                return ['changed' => false, 'guest' => null];
            }
            $cleanupKey = $privacyCleanup?->remember($booking, true);
            if ($path === 'self_service') {
                $copyGeneration = PrivacyCleanup::rememberCopy($db, $bookingId);
            }
            $row = (object) $this->data()->sealChange($booking,
                ['id' => $bookingId, 'guest_emails' => PrivacyAddress::withoutGuest($booking['guest_emails'], (string) $address)]);
            $db->updateObject('#__appointments_bookings', $row, 'id');
            $audit = (object) ['booking_id' => $bookingId, 'action' => 'remove_guest', 'path' => $path, 'actor_user_id' => null,
                'occurred_utc' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
            $db->insertObject('#__appointments_audit', $audit);
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }

        // The calendar copy lists the guests as attendees. It is removed first, also while the
        // feature is off (a later switch-on must not bring the address back), and written again
        // without the address when the feature is on. Both are attempted even if the removal
        // fails: the sanitised rewrite alone replaces the stale copy. A cleanup reference (formal
        // request, or the booking-only reference of the guest link, retried by the booking upkeep
        // task) is only forgotten when both succeed.
        $erased = $this->mirror('erased', ['id' => $bookingId]);
        $mirrored = $this->mirror('saved', ['id' => $bookingId]) && $erased;
        if ($mirrored && $privacyCleanup !== null && $cleanupKey !== null) {
            $privacyCleanup->forget($bookingId, $cleanupKey);
        }
        if ($mirrored && $path === 'self_service') {
            PrivacyCleanup::forgetCopy($db, $bookingId, $copyGeneration);
        }

        return ['changed' => true, 'guest' => $address, '_calendar_cleanup_error' => !$mirrored];
    }

    private function bookingHost(int $bookingId, bool $missingAllowed = false): int
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('host_user_id'))->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('id') . ' = ' . $bookingId);
        $host = (int) $db->setQuery($query)->loadResult();
        if ($host < 1 && !$missingAllowed) {
            throw new InvalidBookingLinkException();
        }

        return $host;
    }

    /**
     * Mirror a committed booking change into the internet calendar of its event
     * type. Never throws: a calendar error must not fail a booking.
     */
    private function mirror(string $action, array $booking): bool
    {
        try {
            $team = TeamServices::fromApplication();

            // Every action also runs while the feature is off: the bridge then removes the copy
            // instead of writing it (removal needs no key).
            $bridge = $team->bookings();
            $result = match ($action) {
                'saved' => $bridge->onBookingSaved($booking),
                'cancelled' => $bridge->onBookingCancelled($booking),
                'erased' => $bridge->onBookingErased((int) ($booking['id'] ?? 0)),
            };

            return $action === 'cancelled' || $result === true;
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('Appointment calendar mirroring failed (' . get_class($e) . ').',
                \Joomla\CMS\Log\Log::WARNING, 'com_appointments');

            return false;
        }
    }

    /**
     * Inside the booking transaction, after the host lock and the event type row and
     * before any plain read: lock the internet calendars of the event type, so bookings
     * of other hosts that share one are serialised (BookingCalendarBridge::lockForBooking()).
     * A database error of that lock ends the transaction like one of the host lock.
     */
    private function lockCalendars(int $eventId): void
    {
        try {
            $team = TeamServices::fromApplication();
        } catch (\Throwable $e) {
            return;
        }

        if ($team->enabled()) {
            $team->bookings()->lockForBooking($eventId);
        }
    }

    /** Fill the busy cache for a reschedule before the locked transaction reads it. */
    private function warmForBooking(int $bookingId, \DateTimeImmutable $start): void
    {
        try {
            $db = $this->db;
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select('e.*')->from($db->quoteName('#__appointments_eventtypes', 'e'))
                ->join('INNER', $db->quoteName('#__appointments_bookings', 'b'), $db->quoteName('b.eventtype_id') . ' = ' . $db->quoteName('e.id'))
                ->where($db->quoteName('b.id') . ' = ' . $bookingId);
            $event = $db->setQuery($query)->loadAssoc();

            if ($event) {
                (new AvailabilityService($db))->warmCalendars($event, $start, $start->modify('+1 second'));
            }
        } catch (\Throwable $e) {
            // Warming is an optimisation only.
        }
    }

    private function conference(): ConferenceService
    {
        return ConferenceService::forComponent($this->db);
    }

    private function lockedBooking(int $bookingId): ?array
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('*')->from($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . $bookingId);

        return $this->open($db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc() ?: null);
    }

    private function data(): BookingData
    {
        return $this->data ??= BookingData::fromApplication();
    }

    /**
     * A booking row with its personal fields in clear. A field that cannot be
     * decrypted (another site secret, a changed byte) is blank, and only its
     * column name is logged: a state change, a reschedule or an erasure still
     * works on such a row, and erasure removes it.
     */
    private function open(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $failed = [];
        $row = $this->data()->openLenient($row, $failed);

        if ($failed !== []) {
            Log::add('Appointment booking ' . (int) ($row['id'] ?? 0) . ': fields could not be decrypted (' . implode(', ', $failed) . ').',
                Log::WARNING, 'com_appointments');
        }

        return $row;
    }

    public function lockedEvent(int $eventId): ?array
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('*')->from($db->quoteName('#__appointments_eventtypes'))
            ->where($db->quoteName('id') . ' = ' . $eventId);

        return $db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc() ?: null;
    }

    private function isSlotDuplicate(\Throwable $error): bool
    {
        do {
            if ((int) $error->getCode() === 1062 && str_contains($error->getMessage(), 'idx_active_slot')) {
                return true;
            }
            $error = $error->getPrevious();
        } while ($error !== null);

        return false;
    }
}
