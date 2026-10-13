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
 * List model for the blocked periods.
 *
 * @since  1.0.0
 */
class BlockedsModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'b.id', 'title', 'b.title', 'start_utc', 'b.start_utc', 'end_utc', 'b.end_utc'];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'b.start_utc', $direction = 'DESC')
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
            $like = '%' . $search . '%';
            $query->where($db->quoteName('b.title') . ' LIKE :search')
                ->bind(':search', $like);
        }

        $ordering  = (string) $this->state->get('list.ordering', 'b.start_utc');
        $direction = strtoupper((string) $this->state->get('list.direction', 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        if (!\in_array($ordering, $this->filter_fields, true)) {
            $ordering = 'b.start_utc';
        }

        $query->order($db->quoteName($ordering) . ' ' . $direction);

        return $query;
    }
}
