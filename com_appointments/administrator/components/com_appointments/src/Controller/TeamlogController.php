<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Service\Team\LockoutService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

/**
 * Internet calendars: verify the hash chain of the log. A POST with the form token, `core.admin`.
 * Verifying reads only and is not logged itself.
 *
 * Work package F.
 *
 * @since  1.4.0
 */
class TeamlogController extends BaseController
{
    public function verify(): bool
    {
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get(Session::getFormToken(), '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        if (!$this->app->getIdentity()->authorise('core.admin', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $services = TeamServices::fromApplication();

        if (!$services->enabled()) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $result = $services->audit()->verify();
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=teamlog', false));

        if ($result->intact) {
            $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_LOG_INTACT', $result->checkedRows, $result->headHash), 'message');

            return true;
        }

        $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_LOG_BROKEN', (int) $result->firstBrokenId), 'error');

        return false;
    }

    /**
     * Lift the sign-in lock of one account at once, also for an account
     * without a device. A POST with the form token, `core.admin`; logged as
     * `login.unlocked`.
     */
    public function unlock(): bool
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

        $userId = $this->input->post->getInt('unlock_user_id', 0);
        $lockout = $services->lockout();
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=teamlog', false));

        if ($userId <= 0 || !$lockout instanceof LockoutService) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_F_FILTER_INVALID'), 'warning');

            return false;
        }

        $lockout->clearFor($userId, (int) $user->id);
        $this->app->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_F_DEVICES_UNLOCKED', 1), 'message');

        return true;
    }
}