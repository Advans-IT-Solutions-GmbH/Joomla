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
 * One user's verified grant on one calendar, as the grant service returns it
 * after checking the row MAC, the grant state, the account and its second
 * factor. Any value object of this class means: this access is effective now.
 *
 * @since  1.4.0
 */
final class CalendarAccess
{
    public function __construct(
        public readonly int $calendarId,
        public readonly string $calendarUri,
        public readonly int $userId,
        public readonly CalendarRole $role,
        public readonly string $audience,
        public readonly string $title,
        public readonly string $colour,
        public readonly int $synctoken
    ) {
    }

    /**
     * Whether a request may decrypt contents. A role that holds a key without
     * an existing wrap is returned by the grant service as not effective, so
     * this only depends on the role.
     */
    public function readsContent(): bool
    {
        return $this->role->holdsKey();
    }
}
