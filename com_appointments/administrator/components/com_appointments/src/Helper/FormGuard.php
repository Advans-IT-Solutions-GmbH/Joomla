<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper;

defined('_JEXEC') or die;

final class FormGuard
{
    public function __construct(private KeyProvider $keys)
    {
    }

    public function sign(int $eventId, int $renderedAt, string $sessionToken): string
    {
        return hash_hmac('sha256', $eventId . ':' . $renderedAt . ':' . $sessionToken, $this->keys->key('booking-form'));
    }

    public function verify(int $eventId, int $renderedAt, string $sessionToken, string $signature, string $honeypot, bool $consent, \DateTimeImmutable $now): bool
    {
        $age = $now->getTimestamp() - $renderedAt;

        return hash_equals($this->sign($eventId, $renderedAt, $sessionToken), $signature)
            && $age >= 2 && $age <= 3600 && $honeypot === '' && $consent;
    }
}
