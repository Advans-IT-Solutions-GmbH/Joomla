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
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Log\Log;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/** Unfinished internal cleanup, not another request lifecycle or an archive of erased content. */
final class PrivacyCleanup
{
    public function __construct(private DatabaseInterface $db, private KeyProvider $keys, private int $requestId, private string $address)
    {
        if ($requestId < 1) {
            throw new \InvalidArgumentException('A Core privacy request is required for cleanup provenance.');
        }
    }

    /**
     * Called with the locked booking, in the SAME transaction as erasure. Only the
     * identifiers needed to retry teardown survive; never names, addresses or keys.
     */
    public function remember(array $booking, bool $guest): string
    {
        if ($guest ? !PrivacyAddress::isGuest($booking['guest_emails'], $this->address)
            : !PrivacyAddress::matches((string) $booking['invitee_email'], $this->address)) {
            throw new \RuntimeException('The booking changed after the privacy search. Retry the request.');
        }
        $snapshot = $guest ? ['id' => (int) $booking['id']] : array_intersect_key($booking, array_flip([
            'id', 'uid', 'host_user_id', 'eventtype_id', 'start_utc', 'end_utc', 'sequence', 'modified_utc',
            'conference_provider', 'conference_room',
        ]));
        $key = hash_hmac('sha256', json_encode([(int) $booking['id'], $guest,
            $snapshot['conference_provider'] ?? null, $snapshot['conference_room'] ?? null], JSON_THROW_ON_ERROR),
            $this->keys->key('privacy-cleanup-index'));
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $payload = base64_encode($nonce . sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            json_encode($snapshot, JSON_THROW_ON_ERROR), $this->aad((int) $booking['id'], $key, $guest),
            $nonce, $this->keys->key('privacy-cleanup')));
        $this->db->setQuery('INSERT IGNORE INTO ' . $this->db->quoteName('#__appointments_privacy_cleanup')
            . ' (request_id, booking_id, cleanup_key, guest, payload) VALUES ('
            . $this->requestId . ', ' . (int) $booking['id'] . ', ' . $this->db->quote($key) . ', '
            . ($guest ? 1 : 0) . ', ' . $this->db->quote($payload) . ')')->execute();

        return $key;
    }

    public function forget(int $bookingId, string $key): void
    {
        $this->db->setQuery('DELETE FROM ' . $this->db->quoteName('#__appointments_privacy_cleanup')
            . ' WHERE request_id = ' . $this->requestId . ' AND booking_id = ' . $bookingId
            . ' AND cleanup_key = ' . $this->db->quote($key))->execute();
    }

    public function requestId(): int
    {
        return $this->requestId;
    }

    /**
     * Retry only copies linked by a prior authorized removal. No email search,
     * invented calendar grants, Scheduler activation or unchecked acknowledgment.
     */
    public function retry(string $address): void
    {
        $query = $this->db->createQuery()
            ->select('*')
            ->from($this->db->quoteName('#__appointments_privacy_cleanup'))
            ->where($this->db->quoteName('request_id') . ' = ' . $this->requestId)
            ->order($this->db->quoteName(['booking_id', 'cleanup_key']))
            ->setLimit(200);
        $rows = $this->db->setQuery($query)->loadAssocList();
        foreach ($rows as $row) {
            try {
                $id = (int) $row['booking_id'];
                $guest = (bool) $row['guest'];
                $snapshot = $this->open($row);
                $current = $this->db->setQuery('SELECT * FROM ' . $this->db->quoteName('#__appointments_bookings')
                    . ' WHERE id = ' . $id)->loadAssoc();
                if ($current && $current['anonymised_utc'] === null) {
                    // A primary booking which is still live belongs to the normal removal
                    // pass. An additional guest must never destroy its owner's conference.
                    if (!$guest || PrivacyAddress::isGuest(BookingData::fromApplication()->open($current)['guest_emails'], $address)) {
                        continue;
                    }
                }
                $bridge = TeamServices::fromApplication()->bookings();
                if (!$bridge->onBookingErased($id)) {
                    continue;
                }
                if (!$guest) {
                    $result = ConferenceService::forComponent($this->db)->destroy($snapshot);
                    if (!empty($result['_conference_cleanup_error']) || !empty($result['_conference_error'])) {
                        continue;
                    }
                } elseif ($current && $current['anonymised_utc'] === null) {
                    // Rebuild from the current row, not from erased guest information.
                    if (!$bridge->onBookingSaved(['id' => $id])) {
                        continue;
                    }
                }
                $this->forget($id, (string) $row['cleanup_key']);
            } catch (\Throwable $error) {
                // The queue remains actionable and blocks completion, including unreadable
                // or tampered provenance. Do not log its provider references or ciphertext.
                Log::add('Appointment privacy cleanup retry failed (' . get_class($error) . ').',
                    Log::WARNING, 'com_appointments');
            }
        }
    }

    public function pending(): int
    {
        return (int) $this->db->setQuery('SELECT COUNT(*) FROM ' . $this->db->quoteName('#__appointments_privacy_cleanup')
            . ' WHERE request_id = ' . $this->requestId)->loadResult();
    }

    private function open(array $row): array
    {
        $packed = base64_decode((string) $row['payload'], true);
        $size = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        if ($packed === false || strlen($packed) <= $size) {
            throw new \UnexpectedValueException('Invalid privacy cleanup provenance.');
        }
        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(substr($packed, $size),
            $this->aad((int) $row['booking_id'], (string) $row['cleanup_key'], (bool) $row['guest']),
            substr($packed, 0, $size), $this->keys->key('privacy-cleanup'));
        if ($plain === false) {
            throw new \UnexpectedValueException('Unreadable privacy cleanup provenance.');
        }
        $snapshot = json_decode($plain, true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($snapshot) || (int) ($snapshot['id'] ?? 0) !== (int) $row['booking_id']) {
            throw new \UnexpectedValueException('Mismatched privacy cleanup provenance.');
        }

        return $snapshot;
    }

    private function aad(int $bookingId, string $key, bool $guest): string
    {
        return 'appointments-privacy-cleanup:' . $this->requestId . ':' . $bookingId . ':' . $key . ':' . ($guest ? 1 : 0);
    }
}
