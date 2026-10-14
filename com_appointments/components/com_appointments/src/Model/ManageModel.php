<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Model;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\SignedLink;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Administrator\Service\AvailabilityService;
use Advans\Component\Appointments\Administrator\Service\BookingStore;
use Advans\Component\Appointments\Administrator\Service\InvalidBookingLinkException;
use Advans\Component\Appointments\Administrator\Service\NotificationException;
use Advans\Component\Appointments\Administrator\Service\NotificationService;
use Advans\Component\Appointments\Administrator\Service\RateLimiter;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Model\BaseDatabaseModel;

defined('_JEXEC') or die;

class ManageModel extends BaseDatabaseModel
{
    public function action(): string
    {
        $action = Factory::getApplication()->getInput()->getCmd('action');
        if (!in_array($action, ['reschedule', 'cancel', 'erase'], true)) {
            throw new InvalidBookingLinkException();
        }

        return $action;
    }

    public function signature(): string
    {
        return Factory::getApplication()->getInput()->getString('signature', '');
    }

    public function completed(): bool
    {
        return (bool) Factory::getApplication()->getUserState($this->receiptKey(), false);
    }

    public function getItem(): ?object
    {
        if ($this->completed()) {
            return null;
        }
        $app = Factory::getApplication();
        $id = $app->getInput()->getInt('id');
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('b.*')->select($db->quoteName('e.title'))->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $id);
        $booking = $db->setQuery($query)->loadObject();

        if (!$booking || !$this->links()->verify($id, $this->action(), $booking->modified_utc, $this->signature())
            || $booking->anonymised_utc !== null) {
            throw new InvalidBookingLinkException();
        }

        return $booking;
    }

    public function deleteMode(): bool
    {
        return match (ComponentHelper::getParams('com_appointments')->get('erase_mode', 'anonymise')) {
            'anonymise' => false,
            'delete' => true,
            default => throw new \RuntimeException(Text::_('COM_APPOINTMENTS_MANAGE_ERROR_CONFIGURATION')),
        };
    }

    public function getSlots(object $booking, string $month, string $timezone, \DateTimeImmutable $now): array
    {
        if (!UtcTimestamp::isDate($month . '-01') || !in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_DATE'), 400);
        }
        $app = Factory::getApplication();
        $keys = new KeyProvider((string) $app->get('secret'));
        if (!(new RateLimiter($this->getDatabase(), $keys))->allow('slots', $app->getInput()->server->getString('REMOTE_ADDR'), $now, 60, 60)) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_RATE'), 429);
        }
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('e.*')->from($db->quoteName('#__appointments_eventtypes', 'e'))
            ->join('INNER', $db->quoteName('#__appointments_schedules', 's'), $db->quoteName('s.id') . ' = ' . $db->quoteName('e.schedule_id'))
            ->where($db->quoteName('e.id') . ' = ' . (int) $booking->eventtype_id)
            ->where($db->quoteName('e.host_user_id') . ' = ' . (int) $booking->host_user_id)
            ->where($db->quoteName('e.published') . ' = 1')->where($db->quoteName('s.published') . ' = 1');
        $event = $db->setQuery($query)->loadAssoc();
        if (!$event || !in_array((int) $booking->state, [0, 1], true) || UtcTimestamp::parse($booking->start_utc) <= $now) {
            return [];
        }
        $from = new \DateTimeImmutable($month . '-01 00:00:00', new \DateTimeZone($timezone));
        $to = $from->modify('+1 month');

        return (new AvailabilityService($db))->slots($event, $now, $from, $to, (int) $booking->id);
    }

    public function apply(\DateTimeImmutable $now, ?\DateTimeImmutable $start = null, string $reason = ''): void
    {
        if ($this->completed()) {
            return;
        }
        $booking = $this->getItem();
        $action = $this->action();
        $store = new BookingStore($this->getDatabase());
        $notification = new NotificationService($this->getDatabase());

        if ($action === 'erase') {
            $result = $store->erase((int) $booking->id, $now, 'self_service', $this->deleteMode(), null, $this->links(), $this->signature());
            $messageBooking = $result['notification'];
            $purpose = 'erasure_notice';
        } elseif ($action === 'cancel') {
            $messageBooking = (int) $booking->state === 2 ? null
                : $store->changeState((int) $booking->id, 2, $now, $this->links(), $this->signature(), $reason);
            $purpose = 'cancellation';
        } else {
            if ($start === null) {
                throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_SLOT'));
            }
            $timezone = Factory::getApplication()->getInput()->getString('timezone', $booking->invitee_timezone);
            $messageBooking = $store->reschedule((int) $booking->id, $start, $now, $this->links(), $this->signature(), $timezone);
            $purpose = 'reschedule';
        }
        $outcome = $action === 'erase' ? $result : $messageBooking;
        if (!empty($outcome['_conference_cleanup_error'])) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_CLEANUP_FAILED'), 'warning');
        }
        if (($messageBooking['conference_state'] ?? '') === 'failed') {
            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'), 'warning');
        }
        Factory::getApplication()->setUserState($this->receiptKey(), true);

        if ($messageBooking !== null && empty($messageBooking['_conference_missing']) && empty($messageBooking['_conference_error'])) {
            try {
                $notification->send($messageBooking, $purpose, $now, $action === 'erase');
            } catch (NotificationException $e) {
                Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_MANAGE_MAIL_FAILED'), 'error');
            }
        } elseif (!empty($messageBooking['_conference_error'])) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_STORAGE_ERROR'), 'error');
        }
    }

    private function links(): SignedLink
    {
        return new SignedLink(new KeyProvider((string) Factory::getApplication()->get('secret')));
    }

    private function receiptKey(): string
    {
        return 'com_appointments.completed.' . hash('sha256', Factory::getApplication()->getInput()->getInt('id')
            . ':' . $this->action() . ':' . $this->signature());
    }
}
