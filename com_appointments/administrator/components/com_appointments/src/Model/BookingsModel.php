<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\CMS\Language\Text;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;

defined('_JEXEC') or die;

class BookingsModel extends ListModel
{
    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= ['b.id', 'b.start_utc', 'b.state', 'e.title'];
        parent::__construct($config, $factory);
    }

    public function authorisedEvents(): array
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'title']))->from($db->quoteName('#__appointments_eventtypes'))->order($db->quoteName('title'));
        $user = Factory::getApplication()->getIdentity();

        return array_values(array_filter($db->setQuery($query)->loadAssocList(), static fn ($row) =>
            $user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $row['id'])));
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['b.id', 'b.eventtype_id', 'b.start_utc', 'b.end_utc', 'b.state',
            'b.invitee_name', 'b.invitee_email', 'b.invitee_phone', 'b.guest_emails', 'b.custom_fields',
            'b.sequence', 'b.conference_state', 'b.conference_error', 'b.anonymised_utc']))
            ->select($db->quoteName('e.title', 'eventtype_title'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e') . ' ON ' . $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'));
        $ids = array_map(static fn ($row) => (int) $row['id'], $this->authorisedEvents());

        if ($ids === [] || !Factory::getApplication()->getIdentity()->authorise('core.manage', 'com_appointments')) {
            $query->where('1 = 0');
        } else {
            $query->where($db->quoteName('b.eventtype_id') . ' IN (' . implode(',', $ids) . ')');
        }
        $event = (string) $this->getState('filter.eventtype_id', '');
        if ($event !== '') {
            $query->where($db->quoteName('b.eventtype_id') . ' = ' . (int) $event);
        }
        $state = (string) $this->getState('filter.state', '');
        if ($state !== '') {
            $query->where($db->quoteName('b.state') . ' = ' . (int) $state);
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $comparison) {
            $day = (string) $this->getState('filter.' . $key, '');
            if ($day !== '') {
                $query->where($db->quoteName('b.start_utc') . ' ' . $comparison . ' '
                    . $db->quote($day . ($key === 'from' ? ' 00:00:00' : ' 23:59:59')));
            }
        }
        $search = (string) $this->getState('filter.search', '');
        if ($search !== '') {
            $value = $db->quote('%' . $db->escape($search, true) . '%', false);
            $query->where('(' . $db->quoteName('b.invitee_name') . ' LIKE ' . $value
                . ' OR ' . $db->quoteName('b.invitee_email') . ' LIKE ' . $value . ')');
        }

        $order = (string) $this->getState('list.ordering', 'b.start_utc');
        $order = in_array($order, $this->filter_fields, true) ? $order : 'b.start_utc';
        $direction = strtoupper((string) $this->getState('list.direction')) === 'ASC' ? 'ASC' : 'DESC';

        return $query->order($db->quoteName($order) . ' ' . $direction);
    }

    protected function populateState($ordering = 'b.start_utc', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
        $app = Factory::getApplication();
        foreach (['eventtype_id', 'state', 'from', 'to', 'search'] as $key) {
            $value = trim((string) $app->getUserStateFromRequest($this->context . '.filter.' . $key, 'filter_' . $key, '', 'string'));
            if (($key === 'eventtype_id' && $value !== '' && (!ctype_digit($value) || (int) $value < 1 || strlen($value) > 10))
                || ($key === 'state' && !in_array($value, ['', '0', '1', '2'], true))
                || (in_array($key, ['from', 'to'], true) && $value !== '' && !UtcTimestamp::isDate($value))
                || ($key === 'search' && (preg_match('//u', $value) !== 1 || mb_strlen($value) > 200))) {
                throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKINGS_FILTER_INVALID'), 400);
            }
            $this->setState('filter.' . $key, $value);
        }
        if ($this->getState('filter.from') !== '' && $this->getState('filter.to') !== ''
            && $this->getState('filter.from') > $this->getState('filter.to')) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKINGS_FILTER_INVALID'), 400);
        }
    }

    protected function getStoreId($id = '')
    {
        foreach (['eventtype_id', 'state', 'from', 'to', 'search'] as $key) {
            $id .= ':' . $this->getState('filter.' . $key);
        }

        return parent::getStoreId($id);
    }

    public function exportRows(): \Generator
    {
        $db = $this->getDatabase();
        $last = 0;
        do {
            $query = $this->getListQuery()->clear('order')->order($db->quoteName('b.id') . ' ASC')
                ->where($db->quoteName('b.id') . ' > ' . $last)->setLimit(200);
            $rows = $db->setQuery($query)->loadAssocList();
            foreach ($rows as $row) {
                $last = (int) $row['id'];
                yield $row;
            }
        } while (count($rows) === 200);
    }
}
