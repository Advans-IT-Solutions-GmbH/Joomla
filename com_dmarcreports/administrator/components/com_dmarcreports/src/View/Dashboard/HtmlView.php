<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\View\Dashboard;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Dashboard view: overview metrics and the prioritised Action Center.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<int, array<string, mixed>> */
    protected $summaries = [];

    /** @var array<string, mixed> */
    protected $totals = [];

    /** @var array<int, array<string, mixed>> */
    protected $actions = [];

    /** @var array<int, array<string, mixed>> */
    protected $suppressed = [];

    public function display($tpl = null)
    {
        $model = $this->getModel();

        $this->summaries  = $model->getSummaries();
        $this->totals     = $model->getTotals();
        $this->actions    = $model->getActions();
        $this->suppressed = $model->getSuppressedActions();

        $this->addToolbar();

        return parent::display($tpl);
    }

    private function addToolbar(): void
    {
        ToolbarHelper::title(Text::_('COM_DMARCREPORTS_DASHBOARD_TITLE'), 'bar-chart');

        $toolbar = \Joomla\CMS\Toolbar\Toolbar::getInstance('toolbar');
        $user    = Factory::getApplication()->getIdentity();

        $toolbar->linkButton('domains')
            ->url('index.php?option=com_dmarcreports&view=domains')
            ->icon('icon-list')
            ->text(Text::_('COM_DMARCREPORTS_TOOLBAR_DOMAINS'));

        $toolbar->linkButton('sources')
            ->url('index.php?option=com_dmarcreports&view=sources')
            ->icon('icon-mail')
            ->text(Text::_('COM_DMARCREPORTS_TOOLBAR_SOURCES'));

        $toolbar->linkButton('systemcheck')
            ->url('index.php?option=com_dmarcreports&view=systemcheck')
            ->icon('icon-health')
            ->text(Text::_('COM_DMARCREPORTS_TOOLBAR_SYSTEMCHECK'));

        if ($user->authorise('core.admin', 'com_dmarcreports')
            || $user->authorise('core.options', 'com_dmarcreports')) {
            $toolbar->preferences('com_dmarcreports');
        }
    }
}
