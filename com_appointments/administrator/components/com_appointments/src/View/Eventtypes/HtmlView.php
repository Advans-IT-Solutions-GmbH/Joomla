<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Eventtypes;

use Joomla\CMS\Factory;
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

    public function display($tpl = null)
    {
        $model = $this->getModel();
        $this->items = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state = $model->getState();

        if ($model->getErrors()) {
            throw new \RuntimeException(implode("\n", $model->getErrors()), 500);
        }

        $user = Factory::getApplication()->getIdentity();
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_EVENTTYPES_TITLE'), 'calendar');
        $toolbar = Toolbar::getInstance('toolbar');

        if ($user->authorise('core.create', 'com_appointments')) {
            $toolbar->addNew('eventtype.add');
        }

        // Each selected row is authorised by the model against its own asset.
        $toolbar->publish('eventtypes.publish')->listCheck(true);
        $toolbar->unpublish('eventtypes.unpublish')->listCheck(true);
        $toolbar->delete('eventtypes.delete')->message('JGLOBAL_CONFIRM_DELETE')->listCheck(true);
        $toolbar->linkButton('fields')->url('index.php?option=com_appointments&view=fields')->text(Text::_('COM_APPOINTMENTS_FIELDS_TITLE'));
        $toolbar->linkButton('dashboard')->url('index.php?option=com_appointments')->text(Text::_('COM_APPOINTMENTS_TOOLBAR_DASHBOARD'));

        return parent::display($tpl);
    }
}
