<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Administrator dashboard.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /**
     * @var  array{eventtypes:int, bookings:int}
     */
    protected $counts = ['eventtypes' => 0, 'bookings' => 0];
    protected $eventCounts = [];

    public function display($tpl = null)
    {
        $this->counts = $this->getModel()->getCounts();
        $this->eventCounts = $this->getModel()->getEventCounts();

        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_DASHBOARD_TITLE'), 'calendar');

        $toolbar = Toolbar::getInstance('toolbar');

        $toolbar->linkButton('schedules')
            ->url('index.php?option=com_appointments&view=schedules')
            ->icon('icon-calendar')
            ->text(Text::_('COM_APPOINTMENTS_TOOLBAR_SCHEDULES'));

        $toolbar->linkButton('blockeds')
            ->url('index.php?option=com_appointments&view=blockeds')
            ->icon('icon-minus-circle')
            ->text(Text::_('COM_APPOINTMENTS_TOOLBAR_BLOCKED'));

        if (Factory::getApplication()->getIdentity()->authorise('core.options', 'com_appointments')) {
            ToolbarHelper::preferences('com_appointments');
        }

        return parent::display($tpl);
    }
}
