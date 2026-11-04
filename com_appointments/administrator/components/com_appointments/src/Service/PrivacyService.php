<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\BookingData;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class PrivacyService
{
    public function __construct(private DatabaseInterface $db, private Registry $params)
    {
    }

    /**
     * The bookings of an invitee address, opened. The personal fields are
     * encrypted at rest, so the candidates are found by BookingRecords in
     * memory (exact address after trimming and Unicode lower case), never by
     * a SQL condition on the address.
     *
     * @return \Generator<array<string, mixed>>
     */
    public function bookings(string $address): \Generator
    {
        $ids = [];

        foreach ($this->records()->findByEmail($address, 200) as $match) {
            if ($match['role'] === 1) {
                $ids[] = $match['id'];
            }
        }

        foreach (array_chunk($ids, 200) as $chunk) {
            foreach ($this->rows($chunk, ['b.*']) as $row) {
                yield $row;
            }
        }
    }

    /**
     * Bookings in which the address is one of the additional guests. Only what concerns the
     * guest is returned: the booking id, the event type, the time, the state and the guest's
     * address as stored. Neither the invitee's data, nor the other guests, nor the answers.
     *
     * @return \Generator<array{id: int, eventtype_id: int, event_title: string, start_utc: string, end_utc: string, state: int, guest_email: string}>
     */
    public function guestBookings(string $address): \Generator
    {
        $normalised = PrivacyAddress::normalise($address);
        $ids = [];

        foreach ($this->records()->findByEmail($address, 200) as $match) {
            if ($match['role'] === 2) {
                $ids[] = $match['id'];
            }
        }

        foreach (array_chunk($ids, 200) as $chunk) {
            foreach ($this->rows($chunk, ['b.id', 'b.uid', 'b.eventtype_id', 'b.start_utc', 'b.end_utc', 'b.state', 'b.guest_emails']) as $row) {
                foreach (PrivacyAddress::guestList($row['guest_emails']) as $guest) {
                    if (PrivacyAddress::isGuest($guest, $normalised)) {
                        yield ['id' => (int) $row['id'], 'eventtype_id' => (int) $row['eventtype_id'],
                            'event_title' => (string) $row['event_title'], 'start_utc' => (string) $row['start_utc'],
                            'end_utc' => (string) $row['end_utc'], 'state' => (int) $row['state'], 'guest_email' => $guest];
                        break;
                    }
                }
            }
        }
    }

    /**
     * Rows of the given bookings with their event title, personal fields opened
     * (a field that cannot be opened is blank).
     *
     * @param int[]    $ids
     * @param string[] $columns
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(array $ids, array $columns): array
    {
        if ($ids === []) {
            return [];
        }

        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($columns === ['b.*'] ? $db->quoteName('b') . '.*' : $db->quoteName($columns))
            ->select($db->quoteName('e.title', 'event_title'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON '
                . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' IN (' . implode(',', array_map('intval', $ids)) . ')')
            ->order($db->quoteName('b.id') . ' ASC');
        $data = BookingData::fromApplication();

        return array_map(static fn (array $row): array => $data->openLenient($row), $db->setQuery($query)->loadAssocList());
    }

    private function records(): BookingRecords
    {
        return new BookingRecords($this->db, new KeyProvider((string) Factory::getApplication()->get('secret', '')));
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
