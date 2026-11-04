<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Model;

use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Advans\Component\Appointments\Administrator\Service\BookingStore;
use Advans\Component\Appointments\Administrator\Service\ConferenceService;
use Advans\Component\Appointments\Administrator\Service\NotificationService;
use Advans\Component\Appointments\Administrator\Service\NotificationException;
use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

defined('_JEXEC') or die;

class BookingModel extends BaseDatabaseModel
{
    /**
     * $proof is the consent record of the consent mode (consent_utc, consent_version), stored
     * with the booking; empty in the other modes.
     */
    public function create(EventtypeModel $events, int $eventId, array $input, \DateTimeImmutable $start, \DateTimeImmutable $now,
        array $proof = []): array
    {
        $app = Factory::getApplication();
        $event = $events->getItem($eventId);
        $params = ComponentHelper::getParams('com_appointments');
        $invitee = (new BookingInput())->validate($input, $events->getFields($eventId), $app->getLanguage()->getTag(),
            BookingInput::requiresPhone((string) $event->location_type),
            BookingInput::maxGuests($params->get('max_guests', BookingInput::DEFAULT_MAX_GUESTS)), BookingInput::fixedDialCode($params));
        $invitee += array_intersect_key($proof, array_flip(['consent_utc', 'consent_version']));

        $booking = (new BookingStore($this->getDatabase()))->create(
            (array) $event, $invitee, $start, $now, $app->getIdentity()->getAuthorisedViewLevels(), $app->getLanguage()->getTag()
        );
        $booking = ConferenceService::forComponent($this->getDatabase())->provision($booking, $now);
        if (!isset($booking['id']) && (!empty($booking['_conference_missing']) || !empty($booking['_conference_error']))) {
            $app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'), 'warning');
            throw new \RuntimeException(Text::_(!empty($booking['_conference_error'])
                ? 'COM_APPOINTMENTS_CONFERENCE_STORAGE_ERROR' : 'JERROR_PAGE_NOT_FOUND'), !empty($booking['_conference_error']) ? 503 : 404);
        }

        // The confirmation page states a missing conference link itself, from the stored
        // conference state, so the visitor sees it once and still after a reload. A message
        // is only added when that state could not be stored.
        if (($booking['conference_state'] ?? '') !== 'failed'
            && (!empty($booking['_conference_missing']) || !empty($booking['_conference_error']))) {
            $app->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'), 'warning');
        }

        try {
            if (empty($booking['_conference_error']) && empty($booking['_conference_missing']) && $booking['anonymised_utc'] === null) {
                $purpose = match ((int) $booking['state']) {
                    0 => 'pending', 1 => 'confirmation', 2 => 'cancellation',
                    default => throw new \RuntimeException('Invalid saved booking state.'),
                };
                (new NotificationService($this->getDatabase()))->send($booking, $purpose, $now);
            }
        } catch (NotificationException $e) {
            // The booking is saved, so this is a warning like the missing conference link,
            // not an error: both messages of the same kind look the same in every template.
            $app->enqueueMessage(Text::_('COM_APPOINTMENTS_BOOKING_MAIL_FAILED'), 'warning');
        }

        return $booking;
    }

    public function getItem(): object
    {
        $id = (int) Factory::getApplication()->getUserState('com_appointments.confirmation.id', 0);

        if ($id < 1) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(array_merge(['b.id', 'b.start_utc', 'b.end_utc', 'b.state', 'b.invitee_timezone', 'b.conference_state', 'b.modified_utc'],
            \Advans\Component\Appointments\Administrator\Helper\BookingData::readColumns('b'))))
            ->select($db->quoteName('e.title'))->select($db->quoteName('e.location_text'))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $id)
            ->where($db->quoteName('b.anonymised_utc') . ' IS NULL');
        $item = $db->setQuery($query)->loadObject();

        if (!$item) {
            throw new \RuntimeException(Text::_('JERROR_PAGE_NOT_FOUND'), 404);
        }

        // The conference link is encrypted at rest; one that cannot be decrypted is not shown.
        $item = \Advans\Component\Appointments\Administrator\Helper\BookingData::fromApplication()->openObject($item);
        // The page shows the conference link only; the other personal fields were read to open the row.
        foreach (array_diff(\Advans\Component\Appointments\Administrator\Helper\BookingData::readColumns(), ['conference_url']) as $column) {
            unset($item->$column);
        }

        return $item;
    }
}
