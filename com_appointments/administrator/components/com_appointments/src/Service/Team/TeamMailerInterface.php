<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

defined('_JEXEC') or die;

/**
 * Mails of the team calendars (work package D), in the recipient's language,
 * through Joomla's mailer. No mail ever contains an app password, a key, a
 * recovery secret or calendar content. The only mail with a secret link is the
 * invitation.
 *
 * @since  1.4.0
 */
interface TeamMailerInterface
{
    /**
     * To the user, on every new device: device name, client, time, and what to
     * do if it was not them.
     */
    public function deviceAdded(int $userId, string $deviceName, string $client, \DateTimeImmutable $createdUtc): void;

    /**
     * To the account's address: the one-time activation link and its expiry.
     */
    public function invitation(string $email, string $link, \DateTimeImmutable $expiresUtc): void;

    /**
     * To every user with `appointments.team.external`: an account completed its
     * activation, with the link to revoke its access in one step.
     */
    public function activationCompleted(int $invitationId, int $userId, string $revokeUrl): void;

    /**
     * To the calendar's owner and managers: a grant was created or changed.
     */
    public function grantChanged(int $calendarId, int $granteeUserId, string $roleLabelKey, int $actorUserId): void;

    /**
     * To the user: access suspended after a password reset or a block; devices in clean-up.
     */
    public function accessSuspended(int $userId, string $reason): void;

    /**
     * To every user with core.admin on com_appointments: an account with a
     * calendar right is temporarily locked after failed sign-ins (so a lock by a
     * third party is visible); at most once per account and 15 minutes.
     */
    public function accountLocked(int $userId, int $retryAfterSeconds): void;

    /**
     * To the former owner and managers of a deleted calendar: title, who deleted
     * it, when. The calendar row no longer exists; the caller passes what the
     * mail needs. No content of the calendar.
     *
     * @param   int[]  $recipientUserIds  Owner and managers at the time of the deletion.
     */
    public function calendarDeleted(string $calendarTitle, array $recipientUserIds, int $actorUserId, \DateTimeImmutable $deletedUtc): void;
}
