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
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Event\Privacy\CanRemoveDataEvent;
use Joomla\CMS\Event\Privacy\ExportRequestEvent;
use Joomla\CMS\Event\Privacy\RemoveDataEvent;
use Joomla\CMS\Language\Text;
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
        // No invitee information lives in the erasure audit, and staff actor IDs
        // must not be exposed in an invitee's domain.
        $event->addResult([$domain]);
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
        } elseif ($service->legalReason() !== '') {
            foreach ($service->bookings($address) as $booking) {
                $status->canRemove = false;
                $status->reason = Text::sprintf('PLG_PRIVACY_APPOINTMENTS_LEGAL_BLOCK', $service->legalReason());
                break;
            }
        }
        $event->addResult($status);
    }

    public function onPrivacyRemoveData(RemoveDataEvent $event): void
    {
        // The request address remains authoritative even if a preceding core
        // plugin has already pseudonymised the event's shared User object.
        $result = $this->service()->remove((string) $event->getRequest()->email,
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->getApplication()->enqueueMessage(Text::sprintf('PLG_PRIVACY_APPOINTMENTS_REMOVED', $result['removed']), 'message');
        if ($result['mail_failed']) {
            $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_MAIL_FAILED'), 'warning');
        }
        if ($result['cleanup_failed']) {
            $this->getApplication()->enqueueMessage(Text::_('PLG_PRIVACY_APPOINTMENTS_CLEANUP_FAILED'), 'warning');
        }
    }

    private function service(): PrivacyService
    {
        $this->getApplication()->bootComponent('com_appointments');

        return new PrivacyService($this->getDatabase(), ComponentHelper::getParams('com_appointments'));
    }
}
