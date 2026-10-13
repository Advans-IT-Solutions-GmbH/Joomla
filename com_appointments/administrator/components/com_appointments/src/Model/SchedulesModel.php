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

defined('_JEXEC') or die;

/**
 * List model for the schedules, with the number of intervals each one has.
 *
 * @since  1.0.0
 */
class SchedulesModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['s.id', 'title', 's.title', 'timezone', 's.timezone', 'published', 's.published'];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 's.title', $direction = 'ASC')
    {
        $this->setState(
            'filter.search',
            $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string')
        );

        parent::populateState($ordering, $direction);
    }

    protected function getStoreId($id = '')
    {
        $id .= ':' . $this->getState('filter.search');

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
            $like = '%' . $search . '%';
            $query->where(
                '(' . $db->quoteName('s.title') . ' LIKE :search OR '
                . $db->quoteName('s.timezone') . ' LIKE :zone)'
            )
                ->bind(':search', $like)
                ->bind(':zone', $like);
        }

        // Only a column of the select list may be ordered by, so a crafted
        // request cannot turn the ordering into an injection point.
        $ordering  = (string) $this->state->get('list.ordering', 's.title');
        $direction = strtoupper((string) $this->state->get('list.direction', 'ASC')) === 'DESC' ? 'DESC' : 'ASC';

        if (!\in_array($ordering, $this->filter_fields, true)) {
            $ordering = 's.title';
        }

        $query->order($db->quoteName($ordering) . ' ' . $direction);

        return $query;
    }
}
