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
 * An authenticated CalDAV request of one device. A device in a clean-up state
 * is authenticated too, but carries no keys: it only ever receives the empty or
 * hidden view of its calendars.
 *
 * @since  1.4.0
 */
final class DeviceSession
{
    public function __construct(
        public readonly int $deviceId,
        public readonly int $userId,
        public readonly string $username,
        public readonly int $state,
        public readonly ?KeyContext $keys
    ) {
        if ($state === TeamStates::DEVICE_ACTIVE && $keys === null) {
            throw new \InvalidArgumentException('An active device session carries the user key.');
        }

        if ($state !== TeamStates::DEVICE_ACTIVE && $keys !== null) {
            throw new \InvalidArgumentException('A device in clean-up carries no key.');
        }
    }

    public function isActive(): bool
    {
        return $this->state === TeamStates::DEVICE_ACTIVE;
    }

    /**
     * The principal path segment of this user. It is the numeric id with a
     * prefix, never the user name, so a URL shows nothing about the account.
     */
    public function principal(): string
    {
        return 'u' . $this->userId;
    }
}
