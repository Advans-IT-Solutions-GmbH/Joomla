<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

defined('_JEXEC') or die;

class BookingModel extends BaseDatabaseModel
{
    public function getItem(?int $id = null): ?object
    {
        $id ??= Factory::getApplication()->getInput()->getInt('id', 0);
        $user = Factory::getApplication()->getIdentity();
        if (!$user->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        if ($id === 0) {
            return null;
        }
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $event = (int) $db->setQuery($query->select($db->quoteName('eventtype_id'))->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('id') . ' = ' . $id))->loadResult();
        if (!$user->authorise('core.manage', 'com_appointments') || !$event
            || !$user->authorise('core.edit', 'com_appointments.eventtype.' . $event)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $item = $db->setQuery($query->select($db->quoteName('b') . '.*')->select($db->quoteName('e.title', 'eventtype_title'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON '
                . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $id)->where($db->quoteName('b.eventtype_id') . ' = ' . $event))->loadObject();
        if (!$item) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }
        return $item;
    }
}
