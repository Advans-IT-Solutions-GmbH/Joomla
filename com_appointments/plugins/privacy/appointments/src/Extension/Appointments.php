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
use Advans\Component\Appointments\Administrator\Service\StaffPrivacy;
use Advans\Plugin\Privacy\Appointments\Team\TeamPrivacy;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Privacy\CanRemoveDataEvent;
use Joomla\CMS\Event\Privacy\ExportRequestEvent;
use Joomla\CMS\Event\Privacy\RemoveDataEvent;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\User\User;
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
        // Staff data of the account Joomla found (absences, host record, hosted event types,
        // owned schedules), whether or not the internet calendars are on. Joomla finds the account
        // with the database collation; only an exactly matching address makes it the requester's.
        $account = $event->getUser();
        $staff = $account !== null && (int) $account->id > 0 && (string) $account->email !== ''
            && PrivacyAddress::matches((string) $account->email, (string) $event->getRequest()->email)
            ? $this->staffDomains($account) : [];
        if ($staff !== []) {
            $event->addResult($staff);
        }
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
        } elseif ($user && (int) $user->id > 0) {
            // Joomla runs this check before every removal, while it still finds the account: the
            // removal and every retry of this request use the scope captured here, also after
            // Joomla has pseudonymised the account. Without a durable scope nothing is removed.
            try {
                $params = ComponentHelper::getParams('com_appointments');
                (new StaffPrivacy($this->getDatabase()))->capture((int) ($event->getRequest()->id ?? 0), (int) $user->id,
                    (array) $params->get('team_groups', []), (int) $params->get('external_group', 0),
                    new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
            } catch (\Throwable $e) {
                Log::add('Appointments: the privacy scope could not be stored (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
                $status->canRemove = false;
                $status->reason = Text::_('PLG_PRIVACY_APPOINTMENTS_SCOPE_FAILED');
            }
        }
        $event->addResult($status);
    }

    public function onPrivacyRemoveData(RemoveDataEvent $event): void
    {
        // The request address remains authoritative even if a preceding core
        // plugin has already pseudonymised the event's shared User object.
        $service = $this->service();
        $result = $service->remove((string) $event->getRequest()->email,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
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
        // The scope captured by the removal check; the account found now only for a request that
        // was checked before this version (Joomla still finds it unless a removal ran already).
        $requestId = (int) ($event->getRequest()->id ?? 0);
        $user = $event->getUser();
        try {
            $scope = (new StaffPrivacy($this->getDatabase()))->scope($requestId)
                ?? ($user !== null && (int) $user->id > 0 ? ['user_id' => (int) $user->id, 'team_member' => null] : null);
        } catch (\Throwable $e) {
            // Without the scope nothing of staff data is touched, and calendar entries are kept.
            Log::add('Appointments: the privacy scope could not be read (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
            $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_STAFF_FAILED'), 'warning');
            $scope = ['user_id' => 0, 'team_member' => true];
        }
        $this->removeStaffData((int) ($scope['user_id'] ?? 0));
        TeamPrivacy::forApplication($this->getApplication(), $this->getDatabase())
            ?->remove((string) $event->getRequest()->email, (int) ($scope['user_id'] ?? 0), $scope['team_member'] ?? null, $requestId);
    }

    /**
     * @return  Domain[]
     */
    private function staffDomains(?User $user): array
    {
        $userId = $user !== null ? (int) $user->id : 0;
        $domains = [];

        if ($userId <= 0) {
            return $domains;
        }

        $this->getApplication()->bootComponent('com_appointments');
        $labels = [
            'appointments_staff_absences'    => 'PLG_PRIVACY_APPOINTMENTS_STAFF_ABSENCES',
            'appointments_staff_host'        => 'PLG_PRIVACY_APPOINTMENTS_STAFF_HOST',
            'appointments_staff_event_types' => 'PLG_PRIVACY_APPOINTMENTS_STAFF_EVENT_TYPES',
            'appointments_staff_schedules'   => 'PLG_PRIVACY_APPOINTMENTS_STAFF_SCHEDULES',
        ];

        foreach ((new StaffPrivacy($this->getDatabase()))->export($userId) as $name => $rows) {
            $domain = new Domain();
            $domain->name = $name;
            $domain->description = Text::_($labels[$name]);

            foreach ($rows as $row) {
                $item = new Item();
                $item->id = (int) ($row['id'] ?? $row['user_id'] ?? 0);

                foreach ($row as $field => $value) {
                    $entry = new Field();
                    $entry->name = (string) $field;
                    $entry->value = $value === null ? '' : (string) $value;
                    $item->addField($entry);
                }

                $domain->addItem($item);
            }

            $domains[] = $domain;
        }

        return $domains;
    }

    /**
     * Staff data of the account: past absences go, current and future ones lose their reason,
     * the host feed credential is revoked. Event types and schedules of the person are team
     * configuration and are never changed automatically: the administrator gets them to review.
     */
    private function removeStaffData(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        $app = $this->getApplication();

        try {
            $result = (new StaffPrivacy($this->getDatabase()))->remove($userId, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        } catch (\Throwable $e) {
            Log::add('Appointments: the privacy removal of staff data failed (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
            $app->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_STAFF_FAILED'), 'warning');

            return;
        }

        $app->enqueueMessage(Text::sprintf('PLG_PRIVACY_APPOINTMENTS_STAFF_REMOVED', $result['absences_deleted'],
            $result['absences_cleared'], $result['host_revoked']), 'message');
        $escape = static fn (array $titles): string => implode(', ', array_map(
            static fn (string $title): string => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'), $titles));

        if ($result['event_types'] !== []) {
            $app->enqueueMessage(Text::sprintf('PLG_PRIVACY_APPOINTMENTS_STAFF_EVENT_TYPES_REVIEW', $escape($result['event_types'])), 'warning');
        }

        if ($result['schedules'] !== []) {
            $app->enqueueMessage(Text::sprintf('PLG_PRIVACY_APPOINTMENTS_STAFF_SCHEDULES_REVIEW', $escape($result['schedules'])), 'warning');
        }
    }

    private function service(): PrivacyService
    {
        $this->getApplication()->bootComponent('com_appointments');

        return new PrivacyService($this->getDatabase(), ComponentHelper::getParams('com_appointments'));
    }
}
