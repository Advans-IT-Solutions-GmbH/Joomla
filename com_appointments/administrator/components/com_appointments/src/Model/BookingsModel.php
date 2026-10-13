<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;

defined('_JEXEC') or die;

class BookingsModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= ['b.id', 'b.start_utc', 'b.state', 'e.title'];
        parent::__construct($config, $factory);
    }

    public function authorisedEvents(): array
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'title']))->from($db->quoteName('#__appointments_eventtypes'))->order($db->quoteName('title'));
        $user = Factory::getApplication()->getIdentity();

        return array_values(array_filter($db->setQuery($query)->loadAssocList(), static fn ($row) =>
            $user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $row['id'])));
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['b.id', 'b.eventtype_id', 'b.start_utc', 'b.end_utc', 'b.state',
            'b.invitee_name', 'b.invitee_email', 'b.sequence', 'b.conference_state', 'b.conference_error', 'b.anonymised_utc']))
            ->select($db->quoteName('e.title', 'eventtype_title'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'));
        $ids = array_map(static fn ($row) => (int) $row['id'], $this->authorisedEvents());

        if ($ids === [] || !Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_appointments')) {
            $query->where('1 = 0');
        } else {
            $query->where($db->quoteName('b.eventtype_id') . ' IN (' . implode(',', $ids) . ')');
        }

        $order = (string) $this->getState('list.ordering', 'b.start_utc');
        $order = in_array($order, $this->filter_fields, true) ? $order : 'b.start_utc';
        $direction = strtoupper((string) $this->getState('list.direction')) === 'ASC' ? 'ASC' : 'DESC';

        return $query->order($db->quoteName($order) . ' ' . $direction);
    }

    protected function populateState($ordering = 'b.start_utc', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
    }
}
