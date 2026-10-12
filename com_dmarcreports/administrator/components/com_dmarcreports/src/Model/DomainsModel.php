<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\DatabaseQuery;

defined('_JEXEC') or die;

/**
 * List model for monitored domains.
 *
 * @since  1.0.0
 */
class DomainsModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        if (empty($config['filter_fields'])) {
            $config['filter_fields'] = ['id', 'domain', 'title', 'expected_policy', 'published'];
        }

        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 'd.domain', $direction = 'ASC')
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
                $db->quoteName('d.id'),
                $db->quoteName('d.domain'),
                $db->quoteName('d.title'),
                $db->quoteName('d.expected_policy'),
                $db->quoteName('d.published'),
                $db->quoteName('d.notes'),
            ]
        )
            ->from($db->quoteName('#__dmarc_domains', 'd'));

        $search = (string) $this->getState('filter.search');

        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where($db->quoteName('d.domain') . ' LIKE :search')
                ->bind(':search', $like);
        }

        $ordering  = $this->state->get('list.ordering', 'd.domain');
        $direction = $this->state->get('list.direction', 'ASC');
        $query->order($db->escape($ordering) . ' ' . $db->escape($direction));

        return $query;
    }
}
