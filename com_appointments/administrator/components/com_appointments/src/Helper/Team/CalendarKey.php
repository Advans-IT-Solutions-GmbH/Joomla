<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

defined('_JEXEC') or die;

/**
 * One version of a calendar key (32 raw bytes, XChaCha20-Poly1305), unlocked for
 * the length of one request.
 *
 * @since  1.4.0
 */
final class CalendarKey extends SecretValue
{
    /**
     * @var  int
     */
    private $calendarId;

    /**
     * @var  int
     */
    private $version;

    public static function create(int $calendarId, int $version, string $key): self
    {
        if ($calendarId <= 0 || $version <= 0 || \strlen($key) !== 32) {
            throw new \InvalidArgumentException('A calendar key needs a calendar, a version and 32 bytes.');
        }

        $calendarKey = new self($key);
        $calendarKey->calendarId = $calendarId;
        $calendarKey->version = $version;

        return $calendarKey;
    }

    public function calendarId(): int
    {
        return $this->calendarId;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function key(): string
    {
        return $this->bytes();
    }
}
