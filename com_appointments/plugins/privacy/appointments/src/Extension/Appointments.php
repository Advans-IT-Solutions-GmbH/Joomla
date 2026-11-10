<?php
/**
 * @package     Appointments Privacy Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\Appointments\Extension;

use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Advans\Component\Appointments\Administrator\Service\PrivacyService;
use Advans\Component\Appointments\Administrator\Service\PrivacyOperationLock;
use Advans\Component\Appointments\Administrator\Service\PrivacyOutcome;
use Advans\Component\Appointments\Administrator\Service\PrivacyStaffCleanup;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Plugin\Privacy\Appointments\Team\TeamPrivacy;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Privacy\CanRemoveDataEvent;
use Joomla\CMS\Event\Privacy\ExportRequestEvent;
use Joomla\CMS\Event\Privacy\RemoveDataEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Component\Privacy\Administrator\Export\Domain;
use Joomla\Component\Privacy\Administrator\Export\Field;
use Joomla\Component\Privacy\Administrator\Export\Item;
use Joomla\Component\Privacy\Administrator\Removal\Status;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\SubscriberInterface;

defined('_JEXEC') or die;

final class Appointments extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    /** @var array<int, PrivacyOperationLock> */
    private array $locks = [];

    public static function getSubscribedEvents(): array
    {
        return [
            'onPrivacyExportRequest' => 'onPrivacyExportRequest',
            'onPrivacyCanRemoveData' => 'onPrivacyCanRemoveData',
            'onPrivacyRemoveData' => 'onPrivacyRemoveData',
        ];
    }

    public function onPrivacyExportRequest(ExportRequestEvent $event): void
    {
        $service = $this->service();
        $domain = new Domain();
        $domain->name = 'appointments_bookings';
        $domain->description = Text::_('PLG_PRIVACY_APPOINTMENTS_DOMAIN');
        foreach ($service->bookings((string) $event->getRequest()->email) as $booking) {
            $item = new Item();
            $item->id = (int) $booking['id'];
            foreach ($booking as $name => $value) {
                $field = new Field();
                $field->name = $name;
                $field->value = $value;
                $item->addField($field);
            }
            $labels = $service->fieldLabels((int) $booking['eventtype_id']);
            foreach (PrivacyAddress::answers($booking['custom_fields']) as $id => $answer) {
                $field = new Field();
                $field->name = 'custom_answer_' . $id;
                $field->value = (isset($labels[$id]) ? $labels[$id] . ': ' : '')
                    . PrivacyAddress::readable($answer);
                $item->addField($field);
            }
            $domain->addItem($item);
        }
        // Bookings of other people in which the address is an additional guest: only what
        // concerns the guest (their address, the event type and the time), never the data of
        // the person who booked or of the other guests.
        $guestDomain = new Domain();
        $guestDomain->name = 'appointments_guest_invitations';
        $guestDomain->description = Text::_('PLG_PRIVACY_APPOINTMENTS_GUEST_DOMAIN');
        foreach ($service->guestBookings((string) $event->getRequest()->email) as $invitation) {
            $item = new Item();
            $item->id = $invitation['id'];
            foreach (['guest_email', 'event_title', 'start_utc', 'end_utc', 'state'] as $name) {
                $field = new Field();
                $field->name = $name;
                $field->value = $invitation[$name];
                $item->addField($field);
            }
            $guestDomain->addItem($item);
        }
        // No invitee information lives in the erasure audit, and staff actor IDs
        // must not be exposed in an invitee's domain.
        $event->addResult([$domain, $guestDomain]);
        // Booking rows that could not be decrypted: the export says it is incomplete, with the count only.
        $incomplete = TeamPrivacy::unreadableBookings($service, $this->getApplication());
        if ($incomplete !== null) {
            $event->addResult([$incomplete]);
        }
        // Internet calendars: entries found by their address, and staff data.
        $team = TeamPrivacy::forApplication($this->getApplication(), $this->getDatabase());
        if ($team !== null) {
            $event->addResult($team->export((string) $event->getRequest()->email, $event->getUser()));
        }
    }

    public function onPrivacyCanRemoveData(CanRemoveDataEvent $event): void
    {
        $service = $this->service();
        $status = new Status();
        $address = (string) $event->getRequest()->email;
        $user = $event->getUser();
        if ($user && !PrivacyAddress::matches((string) $user->email, $address)) {
            $status->canRemove = false;
            $status->reason = Text::_('PLG_PRIVACY_APPOINTMENTS_ACCOUNT_MISMATCH');
        } elseif ($service->legalReason() !== '' && $service->hasBookings($address)) {
            $status->canRemove = false;
            $status->reason = Text::sprintf('PLG_PRIVACY_APPOINTMENTS_LEGAL_BLOCK', $service->legalReason());
        }
        if ($status->canRemove && (int) $event->getRequest()->id > 0) {
            $requestId = (int) $event->getRequest()->id;
            $reason = $this->lock($event->getRequest());
            if ($reason !== '') {
                $status->canRemove = false;
                $status->reason = Text::_($reason);
                $event->addResult($status);
                return;
            }
            try {
                $this->staffScope($requestId)->remember($address, $user);
            } catch (\Throwable $error) {
                Log::add('Appointment staff privacy scope could not be captured (' . get_class($error) . ').',
                    Log::WARNING, 'com_appointments');
                $this->release($requestId);
                $status->canRemove = false;
                $status->reason = Text::_('PLG_PRIVACY_APPOINTMENTS_STAFF_SCOPE_FAILED');
            }
        }
        $event->addResult($status);
    }

    public function onPrivacyRemoveData(RemoveDataEvent $event): void
    {
        $requestId = (int) $event->getRequest()->id;
        // Held from CanRemove until the outcome is recorded, so Core Complete cannot pass its
        // check between an earlier success marker and this attempt's outcome.
        if ($requestId > 0 && ($reason = $this->lock($event->getRequest())) !== '') {
            $this->getApplication()->enqueueMessage(Text::_($reason), 'warning');
            return;
        }
        try {
            $this->removeAttempt($event, $requestId);
        } finally {
            $this->release($requestId);
        }
    }

    private function removeAttempt(RemoveDataEvent $event, int $requestId): void
    {
        // Invalidate both success markers of an earlier attempt independently before any
        // cleanup: the pending attempt alone keeps Complete blocked if the outcome write fails.
        $failure = null;
        $scope = null;
        if ($requestId > 0) {
            try {
                $scope = $this->staffScope($requestId);
                $scope->beginAttempt();
            } catch (\Throwable $error) {
                $failure = $error;
            }
        }
        try {
            PrivacyOutcome::record($this->getDatabase(), $requestId, false);
        } catch (\Throwable $error) {
            $failure ??= $error;
        }
        try {
            if ($failure !== null) {
                throw $failure;
            }
            $this->removeData($event, $scope);
        } catch (\Throwable $error) {
            // A failed listener must not starve later plugins after Core has changed the account.
            Log::add('Appointment privacy removal failed (' . get_class($error) . ').',
                Log::ERROR, 'com_appointments');
            $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_INCOMPLETE'), 'warning');
        }
    }

    private function removeData(RemoveDataEvent $event, ?PrivacyStaffCleanup $staffScope): void
    {
        // The request address remains authoritative even if a preceding core
        // plugin has already pseudonymised the event's shared User object.
        $service = $this->service();
        $requestId = (int) $event->getRequest()->id;
        $staffId = $staffScope !== null ? $staffScope->userId() : (int) ($event->getUser()->id ?? 0);
        $result = $service->remove((string) $event->getRequest()->email,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $requestId);
        TeamPrivacy::unreadableBookings($service, $this->getApplication());
        $this->getApplication()->enqueueMessage(Text::sprintf('PLG_PRIVACY_APPOINTMENTS_REMOVED', $result['removed']), 'message');
        if ($result['guest_removed']) {
            $this->getApplication()->enqueueMessage(Text::sprintf('PLG_PRIVACY_APPOINTMENTS_GUEST_REMOVED', $result['guest_removed']), 'message');
        }
        if ($result['mail_failed']) {
            $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_MAIL_FAILED'), 'warning');
        }
        if ($result['cleanup_failed']) {
            $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_CLEANUP_FAILED'), 'warning');
        }
        $team = TeamPrivacy::forApplication($this->getApplication(), $this->getDatabase());
        $complete = $result['cleanup_failed'] === 0 && $service->unreadable() === 0;
        if ($team !== null) {
            $complete = $team->remove((string) $event->getRequest()->email, $event->getUser(), $staffId) && $complete;
            if ($staffScope !== null && $staffId > 0 && $team->staffRemoved()) {
                try {
                    $staffScope->finish();
                } catch (\Throwable $error) {
                    Log::add('Appointment staff privacy cleanup could not be verified (' . get_class($error) . ').',
                        Log::WARNING, 'com_appointments');
                    $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_STAFF_SCOPE_FAILED'), 'warning');
                    $complete = false;
                }
            }
        } else {
            $db = $this->getDatabase();
            $calendars = (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__appointments_calendars'))->loadResult();
            $userId = $staffId;
            $staffRows = 0;
            if ($userId > 0) {
                foreach (['user_keys', 'devices', 'calendar_key_wraps', 'calendar_grants', 'invitations'] as $name) {
                    $staffRows += (int) $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName('#__appointments_' . $name)
                        . ' WHERE user_id = ' . $userId)->loadResult();
                }
            }
            if ($calendars > 0 || $staffRows > 0 || ($staffScope !== null && $staffId > 0
                && $staffScope->staffPending())) {
                $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_DISABLED_REMAINDER'), 'warning');
                $complete = false;
            }
        }
        if ($complete) {
            $staffScope?->finishAttempt();
        }
        PrivacyOutcome::record($this->getDatabase(), $requestId, $complete);
        if (!$complete) {
            $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_INCOMPLETE'), 'warning');
        }
    }

    /** @return string empty when this plugin holds the request's operation lock and the request is unchanged */
    private function lock(object $request): string
    {
        $requestId = (int) $request->id;
        if (isset($this->locks[$requestId])) {
            return '';
        }
        $this->getApplication()->bootComponent('com_appointments');
        $db = $this->getDatabase();
        try {
            $lock = PrivacyOperationLock::acquire($db, $requestId, (string) $this->getApplication()->get('secret'));
            if ($lock !== null && PrivacyOperationLock::state($db, $requestId)
                !== ['status' => (int) $request->status, 'type' => (string) $request->request_type]) {
                $lock->release();
                return 'PLG_PRIVACY_APPOINTMENTS_CHANGED';
            }
        } catch (\Throwable $error) {
            Log::add('Appointment privacy operation lock failed (' . get_class($error) . ').', Log::WARNING, 'com_appointments');
            $lock = null;
        }
        if ($lock === null) {
            return 'PLG_PRIVACY_APPOINTMENTS_BUSY';
        }
        $this->locks[$requestId] = $lock;
        return '';
    }

    private function release(int $requestId): void
    {
        $lock = $this->locks[$requestId] ?? null;
        unset($this->locks[$requestId]);
        $lock?->release();
    }

    private function staffScope(int $requestId): PrivacyStaffCleanup
    {
        return new PrivacyStaffCleanup($this->getDatabase(),
            new KeyProvider((string) $this->getApplication()->get('secret')), $requestId);
    }

    private function service(): PrivacyService
    {
        $this->getApplication()->bootComponent('com_appointments');

        return new PrivacyService($this->getDatabase(), ComponentHelper::getParams('com_appointments'));
    }
}
