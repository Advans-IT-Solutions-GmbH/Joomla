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

class EventtypesModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= ['e.id', 'e.title', 'e.ordering', 'e.published'];
        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'e.ordering', $direction = 'ASC')
    {
        $this->setState('filter.search', $this->getUserStateFromRequest($this->context . '.search', 'filter_search', '', 'string'));
        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        return parent::getStoreId($id . ':' . $this->getState('filter.search'));
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['e.id', 'e.title', 'e.alias', 'e.published', 'e.duration_minutes', 'e.ordering']))
            ->select($db->quoteName('s.title', 'schedule_title'))
            ->from($db->quoteName('#__appointments_eventtypes', 'e'))
            ->join('LEFT', $db->quoteName('#__appointments_schedules', 's'), $db->quoteName('s.id') . ' = ' . $db->quoteName('e.schedule_id'));
        $ids = array_map('intval', array_column(EventScope::events($db, Factory::getApplication()->getIdentity()), 'id'));
        $query->where($ids === [] ? '1 = 0' : $db->quoteName('e.id') . ' IN (' . implode(',', $ids) . ')');
        $search = (string) $this->getState('filter.search');

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where($db->quoteName('e.title') . ' LIKE :search')->bind(':search', $like);
        }

        $order = (string) $this->getState('list.ordering', 'e.ordering');
        $order = in_array($order, $this->filter_fields, true) ? $order : 'e.ordering';
        $direction = strtoupper((string) $this->getState('list.direction')) === 'DESC' ? 'DESC' : 'ASC';

        return $query->order($db->quoteName($order) . ' ' . $direction);
    }
}
