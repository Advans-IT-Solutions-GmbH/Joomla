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
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Advans\Component\Appointments\Administrator\Helper\FormGuard;
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
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

    public function display($tpl = null)
    {
        $this->item = $this->getModel()->getItem();
        $this->fields = $this->getModel()->getFields((int) $this->item->id);
        $app = Factory::getApplication();
        $app->setHeader('Cache-Control', 'private, no-store', true);
        $this->params = ComponentHelper::getParams('com_appointments');
        $this->inputData = (array) $app->getUserState('com_appointments.booking.' . (int) $this->item->id, []);
        // Field messages of the last refused submission, shown once at their fields.
        $this->errors = array_filter((array) $app->getUserState('com_appointments.booking_errors.' . (int) $this->item->id, []), 'is_string');
        $app->setUserState('com_appointments.booking_errors.' . (int) $this->item->id, null);
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $zoneName = $app->getInput()->getString('timezone', (string) ($this->inputData['timezone'] ?? $this->item->timezone));

        if (!in_array($zoneName, \DateTimeZone::listIdentifiers(), true)) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_ZONE'), 400);
        }

        $this->zone = new \DateTimeZone($zoneName);
        $date = $app->getInput()->getString('month', $now->setTimezone($this->zone)->format('Y-m'));

        if (!UtcTimestamp::isDate($date . '-01')) {
            throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_DATE'), 400);
        }

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

        $this->renderedAt = $now->getTimestamp();
        $this->formSignature = (new FormGuard(new KeyProvider((string) $app->get('secret'))))->sign(
            (int) $this->item->id, $this->renderedAt, Session::getFormToken()
        );
        $assets = $app->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
        $assets->useStyle('com_appointments.site');
        $this->getDocument()->setMetaData('robots', 'noindex,nofollow');

        return parent::display($tpl);
    }
}
