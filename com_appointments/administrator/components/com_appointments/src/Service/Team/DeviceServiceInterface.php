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
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;

defined('_JEXEC') or die;

/**
 * Devices and their app passwords (work package D). One app password per
 * device, shown once, stored only as a keyed hash, without expiry. The Joomla
 * password never authenticates a CalDAV request.
 *
 * @since  1.4.0
 */
interface DeviceServiceInterface
{
    /**
     * Create a device for the signed-in user. Requires: an unlocked key context
     * of the same user (so the session passed password and second factor), an
     * active second factor on the account right now, the confirmed usage rule,
     * and at least one effective grant. Logs
     * `device.created` with the rule version, mails the user.
     *
     * @param   string  $client  One of `outlook-caldavsynchronizer`, `apple`, `davx5`, `thunderbird`, `emclient`, `other`.
     *
     * @return  array{device_id: int, app_password: string}  The password in display form, to show once.
     *
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  403 without the rule or without a grant.
     */
    public function create(KeyContext $keys, string $name, string $client, bool $ruleAccepted): array;

    /**
     * Authenticate a CalDAV request. Returns null for every failure without
     * telling the cases apart (unknown user, wrong or malformed password, other
     * user's device, revoked device, a found hash with a broken row MAC, a device
     * of a blocked account). It throws only on an infrastructure failure (the
     * database is unreachable), never depending on data, so an exception is no
     * oracle. The lock-out is the caller's job, before and after. A device in
     * clean-up is authenticated without keys.
     */
    public function authenticate(string $username, #[\SensitiveParameter] string $appPassword): ?DeviceSession;

    /**
     * Record a synchronisation: `last_sync_utc` of the device and, when given,
     * of the device and calendar pair. The pair row is written at the first
     * synchronisation of a calendar always. Its role is the role of the latest
     * synchronisation and is updated at once, without throttling, whenever it
     * changes; only the time is updated at most once per device and minute.
     * A failure to write the pair row (role, sync token) throws, and the caller
     * then does not deliver the response; only the throttled times are best effort. `$syncToken` is the
     * highest sync token delivered to the device for that calendar; it only rises
     * and is always written (not throttled) while the device is active.
     */
    public function touch(DeviceSession $session, ?int $calendarId, ?int $role = null, ?int $syncToken = null): void;

    /**
     * @return  object[]  The user's devices: id, name, client, state, created_utc, last_sync_utc. Never a hash or salt.
     */
    public function listForUser(int $userId): array;

    /**
     * @param   array{user_id?: int, state?: int, search?: string}  $filters
     *
     * @return  object[]  For the administrator list, same columns plus user_id.
     */
    public function listAll(array $filters): array;

    public function rename(int $actorUserId, int $deviceId, string $name): void;

    /**
     * Compare-and-set of a device state; recomputes `row_mac`. Leaving
     * DEVICE_ACTIVE clears `usk_wrapped`: a device in clean-up never gets keys
     * again. Used by the clean-up (work package F).
     *
     * @return  bool  False when the device is not in `$fromState`.
     */
    public function changeState(int $deviceId, int $fromState, int $toState): bool;

    /**
     * Final revocation (state 0, 401 from then on). The user's own «remove
     * device» and an administrator's «block device» start the clean-up instead
     * (work package F); this is its last step or an emergency stop.
     */
    public function revoke(?int $actorUserId, int $deviceId, string $reason): void;
}
