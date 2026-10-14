<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Controller;

use Advans\Component\Appointments\Administrator\Helper\BookingValidationException;
use Advans\Component\Appointments\Administrator\Helper\FormGuard;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\SlotTakenException;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Administrator\Service\RateLimiter;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

class BookingController extends BaseController
{
    public function submit(): bool
    {
        $sessionToken = Session::getFormToken();

        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($sessionToken, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $eventId = $this->input->getInt('id', 0);
        $events = $this->getModel('Eventtype');
        $events->getItem($eventId);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $keys = new KeyProvider((string) $this->app->get('secret'));
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $data = $this->input->post->get('booking', [], 'array');
        $this->app->setUserState('com_appointments.booking.' . $eventId, $data);

        if (!(new RateLimiter($db, $keys))->allow('booking', $this->input->server->getString('REMOTE_ADDR'), $now, 10, 3600)) {
            return $this->refuse($eventId, 'COM_APPOINTMENTS_BOOKING_ERROR_RATE');
        }

        if (!(new FormGuard($keys))->verify(
            $eventId, $this->input->post->getInt('rendered_at', 0), $sessionToken,
            $this->input->post->getString('form_signature', ''), $this->input->post->getString('website', ''),
            $this->input->post->getInt('consent') === 1, $now
        )) {
            return $this->refuse($eventId, 'COM_APPOINTMENTS_BOOKING_ERROR_FORM');
        }

        $start = UtcTimestamp::parse((string) ($data['start'] ?? ''));

        if ($start === null) {
            return $this->refuse($eventId, 'COM_APPOINTMENTS_BOOKING_ERROR_SLOT');
        }

        try {
            $booking = $this->getModel('Booking')->create($events, $eventId, $data, $start, $now);
        } catch (BookingValidationException $e) {
            return $this->refuse($eventId, $e->languageKey);
        } catch (SlotTakenException $e) {
            return $this->refuse($eventId, 'COM_APPOINTMENTS_BOOKING_ERROR_TAKEN');
        }

        $this->app->setUserState('com_appointments.booking.' . $eventId, null);
        $this->app->setUserState('com_appointments.confirmation.id', (int) $booking['id']);
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=booking', false));

        return true;
    }

    public function manage(): bool
    {
        $token = Session::getFormToken();
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($token, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }
        $model = $this->getModel('Manage');
        $model->getItem();
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $keys = new KeyProvider((string) $this->app->get('secret'));
        $url = 'index.php?option=com_appointments&view=manage&id=' . $this->input->getInt('id')
            . '&action=' . $model->action() . '&signature=' . urlencode($model->signature());
        $month = $this->input->getString('month', '');
        $zone = $this->input->getString('timezone', '');
        if ($month !== '') {
            $url .= '&month=' . urlencode($month) . '&timezone=' . urlencode($zone);
        }
        $this->setRedirect(Route::_($url, false));

        if (!(new RateLimiter($db, $keys))->allow('manage', $this->input->server->getString('REMOTE_ADDR'), $now, 60, 900)) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_RATE'), 'error');
            return false;
        }
        if ($this->input->post->getInt('confirm') !== 1) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_MANAGE_CONFIRM'), 'error');
            return false;
        }
        $reason = $this->input->post->get('reason', '', 'raw');
        if (!is_string($reason) || preg_match('//u', $reason) !== 1 || mb_strlen($reason) > 2000 || str_contains($reason, "\0")) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_MANAGE_ERROR_REASON'), 'error');
            return false;
        }
        $this->app->setUserState('com_appointments.cancel_reason', $reason);
        $start = UtcTimestamp::parse($this->input->post->getString('start', ''));
        if ($model->action() === 'reschedule' && !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_ZONE'), 'error');
            return false;
        }
        if ($model->action() === 'reschedule' && $start === null) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_SLOT'), 'error');
            return false;
        }

        try {
            $model->apply($now, $start, $reason);
        } catch (SlotTakenException $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_TAKEN'), 'error');
            return false;
        } catch (\DomainException $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_MANAGE_ERROR_STATE'), 'error');
            return false;
        }
        $this->app->setUserState('com_appointments.cancel_reason', null);

        return true;
    }

    private function refuse(int $eventId, string $key): bool
    {
        $this->app->enqueueMessage(Text::_($key), 'error');
        $data = (array) $this->app->getUserState('com_appointments.booking.' . $eventId, []);
        $start = is_string($data['start'] ?? null) ? UtcTimestamp::parse($data['start']) : null;
        $zone = is_string($data['timezone'] ?? null) ? $data['timezone'] : '';
        $url = 'index.php?option=com_appointments&view=eventtype&id=' . $eventId;

        if ($start !== null && in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $local = $start->setTimezone(new \DateTimeZone($zone));
            $url .= '&timezone=' . urlencode($zone) . '&month=' . $local->format('Y-m') . '&day=' . $local->format('Y-m-d');
        }

        $this->setRedirect(Route::_($url, false));

        return false;
    }
}
