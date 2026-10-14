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

    public function display($tpl = null)
    {
        $model = $this->getModel();
        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();

        if ($model->getErrors()) {
            throw new \RuntimeException(implode("\n", $model->getErrors()), 500);
        }

        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_BOOKINGS_TITLE'), 'calendar');
        $toolbar = Toolbar::getInstance('toolbar');
        $toolbar->standardButton('approve')->text(Text::_('COM_APPOINTMENTS_BOOKINGS_APPROVE'))->task('bookings.approve')->listCheck(true);
        $toolbar->standardButton('cancel')->text(Text::_('COM_APPOINTMENTS_BOOKINGS_CANCEL'))->task('bookings.cancel')->listCheck(true);
        $toolbar->standardButton('retry')->text(Text::_('COM_APPOINTMENTS_CONFERENCE_RETRY'))->task('bookings.retryConference')->listCheck(true);
        ToolbarHelper::deleteList(Text::_('COM_APPOINTMENTS_BOOKINGS_ERASE_CONFIRM'), 'bookings.erase', 'COM_APPOINTMENTS_BOOKINGS_ERASE');
        $toolbar->linkButton('dashboard')->url('index.php?option=com_appointments')->text(Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'));

        return parent::display($tpl);
    }
}
