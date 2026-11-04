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
 * Activation of accounts that an administrator created (work package C).
 *
 * Nobody registers: every account is created by an administrator in Joomla's
 * user management, in the team group or the external group, and Joomla's own
 * registration stays off. The administrator then sends an activation link:
 * valid once, for 72 hours, bound to the account and to the e-mail address the
 * account had when the link was sent. Only a keyed hash of its 32-byte token
 * is stored. The link only lets the person set a password; the second factor
 * is then enforced by Joomla at the first sign-in. On completion every user
 * with `appointments.team.external` receives a mail with a link to revoke the
 * access in one step. There is no administrator confirmation.
 *
 * Grants are made separately (GrantServiceInterface) and may exist before the
 * activation; they become effective only with a second factor.
 *
 * @since  1.4.0
 */
interface InvitationServiceInterface
{
    /**
     * Send the activation link to the account's e-mail address. The account
     * must be in a team group or the external group, not blocked, and without
     * a completed activation; an open link of the same account is closed. For an
     * external account the actor needs `appointments.team.external`, for a team
     * account `core.admin` on com_appointments. Logs `invitation.created`
     * without the address.
     *
     * @return  int  Invitation id.
     */
    public function invite(int $actorUserId, int $userId): int;

    /**
     * What the activation page may show for a valid, open, unexpired token whose
     * account still has the bound address: user name and the address masked.
     * Null for anything else; the caller answers 404 without telling the cases
     * apart.
     *
     * @return  array{username: string, email_masked: string, expires_utc: string}|null
     */
    public function inspect(string $token): ?array;

    /**
     * Set the account's password (Joomla's password rules, at least the
     * com_users minimum length, otherwise 12) and close the link. Mails the
     * administrators with the revoke link. Logs `invitation.accepted`.
     *
     * @return  int  The user id.
     *
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  404 for an unusable token, 400 for a password the rules refuse.
     */
    public function complete(string $token, string $password): int;

    /**
     * Before completion: close the link. After completion (the one-step revoke
     * from the administrators' mail): remove every grant of the account and
     * block it, which starts the device clean-up. Logs `invitation.revoked`.
     */
    public function revoke(int $actorUserId, int $invitationId): void;

    /**
     * Close expired links.
     *
     * @return  int  Invitations closed.
     */
    public function purgeExpired(\DateTimeImmutable $nowUtc): int;
}
