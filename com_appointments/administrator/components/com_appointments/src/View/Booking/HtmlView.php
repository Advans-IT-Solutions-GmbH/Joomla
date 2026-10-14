<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Booking;

use Advans\Component\Appointments\Administrator\Helper\PrivacyAddress;
use Advans\Component\Appointments\Administrator\Service\ConferenceService;
use Advans\Component\Appointments\Administrator\Service\PrivacyService;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $item;
    protected $answers = [];
    protected $hostUrl;

    public function display($tpl = null)
    {
        $this->item = $this->getModel()->getItem();
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_BOOKINGS_DETAIL'), 'calendar');
        Toolbar::getInstance('toolbar')->linkButton('back')->url('index.php?option=com_appointments&view=bookings')->text(Text::_('JTOOLBAR_BACK'));
        if ($this->item === null) {
            return parent::display($tpl);
        }
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $labels = (new PrivacyService($db, ComponentHelper::getParams('com_appointments')))->fieldLabels((int) $this->item->eventtype_id);
        foreach (PrivacyAddress::answers($this->item->custom_fields) as $id => $answer) {
            $this->answers[] = ['label' => $labels[$id] ?? (string) $id, 'value' => PrivacyAddress::readable($answer)];
        }
        $user = Factory::getApplication()->getIdentity();
        $this->hostUrl = (int) $user->id === (int) $this->item->host_user_id || $user->authorise('core.admin', 'com_appointments')
            ? ConferenceService::forComponent($db)->hostUrl((array) $this->item) : $this->item->conference_url;
        return parent::display($tpl);
    }
}
