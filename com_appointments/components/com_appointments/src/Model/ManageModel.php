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
use Joomla\CMS\Uri\Uri;

defined('_JEXEC') or die;

class ManageModel extends BaseDatabaseModel
{
    public function action(): string
    {
        $action = Factory::getApplication()->getInput()->getCmd('action');
        if (!in_array($action, ['reschedule', 'cancel'], true)) {
            throw $this->invalid();
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

        if (!$booking || $booking->anonymised_utc !== null) {
            throw $this->invalid();
        }

        if ($this->links()->verify($id, $this->action(), $booking->modified_utc, $this->signature())) {
            return $booking;
        }

        // The cancellation made every earlier link stale. A reschedule or cancel link of the
        // version that was cancelled still identifies its holder, who already saw the appointment:
        // it opens the notice that the appointment is cancelled (cancelled()), never a form. An
        // older link, a tampered one and an unknown id stay one indistinguishable refusal.
        $cancelledVersion = (string) ($booking->cancelled_version_utc ?? '');

        if ((int) $booking->state === 2 && $cancelledVersion !== ''
            && $this->links()->verify($id, $this->action(), $cancelledVersion, $this->signature())) {
            return $booking;
        }

        throw $this->invalid();
    }

    /**
     * Whether the page of $booking only reports that the appointment is cancelled: a reschedule or
     * cancel link of a cancelled appointment that was not erased (cancelled by the host or an
     * administrator). The invitee's own cancellation erases the booking, so its links are refused.
     */
    public function cancelled(object $booking): bool
    {
        return (int) $booking->state === 2;
    }

    /**
     * What the receipt of this browser session shows of the completed change: title, start and
     * end in UTC, the zone they are shown in, the event type and whether the mails went out.
     *
     * A cancellation keeps these in the session, because its booking is erased at once; they are
     * no personal data. A reschedule keeps only the row version it produced, and the details are
     * read from the booking now, only while it still exists, is not erased and is still that
     * version. Otherwise (erased, deleted, changed since) the receipt is the generic outcome alone: empty.
     *
     * @return array{title?: string, start_utc?: string, end_utc?: string, timezone?: string, eventtype_id?: int, mailed?: bool}
     */
    public function receipt(): array
    {
        $app = Factory::getApplication();
        $receipt = $app->getUserState($this->receiptKey(), false);

        if ($this->action() === 'cancel' && is_array($receipt)) {
            $zone = (string) ($receipt['timezone'] ?? '');

            return [
                'title' => (string) ($receipt['title'] ?? ''),
                'start_utc' => (string) ($receipt['start_utc'] ?? ''),
                'end_utc' => (string) ($receipt['end_utc'] ?? ''),
                'timezone' => in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC',
                'eventtype_id' => (int) ($receipt['eventtype_id'] ?? 0),
                'mailed' => !empty($receipt['mailed']),
            ];
        }

        if (!is_array($receipt) || !is_string($receipt['modified_utc'] ?? null) || $receipt['modified_utc'] === '') {
            return [];
        }

        $db = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['b.eventtype_id', 'b.start_utc', 'b.end_utc', 'b.invitee_timezone', 'b.modified_utc', 'b.anonymised_utc', 'e.title']))
            ->from($db->quoteName('#__appointments_bookings', 'b'))
            ->join('INNER', $db->quoteName('#__appointments_eventtypes', 'e'), $db->quoteName('e.id') . ' = ' . $db->quoteName('b.eventtype_id'))
            ->where($db->quoteName('b.id') . ' = ' . $app->getInput()->getInt('id', 0));
        $booking = $db->setQuery($query)->loadObject();

        if (!$booking || $booking->anonymised_utc !== null || !hash_equals($receipt['modified_utc'], (string) $booking->modified_utc)) {
            return [];
        }

        $zone = (string) $booking->invitee_timezone;

        return [
            'title' => (string) $booking->title,
            'start_utc' => (string) $booking->start_utc,
            'end_utc' => (string) $booking->end_utc,
            'timezone' => in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC',
            'eventtype_id' => (int) $booking->eventtype_id,
            'mailed' => !empty($receipt['mailed']),
        ];
    }

    /**
     * The refusal of a link: 404 with one message for an unknown id, a wrong signature and a stale
     * link alike. The signature is taken out of the address Joomla's 404 handling sees (for example
     * «System - Redirect» collecting URLs), as the dispatcher does for a refused request.
     */
    private function invalid(): InvalidBookingLinkException
    {
        Uri::getInstance()->delVar('signature');

        return new InvalidBookingLinkException();
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

        if ($action === 'cancel') {
            $messageBooking = (int) $booking->state === 2 ? null
                : $store->changeState((int) $booking->id, 2, $now, $this->links(), $this->signature(), $reason);
            $purpose = 'cancellation';
        } else {
            if ($start === null) {
                throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_SLOT'));
            }
            $timezone = Factory::getApplication()->getInput()->post->getString('timezone', $booking->invitee_timezone);
            $messageBooking = $store->reschedule((int) $booking->id, $start, $now, $this->links(), $this->signature(), $timezone);
            $purpose = 'reschedule';
        }
        if (!empty($messageBooking['_conference_cleanup_error'])) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_CLEANUP_FAILED'), 'warning');
        }
        if (($messageBooking['conference_state'] ?? '') === 'failed') {
            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'), 'warning');
        }
        // The receipt belongs to this browser session. A reschedule keeps no detail of the appointment,
        // only the version it committed (not a later reread, which could already be someone else's
        // change): receipt() and receiptLinks() read the booking again and show it only while it is
        // still that version. A cancellation erases the booking right below, so its receipt keeps the
        // event type's title and the times, which are no personal data, and turns every earlier receipt
        // of this booking in this session into the generic outcome.
        if ($action === 'cancel') {
            $this->forgetReceipts((int) $booking->id);
            $receipt = ['title' => (string) $booking->title, 'start_utc' => (string) $booking->start_utc,
                'end_utc' => (string) $booking->end_utc, 'timezone' => (string) $booking->invitee_timezone,
                'eventtype_id' => (int) $booking->eventtype_id, 'mailed' => false];
        } else {
            $receipt = ['modified_utc' => (string) ($messageBooking['_rescheduled_version'] ?? ''), 'mailed' => false];
        }

        $this->rememberReceipt((int) $booking->id);
        Factory::getApplication()->setUserState($this->receiptKey(), $receipt);

        if ($messageBooking !== null && empty($messageBooking['_conference_missing']) && empty($messageBooking['_conference_error'])) {
            try {
                $notification->send($messageBooking, $purpose, $now);
                // Only now the receipt says that the confirmation is on its way.
                Factory::getApplication()->setUserState($this->receiptKey(), ['mailed' => true] + $receipt);
            } catch (NotificationException $e) {
                // The change is saved: a warning like the other partial outcomes on this page.
                Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_MANAGE_MAIL_FAILED'), 'warning');
            }
        } elseif (!empty($messageBooking['_conference_error'])) {
            Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_STORAGE_ERROR'), 'error');
        }

        if ($action === 'cancel') {
            $this->eraseCancelled((int) $booking->id, $now, $store);
        }
    }

    /**
     * The cancellation erases the invitee's data at once (erase mode of the configuration), after its
     * mails went out. The cancellation itself is saved: an erasure that fails is logged and the
     * retention task erases the booking later.
     */
    private function eraseCancelled(int $id, \DateTimeImmutable $now, BookingStore $store): void
    {
        try {
            $result = $store->erase($id, $now, 'cancellation', $this->deleteMode());

            if (!empty($result['_conference_cleanup_error'])) {
                Factory::getApplication()->enqueueMessage(Text::_('COM_APPOINTMENTS_CONFERENCE_CLEANUP_FAILED'), 'warning');
            }
        } catch (\Throwable $e) {
            \Joomla\CMS\Log\Log::add('Erasure after a cancellation failed: ' . $e->getMessage(), \Joomla\CMS\Log\Log::ERROR, 'com_appointments');
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

    /** The receipt keys of booking $id in this session, so an erasure can reach every one of them. */
    private function rememberReceipt(int $id): void
    {
        $app = Factory::getApplication();
        $index = 'com_appointments.receipts.' . $id;
        $keys = array_filter((array) $app->getUserState($index, []), 'is_string');
        $keys[] = $this->receiptKey();
        $app->setUserState($index, array_values(array_unique($keys)));
    }

    /** Every earlier receipt of booking $id in this session becomes the generic outcome alone. */
    private function forgetReceipts(int $id): void
    {
        $app = Factory::getApplication();

        foreach (array_filter((array) $app->getUserState('com_appointments.receipts.' . $id, []), 'is_string') as $key) {
            $app->setUserState($key, true);
        }
    }

    private function receiptKey(): string
    {
        return 'com_appointments.completed.' . hash('sha256', Factory::getApplication()->getInput()->getInt('id')
            . ':' . $this->action() . ':' . $this->signature());
    }
}
