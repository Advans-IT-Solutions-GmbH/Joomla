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
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;

defined('_JEXEC') or die;

class BookingController extends BaseController
{
    public function submit(): bool
    {
        $sessionToken = Session::getFormToken();

        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($sessionToken, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }

        $eventId = $this->input->getInt('id');
        $events = $this->getModel('Eventtype');
        $events->getItem($eventId);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $keys = new KeyProvider((string) $this->app->get('secret'));
        $db = $this->getModel('Booking')->getDatabase();
        $data = $this->input->post->get('booking', [], 'array');
        $this->app->setUserState('com_appointments.booking.' . $eventId, $data);

        if (!(new RateLimiter($db, $keys))->allow('booking', $this->input->server->getString('REMOTE_ADDR'), $now, 10, 3600)) {
            return $this->refuse($eventId, 'COM_APPOINTMENTS_BOOKING_ERROR_RATE');
        }

        if (!(new FormGuard($keys))->verify(
            $eventId, $this->input->post->getInt('rendered_at'), $sessionToken,
            $this->input->post->getString('form_signature'), $this->input->post->getString('website'),
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
