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
 * The role a user holds on one calendar. The integer is what
 * `#__appointments_calendar_grants.role` stores; the order is the order of
 * privilege, so a higher role includes every lower one.
 *
 * Specification: `.claude/skills/appointments/references/team-calendars.md`,
 * section "Roles and grants".
 *
 * @since  1.4.0
 */
enum CalendarRole: int
{
    /** Sees busy blocks without title, place or attendees. Holds no calendar key. */
    case FreeBusy = 1;

    /** Reads every object of the calendar. */
    case Read = 2;

    /** Reads, creates, changes and deletes objects. */
    case Write = 3;

    /** Write, plus granting the calendar to team members and editing its settings. */
    case Manage = 4;

    /**
     * Whether this role reads contents and therefore holds a wrap of the
     * calendar key. Free/busy never does: the server builds its busy blocks
     * from the time skeleton alone.
     */
    public function holdsKey(): bool
    {
        return $this !== self::FreeBusy;
    }

    public function canWrite(): bool
    {
        return $this->value >= self::Write->value;
    }

    public function canManage(): bool
    {
        return $this === self::Manage;
    }

    public function includes(self $other): bool
    {
        return $this->value >= $other->value;
    }

    /**
     * The highest role an external user may hold. Externals never manage.
     */
    public static function maximumForExternal(): self
    {
        return self::Write;
    }
}
