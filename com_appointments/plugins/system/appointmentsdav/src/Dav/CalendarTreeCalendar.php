<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\CalDAV\Calendar;

defined('_JEXEC') or die;

/**
 * One calendar. Like sabre's, but the owner may always write properties, also
 * on a read-only calendar: PROPPATCH of the own colour and order is allowed for
 * every role, and {@see CalendarBackend::updateCalendar()} refuses everything
 * else. Objects keep the read-only ACL of the role.
 *
 * @since  1.4.0
 */
final class CalendarTreeCalendar extends Calendar
{
    public function getACL()
    {
        $acl = parent::getACL();

        if (!empty($this->calendarInfo['{http://sabredav.org/ns}read-only'])) {
            $acl[] = [
                'privilege' => '{DAV:}write-properties',
                'principal' => $this->getOwner(),
                'protected' => true,
            ];
        }

        return $acl;
    }
}
