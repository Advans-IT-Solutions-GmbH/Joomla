<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class SignedLink
{
    public function __construct(private KeyProvider $keys)
    {
    }

    /** Sign the current row version, not an expiry time or a recipient address. */
    public function sign(int $bookingId, string $action, string $modifiedUtc): string
    {
        if ($bookingId < 1 || !in_array($action, ['reschedule', 'cancel', 'erase'], true)) {
            throw new \InvalidArgumentException('Invalid signed booking action.');
        }

        $modified = UtcTimestamp::parse($modifiedUtc);

        if ($modified === null) {
            throw new \InvalidArgumentException('Invalid booking modification timestamp.');
        }

        $message = $bookingId . "\n" . $action . "\n" . $modified->format('Y-m-d H:i:s');

        return hash_hmac('sha256', $message, $this->keys->key(KeyProvider::PURPOSE_SIGNED_LINK));
    }

    public function verify(int $bookingId, string $action, string $modifiedUtc, string $signature): bool
    {
        try {
            $expected = $this->sign($bookingId, $action, $modifiedUtc);
        } catch (\InvalidArgumentException $exception) {
            return false;
        }

        return hash_equals($expected, $signature);
    }

    /**
     * The link of one additional guest: bound to the booking, the guest's address (compared
     * case-insensitively) and the current row version, with a key of its own. The address is
     * not part of the URL; guestFor() finds it among the booking's guests.
     */
    public function signGuest(int $bookingId, string $guest, string $modifiedUtc): string
    {
        $address = trim($guest);

        if ($bookingId < 1 || $address === '' || preg_match('//u', $address) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $address)) {
            throw new \InvalidArgumentException('Invalid signed guest link.');
        }

        $modified = UtcTimestamp::parse($modifiedUtc);

        if ($modified === null) {
            throw new \InvalidArgumentException('Invalid booking modification timestamp.');
        }

        $message = $bookingId . "\nremove_guest\n" . mb_strtolower($address, 'UTF-8') . "\n" . $modified->format('Y-m-d H:i:s');

        return hash_hmac('sha256', $message, $this->keys->key(KeyProvider::PURPOSE_GUEST_LINK));
    }

    public function verifyGuest(int $bookingId, string $guest, string $modifiedUtc, string $signature): bool
    {
        try {
            $expected = $this->signGuest($bookingId, $guest, $modifiedUtc);
        } catch (\InvalidArgumentException $exception) {
            return false;
        }

        return hash_equals($expected, $signature);
    }

    /**
     * The guest of $guests (as stored) whose link $signature is, or null. Every guest is
     * checked, so the time taken does not tell which position matched.
     *
     * @param list<string> $guests
     */
    public function guestFor(int $bookingId, array $guests, string $modifiedUtc, string $signature): ?string
    {
        $match = null;

        foreach ($guests as $guest) {
            if ($this->verifyGuest($bookingId, (string) $guest, $modifiedUtc, $signature) && $match === null) {
                $match = (string) $guest;
            }
        }

        return $match;
    }
}
