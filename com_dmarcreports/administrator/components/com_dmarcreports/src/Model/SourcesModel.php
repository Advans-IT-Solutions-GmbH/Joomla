<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Model;

use Joomla\CMS\MVC\Model\ListModel;

defined('_JEXEC') or die;

/**
 * List model for report sources (mailboxes).
 *
 * @since  1.0.0
 */
class SourcesModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'name', 'host', 'published', 'last_status'];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 's.name', $direction = 'ASC')
    {
        $search = $this->getUserStateFromRequest($this->context . '.filter.search', 'filter_search', '', 'string');
        $this->setState('filter.search', $search);

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
                $db->quoteName('s.name'),
                $db->quoteName('s.host'),
                $db->quoteName('s.username'),
                $db->quoteName('s.folder'),
                $db->quoteName('s.published'),
                $db->quoteName('s.last_run'),
                $db->quoteName('s.last_status'),
                $db->quoteName('s.last_message'),
                $db->quoteName('d.domain', 'domain'),
            ]
        )
            ->from($db->quoteName('#__dmarc_sources', 's'))
            ->join('LEFT', $db->quoteName('#__dmarc_domains', 'd'),
                $db->quoteName('d.id') . ' = ' . $db->quoteName('s.domain_id'));

        $search = (string) $this->getState('filter.search');

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where($db->quoteName('s.name') . ' LIKE :search')
                ->bind(':search', $like);
        }

        $ordering  = $this->state->get('list.ordering', 's.name');
        $direction = $this->state->get('list.direction', 'ASC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}
