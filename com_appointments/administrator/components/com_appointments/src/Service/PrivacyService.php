<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class PrivacyService
{
    public function __construct(private DatabaseInterface $db, private Registry $params)
    {
    }

    public function bookings(string $address): \Generator
    {
        $normalised = PrivacyAddress::normalise($address);
        $db = $this->db;
        $last = 0;
        $column = $db->quoteName('b.invitee_email');
        $value = 'TRIM(' . $column . ')';
        // Like the shop privacy plugin, SQL only supplies a lossless candidate
        // set; accent-insensitive collations must never decide ownership.
        $candidates = ['LENGTH(' . $value . ') <> CHAR_LENGTH(' . $value . ')',
            $value . " REGEXP '[[:cntrl:]]'"];
        if (preg_match('/[^\x00-\x7F]/', $normalised) !== 1) {
            $candidates[] = 'LOWER(CONVERT(' . $value . ' USING utf8mb4) COLLATE utf8mb4_bin) = ' . $db->quote($normalised);
        }

        do {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('b') . '.*')->select($db->quoteName('e.title', 'event_title'))
                ->from($db->quoteName('#__appointments_bookings', 'b'))
                ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON '
                    . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
                ->where($db->quoteName('b.id') . ' > ' . $last)
                ->where($column . ' IS NOT NULL')
                ->where('(' . implode(' OR ', $candidates) . ')')
                ->order($db->quoteName('b.id') . ' ASC')->setLimit(200);
            $rows = $db->setQuery($query)->loadAssocList();
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                if (PrivacyAddress::matches($row['invitee_email'], $normalised)) {
                    yield $row;
                }
            }
        } while (count($rows) === 200);
    }

    /**
     * Bookings in which the address is one of the additional guests. Only what concerns the
     * guest is returned: the booking id, the event type, the time, the state and the guest's
     * address as stored. Neither the invitee's data, nor the other guests, nor the answers.
     * SQL only narrows the candidates losslessly; ownership is decided per line in PHP.
     *
     * @return \Generator<array{id: int, eventtype_id: int, event_title: string, start_utc: string, end_utc: string, state: int, guest_email: string}>
     */
    public function guestBookings(string $address): \Generator
    {
        $normalised = PrivacyAddress::normalise($address);
        $db = $this->db;
        $last = 0;
        $column = $db->quoteName('b.guest_emails');
        $candidates = ['LENGTH(' . $column . ') <> CHAR_LENGTH(' . $column . ')'];
        if (preg_match('/[^\x00-\x7F]/', $normalised) !== 1) {
            $candidates[] = 'LOWER(CONVERT(' . $column . ' USING utf8mb4) COLLATE utf8mb4_bin) LIKE '
                . $db->quote('%' . $db->escape($normalised, true) . '%', false);
        }

        do {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName(['b.id', 'b.eventtype_id', 'b.start_utc', 'b.end_utc', 'b.state', 'b.guest_emails']))
                ->select($db->quoteName('e.title', 'event_title'))
                ->from($db->quoteName('#__appointments_bookings', 'b'))
                ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON '
                    . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
                ->where($db->quoteName('b.id') . ' > ' . $last)
                ->where($column . ' IS NOT NULL')
                ->where($db->quoteName('b.anonymised_utc') . ' IS NULL')
                ->where('(' . implode(' OR ', $candidates) . ')')
                ->order($db->quoteName('b.id') . ' ASC')->setLimit(200);
            $rows = $db->setQuery($query)->loadAssocList();
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                foreach (PrivacyAddress::guestList($row['guest_emails']) as $guest) {
                    if (PrivacyAddress::isGuest($guest, $normalised)) {
                        yield ['id' => (int) $row['id'], 'eventtype_id' => (int) $row['eventtype_id'],
                            'event_title' => (string) $row['event_title'], 'start_utc' => (string) $row['start_utc'],
                            'end_utc' => (string) $row['end_utc'], 'state' => (int) $row['state'], 'guest_email' => $guest];
                        break;
                    }
                }
            }
        } while (count($rows) === 200);
    }

    /** Whether the address has any booking, as invitee or as additional guest. */
    public function hasBookings(string $address): bool
    {
        foreach ($this->bookings($address) as $booking) {
            return true;
        }
        foreach ($this->guestBookings($address) as $booking) {
            return true;
        }

        return false;
    }

    public function legalReason(): string
    {
        return trim((string) $this->params->get('removal_block_reason', ''));
    }

    public function fieldLabels(int $eventId): array
    {
        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'label']))->from($db->quoteName('#__appointments_fields'))
            ->where($db->quoteName('eventtype_id') . ' = ' . $eventId);

        return array_column($db->setQuery($query)->loadAssocList(), 'label', 'id');
    }

    public function remove(string $address, \DateTimeImmutable $now): array
    {
        $mode = (string) $this->params->get('erase_mode', 'anonymise');
        if (!in_array($mode, ['anonymise', 'delete'], true)) {
            throw new \InvalidArgumentException('Invalid appointment erasure mode.');
        }
        $store = new BookingStore($this->db);
        $mail = new NotificationService($this->db);
        $result = ['removed' => 0, 'guest_removed' => 0, 'mail_failed' => 0, 'cleanup_failed' => 0];
        if ($this->legalReason() !== '' && $this->hasBookings($address)) {
            throw new \RuntimeException('Appointment removal is blocked by the configured legal reason.');
        }
        // A guest address is removed from the guest list only; the other person's booking stays.
        // Collected first, because each removal changes the rows the generator pages through.
        $guestIds = [];
        foreach ($this->guestBookings($address) as $guestBooking) {
            $guestIds[] = $guestBooking['id'];
        }
        foreach ($guestIds as $guestId) {
            $result['guest_removed'] += $store->removeGuest($guestId, $now, 'privacy_request', $address)['changed'] ? 1 : 0;
        }
        foreach ($this->bookings($address) as $booking) {
            $erasure = $store->erase((int) $booking['id'], $now, 'privacy_request', $mode === 'delete');
            $result['removed'] += $erasure['changed'] ? 1 : 0;
            $result['cleanup_failed'] += !empty($erasure['_conference_cleanup_error']) ? 1 : 0;
            if ($erasure['notification'] !== null) {
                try {
                    $mail->send($erasure['notification'], 'erasure_notice', $now, true);
                } catch (NotificationException $e) {
                    $result['mail_failed']++;
                }
            }
        }

        return $result;
    }
}
