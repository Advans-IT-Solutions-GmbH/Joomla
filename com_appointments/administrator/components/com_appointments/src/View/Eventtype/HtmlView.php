<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Eventtype;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected $form;
    protected $item;

    public function display($tpl = null)
    {
        $this->item = $this->getModel()->getItem();
        $this->form = $this->getModel()->getForm((array) $this->item);

        if ($this->getModel()->getErrors()) {
            throw new \RuntimeException(implode("\n", $this->getModel()->getErrors()), 500);
        }

        Factory::getApplication()->getInput()->set('hidemainmenu', true);
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_EVENTTYPE_EDIT'), 'calendar');
        $toolbar = Toolbar::getInstance('toolbar');
        $toolbar->apply('eventtype.apply');
        $toolbar->save('eventtype.save');
        $toolbar->cancel('eventtype.cancel');
        $this->setLayout('edit');

        return parent::display($tpl);
    }
}
