<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Model;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;
use Joomla\CMS\Version;

defined('_JEXEC') or die;

class SystemModel extends BaseDatabaseModel
{
    public function getChecks(): array
    {
        $app = Factory::getApplication();
        $user = $app->getIdentity();
        if (!$user->authorise('core.manage', 'com_appointments') || !$user->authorise('core.options', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        $db = $this->getDatabase();
        $version = (new Version())->getShortVersion();
        $timezone = (string) $app->get('offset', 'UTC');
        $checks = [
            ['label' => 'COM_APPOINTMENTS_SYSTEM_PHP', 'ok' => version_compare(PHP_VERSION, '8.1', '>='), 'value' => PHP_VERSION],
            ['label' => 'COM_APPOINTMENTS_SYSTEM_JOOMLA', 'ok' => version_compare($version, '5.4', '>='), 'value' => $version],
            ['label' => 'COM_APPOINTMENTS_SYSTEM_TIMEZONE', 'ok' => in_array($timezone, \DateTimeZone::listIdentifiers(), true), 'value' => $timezone],
            ['label' => 'COM_APPOINTMENTS_SYSTEM_MAIL', 'ok' => (bool) $app->get('mailonline', true),
                'value' => (string) $app->get('mailer', 'mail')],
        ];
        foreach (['task' => 'COM_APPOINTMENTS_SYSTEM_TASK', 'privacy' => 'COM_APPOINTMENTS_SYSTEM_PRIVACY'] as $group => $label) {
            $query = $this->query()->select($db->quoteName('enabled'))->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . " = 'plugin'")->where($db->quoteName('element') . " = 'appointments'")
                ->where($db->quoteName('folder') . ' = ' . $db->quote($group));
            $enabled = (int) $db->setQuery($query)->loadResult() === 1;
            $checks[] = ['label' => $label, 'ok' => $enabled,
                'value' => Text::_($enabled ? 'JENABLED' : 'JDISABLED')];
        }
        foreach (['reminders' => 'COM_APPOINTMENTS_SYSTEM_REMINDERS', 'retention' => 'COM_APPOINTMENTS_SYSTEM_RETENTION'] as $routine => $label) {
            $query = $this->query()->select($db->quoteName(['last_execution', 'last_exit_code']))
                ->from($db->quoteName('#__scheduler_tasks'))->where($db->quoteName('type') . ' = '
                    . $db->quote('plg_task_appointments.' . $routine))->where($db->quoteName('state') . ' = 1')
                ->order($db->quoteName('last_execution') . ' DESC')->setLimit(1);
            $task = $db->setQuery($query)->loadAssoc();
            $checks[] = ['label' => $label,
                'ok' => $task && !empty($task['last_execution']) && (int) $task['last_exit_code'] === 0,
                'value' => $task['last_execution'] ?? Text::_('COM_APPOINTMENTS_SYSTEM_NEVER')];
        }
        $invalid = 0;
        foreach (['eventtypes', 'hosts'] as $table) {
            $query = $this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_' . $table))
                ->where($db->quoteName('feed_token') . " NOT REGEXP '^[a-f0-9]{64}$'");
            $invalid += (int) $db->setQuery($query)->loadResult();
        }
        $checks[] = ['label' => 'COM_APPOINTMENTS_SYSTEM_FEEDS', 'ok' => $invalid === 0, 'value' => (string) $invalid];
        $query = $this->query()->select('MIN(' . $db->quoteName('occurred_utc') . ')')->from($db->quoteName('#__appointments_audit'));
        $oldest = $db->setQuery($query)->loadResult();
        $checks[] = ['label' => 'COM_APPOINTMENTS_SYSTEM_AUDIT', 'ok' => true,
            'value' => $oldest ?? Text::_('COM_APPOINTMENTS_SYSTEM_NONE')];
        return $checks;
    }

    private function query(): \Joomla\Database\QueryInterface
    {
        $db = $this->getDatabase();
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
