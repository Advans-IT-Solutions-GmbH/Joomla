<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Advans\Component\Appointments\Administrator\Service\BookingStore;
use Advans\Component\Appointments\Administrator\Service\NotificationService;
use Advans\Component\Appointments\Administrator\Service\NotificationException;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

class BookingsController extends BaseController
{
    public function approve(): bool
    {
        return $this->transition(1);
    }

    public function cancel(): bool
    {
        return $this->transition(2);
    }

    public function erase(): bool
    {
        $token = Session::getFormToken();
        $user = $this->app->getIdentity();
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($token, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }
        if (!$user->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->input->post->get('cid', [], 'array')), static fn ($id) => $id > 0)));
        foreach ($ids as $id) {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('eventtype_id'))->from($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . $id);
            $eventId = (int) $db->setQuery($query)->loadResult();
            if (!$eventId || !$user->authorise('core.delete', 'com_appointments.eventtype.' . $eventId)) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }
        }
        $delete = match (ComponentHelper::getParams('com_appointments')->get('erase_mode', 'anonymise')) {
            'anonymise' => false,
            'delete' => true,
            default => throw new \RuntimeException(Text::_('COM_APPOINTMENTS_CONFIG_ERASE_INVALID')),
        };
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        foreach ($ids as $id) {
            $result = (new BookingStore($db))->erase($id, $now, 'admin', $delete, (int) $user->id);
            if ($result['notification'] !== null) {
                try {
                    (new NotificationService($db))->send($result['notification'], 'erasure_notice', $now, true);
                } catch (NotificationException $e) {
                    $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_MAIL_FAILED'), 'error');
                }
            }
        }
        $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_ERASED'), 'message');
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=bookings', false));

        return true;
    }

    private function transition(int $state): bool
    {
        $token = Session::getFormToken();

        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($token, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $user = $this->app->getIdentity();

        if (!$user->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $ids = array_values(array_unique(array_filter(array_map('intval', $this->input->post->get('cid', [], 'array')), static fn ($id) => $id > 0)));

        foreach ($ids as $id) {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('eventtype_id'))->from($db->quoteName('#__appointments_bookings'))->where($db->quoteName('id') . ' = ' . $id);
            $eventId = (int) $db->setQuery($query)->loadResult();

            if (!$eventId || !$user->authorise('core.edit.state', 'com_appointments.eventtype.' . $eventId)) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }
        }

        foreach ($ids as $id) {
            try {
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $booking = (new BookingStore($db))->changeState($id, $state, $now);
                if ($booking['_changed']) {
                    (new NotificationService($db))->send($booking, $state === 1 ? 'approved' : 'cancellation', $now);
                }
            } catch (NotificationException $e) {
                $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_MAIL_FAILED'), 'error');
            } catch (\DomainException $e) {
                $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_ERROR_STATE'), 'error');
                $this->setRedirect(Route::_('index.php?option=com_appointments&view=bookings', false));

                return false;
            }
        }

        $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_UPDATED'), 'message');
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=bookings', false));

        return true;
    }
}
