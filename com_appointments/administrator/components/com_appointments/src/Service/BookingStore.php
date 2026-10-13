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
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class BookingStore
{
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
        $db->transactionStart();

        try {
            $this->lockHost($host);
            $event = $this->lockedEvent($eventId);

            if (!$event || (int) $event['host_user_id'] !== $host || (int) $event['published'] !== 1
                || !in_array((int) $event['access'], array_map('intval', $viewLevels), true)
                || !in_array($event['language'], ['*', $language], true)) {
                throw new SlotTakenException();
            }

            $context = (new AvailabilityService($db))->context($event, $start, $start->modify('+1 second'));

            if (!(new BookingGuard())->accepts($context['engine'], $start, $now, $context['busy'], $context['caps'])) {
                throw new SlotTakenException();
            }

            $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            $rules = SlotRules::fromEventType($event);
            $end = $start->modify('+' . $rules->getDurationSeconds() . ' seconds');

            if (UtcTimestamp::parse($end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')) === null) {
                throw new SlotTakenException();
            }

            $row = (object) array_merge($invitee, [
                'uid' => bin2hex(random_bytes(32)), 'sequence' => 0, 'eventtype_id' => $eventId, 'host_user_id' => $host,
                'start_utc' => $start->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'end_utc' => $end->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                'state' => (int) $event['requires_approval'] === 1 ? 0 : 1,
                'created_utc' => $time, 'modified_utc' => $time,
            ]);
            $db->insertObject('#__appointments_bookings', $row, 'id');
            $db->transactionCommit();

            return (array) $row;
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
            $booking = $db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc();

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
            $row = (object) ['id' => $bookingId, 'state' => $state, 'sequence' => $booking['sequence'], 'modified_utc' => $booking['modified_utc'],
                'cancel_reason' => $booking['cancel_reason']];
            $db->updateObject('#__appointments_bookings', $row, 'id');
            $db->transactionCommit();

            return $booking + ['_changed' => true];
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }
    }

    public function reschedule(int $bookingId, \DateTimeImmutable $start, \DateTimeImmutable $now, ?SignedLink $links = null, string $signature = '', ?string $timezone = null): array
    {
        if ($timezone !== null && !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException('Invalid rescheduled appointment time zone.');
        }
        $db = $this->db;
        $host = $this->bookingHost($bookingId);
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
            $context = (new AvailabilityService($db))->context($event, $start, $start->modify('+1 second'), $bookingId);
            if (!(new BookingGuard())->accepts($context['engine'], $start, $now, $context['busy'], $context['caps'])) {
                throw new SlotTakenException();
            }
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
            $row = (object) ['id' => $bookingId, 'start_utc' => $booking['start_utc'], 'end_utc' => $booking['end_utc'],
                'sequence' => $booking['sequence'], 'modified_utc' => $booking['modified_utc'], 'reminder_sent_utc' => null,
                'invitee_timezone' => $booking['invitee_timezone']];
            $db->updateObject('#__appointments_bookings', $row, 'id', true);
            $db->transactionCommit();

            return $booking;
        } catch (\Throwable $e) {
            $db->transactionRollback();
            if ($this->isSlotDuplicate($e)) {
                throw new SlotTakenException('', 0, $e);
            }
            throw $e;
        }
    }

    public function erase(int $bookingId, \DateTimeImmutable $now, string $path, bool $delete = false, ?int $actor = null, ?SignedLink $links = null, string $signature = '', ?int $retentionDays = null): array
    {
        if (!in_array($path, ['self_service', 'privacy_request', 'retention', 'admin'], true)
            || ($path === 'admin' ? $actor === null || $actor < 1 : $actor !== null)
            || ($path === 'self_service' && $links === null)
            || ($path === 'retention' && ($retentionDays === null || $retentionDays < 0 || $retentionDays > 65535))
            || ($path !== 'retention' && $retentionDays !== null)) {
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
                $db->updateObject('#__appointments_bookings', (object) $erased, 'id', true);
            }
            $audit = (object) ['booking_id' => $bookingId, 'action' => $delete ? 'delete' : 'anonymise',
                'path' => $path, 'actor_user_id' => $actor, 'occurred_utc' => $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
            $db->insertObject('#__appointments_audit', $audit);
            $db->transactionCommit();

            return ['changed' => true, 'notification' => $notification];
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
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

    private function lockedBooking(int $bookingId): ?array
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('*')->from($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . $bookingId);

        return $db->setQuery((string) $query . ' FOR UPDATE')->loadAssoc() ?: null;
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
