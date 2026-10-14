<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\View\Systemcheck;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Re-runnable System Check view.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<int, array<string, mixed>> */
    protected $checks = [];

    /** @var array{enabled:bool, network:bool, domains:array<int, array<string, mixed>>} */
    protected $mtasts = ['enabled' => false, 'network' => false, 'domains' => []];

    public function display($tpl = null)
    {
        $this->checks = $this->getModel()->getChecks();
        $this->mtasts = $this->getModel()->getMtaStsChecks();

        ToolbarHelper::title(Text::_('COM_DMARCREPORTS_SYSTEMCHECK_TITLE'), 'health');
        ToolbarHelper::link(
            Route::_('index.php?option=com_dmarcreports&view=dashboard'),
            Text::_('COM_DMARCREPORTS_TOOLBAR_DASHBOARD'),
            'icon-arrow-left'
        );

        return parent::display($tpl);
    }
}
