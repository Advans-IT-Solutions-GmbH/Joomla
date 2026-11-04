<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarAccess;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;

defined('_JEXEC') or die;

/**
 * Work package C replaces the body of this class; the contract is
 * {@see GrantServiceInterface}. Until then every method refuses with 501, so nothing
 * half-built is reachable.
 *
 * @since  1.4.0
 */
final class GrantService implements GrantServiceInterface
{
    public function __construct(private readonly TeamServices $services)
    {
    }

    public function accessFor(int $userId, int $calendarId): ?CalendarAccess
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function calendarsFor(int $userId): array
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function grantsOf(int $calendarId): array
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function audienceOf(int $userId): string
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function hasSecondFactor(int $userId): bool
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function canGrant(int $actorUserId, int $calendarId, int $granteeUserId, CalendarRole $role): bool
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function grant(int $actorUserId, int $calendarId, int $granteeUserId, CalendarRole $role, ?KeyContext $actorKeys): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function revoke(int $actorUserId, int $calendarId, int $granteeUserId, ?KeyContext $actorKeys): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function suspendAllForUser(int $userId, string $reason): int
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function confirmExternal(int $actorUserId, int $calendarId, int $granteeUserId): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }
}
