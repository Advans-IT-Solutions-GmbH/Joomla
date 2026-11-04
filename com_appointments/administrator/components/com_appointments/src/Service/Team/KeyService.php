<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKey;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;

defined('_JEXEC') or die;

/**
 * Work package B replaces the body of this class; the contract is
 * {@see KeyServiceInterface}. Until then every method refuses with 501, so nothing
 * half-built is reachable.
 *
 * @since  1.4.0
 */
final class KeyService implements KeyServiceInterface
{
    public function __construct(private readonly TeamServices $services)
    {
    }

    public function createRecoveryKey(int $actorUserId): string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function rotateRecoveryKey(string $oldRecoverySecret, int $actorUserId): string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function recoveryFingerprint(): ?string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function hasUserKey(int $userId): bool
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function userPublicKey(int $userId): ?string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function createUserKey(int $userId, string $password): KeyContext
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function unlockWithPassword(int $userId, string $password): ?KeyContext
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function rewrapForPassword(KeyContext $keys, string $newPassword): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function resetUserKey(int $userId): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function wrapForDevice(KeyContext $keys, string $appPasswordRaw, string $kekSalt): string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function unlockWithDevice(int $userId, string $appPasswordRaw, string $kekSalt, string $uskWrapped): ?KeyContext
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function createCalendarKeys(int $calendarId, int $ownerUserId): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function openKeyring(int $calendarId, KeyContext $keys): ?CalendarKeyring
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function wrapForUser(CalendarKey $key, int $userId): bool
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function completePendingWraps(CalendarKeyring $keyring): int
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function deleteUserWraps(int $calendarId, int $userId): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function rotateCalendarKey(CalendarKeyring $keyring): CalendarKeyring
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function recoverKeyring(int $calendarId, string $recoverySecret): ?CalendarKeyring
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function encryptObject(CalendarKey $key, string $uri, string $ics): string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function sealObject(int $calendarId, string $uri, string $ics): array
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function decryptObject(CalendarKeyring $keyring, StoredObject $object): ?string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function sealTiming(int $calendarId, string $uri, string $skeleton): string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function openTiming(int $calendarId, string $uri, string $timing): ?string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function uidHash(int $calendarId, string $uid): string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function rowMac(string $table, array $fields): string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function verifyRowMac(string $table, array $fields, string $mac): bool
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }
}
