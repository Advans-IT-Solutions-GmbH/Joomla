<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Service\Close\Queue;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class CloseController extends BaseController
{
    public function retry(): bool
    {
        $this->requireAdmin();
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=dashboard', false));
        try {
            $result = $this->queue()->run(20, true);
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_RETRY_RESULT'),
                $result['failed'] > 0 || $result['disabled'] > 0 || !empty($result['cooldown']) ? 'warning' : 'message');
            return $result['failed'] === 0 && $result['disabled'] === 0;
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_FAILED'), 'error');
            return false;
        }
    }

    public function reconcile(): bool
    {
        $this->requireAdmin();
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=dashboard', false));
        try {
            $id = $this->input->post->getInt('booking_id');
            if ($id < 1) {
                throw new \InvalidArgumentException('Invalid booking identifier.');
            }
            $this->queue()->reconcile($id, trim($this->input->post->getString('remote_id')));
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_RECONCILED'));
            return true;
        } catch (\Throwable $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CLOSE_FAILED'), 'error');
            return false;
        }
    }

    private function queue(): Queue
    {
        return new Queue(Factory::getContainer()->get(DatabaseInterface::class), ComponentHelper::getParams('com_appointments'));
    }

    private function requireAdmin(): void
    {
        $token = Session::getFormToken();
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($token, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }
        if (!$this->app->getIdentity()->authorise('core.admin', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }
}
