<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Service\FeedService;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

class FeedsController extends BaseController
{
    public function rotate(): bool
    {
        $user = $this->app->getIdentity();
        if (!$user->authorise('core.manage', 'com_appointments') || !$user->authorise('core.options', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        $token = Session::getFormToken();
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($token, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }
        $scope = $this->input->post->getString('scope');
        $id = $this->input->post->getInt('id');
        if (!in_array($scope, ['event', 'host'], true) || $id < 1) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND'), 404);
        }
        if ($scope === 'event' && !$user->authorise('core.edit', 'com_appointments.eventtype.' . $id)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        if ($this->input->post->getInt('confirm') !== 1) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_FEEDS_ERROR_CONFIRM'), 400);
        }
        $service = new FeedService(Factory::getContainer()->get(DatabaseInterface::class));
        try {
            $service->rotate($scope, $id, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\DomainException $e) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND'), 404, $e);
        }
        $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_FEEDS_ROTATED'), 'message');
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=feeds', false));

        return true;
    }

    public function export(): void
    {
        $user = $this->app->getIdentity();
        if (!$user->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        $id = $this->input->getInt('id');
        $service = new FeedService(Factory::getContainer()->get(DatabaseInterface::class));
        $eventId = $service->bookingEventId($id);
        if ($id < 1 || $eventId < 1) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND'), 404);
        }
        if (!$user->authorise('core.edit', 'com_appointments.eventtype.' . $eventId)) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        try {
            $calendar = $service->export($id);
        } catch (\DomainException $e) {
            if ($e->getCode() === 404) {
                throw new \RuntimeException(Text::_('COM_APPOINTMENTS_FEEDS_ERROR_NOT_FOUND'), 404, $e);
            }
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_FEEDS_ERROR_DATA'), 500, $e);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_FEEDS_ERROR_DATA'), 500, $e);
        }
        $this->app->setHeader('Cache-Control', 'private, no-store', true);
        $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $this->app->setHeader('Referrer-Policy', 'no-referrer', true);
        $this->app->setHeader('Content-Type', 'text/calendar; charset=UTF-8; method=' . $calendar['method'], true);
        $this->app->setHeader('Content-Disposition', 'attachment; filename="' . $calendar['filename'] . '"', true);
        $this->app->sendHeaders();
        if ($this->input->getMethod() !== 'HEAD') {
            echo $calendar['body'];
        }
        $this->app->close();
    }
}
