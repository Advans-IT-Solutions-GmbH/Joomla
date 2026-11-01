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
use Joomla\CMS\Language\Associations;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Advans\Component\Appointments\Administrator\Helper\BookingInput;
use Advans\Component\Appointments\Administrator\Helper\FormGuard;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Site\View\PageTitleTrait;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    use PageTitleTrait;

    protected $item;
    protected $fields = [];
    protected $slots = [];
    protected $zone;
    protected $month;
    protected $day;
    protected $days = [];
    protected $inputData = [];
    protected $errors = [];
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

    public function display($tpl = null)
    {
        $this->prepare(true);
        $app = Factory::getApplication();
        $this->renderedAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        $this->formSignature = (new FormGuard(new KeyProvider((string) $app->get('secret'))))->sign(
            (int) $this->item->id, $this->renderedAt, Session::getFormToken()
        );
        $this->privacyLink = $this->resolvePrivacyLink();
        $assets = $app->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
        $assets->useStyle('com_appointments.site');
        // Progressive enhancement only: without the script every step is a normal link or form.
        $assets->useScript('com_appointments.booking');
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
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $zoneName = $app->getInput()->getString('timezone', (string) ($this->inputData['timezone'] ?? $this->item->timezone));

        if (!in_array($zoneName, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_ZONE'), 400);
        }

        $this->zone = new \DateTimeZone($zoneName);
        $currentMonth = $now->setTimezone($this->zone)->format('Y-m');
        $date = $app->getInput()->getString('month', $currentMonth);

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
        $this->day = $app->getInput()->getString('day', isset($this->slots[$today]) ? $today : (array_key_first($this->slots) ?? $date . '-01'));

        if (!UtcTimestamp::isDate($this->day) || substr($this->day, 0, 7) !== $date) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_DATE'), 400);
        }

        // Iterate Gregorian dates independently of time-zone gaps.
        $calendar = new \DateTimeImmutable($date . '-01', new \DateTimeZone('UTC'));
        for ($i = 0; $i < (int) $calendar->format('t'); $i++) {
            $this->days[] = $calendar->modify('+' . $i . ' days')->format('Y-m-d');
        }

        $this->phoneRequired = BookingInput::requiresPhone((string) $this->item->location_type);
        $dialCode = trim((string) $this->params->get('phone_default_country', ''), " +\t");
        $this->defaultDialCode = in_array($dialCode, BookingInput::DIAL_CODES, true) ? $dialCode : '';
    }

    /**
     * The privacy notice the consent label links to: the configured menu item (in the
     * visitor's language when the item has a language association), else the configured
     * address. A menu item the visitor cannot open is never linked.
     */
    private function resolvePrivacyLink(): string
    {
        $app = Factory::getApplication();
        $itemId = (int) $this->params->get('privacy_menuitem', 0);

        if ($itemId > 0) {
            if (Associations::isEnabled()) {
                $associated = Associations::getAssociations('com_menus', '#__menu', 'com_menus.item', $itemId, 'id', '', '');
                $itemId = (int) ($associated[$app->getLanguage()->getTag()]->id ?? $itemId);
            }

            $menuItem = $app->getMenu()->getItem($itemId);

            if ($menuItem !== null && in_array((int) $menuItem->access, array_map('intval', $app->getIdentity()->getAuthorisedViewLevels()), true)) {
                $anchor = trim((string) $this->params->get('privacy_anchor', ''), " #\t");

                return Route::_('index.php?Itemid=' . $itemId) . ($anchor !== '' ? '#' . rawurlencode($anchor) : '');
            }
        }

        return trim((string) $this->params->get('privacy_url', ''));
    }
}
