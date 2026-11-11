<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Helper\Team;

defined('_JEXEC') or die;

/**
 * The stored state values of the team calendar tables, in one place so that no
 * package invents its own number for the same state.
 *
 * @since  1.4.0
 */
final class TeamStates
{
    /** `#__appointments_calendar_grants.state`: effective. */
    public const GRANT_ACTIVE = 1;

    /** Decided, but the calendar key is not yet wrapped for the user (no key pair yet, or no key holder online yet). */
    public const GRANT_PENDING_KEY = 2;

    /** Suspended by a password reset or a blocked account; only a manager or the recovery key reactivates it. */
    public const GRANT_SUSPENDED = 3;

    /** `#__appointments_devices.state`: normal access. */
    public const DEVICE_ACTIVE = 1;

    /** Clean-up, phase 1: every calendar is served empty, so the client deletes its local copy. Writes are refused. */
    public const DEVICE_CLEANUP_EMPTY = 2;

    /** Clean-up, phase 2: the calendars are no longer listed, so the client removes them. */
    public const DEVICE_CLEANUP_HIDDEN = 3;

    /** Revoked: every request is refused with 401. */
    public const DEVICE_REVOKED = 0;

    /**
     * `#__appointments_devices.removal_mode`, «When removed or blocked»: the device goes through the
     * clean-up (its calendars empty, then hidden, then 401), so the client deletes its local copy.
     * For phones and tablets. The value of every device created before the setting existed.
     */
    public const REMOVAL_CLEANUP = 'cleanup';

    /**
     * «Block only»: the device is revoked at once (401 for every request) and never receives an
     * empty calendar, so a client that synchronises two ways into a calendar of its own (Outlook with
     * CalDav Synchronizer) reports an error and deletes nothing.
     */
    public const REMOVAL_BLOCK = 'block';

    /** `#__appointments_invitations.state` (account activation links). */
    public const INVITATION_OPEN = 1;
    public const INVITATION_COMPLETED = 2;
    public const INVITATION_CLOSED = 0;

    /** `#__appointments_calendar_objects.enc_scheme`: XChaCha20-Poly1305 under the calendar key. */
    public const SCHEME_CALENDAR_KEY = 1;

    /** Sealed box to the calendar's public key (booking flow, no user key). */
    public const SCHEME_SEALED = 2;

    /** `#__appointments_calendar_changes.operation`. */
    public const CHANGE_ADDED = 1;
    public const CHANGE_MODIFIED = 2;
    public const CHANGE_DELETED = 3;

    /** `#__appointments_calendar_grants.audience`. */
    public const AUDIENCE_TEAM = 'team';
    public const AUDIENCE_EXTERNAL = 'external';

    /** Version of the device usage rule the user confirms; raise it when the rule text changes. */
    public const DEVICE_RULE_VERSION = '1';

    /** Lifetime of an account activation link, in hours. */
    public const INVITATION_HOURS = 72;

    /** Retention of the team calendar log, in months. */
    public const LOG_RETENTION_MONTHS = 12;

    /** Failed sign-ins before a temporary lock. */
    public const LOCK_THRESHOLD = 10;
}
