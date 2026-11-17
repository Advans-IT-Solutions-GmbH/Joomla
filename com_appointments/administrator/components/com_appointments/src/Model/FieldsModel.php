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

class FieldsModel extends ListModel
{
    use ListFiltersTrait;

    /** Columns the list may be sorted by. */
    private const SORTABLE = ['f.id', 'f.label', 'f.type', 'f.required', 'f.published', 'f.ordering', 'e.title'];

    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= self::SORTABLE;
        parent::__construct($config, $factory);
    }

    protected function filterNames(): array
    {
        return ['search', 'eventtype_id', 'type', 'required', 'published'];
    }

    protected function populateState($ordering = 'f.ordering', $direction = 'ASC')
    {
        $this->adoptFlatFilters();
        parent::populateState($ordering, $direction);
        $this->setState('filter.search', $this->textFilter('search'));
        $this->setState('filter.eventtype_id', $this->idFilter('eventtype_id'));
        $type = $this->rawFilter('type');
        $this->setState('filter.type', $type !== null && preg_match('/^[a-z]{1,20}$/D', $type) === 1 ? $type : '');
        $this->setState('filter.required', $this->optionFilter('required', ['0', '1']));
        $this->setState('filter.published', $this->optionFilter('published', ['0', '1']));
    }

    protected function getStoreId($id = '')
    {
        foreach ($this->filterNames() as $name) {
            $id .= ':' . $this->getState('filter.' . $name);
        }

        return parent::getStoreId($id);
    }

    /**
     * Whether the list is sorted by the field order, the only sorting in which rows can be
     * dragged: the rows of one event type are then next to each other, in booking-form order.
     */
    public function orderedByOrdering(): bool
    {
        return $this->listOrdering(self::SORTABLE, 'f.ordering', 'ASC')[0] === 'f.ordering';
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['f.id', 'f.label', 'f.type', 'f.required', 'f.published', 'f.eventtype_id', 'f.ordering']))
            ->select($db->quoteName('e.title', 'eventtype_title'))
            ->from($db->quoteName('#__appointments_fields', 'f'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('f.eventtype_id'));
        // The permission scope first: a filter only narrows it, never widens it.
        $ids = array_map('intval', array_column(EventScope::events($db, Factory::getApplication()->getIdentity()), 'id'));
        $query->where($ids === [] ? '1 = 0' : $db->quoteName('f.eventtype_id') . ' IN (' . implode(',', $ids) . ')');

        $eventId = (int) $this->getState('filter.eventtype_id');

        if ($eventId > 0) {
            $query->where($db->quoteName('f.eventtype_id') . ' = :eventtype')->bind(':eventtype', $eventId, ParameterType::INTEGER);
        }

        $type = (string) $this->getState('filter.type');

        if ($type !== '') {
            $query->where($db->quoteName('f.type') . ' = :type')->bind(':type', $type);
        }

        $required = (string) $this->getState('filter.required');

        if ($required !== '') {
            $requiredFlag = (int) $required;
            $query->where($db->quoteName('f.required') . ' = :required')->bind(':required', $requiredFlag, ParameterType::INTEGER);
        }

        $published = (string) $this->getState('filter.published');

        if ($published !== '') {
            $publishedFlag = (int) $published;
            $query->where($db->quoteName('f.published') . ' = :published')->bind(':published', $publishedFlag, ParameterType::INTEGER);
        }

        $search = (string) $this->getState('filter.search');

        if ($search !== '') {
            if (preg_match('/^id:(\d{1,10})$/D', $search, $match) === 1) {
                $id = (int) $match[1];
                $query->where($db->quoteName('f.id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
            } else {
                $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
                $query->where($db->quoteName('f.label') . ' LIKE :search')->bind(':search', $like);
            }
        }

        [$order, $direction] = $this->listOrdering(self::SORTABLE, 'f.ordering', 'ASC');

        if ($order === 'f.ordering') {
            // Grouped by event type, so the fields of one event type stay together and can be
            // dragged within their group. Inside a group the order is the booking-form order.
            return $query->order($db->quoteName('e.title') . ' ASC')
                ->order($db->quoteName('f.eventtype_id') . ' ASC')
                ->order($db->quoteName('f.ordering') . ' ' . $direction)
                ->order($db->quoteName('f.id') . ' ' . $direction);
        }

        return $query->order($db->quoteName($order) . ' ' . $direction)->order($db->quoteName('f.id') . ' ASC');
    }
}
