<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\CalDAV\CalendarRoot;

defined('_JEXEC') or die;

/**
 * `calendars/`: the calendar home of the request's own principal only (the
 * principal backend knows no other), built with {@see CalendarTreeHome}.
 *
 * @since  1.4.0
 */
final class CalendarTree extends CalendarRoot
{
    public function getChildForPrincipal(array $principal)
    {
        return new CalendarTreeHome($this->caldavBackend, $principal);
    }
}
