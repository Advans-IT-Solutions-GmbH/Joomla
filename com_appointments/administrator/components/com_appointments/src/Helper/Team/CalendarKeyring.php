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
 * Every unlocked key version of one calendar for one request. Objects written
 * before a rotation stay readable until they are re-encrypted; new objects are
 * always written with the current version.
 *
 * @since  1.4.0
 */
final class CalendarKeyring
{
    /**
     * @var  int
     */
    private $calendarId;

    /**
     * @var  int
     */
    private $currentVersion;

    /**
     * @var  array<int, CalendarKey>
     */
    private $keys = [];

    /**
     * @param   CalendarKey[]  $keys  At least the current version.
     */
    public function __construct(int $calendarId, int $currentVersion, array $keys)
    {
        foreach ($keys as $key) {
            if (!$key instanceof CalendarKey || $key->calendarId() !== $calendarId) {
                throw new \InvalidArgumentException('Every key of a keyring belongs to its calendar.');
            }

            $this->keys[$key->version()] = $key;
        }

        if (!isset($this->keys[$currentVersion])) {
            throw new \InvalidArgumentException('A keyring holds its current version.');
        }

        $this->calendarId = $calendarId;
        $this->currentVersion = $currentVersion;
    }

    public function calendarId(): int
    {
        return $this->calendarId;
    }

    public function current(): CalendarKey
    {
        return $this->keys[$this->currentVersion];
    }

    public function version(int $version): ?CalendarKey
    {
        return $this->keys[$version] ?? null;
    }

    public function wipe(): void
    {
        foreach ($this->keys as $key) {
            $key->wipe();
        }

        $this->keys = [];
    }

    public function __serialize(): array
    {
        throw new \LogicException('Key material cannot be serialised.');
    }

    public function __unserialize(array $data): void
    {
        throw new \LogicException('Key material cannot be unserialised.');
    }
}
