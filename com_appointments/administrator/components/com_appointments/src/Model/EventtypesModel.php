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
use Joomla\Database\ParameterType;
use Advans\Component\Appointments\Administrator\Helper\EventScope;

defined('_JEXEC') or die;

class EventtypesModel extends ListModel
{
    use ListFiltersTrait;

    /** Columns the list may be sorted by. */
    private const SORTABLE = ['e.id', 'e.title', 'e.ordering', 'e.published', 'e.duration_minutes', 's.title'];

    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= self::SORTABLE;
        parent::__construct($config, $factory);
    }

    protected function filterNames(): array
    {
        return ['search', 'published', 'schedule_id'];
    }

    protected function populateState($ordering = 'e.ordering', $direction = 'ASC')
    {
        $this->adoptFlatFilters();
        parent::populateState($ordering, $direction);
        $this->setState('filter.search', $this->textFilter('search'));
        $this->setState('filter.published', $this->optionFilter('published', ['0', '1']));
        $this->setState('filter.schedule_id', $this->idFilter('schedule_id'));
    }

    protected function getStoreId($id = '')
    {
        foreach ($this->filterNames() as $name) {
            $id .= ':' . $this->getState('filter.' . $name);
        }

        return parent::getStoreId($id);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['e.id', 'e.title', 'e.alias', 'e.published', 'e.duration_minutes', 'e.ordering']))
            ->select($db->quoteName('s.title', 'schedule_title'))
            ->from($db->quoteName('#__appointments_eventtypes', 'e'))
            ->join('LEFT', $db->quoteName('#__appointments_schedules', 's'), $db->quoteName('s.id') . ' = ' . $db->quoteName('e.schedule_id'));
        // The permission scope first: a filter only narrows it, never widens it.
        $ids = array_map('intval', array_column(EventScope::events($db, Factory::getApplication()->getIdentity()), 'id'));
        $query->where($ids === [] ? '1 = 0' : $db->quoteName('e.id') . ' IN (' . implode(',', $ids) . ')');
        $search = (string) $this->getState('filter.search');

        if ($search !== '') {
            if (preg_match('/^id:(\d{1,10})$/D', $search, $match) === 1) {
                $id = (int) $match[1];
                $query->where($db->quoteName('e.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $query->where($db->quoteName('e.title') . ' LIKE :search')->bind(':search', $like);
            }
        }

        $published = (string) $this->getState('filter.published');

        if ($published !== '') {
            $publishedFlag = (int) $published;
            $query->where($db->quoteName('e.published') . ' = :published')->bind(':published', $publishedFlag, ParameterType::INTEGER);
        }

        $schedule = (int) $this->getState('filter.schedule_id');

        if ($schedule > 0) {
            $query->where($db->quoteName('e.schedule_id') . ' = :schedule')->bind(':schedule', $schedule, ParameterType::INTEGER);
        }

        [$order, $direction] = $this->listOrdering(self::SORTABLE, 'e.ordering', 'ASC');

        return $query->order($db->quoteName($order) . ' ' . $direction)->order($db->quoteName('e.id') . ' ' . $direction);
    }
}
