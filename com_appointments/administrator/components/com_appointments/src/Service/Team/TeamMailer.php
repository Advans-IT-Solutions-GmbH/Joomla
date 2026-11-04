<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;

defined('_JEXEC') or die;

/**
 * Work package D replaces the body of this class; the contract is
 * {@see TeamMailerInterface}. Until then every method refuses with 501, so nothing
 * half-built is reachable.
 *
 * @since  1.4.0
 */
final class TeamMailer implements TeamMailerInterface
{
    public function __construct(private readonly TeamServices $services)
    {
    }

    public function deviceAdded(int $userId, string $deviceName, string $client, \DateTimeImmutable $createdUtc): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function invitation(string $email, string $link, \DateTimeImmutable $expiresUtc): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function activationCompleted(int $invitationId, int $userId, string $revokeUrl): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function grantChanged(int $calendarId, int $granteeUserId, string $roleLabelKey, int $actorUserId): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function accessSuspended(int $userId, string $reason): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function calendarDeleted(string $calendarTitle, array $recipientUserIds, int $actorUserId, \DateTimeImmutable $deletedUtc): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }
}
