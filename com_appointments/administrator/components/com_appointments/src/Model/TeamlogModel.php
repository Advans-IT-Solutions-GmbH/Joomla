<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Factory;
use Joomla\CMS\MVC\Model\ListModel;
use Joomla\Database\ParameterType;

defined('_JEXEC') or die;

/**
 * The internet calendar log for administrators (`core.admin`). The address hash and the chain
 * hashes are not selected; the checksum is shown separately by the view.
 *
 * Work package F.
 *
 * @since  1.4.0
 */
class TeamlogModel extends ListModel
{
    use ListFiltersTrait;

    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= ['l.id'];
        parent::__construct($config, $factory);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['l.id', 'l.occurred_utc', 'l.event', 'l.actor_user_id', 'l.actor_device_id',
            'l.subject_user_id', 'l.calendar_id', 'l.object_ref', 'l.details']))
            ->select($db->quoteName('a.name', 'actor_name'))
            ->select($db->quoteName('s.name', 'subject_name'))
            ->select($db->quoteName('c.title', 'calendar_title'))
            ->from($db->quoteName('#__appointments_teamlog', 'l'))
            ->join('LEFT', $db->quoteName('#__users', 'a') . ' ON ' . $db->quoteName('a.id') . ' = ' . $db->quoteName('l.actor_user_id'))
            ->join('LEFT', $db->quoteName('#__users', 's') . ' ON ' . $db->quoteName('s.id') . ' = ' . $db->quoteName('l.subject_user_id'))
            ->join('LEFT', $db->quoteName('#__appointments_calendars', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('l.calendar_id'));

        if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_appointments')) {
            $query->where('1 = 0');
        }

        $event = (string) $this->getState('filter.event', '');

        if ($event !== '') {
            $query->where($db->quoteName('l.event') . ' = :event')->bind(':event', $event);
        }

        $user = (string) $this->getState('filter.user_id', '');

        if ($user !== '') {
            $userId = (int) $user;
            $query->where('(' . $db->quoteName('l.actor_user_id') . ' = :actor OR '
                . $db->quoteName('l.subject_user_id') . ' = :subject)')
                ->bind(':actor', $userId, ParameterType::INTEGER)
                ->bind(':subject', $userId, ParameterType::INTEGER);
        }

        // The reference shown under «Details» (a calendar, device or request reference).
        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
            $query->where($db->quoteName('l.object_ref') . ' LIKE :search')->bind(':search', $like);
        }

        // Stored in UTC and shown in UTC: a day of the filter is a UTC day.
        $from = (string) $this->getState('filter.from', '');

        if ($from !== '') {
            $fromTime = $from . ' 00:00:00';
            $query->where($db->quoteName('l.occurred_utc') . ' >= :from')->bind(':from', $fromTime);
        }

        $to = (string) $this->getState('filter.to', '');

        if ($to !== '') {
            $toTime = $to . ' 23:59:59';
            $query->where($db->quoteName('l.occurred_utc') . ' <= :to')->bind(':to', $toTime);
        }

        $direction = strtoupper((string) $this->getState('list.direction')) === 'ASC' ? 'ASC' : 'DESC';

        return $query->order($db->quoteName('l.id') . ' ' . $direction);
    }

    protected function filterNames(): array
    {
        return ['search', 'event', 'user_id', 'from', 'to'];
    }

    protected function populateState($ordering = 'l.id', $direction = 'DESC')
    {
        // Searchtools send filter[...]; links of earlier releases the flat filter_<name>.
        $this->adoptFlatFilters();
        parent::populateState($ordering, $direction);
        $search = $this->rawFilter('search');
        $event = $this->rawFilter('event');
        $user = $this->rawFilter('user_id');
        $from = $this->rawFilter('from');
        $to = $this->rawFilter('to');

        if ($search === null || $event === null || $user === null || $from === null || $to === null
            || preg_match('//u', $search) !== 1 || mb_strlen($search) > 64
            || ($event !== '' && !\in_array($event, AuditEvent::all(), true))
            || ($user !== '' && (!ctype_digit($user) || \strlen($user) > 10 || (int) $user < 1))
            || ($from !== '' && !UtcTimestamp::isDate($from))
            || ($to !== '' && !UtcTimestamp::isDate($to))
            || ($from !== '' && $to !== '' && $from > $to)) {
            // Not kept: a forged link must not leave the log stuck on an error.
            $this->rejectFilters('COM_APPOINTMENTS_TEAM_F_FILTER_INVALID');
        }

        $this->setState('filter.search', $search);
        $this->setState('filter.event', $event);
        $this->setState('filter.user_id', $user);
        $this->setState('filter.from', $from);
        $this->setState('filter.to', $to);
    }

    protected function getStoreId($id = '')
    {
        foreach ($this->filterNames() as $key) {
            $id .= ':' . $this->getState('filter.' . $key);
        }

        return parent::getStoreId($id);
    }
}
