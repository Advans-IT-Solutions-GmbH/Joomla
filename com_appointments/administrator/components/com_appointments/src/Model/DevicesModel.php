<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\ListModel;

defined('_JEXEC') or die;

/**
 * Every device of the internet calendars, for administrators (`core.admin`). Never a hash, a salt
 * or a wrapped key: those columns are not selected.
 *
 * Work package F.
 *
 * @since  1.4.0
 */
class DevicesModel extends ListModel
{
    public const STATES = [
        TeamStates::DEVICE_ACTIVE         => 'COM_APPOINTMENTS_TEAM_F_DEVICE_ACTIVE',
        TeamStates::DEVICE_CLEANUP_EMPTY  => 'COM_APPOINTMENTS_TEAM_F_DEVICE_CLEANUP_EMPTY',
        TeamStates::DEVICE_CLEANUP_HIDDEN => 'COM_APPOINTMENTS_TEAM_F_DEVICE_CLEANUP_HIDDEN',
        TeamStates::DEVICE_REVOKED        => 'COM_APPOINTMENTS_TEAM_F_DEVICE_REVOKED',
    ];

    public function __construct($config = [], $factory = null)
    {
        $config['filter_fields'] ??= ['d.id', 'd.last_sync_utc', 'd.created_utc', 'd.state', 'u.name'];
        parent::__construct($config, $factory);
    }

    protected function getListQuery()
    {
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['d.id', 'd.user_id', 'd.name', 'd.client', 'd.removal_mode', 'd.state', 'd.rule_version',
            'd.rule_accepted_utc', 'd.created_utc', 'd.last_sync_utc', 'd.cleanup_started_utc', 'd.revoked_utc', 'd.revoke_reason']))
            ->select($db->quoteName('u.name', 'user_name'))
            ->select($db->quoteName('u.username', 'user_username'))
            ->from($db->quoteName('#__appointments_devices', 'd'))
            ->join('LEFT', $db->quoteName('#__users', 'u') . ' ON ' . $db->quoteName('u.id') . ' = ' . $db->quoteName('d.user_id'));

        if (!Factory::getApplication()->getIdentity()->authorise('core.admin', 'com_appointments')) {
            $query->where('1 = 0');
        }

        $state = (string) $this->getState('filter.state', '');

        if ($state !== '') {
            $query->where($db->quoteName('d.state') . ' = ' . (int) $state);
        }

        $search = (string) $this->getState('filter.search', '');

        if ($search !== '') {
            $value = $db->quote('%' . $db->escape($search, true) . '%', false);
            $query->where('(' . $db->quoteName('d.name') . ' LIKE ' . $value . ' OR ' . $db->quoteName('u.name') . ' LIKE ' . $value
                . ' OR ' . $db->quoteName('u.username') . ' LIKE ' . $value . ')');
        }

        $order = (string) $this->getState('list.ordering', 'd.last_sync_utc');
        $order = \in_array($order, $this->filter_fields, true) ? $order : 'd.last_sync_utc';
        $direction = strtoupper((string) $this->getState('list.direction')) === 'ASC' ? 'ASC' : 'DESC';

        return $query->order($db->quoteName($order) . ' ' . $direction)->order($db->quoteName('d.id') . ' DESC');
    }

    protected function populateState($ordering = 'd.last_sync_utc', $direction = 'DESC')
    {
        parent::populateState($ordering, $direction);
        $app = Factory::getApplication();

        foreach (['state', 'search'] as $key) {
            $value = trim((string) $app->getUserStateFromRequest($this->context . '.filter.' . $key, 'filter_' . $key, '', 'string'));

            if (($key === 'state' && $value !== '' && !\array_key_exists((int) $value, self::STATES))
                || ($key === 'state' && $value !== '' && !ctype_digit($value))
                || ($key === 'search' && (preg_match('//u', $value) !== 1 || mb_strlen($value) > 100))) {
                // Not kept: a forged link must not leave the list stuck on an error.
                $app->setUserState($this->context . '.filter.' . $key, '');

                throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_TEAM_F_FILTER_INVALID'), 400);
            }

            $this->setState('filter.' . $key, $value);
        }
    }
}
