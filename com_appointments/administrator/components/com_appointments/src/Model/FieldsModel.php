<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Factory;
use Advans\Component\Appointments\Administrator\Helper\EventScope;

defined('_JEXEC') or die;

class FieldsModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= ['f.id', 'f.label', 'f.ordering'];
        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'f.ordering', $direction = 'ASC')
    {
        $this->setState('filter.eventtype_id', $this->getUserStateFromRequest($this->context . '.eventtype', 'filter_eventtype_id', 0, 'int'));
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        return parent::getStoreId($id . ':' . $this->getState('filter.eventtype_id'));
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['f.id', 'f.label', 'f.type', 'f.required', 'f.published', 'f.eventtype_id', 'f.ordering']))
            ->select($db->quoteName('e.title', 'eventtype_title'))
            ->from($db->quoteName('#__appointments_fields', 'f'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('f.eventtype_id'));
        $ids = array_map('intval', array_column(EventScope::events($db, Factory::getApplication()->getIdentity()), 'id'));
        $query->where($ids === [] ? '1 = 0' : $db->quoteName('f.eventtype_id') . ' IN (' . implode(',', $ids) . ')');
        $eventId = (int) $this->getState('filter.eventtype_id');

        if ($eventId > 0) {
            $query->where($db->quoteName('f.eventtype_id') . ' = ' . $eventId);
        }

        $order = (string) $this->getState('list.ordering', 'f.ordering');
        $order = in_array($order, $this->filter_fields, true) ? $order : 'f.ordering';
        $direction = strtoupper((string) $this->getState('list.direction')) === 'DESC' ? 'DESC' : 'ASC';

        return $query->order($db->quoteName($order) . ' ' . $direction);
    }
}
