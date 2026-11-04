<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession;

defined('_JEXEC') or die;

/**
 * Blocking with clean-up (work package F). A device to be blocked first
 * receives its calendars empty (every object reported deleted, writes refused),
 * so the client removes its local copy; then the calendars are no longer listed,
 * so the client removes the calendars; then every request is refused with 401.
 * Each phase ends when the device has fetched it, or after the configured
 * number of days. A device that never connects again keeps whatever it had:
 * the clean-up cannot reach it, which is why the usage rule asks for a device
 * lock code.
 *
 * @since  1.4.0
 */
interface CleanupServiceInterface
{
    public const VIEW_NORMAL = 'normal';
    public const VIEW_EMPTY = 'empty';
    public const VIEW_HIDDEN = 'hidden';

    /**
     * Start the clean-up for every device of a user and suspend the user's grants.
     *
     * @return  int  Devices put into clean-up.
     */
    public function startForUser(int $userId, string $reason, ?int $actorUserId): int;

    public function startForDevice(int $deviceId, string $reason, ?int $actorUserId): void;

    /**
     * Which view of a calendar this device gets now. (An active device of a user
     * without an active second factor never gets here: the CalDAV handler refuses
     * every such request with 403 before any calendar is touched.)
     */
    public function viewFor(DeviceSession $session, int $calendarId): string;

    /**
     * The device fetched the empty view of a calendar (sync-collection or a
     * depth-1 PROPFIND of the collection after the clean-up started).
     */
    public function markDelivered(DeviceSession $session, int $calendarId): void;

    /**
     * The device listed its calendar home while hidden.
     */
    public function markHomeSeen(DeviceSession $session): void;

    /**
     * Advance every device whose phase is delivered or timed out (Scheduler task).
     *
     * @return  int  Devices advanced.
     */
    public function advance(\DateTimeImmutable $nowUtc): int;

    /**
     * The Joomla account was blocked (`block` set) or the calendar access of a
     * user was blocked in the administrator.
     */
    public function onUserBlocked(int $userId, ?int $actorUserId): void;

    /**
     * The Joomla account was deleted: devices, key pair, wraps and grants go;
     * owned calendars keep their data with owner 0 until a recovery reassigns
     * them; log rows stay for their retention.
     */
    public function onUserDeleted(int $userId): void;
}
