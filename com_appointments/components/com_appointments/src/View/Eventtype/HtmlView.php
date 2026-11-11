<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Eventtype;

use Joomla\CMS\Factory;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Advans\Component\Appointments\Administrator\Helper\ConsentMode;
use Advans\Component\Appointments\Administrator\Helper\FormGuard;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Site\Helper\PrivacyLink;
use Advans\Component\Appointments\Site\View\AssetsTrait;
use Advans\Component\Appointments\Site\View\PageTitleTrait;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;
    use AssetsTrait;

    /**
     * Flag before a fixed calling code: the image of Joomla's language switcher
     * (media/mod_languages/images/<name>.gif) for the calling codes that belong to one country.
     * Codes without an entry, or a site without the image, show the code alone.
     */
    private const DIAL_FLAGS = [
        '27' => 'af_za', '30' => 'el_gr', '31' => 'nl_nl', '32' => 'nl_be', '33' => 'fr_fr', '34' => 'es_es',
        '36' => 'hu_hu', '39' => 'it_it', '40' => 'ro_ro', '41' => 'ch', '43' => 'at', '44' => 'en_gb',
        '45' => 'da_dk', '46' => 'sv_se', '47' => 'nb_no', '48' => 'pl_pl', '49' => 'de_de', '55' => 'pt_br',
        '57' => 'es_co', '60' => 'ms_my', '61' => 'en_au', '62' => 'id_id', '64' => 'en_nz', '66' => 'th_th',
        '81' => 'ja_jp', '82' => 'ko_kr', '84' => 'vi_vn', '86' => 'zh_cn', '90' => 'tr_tr', '91' => 'hi_in',
        '92' => 'ur_pk', '94' => 'si_lk', '98' => 'fa_ir', '254' => 'sw_ke', '351' => 'pt_pt', '352' => 'de_lu',
        '353' => 'ga_ie', '354' => 'is_is', '355' => 'sq_al', '358' => 'fi_fi', '359' => 'bg_bg', '370' => 'lt_lt',
        '371' => 'lv_lv', '372' => 'et_ee', '374' => 'hy_am', '375' => 'be_by', '380' => 'uk_ua', '381' => 'sr_rs',
        '382' => 'srp_me', '385' => 'hr_hr', '386' => 'sl_si', '389' => 'mk_mk', '420' => 'cs_cz', '421' => 'sk_sk',
        '423' => 'de_li', '852' => 'hk_hk', '855' => 'km_kh', '856' => 'lo_la', '886' => 'zh_tw', '972' => 'he_il',
        '976' => 'mn_mn', '993' => 'tk_tm', '994' => 'az_az', '995' => 'ka_ge', '998' => 'uz_uz',
    ];

    protected $item;
    protected $fields = [];
    protected $slots = [];
    protected $zone;
    protected $month;
    protected $day;
    protected $days = [];
    protected $inputData = [];
    protected $errors = [];
    protected bool $focusSystemMessage = false;
    protected $renderedAt;
    protected $formSignature;
    protected $params;
    /** Whether the month before the shown one lies entirely in the past of the displayed zone. */
    protected bool $isFirstMonth = false;
    /** The fixed telephone field: shown and required for both telephone location types. */
    protected bool $phoneRequired = false;
    /** Calling code preselected in every telephone field, or '' for none. */
    protected string $defaultDialCode = '';
    /** Address of the privacy notice for the consent label, or '' for none. */
    protected string $privacyLink = '';
    /**
     * The step of the booking page: 'time' (date and time) or 'details' (the visitor's
     * details for the chosen time). The confirmation is the booking view.
     */
    protected string $step = 'time';
    /** Whether the visitor picked the day: only then the times of the day are shown. */
    protected bool $dayChosen = false;
    /** The chosen time in UTC on the details step, otherwise null. */
    protected ?\DateTimeImmutable $selected = null;
    /** Layout of the information column: the sub-layout default_info_<name>.php. */
    protected string $infoLayout = 'logo';
    /** The title of the event type above the card instead of in the information column. */
    protected bool $titleAbove = false;
    /** Clock of the times shown: '24' or '12', from the address or the language (COM_APPOINTMENTS_CLOCK_DEFAULT). */
    protected string $clock = '24';
    /** The clock of the language, which the addresses leave out. */
    protected string $defaultClock = '24';
    /** Pattern of a time of day in the chosen clock (date() letters). */
    protected string $timeFormat = 'H:i';
    /** Most further guests per booking; 0 shows no guest field. */
    protected int $maxGuests = 0;
    /** The only calling code of the telephone fields, or '' when the visitor chooses one. */
    protected string $fixedDialCode = '';
    /** Address of the flag before the fixed calling code, or '' for none. */
    protected string $dialFlag = '';
    /**
     * Whether bookings are switched off because no valid public site URL is configured: every
     * booking sends mails with links, and the component sends none then (fail closed). The page
     * answers 503 with a neutral notice and is kept out of every cache.
     */
    public static function unavailable(): bool
    {
        if (\Advans\Component\Appointments\Administrator\Helper\PublicSiteUrl::current() !== null) {
            return false;
        }

        $app = Factory::getApplication();
        $app->setHeader('status', '503', true);
        $app->setHeader('Retry-After', '3600', true);
        $app->setHeader('Cache-Control', 'private, no-store', true);
        \Advans\Component\Appointments\Site\Helper\NoPageCache::apply($app);

        return true;
    }

    /** How the details step asks about the privacy notice: ConsentMode::NOTICE, ACKNOWLEDGE or CONSENT. */
    protected string $consentMode = 'notice';
    /** Version of the checkbox label shown (ConsentMode::version), '' without a checkbox; the controller compares it. */
    protected string $consentVersion = '';

    public function display($tpl = null)
    {
        if (self::unavailable()) {
            // No form at all: a booking without its mails is never taken (layout «unavailable»).
            $this->setLayout('unavailable');
            $this->getDocument()->setMetaData('robots', 'noindex,nofollow');

            return parent::display($tpl);
        }

        $this->prepare(true);
        $app = Factory::getApplication();
        $this->renderedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        $this->formSignature = (new FormGuard(new KeyProvider((string) $app->get('secret'))))->sign(
            (int) $this->item->id, $this->renderedAt, Session::getFormToken()
        );
        $this->privacyLink = PrivacyLink::resolve($this->params);
        // Progressive enhancement only: without the script every step is a normal link or form.
        $this->useAppointmentsAssets(true);
        $this->getDocument()->setMetaData('robots', 'noindex,nofollow');
        $this->applyPageTitle('eventtype', (string) $this->item->title, (int) $this->item->id);

        return parent::display($tpl);
    }

    /**
     * Everything the page and the date-and-time part (`default_picker.php`) need. The
     * refusal messages of the last submission are shown once, so only the full page
     * consumes them; the background update of the times leaves them alone.
     */
    protected function prepare(bool $consumeErrors): void
    {
        $this->item = $this->getModel()->getItem();
        $this->fields = $this->getModel()->getFields((int) $this->item->id);
        $app = Factory::getApplication();
        $app->setHeader('Cache-Control', 'private, no-store', true);
        $this->params = ComponentHelper::getParams('com_appointments');
        $this->inputData = (array) $app->getUserState('com_appointments.booking.' . (int) $this->item->id, []);

        if ($consumeErrors) {
            // Field messages of the last refused submission, shown once at their fields.
            $this->errors = array_filter((array) $app->getUserState('com_appointments.booking_errors.' . (int) $this->item->id, []), 'is_string');
            $app->setUserState('com_appointments.booking_errors.' . (int) $this->item->id, null);
            $messageKey = 'com_appointments.booking_focus_message.' . (int) $this->item->id;
            $this->focusSystemMessage = (bool) $app->getUserState($messageKey, false);
            $app->setUserState($messageKey, null);

            if ($this->focusSystemMessage) {
                $this->getDocument()->addScriptOptions('com_appointments.focusSystemMessage', true);
            }
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $zoneName = $app->getInput()->getString('timezone', (string) ($this->inputData['timezone'] ?? $this->item->timezone));

        if (!in_array($zoneName, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_ZONE'), 400);
        }

        $this->zone = new \DateTimeZone($zoneName);
        $input = $app->getInput();
        // The chosen time (step 2) names its own day; month and day of the address only
        // apply to the date-and-time step.
        $startValue = trim($input->getString('start', ''));
        $requested = $startValue === '' ? null : UtcTimestamp::parse($startValue);

        if ($startValue !== '' && $requested === null) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_SLOT'), 400);
        }

        $local = $requested?->setTimezone($this->zone);
        $currentMonth = $now->setTimezone($this->zone)->format('Y-m');
        $date = $local !== null ? $local->format('Y-m') : $input->getString('month', $currentMonth);

        if (!UtcTimestamp::isDate($date . '-01')) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_DATE'), 400);
        }

        $this->isFirstMonth = $date <= $currentMonth;
        $this->month = new \DateTimeImmutable($date . '-01 00:00:00', $this->zone);
        $monthEnd = $this->month->modify('+1 month');
        $slots = $this->getModel()->getSlots(
            $now, $this->month->setTimezone(new \DateTimeZone('UTC')), $monthEnd->setTimezone(new \DateTimeZone('UTC'))
        );

        foreach ($slots as $slot) {
            $this->slots[$slot->setTimezone($this->zone)->format('Y-m-d')][] = $slot;
        }

        $today = $now->setTimezone($this->zone)->format('Y-m-d');
        $day = $local !== null ? $local->format('Y-m-d') : $input->getString('day', '');
        // Without a chosen day the calendar stands alone; the first bookable day is only the
        // day the time-zone form keeps.
        $this->dayChosen = $day !== '';
        $this->day = $day !== '' ? $day : (isset($this->slots[$today]) ? $today : (array_key_first($this->slots) ?? $date . '-01'));

        if (!UtcTimestamp::isDate($this->day) || substr($this->day, 0, 7) !== $date) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_DATE'), 400);
        }

        if ($requested !== null) {
            foreach ($this->slots[$this->day] ?? [] as $slot) {
                if ($slot == $requested) {
                    $this->selected = $slot;
                }
            }

            // A time that was taken or has passed since the link was made: the times of its
            // day again, with the reason at the times instead of an error page.
            if ($this->selected === null) {
                $this->errors['start'] ??= 'COM_APPOINTMENTS_BOOKING_ERROR_TAKEN';
            }
        }

        $this->step = $this->selected !== null ? 'details' : 'time';
        $layout = (string) ($this->item->info_layout ?? '');
        $this->infoLayout = in_array($layout, ['logo', 'person', 'event'], true) ? $layout : 'logo';
        $this->titleAbove = (string) ($this->item->info_title ?? '') === 'above';

        // Iterate Gregorian dates independently of time-zone gaps.
        $calendar = new \DateTimeImmutable($date . '-01', new \DateTimeZone('UTC'));
        for ($i = 0; $i < (int) $calendar->format('t'); $i++) {
            $this->days[] = $calendar->modify('+' . $i . ' days')->format('Y-m-d');
        }

        $this->phoneRequired = BookingInput::requiresPhone((string) $this->item->location_type);
        $dialCode = trim((string) $this->params->get('phone_default_country', ''), " +\t");
        $this->defaultDialCode = in_array($dialCode, BookingInput::DIAL_CODES, true) ? $dialCode : '';
        $this->fixedDialCode = BookingInput::fixedDialCode($this->params);
        $flag = self::DIAL_FLAGS[$this->fixedDialCode] ?? '';

        if ($flag !== '' && is_file(JPATH_ROOT . '/media/mod_languages/images/' . $flag . '.gif')) {
            $this->dialFlag = Uri::root(true) . '/media/mod_languages/images/' . $flag . '.gif';
        }

        $this->maxGuests = BookingInput::maxGuests($this->params->get('max_guests', BookingInput::DEFAULT_MAX_GUESTS));
        $this->consentMode = ConsentMode::fromParams($this->params);

        if (ConsentMode::requiresCheckbox($this->consentMode)) {
            $this->consentVersion = ConsentMode::version($this->consentMode, $app->getLanguage()->getTag(), ConsentMode::labelText(
                $this->consentMode, PrivacyLink::resolve($this->params) !== '',
                static fn (string $key): string => Text::_($key), Text::_('COM_APPOINTMENTS_BOOKING_PRIVACY_LINK')
            ));
        }

        // The clock of the language unless the visitor switched it; the addresses only carry a switched clock.
        $this->defaultClock = Text::_('COM_APPOINTMENTS_CLOCK_DEFAULT') === '12' ? '12' : '24';
        $clock = $input->getString('clock', '');
        $this->clock = in_array($clock, ['12', '24'], true) ? $clock : $this->defaultClock;
        $this->timeFormat = Text::_($this->clock === '12' ? 'COM_APPOINTMENTS_DATE_FORMAT_TIME_12' : 'COM_APPOINTMENTS_DATE_FORMAT_TIME');
    }

    /**
     * Address of this booking page in the displayed zone plus $query (for example month, day
     * or start), routed through the menu item and escaped for an attribute. Every step is a
     * plain link like this one, so it works without JavaScript and survives a reload.
     *
     * @param   array<string, string>  $query     Further parameters of the address.
     * @param   string                 $fragment  Anchor the browser lands at, without "#".
     */
    public function bookingLink(array $query = [], string $fragment = ''): string
    {
        $url = 'index.php?option=com_appointments&view=eventtype&id=' . (int) $this->item->id
            . '&timezone=' . urlencode($this->zone->getName())
            . ($this->clock !== $this->defaultClock ? '&clock=' . $this->clock : '');

        foreach ($query as $key => $value) {
            $url .= '&' . urlencode((string) $key) . '=' . urlencode((string) $value);
        }

        return Route::_($url) . ($fragment !== '' ? '#' . $fragment : '');
    }

    /**
     * Target of a GET form on this page: the routed address without its query, plus the
     * query as hidden fields. A browser replaces the query of a GET form's action with the
     * form's fields, so a non-SEF address keeps its option, view and id only this way.
     *
     * @return  array{0: string, 1: array<string, string>}  Unescaped action and hidden fields.
     */
    public function formTarget(): array
    {
        $action = Route::_('index.php?option=com_appointments&view=eventtype&id=' . (int) $this->item->id, false);
        $fields = [];
        $position = strpos($action, '?');

        if ($position !== false) {
            parse_str(substr($action, $position + 1), $query);
            $action = substr($action, 0, $position);

            foreach ($query as $key => $value) {
                if (is_string($value)) {
                    $fields[(string) $key] = $value;
                }
            }
        }

        return [$action, $fields];
    }

    /**
     * Address, width and height of an image chosen in a media field of the event type, or
     * null for none. A relative path is made absolute, so it also resolves below a menu
     * item's address; the address is unescaped.
     *
     * @return  array{src: string, width: int, height: int}|null
     */
    public function infoImage(string $value): ?array
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $image = HTMLHelper::_('cleanImageURL', $value);
        // The media field appends "#joomlaImage://…" without size for some files; it is no part of the file's address.
        $src = trim((string) preg_replace('/#.*$/s', '', (string) ($image->url ?? '')));

        if ($src === '') {
            return null;
        }

        if (!preg_match('#^(?:[a-z][a-z0-9+.-]*:|/)#i', $src)) {
            $src = Uri::root(true) . '/' . ltrim($src, '/');
        }

        return [
            'src' => $src,
            'width' => (int) ($image->attributes['width'] ?? 0),
            'height' => (int) ($image->attributes['height'] ?? 0),
        ];
    }
}
