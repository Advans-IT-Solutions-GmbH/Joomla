<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Manage;

use Advans\Component\Appointments\Administrator\Helper\RescheduleSlots;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Administrator\Service\InvalidBookingLinkException;
use Advans\Component\Appointments\Site\Helper\BackLink;
use Advans\Component\Appointments\Site\Helper\NoPageCache;
use Advans\Component\Appointments\Site\View\AssetsTrait;
use Advans\Component\Appointments\Site\View\PageTitleTrait;
use Advans\Component\Appointments\Site\View\ResultNoticesTrait;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;

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
    /** Month shown on the reschedule page (first day, in the displayed zone). */
    protected ?\DateTimeImmutable $month = null;
    protected $days = [];
    protected $day = '';
    protected $currentDay = '';
    protected $zone;
    protected $reason;
    protected $errors = [];
    /** The new time chosen on the reschedule page (UTC), null while the time is still being chosen. */
    protected ?\DateTimeImmutable $selected = null;
    /** Entries of an earlier submission shown again; the reschedule page has none (the booking partials read it). */
    protected array $inputData = [];
    /** Clock of the times shown, '24' or '12' (as on the booking page). */
    protected string $clock = '24';
    protected string $defaultClock = '24';
    protected string $timeFormat = 'H:i';
    /** New signed reschedule and cancel addresses on the receipt of a reschedule (ManageModel::receiptLinks). */
    protected array $manageLinks = [];
    /** Itemid of the page frame, kept by the GET form of the reschedule page; 0 for none. */
    protected int $itemId = 0;
    /** What the receipt shows of the changed appointment (ManageModel::receipt()); empty for none. */
    protected array $receipt = [];
    /** Whether the link only reports that the appointment is cancelled (ManageModel::cancelled()), without a form. */
    protected bool $cancelled = false;
    /** Whether the visitor has selected a day in the reschedule calendar. */
    protected bool $dayChosen = false;
    /** Whether the previous month lies in the past of the displayed time zone. */
    protected bool $isFirstMonth = false;
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
            $this->prepareReschedule();
            // The same booking layout, calendar and zone field as the first booking, with its script.
            $this->useAppointmentsAssets(true);
        }

        return parent::display($tpl);
    }

    /**
     * The date-and-time part of the reschedule page: the data the partials of the booking page
     * (tmpl/eventtype/default_picker.php, default_zone.php, default_zone_note.php) read, so the
     * reschedule page shows the same calendar, times and time-zone field as the first booking.
     * Also the data of the background update (format=raw, RawView).
     */
    protected function prepareReschedule(): void
    {
        $app = Factory::getApplication();
        $input = $app->getInput();
        $model = $this->getModel();
        $this->addTemplatePath(JPATH_SITE . '/components/com_appointments/tmpl/eventtype');
        $zoneName = $input->getString('timezone', $this->zone->getName());

        if (!in_array($zoneName, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_ZONE'), 400);
        }

        $this->zone = new \DateTimeZone($zoneName);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $current = UtcTimestamp::parse((string) $this->item->start_utc);

        if ($current === null) {
            throw new \RuntimeException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_DATE'), 500);
        }

        $startValue = trim($input->getString('start', ''));
        $requested = $startValue === '' ? null : UtcTimestamp::parse($startValue);

        if ($startValue !== '' && $requested === null) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_SLOT'), 400);
        }

        $currentMonth = $now->setTimezone($this->zone)->format('Y-m');
        $month = $requested !== null ? $requested->setTimezone($this->zone)->format('Y-m') : $input->getString('month', '');

        if ($month === '') {
            $month = max($currentMonth, $current->setTimezone($this->zone)->format('Y-m'));
        }

        if (!UtcTimestamp::isDate($month . '-01')) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_DATE'), 400);
        }

        $this->isFirstMonth = $month <= $currentMonth;
        $this->month = new \DateTimeImmutable($month . '-01 00:00:00', $this->zone);
        $firstDay = new \DateTimeImmutable($month . '-01', new \DateTimeZone('UTC'));

        for ($offset = 0; $offset < (int) $firstDay->format('t'); $offset++) {
            $this->days[] = $firstDay->modify('+' . $offset . ' days')->format('Y-m-d');
        }

        $this->currentDay = $current->setTimezone($this->zone)->format('Y-m-d');
        $this->day = $requested !== null ? $requested->setTimezone($this->zone)->format('Y-m-d') : $input->getString('day', '');
        $this->dayChosen = $this->day !== '';

        if ($this->dayChosen && (!UtcTimestamp::isDate($this->day) || substr($this->day, 0, 7) !== $month)) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_DATE'), 400);
        }

        if (!$this->dayChosen) {
            $this->day = $month . '-01';
        }

        $this->slots = RescheduleSlots::byLocalDay($model->getSlots($this->item, $month, $zoneName, $now), $current, $this->zone);

        if ($requested !== null) {
            foreach ($this->slots[$this->day] ?? [] as $slot) {
                if ($slot == $requested) {
                    $this->selected = $slot;
                }
            }

            // A time that was taken since the link was made: the times of its day again, with the reason.
            if ($this->selected === null) {
                $this->errors['start'] ??= 'COM_APPOINTMENTS_BOOKING_ERROR_TAKEN';
            }
        }

        $this->defaultClock = Text::_('COM_APPOINTMENTS_CLOCK_DEFAULT') === '12' ? '12' : '24';
        $clock = $input->getString('clock', '');
        $this->clock = in_array($clock, ['12', '24'], true) ? $clock : $this->defaultClock;
        $this->timeFormat = Text::_($this->clock === '12' ? 'COM_APPOINTMENTS_DATE_FORMAT_TIME_12' : 'COM_APPOINTMENTS_DATE_FORMAT_TIME');
    }

    /**
     * Routed address of this page plus $query, escaped for an attribute; the same helper as the
     * booking page's, so the shared partials work unchanged.
     *
     * @param   array<string, string>  $query     Further parameters of the address.
     * @param   string                 $fragment  Anchor, without "#".
     */
    public function bookingLink(array $query = [], string $fragment = ''): string
    {
        $parameters = array_merge($this->pageQuery(), ['timezone' => $this->zone->getName()],
            $this->clock !== $this->defaultClock ? ['clock' => $this->clock] : [], $query);

        return $this->escape(Route::_('index.php?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986), false))
            . ($fragment !== '' ? '#' . $fragment : '');
    }

    /**
     * Target of a GET form on this page: the address without its query plus the query as hidden
     * fields (a browser replaces the query of a GET form's action).
     *
     * @return  array{0: string, 1: array<string, string>}  Unescaped action and hidden fields.
     */
    public function formTarget(): array
    {
        $fields = [];

        foreach ($this->pageQuery() as $key => $value) {
            $fields[(string) $key] = (string) $value;
        }

        return [Route::_('index.php', false), $fields];
    }

    /** @return array<string, string|int> The parameters that name this signed page. */
    private function pageQuery(): array
    {
        $query = [
            'option' => 'com_appointments',
            'view' => 'manage',
            'id' => (int) $this->item->id,
            'action' => (string) $this->action,
            'signature' => (string) $this->signature,
        ];

        if ($this->itemId > 0) {
            $query['Itemid'] = $this->itemId;
        }

        return $query;
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
