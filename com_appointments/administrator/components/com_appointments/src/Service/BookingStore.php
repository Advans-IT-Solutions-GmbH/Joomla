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

    public function changeState(int $bookingId, int $state, \DateTimeImmutable $now): array
    {
        if (!in_array($state, [1, 2], true)) {
            throw new \InvalidArgumentException('Unsupported booking state change.');
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

            if (!$booking || $booking['anonymised_utc'] !== null
                || ($state === 1 && (int) $booking['state'] !== 0)) {
                throw new \DomainException('The booking cannot make this state transition.');
            }

            if ((int) $booking['state'] === $state) {
                $db->transactionCommit();

                return $booking;
            }

            $booking['state'] = $state;
            $booking['sequence'] = (int) $booking['sequence'] + 1;
            $booking['modified_utc'] = ModifiedTime::next($booking['modified_utc'], $now);
            $row = (object) ['id' => $bookingId, 'state' => $state, 'sequence' => $booking['sequence'], 'modified_utc' => $booking['modified_utc']];
            $db->updateObject('#__appointments_bookings', $row, 'id');
            $db->transactionCommit();

            return $booking;
        } catch (\Throwable $e) {
            $db->transactionRollback();

            throw $e;
        }
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
