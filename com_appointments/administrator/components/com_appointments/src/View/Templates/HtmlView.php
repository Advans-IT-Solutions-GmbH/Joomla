<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Templates;

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

        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_TEMPLATES_TITLE'), 'mail');
        $toolbar = Toolbar::getInstance('toolbar');

        if (Factory::getApplication()->getIdentity()->authorise('core.create', 'com_appointments')) {
            $toolbar->addNew('template.add');
        }

        $toolbar->publish('templates.publish')->listCheck(true);
        $toolbar->unpublish('templates.unpublish')->listCheck(true);
        $toolbar->delete('templates.delete')->message('JGLOBAL_CONFIRM_DELETE')->listCheck(true);
        $toolbar->linkButton('events')->url('index.php?option=com_appointments&view=eventtypes')->text(Text::_('COM_APPOINTMENTS_EVENTTYPES_TITLE'));

        return parent::display($tpl);
    }
}
