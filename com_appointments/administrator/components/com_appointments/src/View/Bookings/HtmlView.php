<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Bookings;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $items = [];
    protected $pagination;
    protected $state;
    protected $events = [];

    /** Bookings of 1.3 still in clear text after the migration window. */
    protected $unverified = 0;

    public function display($tpl = null)
    {
        $model = $this->getModel();
        $user = \Joomla\CMS\Factory::getApplication()->getIdentity();
        // An administrator's visit continues the migration (team-calendars.md, 5.10), never depending on the Scheduler.
        $this->unverified = \Advans\Component\Appointments\Administrator\Model\DashboardModel::continueMigration();
        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();
        $this->events = $model->authorisedEvents();

        if ($model->getErrors()) {
            throw new \RuntimeException(implode("\n", $model->getErrors()), 500);
        }

        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_BOOKINGS_TITLE'), 'calendar');
        $toolbar = Toolbar::getInstance('toolbar');
        $toolbar->standardButton('approve')->text(Text::_('COM_APPOINTMENTS_BOOKINGS_APPROVE'))->task('bookings.approve')->listCheck(true);
        $toolbar->standardButton('cancel')->text(Text::_('COM_APPOINTMENTS_BOOKINGS_CANCEL'))->task('bookings.cancel')->listCheck(true);
        $toolbar->standardButton('retry')->text(Text::_('COM_APPOINTMENTS_CONFERENCE_RETRY'))->task('bookings.retryConference')->listCheck(true);
        $toolbar->standardButton('export')->text(Text::_('COM_APPOINTMENTS_BOOKINGS_CSV'))->task('bookings.exportCsv');
        ToolbarHelper::deleteList(Text::_('COM_APPOINTMENTS_BOOKINGS_ERASE_CONFIRM'), 'bookings.erase', 'COM_APPOINTMENTS_BOOKINGS_ERASE');
        if ($this->unverified > 0) {
            \Joomla\CMS\Factory::getApplication()->enqueueMessage(Text::sprintf('COM_APPOINTMENTS_TEAM_E_BOOKINGS_UNVERIFIED_NOTICE', $this->unverified), 'warning');

            if ($user->authorise('core.admin', 'com_appointments')) {
                $toolbar->standardButton('encrypt')->icon('icon-lock')->text(Text::_('COM_APPOINTMENTS_TEAM_E_BOOKINGS_ENCRYPT'))->task('bookings.encrypt');
            }
        }

        $toolbar->linkButton('dashboard')->url('index.php?option=com_appointments')->text(Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'));

        return parent::display($tpl);
    }
}
