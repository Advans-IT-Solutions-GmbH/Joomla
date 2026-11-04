<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Controller;

use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Advans\Component\Appointments\Administrator\Helper\BookingValidationException;
use Advans\Component\Appointments\Administrator\Helper\ConsentMode;
use Advans\Component\Appointments\Administrator\Helper\FormGuard;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\SlotTakenException;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Administrator\Service\FeedService;
use Advans\Component\Appointments\Administrator\Service\RateLimiter;
use Advans\Component\Appointments\Site\Helper\PrivacyLink;
use Joomla\CMS\Component\ComponentHelper;
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
        $event = $events->getItem($eventId);
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
            $this->input->post->getString('form_signature', ''), $this->input->post->getString('website', ''), $now
        )) {
            return $this->refuse($eventId, 'COM_APPOINTMENTS_BOOKING_ERROR_FORM');
        }

        $start = is_string($data['start'] ?? null) ? UtcTimestamp::parse($data['start']) : null;
        $fieldErrors = [];

        if ($start === null) {
            $fieldErrors['start'] = 'COM_APPOINTMENTS_BOOKING_ERROR_SLOT';
        }

        // The checkbox exists only in the acknowledge and consent modes; in the notice mode a
        // posted value is ignored and nothing is required or stored.
        $params = ComponentHelper::getParams('com_appointments');
        $consentMode = ConsentMode::fromParams($params);
        $proof = [];

        if (ConsentMode::requiresCheckbox($consentMode) && $this->input->post->getInt('consent') !== 1) {
            $fieldErrors['consent'] = $consentMode === ConsentMode::ACKNOWLEDGE
                ? 'COM_APPOINTMENTS_BOOKING_ERROR_ACKNOWLEDGE' : 'COM_APPOINTMENTS_BOOKING_ERROR_CONSENT';
        } elseif ($consentMode === ConsentMode::CONSENT) {
            // The version names the exact label the page showed: the same helper derives it there.
            $label = ConsentMode::labelText($consentMode, PrivacyLink::resolve($params) !== '',
                static fn (string $key): string => Text::_($key), Text::_('COM_APPOINTMENTS_BOOKING_PRIVACY_LINK'));
            $proof = [
                'consent_utc' => $now->format('Y-m-d H:i:s'),
                'consent_version' => ConsentMode::version($consentMode, $this->app->getLanguage()->getTag(), $label),
            ];
        }

        if ($fieldErrors !== []) {
            // Report the other fields in the same round trip, so the visitor corrects
            // everything at once. Nothing is saved on this path.
            try {
                (new BookingInput())->validate($data, $events->getFields($eventId), $this->app->getLanguage()->getTag(),
                    BookingInput::requiresPhone((string) $event->location_type),
                    BookingInput::maxGuests($params->get('max_guests', BookingInput::DEFAULT_MAX_GUESTS)), BookingInput::fixedDialCode($params));
            } catch (BookingValidationException $e) {
                if ($e->fieldErrors === []) {
                    return $this->refuse($eventId, $e->languageKey);
                }

                $fieldErrors += $e->fieldErrors;
            }

            return $this->refuse($eventId, '', $fieldErrors);
        }

        try {
            $booking = $this->getModel('Booking')->create($events, $eventId, $data, $start, $now, $proof);
        } catch (BookingValidationException $e) {
            return $this->refuse($eventId, $e->fieldErrors === [] ? $e->languageKey : '', $e->fieldErrors);
        } catch (SlotTakenException $e) {
            return $this->refuse($eventId, 'COM_APPOINTMENTS_BOOKING_ERROR_TAKEN');
        }

        $this->app->setUserState('com_appointments.booking.' . $eventId, null);
        $this->app->setUserState('com_appointments.booking_errors.' . $eventId, null);
        $this->app->setUserState('com_appointments.confirmation.id', (int) $booking['id']);
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=booking', false));

        return true;
    }

    /**
     * The calendar entry of the appointment on the confirmation page, as an .ics download.
     * Like that page it is only available to the browser session that made the booking; the
     * entry carries the same UID as the one attached to the confirmation email, so a calendar
     * that already holds the appointment updates it instead of adding a second one.
     */
    public function ics(): void
    {
        $booking = $this->getModel('Booking')->getItem();

        // Only the appointment the page showed: a link of an earlier confirmation in another tab is
        // stale once this session booked again, and never returns the newer appointment.
        if ($this->input->getInt('id', 0) !== (int) $booking->id || (int) $booking->state === 2) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        try {
            $calendar = (new FeedService(Factory::getContainer()->get(DatabaseInterface::class)))->export((int) $booking->id);
        } catch (\DomainException | \InvalidArgumentException $e) {
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
        // Visible fields get their message at the field (manage layout), hidden values
        // a message for the whole form. A valid reason is kept for the next attempt.
        // Kept until the next submission of this action, so the messages are still at their
        // fields when the visitor reloads the page.
        $errorsKey = 'com_appointments.manage_errors.' . $this->input->getInt('id') . '.' . $model->action();
        $this->app->setUserState($errorsKey, null);
        $fieldErrors = [];
        $reason = $this->input->post->get('reason', '', 'raw');
        if (!is_string($reason) || preg_match('//u', $reason) !== 1 || mb_strlen($reason) > 2000 || str_contains($reason, "\0")) {
            $fieldErrors['reason'] = 'COM_APPOINTMENTS_MANAGE_ERROR_REASON';
        } else {
            $this->app->setUserState('com_appointments.cancel_reason', $reason);
        }
        if ($model->action() === 'reschedule' && !in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_ZONE'), 'error');
            return false;
        }
        $start = UtcTimestamp::parse($this->input->post->getString('start', ''));
        if ($model->action() === 'reschedule' && $start === null) {
            $fieldErrors['start'] = 'COM_APPOINTMENTS_BOOKING_ERROR_SLOT';
        }
        if ($this->input->post->getInt('confirm') !== 1) {
            $fieldErrors['confirm'] = 'COM_APPOINTMENTS_MANAGE_ERROR_CONFIRM';
        }
        if ($fieldErrors !== []) {
            $this->app->setUserState($errorsKey, $fieldErrors);
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

    /**
     * $key is a message for the whole form, shown in the system message area.
     * $fieldErrors are shown at their fields by the booking layout.
     */
    private function refuse(int $eventId, string $key, array $fieldErrors = []): bool
    {
        if ($key !== '') {
            $this->app->enqueueMessage(Text::_($key), 'error');
        }

        $this->app->setUserState('com_appointments.booking_errors.' . $eventId, $fieldErrors);
        $data = (array) $this->app->getUserState('com_appointments.booking.' . $eventId, []);
        $start = is_string($data['start'] ?? null) ? UtcTimestamp::parse($data['start']) : null;
        $zone = is_string($data['timezone'] ?? null) ? $data['timezone'] : '';
        $url = 'index.php?option=com_appointments&view=eventtype&id=' . $eventId;
        // Back to the details of the same time, unless the time itself is the problem: then
        // the times of its day, refreshed, so the visitor chooses another one.
        $details = !isset($fieldErrors['start']) && $key !== 'COM_APPOINTMENTS_BOOKING_ERROR_TAKEN';

        if ($start !== null && in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
            $local = $start->setTimezone(new \DateTimeZone($zone));
            $url .= '&timezone=' . urlencode($zone) . '&month=' . $local->format('Y-m') . '&day=' . $local->format('Y-m-d')
                . ($details ? '&start=' . urlencode(UtcTimestamp::toSql($start)) : '');
        } else {
            $details = false;
        }

        // Field messages stand at their fields, so the page lands at the form instead of its
        // top. A message for the whole form is shown in the template's message area at the
        // top of the page, which the anchor would scroll out of view.
        $this->setRedirect(Route::_($url, false) . ($key === '' ? ($details ? '#appointment-details' : '#appointment-picker') : ''));

        return false;
    }
}
