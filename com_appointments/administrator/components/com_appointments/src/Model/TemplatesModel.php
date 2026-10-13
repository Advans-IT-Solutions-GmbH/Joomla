<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\MVC\Model\ListModel;

defined('_JEXEC') or die;

class TemplatesModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= ['t.id', 't.purpose', 't.language', 't.eventtype_id', 't.published'];
        parent::__construct($config, $factory);
    }

    protected function populateState($ordering = 't.id', $direction = 'ASC')
    {
        $this->setState('filter.eventtype_id', $this->getUserStateFromRequest($this->context . '.eventtype', 'filter_eventtype_id', -1, 'int'));
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
        $query->select($db->quoteName(['t.id', 't.eventtype_id', 't.purpose', 't.language', 't.subject', 't.published', 't.checked_out']))
            ->select($db->quoteName('e.title', 'eventtype_title'))
            ->from($db->quoteName('#__appointments_templates', 't'))
            ->join('LEFT', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('t.eventtype_id'));
        $eventId = (int) $this->getState('filter.eventtype_id', -1);

        if ($eventId >= 0) {
            $query->where($db->quoteName('t.eventtype_id') . ' = ' . $eventId);
        }

        $order = (string) $this->getState('list.ordering', 't.id');
        $order = in_array($order, $this->filter_fields, true) ? $order : 't.id';
        $direction = strtoupper((string) $this->getState('list.direction')) === 'DESC' ? 'DESC' : 'ASC';

        return $query->order($db->quoteName($order) . ' ' . $direction);
    }
}
