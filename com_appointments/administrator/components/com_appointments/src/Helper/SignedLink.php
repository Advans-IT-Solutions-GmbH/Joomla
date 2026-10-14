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
}
