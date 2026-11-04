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
     * To the invited address: calendar title, role, expiry and the one-time link.
     */
    public function invitation(string $email, string $link, string $calendarTitle, string $roleLabelKey, \DateTimeImmutable $expiresUtc): void;

    /**
     * To every user with `appointments.team.external`: an invitation was accepted and waits for confirmation.
     */
    public function invitationAccepted(int $invitationId, string $calendarTitle): void;

    /**
     * To the calendar's owner and managers: a grant was created or changed.
     */
    public function grantChanged(int $calendarId, int $granteeUserId, string $roleLabelKey, int $actorUserId): void;

    /**
     * To the user: access suspended after a password reset or a block; devices in clean-up.
     */
    public function accessSuspended(int $userId, string $reason): void;
}
