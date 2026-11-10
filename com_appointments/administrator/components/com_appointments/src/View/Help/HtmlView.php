<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Help;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

class HtmlView extends BaseHtmlView
{
    protected bool $canOptions = false;

    public function display($tpl = null)
    {
        $this->canOptions = Factory::getApplication()->getIdentity()->authorise('core.options', 'com_appointments');
        ToolbarHelper::title(Text::_('COM_APPOINTMENTS_HELP_TITLE'), 'info-circle');
        Toolbar::getInstance('toolbar')->linkButton('back')
            ->url('index.php?option=com_appointments')
            ->text(Text::_('JTOOLBAR_BACK'));

        if ($this->canOptions) {
            ToolbarHelper::preferences('com_appointments');
        }

        return parent::display($tpl);
    }
}
