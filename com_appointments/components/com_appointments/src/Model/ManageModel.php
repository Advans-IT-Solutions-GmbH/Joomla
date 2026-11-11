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
        $id = $app->getInput()->getInt('id', 0);
        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select('b.*')->select($db->quoteName('e.title'))->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $id);
        $booking = $db->setQuery($query)->loadObject();
        // Personal fields are encrypted at rest; one that cannot be decrypted is shown empty.
        $booking = $booking ? \Advans\Component\Appointments\Administrator\Helper\BookingData::fromApplication()->openObject($booking) : null;

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
        // The receipt belongs to this browser session. After a reschedule it also keeps the version
        // this reschedule committed (not a later reread, which could already be someone else's
        // change), for which receiptLinks() shows the new signed links.
        Factory::getApplication()->setUserState($this->receiptKey(), $action === 'reschedule'
            ? ['modified_utc' => (string) ($messageBooking['_rescheduled_version'] ?? '')] : true);

        if ($messageBooking !== null && empty($messageBooking['_conference_missing']) && empty($messageBooking['_conference_error'])) {
            try {
                $notification->send($messageBooking, $purpose, $now, $action === 'erase');
            } catch (NotificationException $e) {
                // The change is saved: a warning like the other partial outcomes on this page.
                Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_MANAGE_MAIL_FAILED'), 'warning');
            }
        } elseif (!empty($messageBooking['_conference_error'])) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_STORAGE_ERROR'), 'error');
        }
    }

    /**
     * The new signed reschedule and cancel addresses on the receipt of a reschedule, like the
     * confirmation page of a booking shows them: only to the browser session that made the change
     * (its receipt), only while the appointment is still the version this change produced, and
     * never for a cancelled or erased appointment. Empty otherwise, or without a valid public site
     * address (the links are then only in the mail).
     *
     * @return array{reschedule_url?: string, cancel_url?: string}
     */
    public function receiptLinks(): array
    {
        $app = Factory::getApplication();
        $receipt = $app->getUserState($this->receiptKey(), false);

        if ($this->action() !== 'reschedule' || !is_array($receipt) || !is_string($receipt['modified_utc'] ?? null)
            || $receipt['modified_utc'] === '') {
            return [];
        }

        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['id', 'eventtype_id', 'state', 'modified_utc', 'anonymised_utc']))
            ->from($db->quoteName('#__appointments_bookings'))
            ->where($db->quoteName('id') . ' = ' . $app->getInput()->getInt('id', 0));
        $booking = $db->setQuery($query)->loadObject();

        if (!$booking || $booking->anonymised_utc !== null || !in_array((int) $booking->state, [0, 1], true)
            || !hash_equals($receipt['modified_utc'], (string) $booking->modified_utc)) {
            return [];
        }

        try {
            $links = (new NotificationService($db))->links(['id' => (int) $booking->id, 'eventtype_id' => (int) $booking->eventtype_id,
                'modified_utc' => (string) $booking->modified_utc], $app->getLanguage()->getTag());
        } catch (NotificationException $e) {
            return [];
        }

        return array_intersect_key($links, array_flip(['reschedule_url', 'cancel_url']));
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
