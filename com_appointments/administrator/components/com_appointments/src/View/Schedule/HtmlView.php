<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\View\Schedule;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Edit view for a single schedule, including its intervals.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    protected $form;

    protected $item;

    public function display($tpl = null)
    {
        $this->form = $this->getModel()->getForm();
        $this->item = $this->getModel()->getItem();

        $this->addToolbar();

        // This view only ever renders the edit form; pin the layout so the
        // default URL (view=schedule, no layout) resolves to
        // tmpl/schedule/edit.php.
        $this->setLayout('edit');

        return parent::display($tpl);
    }

    private function addToolbar(): void
    {
        Factory::getApplication()->getInput()->set('hidemainmenu', true);

        $isNew   = empty($this->item->id);
        $toolbar = Toolbar::getInstance('toolbar');

        ToolbarHelper::title(
            Text::_($isNew ? 'COM_APPOINTMENTS_SCHEDULE_NEW' : 'COM_APPOINTMENTS_SCHEDULE_EDIT'),
            'calendar'
        );

        $toolbar->apply('schedule.apply');
        $toolbar->save('schedule.save');
        $toolbar->cancel('schedule.cancel');
    }
}
