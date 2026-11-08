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
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKeyring;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;

defined('_JEXEC') or die;

/**
 * Who may do what with which calendar (work package C). Default deny: without
 * a verified, effective grant row there is no access, for nobody, including
 * Super Users.
 *
 * An access is effective when all of these hold, checked on every call:
 * the grant row exists and its MAC verifies; its state is active; the calendar
 * is active; the Joomla account is not blocked and is in a team group or the
 * external group; the account has a second factor configured; and, for a
 * key-holding role, a wrap of the current key version exists.
 *
 * Who may grant: a manager of the calendar to team members (up to manage);
 * only a user with the component permission `appointments.team.external` to
 * external users (up to write), and only on a calendar that allows externals.
 * Team members are the members of the option `team_groups`, external users the
 * members of `external_group`; a member of both counts as external.
 *
 * @since  1.4.0
 */
interface GrantServiceInterface
{
    public function accessFor(int $userId, int $calendarId): ?CalendarAccess;

    /**
     * @return  CalendarAccess[]  Every effective access of the user, for the calendar home and «My calendars».
     */
    public function calendarsFor(int $userId): array;

    /**
     * Every grant of a calendar with its state, for the management views.
     *
     * @return  object[]  Rows with user_id, role, state, audience, created_utc; no key material.
     */
    public function grantsOf(int $calendarId): array;

    /**
     * @return  string  `team`, `external` or `none` (none may hold no grant).
     */
    public function audienceOf(int $userId): string;

    /**
     * Whether the account has at least one second-factor method other than backup codes.
     */
    public function hasSecondFactor(int $userId): bool;

    public function canGrant(int $actorUserId, int $calendarId, int $granteeUserId, CalendarRole $role): bool;

    /**
     * Create or change a grant. With the actor's key context the calendar key is
     * wrapped at once; without (or when the grantee has no key pair yet) the
     * grant is pending until a key holder's request completes it. Mails the
     * calendar's owner and managers, logs `grant.created` or `grant.changed`.
     * MFA-checked component administrators may grant without their own calendar
     * role, including to themselves. With $makeOwner (team Manage only), owner
     * metadata changes atomically; the old owner's grant remains untouched.
     *
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  403 when not allowed.
     */
    public function grant(int $actorUserId, int $calendarId, int $granteeUserId, CalendarRole $role, ?KeyContext $actorKeys, bool $makeOwner = false): void;

    /**
     * Remove a grant and every wrap of the user for this calendar. When the user
     * held a key and the actor's key context is given, the calendar key is
     * rotated; otherwise the rotation is marked due and done by the next key
     * holder's request.
     */
    public function revoke(int $actorUserId, int $calendarId, int $granteeUserId, ?KeyContext $actorKeys): void;

    /**
     * Suspend every grant of a user (password reset, blocked account). Only a
     * manager (or the recovery key) reactivates them, by granting again.
     *
     * @return  int  Grants suspended.
     */
    public function suspendAllForUser(int $userId, string $reason): int;

    /**
     * Grant with the recovery key (an administrator with core.admin on the
     * component, holding the offline secret): the keyring comes from
     * KeyServiceInterface::recoverKeyring(). Bypasses the manager rule, not the
     * audience rules (team up to manage, external only with allow_external, up
     * to write, actor with appointments.team.external). Wraps the current
     * version for the grantee at once. With $makeOwner and role manage, and only
     * when the calendar has no owner (owner_user_id 0) or the owner holds no
     * effective grant, the grantee becomes the owner. Logs key.recovery_used and
     * grant.created or grant.changed, mails the owner and managers.
     *
     * @throws  \Advans\Component\Appointments\Administrator\Helper\Team\TeamException  403 when not allowed, 404 for an unknown calendar or grantee.
     */
    public function grantWithRecovery(int $actorUserId, CalendarKeyring $keyring, int $granteeUserId, CalendarRole $role, bool $makeOwner = false): void;

    /**
     * Per-user display properties of one calendar, for CalDAV PROPPATCH. Only an
     * effective access may set them. Keys: `colour` (#RRGGBB or #RRGGBBAA) and
     * `order` (int 0..9999); null removes. Display only: not part of row_mac,
     * not logged.
     *
     * @param   array{colour?: string|null, order?: int|null}  $props
     */
    public function setClientProps(int $userId, int $calendarId, array $props): void;
}
