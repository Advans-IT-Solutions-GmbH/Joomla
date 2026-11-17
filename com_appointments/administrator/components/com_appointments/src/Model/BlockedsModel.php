<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * List model for the blocked periods.
 *
 * @since  1.0.0
 */
class BlockedsModel extends ListModel
{
    use ListFiltersTrait;

    /** Columns the list may be sorted by. */
    private const SORTABLE = ['b.id', 'b.title', 'b.start_utc', 'b.end_utc', 'u.name'];

    public function __construct($config = [], $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = self::SORTABLE;
        }

        parent::__construct($config, $factory);
    }

    protected function filterNames(): array
    {
        return ['search', 'user_id', 'period'];
    }

    protected function populateState($ordering = 'b.start_utc', $direction = 'DESC')
    {
        $this->adoptFlatFilters();
        parent::populateState($ordering, $direction);
        $this->setState('filter.search', $this->textFilter('search'));
        $this->setState('filter.user_id', $this->idFilter('user_id'));
        $this->setState('filter.period', $this->optionFilter('period', ['upcoming', 'past']));
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
                $db->quoteName('b.id'),
                $db->quoteName('b.title'),
                $db->quoteName('b.start_utc'),
                $db->quoteName('b.end_utc'),
                $db->quoteName('b.user_id'),
                $db->quoteName('u.name', 'user_name'),
            ]
        )
            ->from($db->quoteName('#__appointments_blocked', 'b'))
            ->join(
                'LEFT',
                $db->quoteName('#__users', 'u'),
                $db->quoteName('u.id') . ' = ' . $db->quoteName('b.user_id')
            );

        $search = (string) $this->getState('filter.search');

        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $query->where($db->quoteName('b.title') . ' LIKE :search')
                ->bind(':search', $like);
        }

        $userId = (int) $this->getState('filter.user_id');

        if ($userId > 0) {
            $query->where($db->quoteName('b.user_id') . ' = :user')->bind(':user', $userId, ParameterType::INTEGER);
        }

        // Stored in UTC, so the comparison is against the current UTC time. A period that has
        // started but not ended yet counts as current and is listed with the upcoming ones.
        $period = (string) $this->getState('filter.period');

        if ($period !== '') {
            $now = Factory::getDate('now', 'UTC')->toSql();
            $query->where($db->quoteName('b.end_utc') . ($period === 'past' ? ' < ' : ' >= ') . ':now')->bind(':now', $now);
        }

        [$ordering, $direction] = $this->listOrdering(self::SORTABLE, 'b.start_utc', 'DESC');
        $query->order($db->quoteName($ordering) . ' ' . $direction)->order($db->quoteName('b.id') . ' ' . $direction);

        return $query;
    }
}
