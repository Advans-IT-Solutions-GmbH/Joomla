<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\CalDAV\CalendarHome;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\NotFound;

defined('_JEXEC') or die;

/**
 * The calendar home: exactly the calendars the backend lists, as
 * {@see CalendarTreeCalendar}; no inbox, outbox, notifications or subscriptions.
 *
 * @since  1.4.0
 */
final class CalendarTreeHome extends CalendarHome
{
    public function getChild($name)
    {
        foreach ($this->getChildren() as $calendar) {
            if ($calendar->getName() === $name) {
                return $calendar;
            }
        }

        throw new NotFound('Not found.');
    }

    public function getChildren()
    {
        $children = [];

        foreach ($this->caldavBackend->getCalendarsForUser($this->principalInfo['uri']) as $calendar) {
            $children[] = new CalendarTreeCalendar($this->caldavBackend, $calendar);
        }

        return $children;
    }
}
