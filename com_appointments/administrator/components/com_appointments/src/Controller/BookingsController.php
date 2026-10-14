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
use Advans\Component\Appointments\Administrator\Service\ConferenceService;
use Advans\Component\Appointments\Administrator\Helper\SlotTakenException;
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

    public function exportCsv(): void
    {
        $this->requireEditRequest();
        $model = $this->getModel('Bookings', 'Administrator', ['ignore_request' => false]);
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $columns = ['id', 'eventtype_title', 'start_utc', 'end_utc', 'state', 'invitee_name', 'invitee_email',
            'invitee_phone', 'guest_emails', 'custom_fields', 'sequence', 'conference_state', 'conference_error', 'anonymised_utc'];
        $output = tmpfile();
        if ($output === false) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKINGS_EXPORT_FAILED'), 503);
        }
        try {
            $db->transactionStart();
            try {
                if (fputcsv($output, $columns, ',', '"', '') === false) {
                    throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKINGS_EXPORT_FAILED'), 503);
                }
                foreach ($model->exportRows() as $row) {
                    $values = array_map(static fn ($key) =>
                        \Advans\Component\Appointments\Administrator\Helper\CsvCell::safe(isset($row[$key]) ? (string) $row[$key] : null), $columns);
                    if (fputcsv($output, $values, ',', '"', '') === false) {
                        throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKINGS_EXPORT_FAILED'), 503);
                    }
                }
                $db->transactionCommit();
            } catch (\Throwable $e) {
                $db->transactionRollback();
                throw $e;
            }
            if (!rewind($output)) {
                throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKINGS_EXPORT_FAILED'), 503);
            }
            $this->app->setHeader('Content-Type', 'text/csv; charset=utf-8', true);
            $this->app->setHeader('Content-Disposition', 'attachment; filename="appointments.csv"', true);
            $this->app->setHeader('Cache-Control', 'private, no-store', true);
            $this->app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
            $this->app->sendHeaders();
            if (fpassthru($output) === false) {
                throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKINGS_EXPORT_FAILED'), 503);
            }
        } finally {
            fclose($output);
        }
        $this->app->close();
    }

    public function reschedule(): bool
    {
        $this->requireEditRequest();
        $id = $this->input->post->getInt('id');
        if ($id < 1) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKINGS_START_INVALID'), 400);
        }
        $model = $this->getModel('Booking', 'Administrator', ['ignore_request' => true]);
        $model->getItem($id);
        $value = $this->input->post->getString('start', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/D', $value) !== 1) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKINGS_START_INVALID'), 400);
        }
        $value = str_replace('T', ' ', $value) . (strlen($value) === 16 ? ':00' : '');
        $start = \Advans\Component\Appointments\Administrator\Helper\UtcTimestamp::parse($value);
        if ($start === null) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKINGS_START_INVALID'), 400);
        }
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=booking&id=' . $id, false));
        try {
            $booking = (new BookingStore($db))->reschedule($id, $start, $now);
        } catch (SlotTakenException $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_SLOT_TAKEN'), 'warning');
            return false;
        } catch (\DomainException $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_START_INVALID'), 'warning');
            return false;
        }
        if (!empty($booking['_conference_error']) || !empty($booking['_conference_missing'])) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_STORAGE_ERROR'), 'error');
            return false;
        }
        if (!empty($booking['_conference_cleanup_error'])) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_CLEANUP_FAILED'), 'warning');
        }
        if (($booking['conference_state'] ?? '') === 'failed') {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'), 'warning');
        }
        try {
            (new NotificationService($db))->send($booking, (int) $booking['state'] === 2 ? 'cancellation' : 'reschedule', $now);
        } catch (NotificationException $e) {
            $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_MAIL_FAILED'), 'error');
            return false;
        }
        $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_RESCHEDULED'), 'message');
        return true;
    }

    private function requireEditRequest(): void
    {
        $token = Session::getFormToken();
        if ($this->input->getMethod() !== 'POST' || $this->input->post->get($token, '', 'alnum') !== '1') {
            throw new \RuntimeException(Text::_('JINVALID_TOKEN'), 403);
        }
        if (!$this->app->getIdentity()->authorise('core.manage', 'com_appointments')) {
            throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }
    }

    public function retryConference(): bool
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
        $rows = [];
        foreach ($ids as $id) {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $booking = $db->setQuery($query->select('*')->from($db->quoteName('#__appointments_bookings'))
                ->where($db->quoteName('id') . ' = ' . $id))->loadAssoc();
            if (!$booking || !$user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $booking['eventtype_id'])) {
                throw new \RuntimeException(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }
            $rows[] = $booking;
        }
        $this->setRedirect(Route::_('index.php?option=com_appointments&view=bookings', false));
        $success = true;
        foreach ($rows as $booking) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
            $booking = ConferenceService::forComponent($db)->provision($booking, $now, true);
            if (empty($booking['_conference_changed']) || !empty($booking['_conference_missing']) || !empty($booking['_conference_error'])) {
                $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'), 'warning');
                $success = false;
                continue;
            }
            try {
                (new NotificationService($db))->send($booking, 'reschedule', $now);
                $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_RETRIED'), 'message');
            } catch (NotificationException $e) {
                $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKINGS_MAIL_FAILED'), 'error');
                $success = false;
            }
        }

        return $success;
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
            if (!empty($result['_conference_cleanup_error'])) {
                $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_CLEANUP_FAILED'), 'warning');
            }
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
                if (!empty($booking['_conference_cleanup_error'])) {
                    $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_CLEANUP_FAILED'), 'warning');
                }
                if (!empty($booking['_conference_error'])) {
                    $this->app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_STORAGE_ERROR'), 'error');
                }
                if (!empty($booking['_changed']) && empty($booking['_conference_missing']) && empty($booking['_conference_error'])) {
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
