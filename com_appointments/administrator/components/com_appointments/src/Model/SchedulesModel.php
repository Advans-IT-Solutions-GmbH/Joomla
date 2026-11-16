<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * List model for the schedules, with the number of intervals each one has.
 *
 * @since  1.0.0
 */
class SchedulesModel extends ListModel
{
    use ListFiltersTrait;

    /** Columns the list may be sorted by: only columns of the select list. */
    private const SORTABLE = ['s.id', 's.title', 's.timezone', 's.published', 'u.name'];

    public function __construct($config = [], $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = self::SORTABLE;
        }

        parent::__construct($config, $factory);
    }

    protected function filterNames(): array
    {
        return ['search', 'published', 'owner_user_id'];
    }

    protected function populateState($ordering = 's.title', $direction = 'ASC')
    {
        $this->adoptFlatFilters();
        parent::populateState($ordering, $direction);
        $this->setState('filter.search', $this->textFilter('search'));
        $this->setState('filter.published', $this->optionFilter('published', ['0', '1']));
        $this->setState('filter.owner_user_id', $this->idFilter('owner_user_id'));
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
        $db    = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);

        $query->select(
            [
                $db->quoteName('s.id'),
                $db->quoteName('s.title'),
                $db->quoteName('s.timezone'),
                $db->quoteName('s.published'),
                $db->quoteName('s.owner_user_id'),
                $db->quoteName('u.name', 'owner_name'),
            ]
        )
            ->select('COUNT(' . $db->quoteName('r.id') . ') AS ' . $db->quoteName('rule_count'))
            ->from($db->quoteName('#__appointments_schedules', 's'))
            ->join(
                'LEFT',
                $db->quoteName('#__appointments_schedule_rules', 'r'),
                $db->quoteName('r.schedule_id') . ' = ' . $db->quoteName('s.id')
            )
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'u'),
                $db->quoteName('u.id') . ' = ' . $db->quoteName('s.owner_user_id')
            )
            ->group(
                $db->quoteName(
                    ['s.id', 's.title', 's.timezone', 's.published', 's.owner_user_id', 'u.name']
                )
            );

        $search = (string) $this->getState('filter.search');

        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $query->where(
                '(' . $db->quoteName('s.title') . ' LIKE :search OR '
                . $db->quoteName('s.timezone') . ' LIKE :zone)'
            )
                ->bind(':search', $like)
                ->bind(':zone', $like);
        }

        $published = (string) $this->getState('filter.published');

        if ($published !== '') {
            $publishedFlag = (int) $published;
            $query->where($db->quoteName('s.published') . ' = :published')->bind(':published', $publishedFlag, ParameterType::INTEGER);
        }

        $owner = (int) $this->getState('filter.owner_user_id');

        if ($owner > 0) {
            $query->where($db->quoteName('s.owner_user_id') . ' = :owner')->bind(':owner', $owner, ParameterType::INTEGER);
        }

        // Only a column of the select list may be ordered by, so a crafted
        // request cannot turn the ordering into an injection point.
        [$ordering, $direction] = $this->listOrdering(self::SORTABLE, 's.title', 'ASC');
        $query->order($db->quoteName($ordering) . ' ' . $direction)->order($db->quoteName('s.id') . ' ' . $direction);

        return $query;
    }
}
