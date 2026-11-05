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
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\ListModel;

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
            $query->where($db->quoteName('l.event') . ' = ' . $db->quote($event));
        }

        $user = (string) $this->getState('filter.user_id', '');

        if ($user !== '') {
            $query->where('(' . $db->quoteName('l.actor_user_id') . ' = ' . (int) $user . ' OR '
                . $db->quoteName('l.subject_user_id') . ' = ' . (int) $user . ')');
        }

        $direction = strtoupper((string) $this->getState('list.direction')) === 'ASC' ? 'ASC' : 'DESC';

        return $query->order($db->quoteName('l.id') . ' ' . $direction);
    }

    protected function populateState($ordering = 'l.id', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
        $app = Factory::getApplication();
        $event = trim((string) $app->getUserStateFromRequest($this->context . '.filter.event', 'filter_event', '', 'string'));
        $user = trim((string) $app->getUserStateFromRequest($this->context . '.filter.user_id', 'filter_user_id', '', 'string'));

        if (($event !== '' && !\in_array($event, AuditEvent::all(), true))
            || ($user !== '' && (!ctype_digit($user) || \strlen($user) > 10 || (int) $user < 1))) {
            // Not kept: a forged link must not leave the log stuck on an error.
            $app->setUserState($this->context . '.filter.event', '');
            $app->setUserState($this->context . '.filter.user_id', '');

            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_TEAM_F_FILTER_INVALID'), 400);
        }

        $this->setState('filter.event', $event);
        $this->setState('filter.user_id', $user);
    }
}
