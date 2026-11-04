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
 * The events of the team calendar log (`#__appointments_teamlog.event`).
 *
 * Only sign-ins and changes are logged, never read access: a CalDAV request that
 * only reads leaves no row. `details` never holds calendar content, an app
 * password, a key, an e-mail address of an invitee or an IP address in clear.
 *
 * @since  1.4.0
 */
final class AuditEvent
{
    public const LOGIN_SUCCESS = 'login.success';
    public const LOGIN_FAILURE = 'login.failure';
    public const LOGIN_LOCKED = 'login.locked';
    public const LOGIN_UNLOCKED = 'login.unlocked';
    public const DAV_AUTH_FAILURE = 'dav.auth_failure';
    public const DAV_LOCKED = 'dav.locked';

    public const DEVICE_CREATED = 'device.created';
    public const DEVICE_RENAMED = 'device.renamed';
    public const DEVICE_CLEANUP_STARTED = 'device.cleanup_started';
    public const DEVICE_CLEANUP_ADVANCED = 'device.cleanup_advanced';
    public const DEVICE_REVOKED = 'device.revoked';

    public const CALENDAR_CREATED = 'calendar.created';
    public const CALENDAR_UPDATED = 'calendar.updated';
    public const CALENDAR_DELETED = 'calendar.deleted';

    public const GRANT_CREATED = 'grant.created';
    public const GRANT_CHANGED = 'grant.changed';
    public const GRANT_REVOKED = 'grant.revoked';
    public const GRANT_SUSPENDED = 'grant.suspended';
    public const GRANT_KEY_COMPLETED = 'grant.key_completed';

    public const INVITATION_CREATED = 'invitation.created';
    public const INVITATION_ACCEPTED = 'invitation.accepted';
    public const INVITATION_REVOKED = 'invitation.revoked';

    public const OBJECT_CREATED = 'object.created';
    public const OBJECT_UPDATED = 'object.updated';
    public const OBJECT_DELETED = 'object.deleted';
    public const BOOKING_WRITTEN = 'booking.written';
    public const BOOKING_REMOVED = 'booking.removed';
    public const OBJECT_RETENTION = 'object.retention';

    public const PRIVACY_SEARCH = 'privacy.search';
    public const PRIVACY_EXPORT = 'privacy.export';
    public const PRIVACY_DELETE = 'privacy.delete';

    public const USER_KEY_CREATED = 'key.user_created';
    public const USER_KEY_RESET = 'key.user_reset';
    public const CALENDAR_KEY_ROTATED = 'key.calendar_rotated';
    public const RECOVERY_KEY_CREATED = 'key.recovery_created';
    public const RECOVERY_KEY_ROTATED = 'key.recovery_rotated';
    public const RECOVERY_USED = 'key.recovery_used';
    public const ROW_MAC_FAILURE = 'key.mac_failure';

    public const USER_CLEANUP_STARTED = 'user.cleanup_started';
    public const LOG_PRUNED = 'log.pruned';

    /**
     * @return  string[]  Every event, for validation and the backend filter.
     */
    public static function all(): array
    {
        return array_values((new \ReflectionClass(self::class))->getConstants());
    }
}
