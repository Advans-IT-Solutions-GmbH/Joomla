<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Service\Team\LockoutService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

/**
 * Internet calendars. Devices of all users: block with clean-up, revoke at once, block a user's
 * calendar access. Every task is a POST with the form token and needs `core.admin`; nothing
 * happens while the internet calendars are switched off.
 *
 * Work package F.
 *
 * @since  1.4.0
 */
class DevicesController extends BaseController
{
    /**
     * Block the selected devices: they receive their calendars empty, then none, then 401.
     */
    public function block(): bool
    {
        return $this->run(static function (TeamServices $services, array $ids, int $actor): int {
            foreach ($ids as $id) {
                $services->cleanup()->startForDevice($id, 'admin_block', $actor);
            }

            return \count($ids);
        }, 'COM_APPOINTMENTS_TEAM_F_DEVICES_BLOCKED');
    }

    /**
     * Revoke the selected devices at once (401 from the next request on), without the
     * clean-up: the emergency stop for a lost or stolen device.
     */
    public function revoke(): bool
    {
        return $this->run(static function (TeamServices $services, array $ids, int $actor): int {
            foreach ($ids as $id) {
                $services->devices()->revoke($actor, $id, 'admin_revoke');
            }

            return \count($ids);
        }, 'COM_APPOINTMENTS_TEAM_F_DEVICES_REVOKED');
    }

    /**
     * Block the calendar access of the users of the selected devices: every device of
     * theirs goes into clean-up and their grants are suspended.
     */
    public function blockUser(): bool
    {
        return $this->run(static function (TeamServices $services, array $ids, int $actor): int {
            $db = $services->db();
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $users = array_values(array_unique(array_map('intval', $db->setQuery($query->select($db->quoteName('user_id'))
                ->from($db->quoteName('#__appointments_devices'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'))->loadColumn() ?: [])));

            foreach ($users as $userId) {
                if ($userId > 0) {
                    $services->cleanup()->onUserBlocked($userId, $actor);
                }
            }

            return \count($users);
        }, 'COM_APPOINTMENTS_TEAM_F_DEVICES_USERS_BLOCKED');
    }

    /**
     * Lift the sign-in lock of the users of the selected devices at once.
     */
    public function unlock(): bool
    {
        return $this->run(static function (TeamServices $services, array $ids, int $actor): int {
            $lockout = $services->lockout();

            if (!$lockout instanceof LockoutService) {
                return 0;
            }

            $db = $services->db();
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $users = array_values(array_unique(array_map('intval', $db->setQuery($query->select($db->quoteName('user_id'))
                ->from($db->quoteName('#__appointments_devices'))
                ->where($db->quoteName('id') . ' IN (' . implode(',', $ids) . ')'))->loadColumn() ?: [])));

            foreach ($users as $userId) {
                $lockout->clearFor($userId, $actor);
            }

            return \count($users);
        }, 'COM_APPOINTMENTS_TEAM_F_DEVICES_UNLOCKED');
    }

    /**
     * @param   callable(TeamServices, int[], int): int  $action
     */
    private function run(callable $action, string $doneKey): bool
    {
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get(Session::getFormToken(), '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $user = $this->app->getIdentity();

        if (!$user->authorise('core.admin', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $services = TeamServices::fromApplication();

        if (!$services->enabled()) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $this->input->post->get('cid', [], 'array')),
            static fn (int $id): bool => $id > 0)));
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=devices', false));

        if ($ids === []) {
            $this->app->enqueueMessage(Text::_('JGLOBAL_NO_ITEM_SELECTED'), 'warning');

            return false;
        }

        try {
            $count = $action($services, $ids, (int) $user->id);
        } catch (TeamException $e) {
            // The message of a TeamException is a language key, never a detail.
            $this->app->enqueueMessage(Text::_($e->getMessage()), 'error');

            return false;
        }

        $this->app->enqueueMessage(Text::sprintf($doneKey, $count), 'message');

        return true;
    }
}