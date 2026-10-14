<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\View\Manage;

use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $item;
    protected $action;
    protected $signature;
    protected $deleteMode;
    protected $completed;
    protected $slots = [];
    protected $month;
    protected $zone;
    protected $reason;

    public function display($tpl = null)
    {
        $app = Factory::getApplication();
        $app->setHeader('Referrer-Policy', 'no-referrer', true);
        $app->setHeader('X-Robots-Tag', 'noindex, nofollow', true);
        $app->setHeader('Cache-Control', 'private, no-store', true);
        $assets = $app->getDocument()->getWebAssetManager();
        $assets->getRegistry()->addExtensionRegistryFile('com_appointments');
        $assets->useStyle('com_appointments.site');
        $model = $this->getModel();
        $this->action = $model->action();
        $this->signature = $model->signature();
        $this->completed = $model->completed();
        $this->item = $model->getItem();
        $this->deleteMode = $model->deleteMode();
        $this->reason = (string) $app->getUserState('com_appointments.cancel_reason', '');

        if (!$this->completed && $this->action === 'reschedule') {
            $zone = $app->getInput()->getString('timezone', $this->item->invitee_timezone);
            if (!in_array($zone, \DateTimeZone::listIdentifiers(), true)) {
                throw new \InvalidArgumentException(Text::_('COM_APPOINTMENTS_BOOKING_ERROR_ZONE'), 400);
            }
            $this->zone = new \DateTimeZone($zone);
            $this->month = $app->getInput()->getString('month', UtcTimestamp::parse($this->item->start_utc)->setTimezone($this->zone)->format('Y-m'));
            $this->slots = $model->getSlots($this->item, $this->month, $zone, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        }

        return parent::display($tpl);
    }
}
