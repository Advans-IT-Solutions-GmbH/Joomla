<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Manage;

use Advans\Component\Appointments\Administrator\Service\InvalidBookingLinkException;
use Advans\Component\Appointments\Site\Helper\BackLink;
use Advans\Component\Appointments\Site\Helper\NoPageCache;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Site\View\AssetsTrait;
use Advans\Component\Appointments\Site\View\PageTitleTrait;
use Advans\Component\Appointments\Site\View\ResultNoticesTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;
    use AssetsTrait;
    use ResultNoticesTrait;

    protected $item;
    protected $action;
    protected $signature;
    protected $deleteMode;
    protected $completed;
    protected $slots = [];
    protected $month;
    protected $zone;
    protected $reason;
    protected $errors = [];
    /** New signed reschedule and cancel addresses on the receipt of a reschedule (ManageModel::receiptLinks). */
    protected array $manageLinks = [];
    /** Itemid of the page frame, kept by the GET form of the reschedule page; 0 for none. */
    protected int $itemId = 0;
    /** What the receipt shows of the changed appointment (ManageModel::receipt()); empty for none. */
    protected array $receipt = [];
    /** Whether the link only reports that the appointment is cancelled (ManageModel::cancelled()), without a form. */
    protected bool $cancelled = false;
    /** Booking page of the event type after a cancellation (BackLink::booking()), '' for none. */
    protected string $bookAgain = '';

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('Cache-Control', 'private, no-store', true);
        // Nor Joomla's page cache: the page carries signed links and tokens of one browser session.
        NoPageCache::apply($app);
        $model = $this->getModel();

        try {
            $this->action = $model->action();
            $this->signature = $model->signature();
            $this->completed = $model->completed();
            // The item first: an invalid link ends here with the refusal, before anything else.
            $this->item = $model->getItem();
        } catch (InvalidBookingLinkException $e) {
            return $this->displayInvalid();
        }
        $this->cancelled = !$this->completed && $model->cancelled($this->item);
        // A page that reports a cancellation (its receipt, or a link of a cancelled appointment)
        // says so in its heading; every other page names the action of its link.
        $this->applyPageTitle('manage', Text::_($this->cancelled || ($this->completed && $this->action === 'cancel')
            ? 'COM_APPOINTMENTS_BOOKING_CANCELLED_TITLE'
            : ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULE', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCEL', 'erase' => 'COM_APPOINTMENTS_MANAGE_ERASE'][$this->action]));
        $focusKey = 'com_appointments.manage_focus_message.' . $app->getInput()->getInt('id') . '.' . $this->action;
        $focusMessage = !$this->completed && (bool) $app->getUserState($focusKey, false);
        $app->setUserState($focusKey, null);
        // Ordinary forms keep native field autofocus; only outcomes and page-level refusals need the script.
        $this->useAppointmentsAssets($this->completed || $focusMessage);

        if ($focusMessage) {
            $this->getDocument()->addScriptOptions('com_appointments.focusSystemMessage', true);
        }

        if ($this->completed) {
            $this->takeResultNotices();
            $this->manageLinks = $model->receiptLinks();
            $this->receipt = $model->receipt();
        }

        // After a cancellation the way on is a new booking of the same event type.
        $eventtypeId = $this->cancelled ? (int) $this->item->eventtype_id
            : ($this->completed && $this->action === 'cancel' ? (int) ($this->receipt['eventtype_id'] ?? 0) : 0);
        $this->bookAgain = $eventtypeId > 0 ? BackLink::booking($eventtypeId) : '';

        $active = $app->getMenu()->getActive();
        $this->itemId = $active !== null && ($active->query['option'] ?? '') === 'com_appointments' ? (int) $active->id : 0;
        $this->deleteMode = $model->deleteMode();
        $this->reason = (string) $app->getUserState('com_appointments.cancel_reason', '');
        // Field messages of the last refused submission of this action. They stay until the
        // next submission, so a reload keeps them at their fields.
        $this->errors = array_filter((array) $app->getUserState('com_appointments.manage_errors.' . $app->getInput()->getInt('id') . '.' . $this->action, []), 'is_string');

        // Times are shown in the invitee's zone, like on the booking page; a receipt in the zone the
        // change was made in. On the reschedule page the zone the visitor selects applies to all times.
        $invitee = $this->completed ? (string) ($this->receipt['timezone'] ?? '') : (string) $this->item->invitee_timezone;
        $this->zone = new \DateTimeZone(in_array($invitee, \DateTimeZone::listIdentifiers(), true) ? $invitee : 'UTC');

        if (!$this->completed && !$this->cancelled && $this->action === 'reschedule') {
            $zone = $app->getInput()->getString('timezone', $this->zone->getName());
            if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_ZONE'), 400);
            }
            $this->zone = new \DateTimeZone($zone);
            $this->month = $app->getInput()->getString('month', UtcTimestamp::parse($this->item->start_utc)->setTimezone($this->zone)->format('Y-m'));
            $this->slots = $model->getSlots($this->item, $this->month, $zone, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }

        return parent::display($tpl);
    }

    /**
     * The page of a signed link that opens nothing: an earlier link of an appointment that was
     * rescheduled or cancelled since, a used erase link, a tampered link or an unknown appointment.
     * One page for all of them, still with status 404 and the same text, so it does not tell which
     * applies (the privacy boundary of the signed pages). It explains that only the links of the
     * newest mail apply and offers a new booking (BackLink::again(), from the address alone).
     */
    private function displayInvalid()
    {
        $app = Factory::getApplication();
        $app->setHeader('status', '404', true);
        $this->applyPageTitle('manage', Text::_('COM_APPOINTMENTS_MANAGE_INVALID_TITLE'));
        $this->useAppointmentsAssets(false);
        $this->bookAgain = BackLink::again();
        $this->setLayout('invalid');

        return parent::display();
    }
}
