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
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

final class PrivacyService
{
    private int $unreadable = 0;

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
        $records = $this->records();

        foreach ($records->findByEmail($address, 200) as $match) {
            if ($match['role'] === 1) {
                $ids[] = $match['id'];
            }
        }

        $this->reportUnreadable($records);

        $normalised = PrivacyAddress::normalise($address);

        foreach (array_chunk($ids, 200) as $chunk) {
            foreach ($this->rows($chunk, ['b.*']) as $row) {
                // Checked again on the row that is returned: it is read a second time.
                if (is_string($row['invitee_email']) && PrivacyAddress::matches($row['invitee_email'], $normalised)) {
                    yield $row;
                }
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
        $records = $this->records();

        foreach ($records->findByEmail($address, 200) as $match) {
            if ($match['role'] === 2) {
                $ids[] = $match['id'];
            }
        }

        $this->reportUnreadable($records);

        foreach (array_chunk($ids, 200) as $chunk) {
            foreach ($this->rows($chunk, array_merge(['b.id', 'b.eventtype_id', 'b.start_utc', 'b.end_utc', 'b.state'], BookingData::readColumns('b'))) as $row) {
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

        // The markers of an opened row are no booking data: they never reach the export.
        // The markers of an opened row are no booking data and never reach the export; a booking of
        // 1.3 still in clear text after the migration window is exported as not verified.
        return array_map(static function (array $row) use ($data): array {
            $row = $data->openLenient($row);
            $unverified = !empty($row[BookingData::UNVERIFIED]);

            return array_diff_key($row, [BookingData::OPENED => true, BookingData::CLEAR => true, BookingData::UNVERIFIED => true])
                + ['unverified' => $unverified ? 1 : 0];
        }, $db->setQuery($query)->loadAssocList());
    }

    /**
     * Bookings that could not be opened cannot be searched: the operator learns it
     * from the log (a count, never an address), and unreadable() tells the caller
     * that the request was not complete.
     */
    private function reportUnreadable(BookingRecords $records): void
    {
        $count = $records->unreadable();
        $this->unreadable += $count;

        if ($count > 0) {
            Log::add('Appointment privacy request: ' . $count . ' bookings could not be decrypted and were not searched.', Log::WARNING, 'com_appointments');
        }
    }

    /** Bookings the searches of this service could not open. */
    public function unreadable(): int
    {
        return $this->unreadable;
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

    public function remove(string $address, \DateTimeImmutable $now, int $requestId = 0): array
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
        $cleanup = $requestId > 0 ? new PrivacyCleanup($this->db,
            new KeyProvider((string) Factory::getApplication()->get('secret', '')), $requestId, $address) : null;
        $cleanup?->retry($address);
        // A guest address is removed from the guest list only; the other person's booking stays.
        // Collected first, because each removal changes the rows the generator pages through.
        $guestIds = [];
        foreach ($this->guestBookings($address) as $guestBooking) {
            $guestIds[] = $guestBooking['id'];
        }
        foreach ($guestIds as $guestId) {
            $erasure = $store->removeGuest($guestId, $now, 'privacy_request', $address, privacyCleanup: $cleanup);
            $result['guest_removed'] += $erasure['changed'] ? 1 : 0;
            $result['cleanup_failed'] += !empty($erasure['_calendar_cleanup_error']) ? 1 : 0;
        }
        foreach ($this->bookings($address) as $booking) {
            $erasure = $store->erase((int) $booking['id'], $now, 'privacy_request', $mode === 'delete', privacyCleanup: $cleanup);
            $result['removed'] += $erasure['changed'] ? 1 : 0;
            $result['cleanup_failed'] += !empty($erasure['_conference_cleanup_error']) || !empty($erasure['_calendar_cleanup_error'])
                || ($requestId < 1 && !empty($erasure['_crm_cleanup_error'])) ? 1 : 0;
            if ($erasure['notification'] !== null) {
                try {
                    $mail->send($erasure['notification'], 'erasure_notice', $now, true);
                } catch (NotificationException $e) {
                    $result['mail_failed']++;
                }
            }
        }

        $result['cleanup_failed'] = max($result['cleanup_failed'], $cleanup?->pending() ?? 0);
        if ($requestId > 0) {
            $crm = new Close\Queue($this->db, $this->params);
            if ($crm->pendingPrivacy($requestId) > 0) {
                try {
                    $crm->run(20, true, $requestId);
                } catch (\Throwable $e) {
                    \Joomla\CMS\Log\Log::add('Appointment CRM erasure remains pending; check delivery configuration.',
                        \Joomla\CMS\Log\Log::WARNING, 'com_appointments');
                }
            }
            $result['cleanup_failed'] += $crm->pendingPrivacy($requestId);
        }

        return $result;
    }
}
