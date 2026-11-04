<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;

defined('_JEXEC') or die;

/**
 * Invitations of external users (work package C). Only a user with the
 * component permission `appointments.team.external` invites.
 * The link is valid once, for 72 hours; only a keyed hash of its 32-byte token
 * is stored. It carries no key: the grant it leads to becomes active only after
 * an administrator confirms the accepted invitation, and its calendar key is
 * wrapped by the next key holder's request.
 *
 * @since  1.4.0
 */
interface InvitationServiceInterface
{
    /**
     * Create the invitation and mail the link to the address (the token exists
     * only in that mail). Logs `invitation.created` without the address.
     *
     * @return  int  Invitation id.
     */
    public function invite(int $actorUserId, string $email, int $calendarId, CalendarRole $role): int;

    /**
     * What the acceptance page may show for a valid, open, unexpired token:
     * calendar title, role and the address masked. Null for anything else; the
     * caller answers 404 without telling the cases apart.
     *
     * @return  array{calendar_title: string, role: CalendarRole, email_masked: string, expires_utc: string}|null
     */
    public function inspect(string $token): ?array;

    /**
     * Create the external Joomla account for the invited address, in the
     * external group only, and accept the invitation for it. The account must
     * then set up its second factor before it can do anything else.
     *
     * @return  int  The new user id.
     */
    public function acceptWithNewAccount(string $token, string $name, string $username, string $password): int;

    /**
     * Accept for an existing, signed-in account whose e-mail address equals the invited one.
     */
    public function acceptForUser(string $token, int $userId): void;

    public function confirm(int $actorUserId, int $invitationId): void;

    public function revoke(int $actorUserId, int $invitationId): void;

    /**
     * Close expired invitations and clear their addresses.
     *
     * @return  int  Invitations closed.
     */
    public function purgeExpired(\DateTimeImmutable $nowUtc): int;
}
