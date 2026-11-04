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
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/**
 * Activation links of administrator-created accounts: send, close, and the
 * one-step revoke of a completed activation.
 *
 * Every task: `core.manage`, the feature switched on, POST with the form
 * token. Who may act on which account (core.admin for team accounts, the right
 * to share externally for external accounts) is decided by the service.
 *
 * @since  1.4.0
 */
class InvitationsController extends BaseController
{
    public function invite(): bool
    {
        $services = $this->guard();
        $userId = $this->input->post->getInt('user_id', 0);

        return $this->attempt(function () use ($services, $userId): void {
            $services->invitations()->invite((int) $this->app->getIdentity()->id, $userId);
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_INVITATION_SENT'), 'message');
        });
    }

    public function revoke(): bool
    {
        $services = $this->guard();
        $id = $this->input->post->getInt('id', 0);

        if ($this->input->post->getInt('confirm', 0) !== 1) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_REVOKE_CONFIRM_MISSING'), 'warning');
            $this->setRedirect(Route::_('index.php?option=com_appointments&view=invitations&layout=revoke&id=' . $id, false));

            return false;
        }

        return $this->attempt(function () use ($services, $id): void {
            $services->invitations()->revoke((int) $this->app->getIdentity()->id, $id);
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_TEAM_C_INVITATION_REVOKED'), 'message');
        });
    }

    private function attempt(callable $work): bool
    {
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=invitations', false));

        try {
            $work();

            return true;
        } catch (TeamException $e) {
            $key = $e->getMessage();
            $this->app->enqueueMessage(
                Text::_($this->app->getLanguage()->hasKey($key) ? $key : 'COM_APPOINTMENTS_TEAM_C_ERROR_UNAVAILABLE'),
                'error'
            );

            return false;
        }
    }

    private function guard(): TeamServices
    {
        if (!$this->app->getIdentity()->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        if ($this->input->getMethod() !== 'POST' || !$this->checkToken('post', false)) {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $services = TeamServices::fromApplication();

        if (!$services->enabled()) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $this->app->setHeader('Cache-Control', 'private, no-store', true);

        return $services;
    }
}
