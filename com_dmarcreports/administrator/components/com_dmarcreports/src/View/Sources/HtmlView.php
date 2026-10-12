<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\View\Sources;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * List view of report sources (mailboxes).
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<int, object> */
    protected $items = [];

    protected $pagination;

    protected $state;

    public function display($tpl = null)
    {
        $model            = $this->getModel();
        $this->items      = $model->getItems();
        $this->pagination = $model->getPagination();
        $this->state      = $model->getState();

        $this->addToolbar();

        return parent::display($tpl);
    }

    private function addToolbar(): void
    {
        $user    = Factory::getApplication()->getIdentity();
        $toolbar = \Joomla\CMS\Toolbar\Toolbar::getInstance('toolbar');

        ToolbarHelper::title(Text::_('COM_DMARCREPORTS_SOURCES_TITLE'), 'mail');

        if ($user->authorise('core.create', 'com_dmarcreports')) {
            $toolbar->addNew('source.add');
        }

        if ($user->authorise('core.edit.state', 'com_dmarcreports')) {
            $toolbar->publish('sources.publish')->listCheck(true);
            $toolbar->unpublish('sources.unpublish')->listCheck(true);
        }

        if ($user->authorise('core.delete', 'com_dmarcreports')) {
            $toolbar->delete('sources.delete')
                ->message('JGLOBAL_CONFIRM_DELETE')
                ->listCheck(true);
        }

        $toolbar->linkButton('dashboard')
            ->url('index.php?option=com_dmarcreports&view=dashboard')
            ->icon('icon-arrow-left')
            ->text(Text::_('COM_DMARCREPORTS_TOOLBAR_DASHBOARD'));
    }
}
