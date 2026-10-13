<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\View\Detail;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Per-domain drill-down view. Degrades to a domain chooser when no domain is
 * selected.
 *
 * @since  1.0.0
 */
class HtmlView extends BaseHtmlView
{
    /** @var array<string, mixed>|null */
    protected $metrics;

    /** @var array<string, mixed> */
    protected $dns = [];

    /** @var array<string, mixed> */
    protected $mtasts = [];

    /** @var array<string, mixed> */
    protected $tlsrpt = [];

    /** @var array<int, array<string, mixed>> */
    protected $actions = [];

    /** @var array<int, object> */
    protected $domains = [];

    public function display($tpl = null)
    {
        /** @var \Advans\Component\DmarcReports\Administrator\Model\DetailModel $model */
        $model = $this->getModel();

        $this->domains = $model->getDomainList();
        $this->metrics = $model->getMetrics();

        if ($this->metrics !== null) {
            $this->dns     = $model->getDnsDiagnostics($this->metrics);
            $this->mtasts  = $model->getMtaStsDiagnostics($this->metrics);
            $this->tlsrpt  = $model->getTlsRptSummary($this->metrics);
            $this->actions = $model->getActions($this->metrics, $this->dns, $this->mtasts);
        }

        ToolbarHelper::title(Text::_('COM_DMARCREPORTS_DETAIL_TITLE'), 'search');
        ToolbarHelper::link(
            \Joomla\CMS\Router\Route::_('index.php?option=com_dmarcreports&view=dashboard'),
            Text::_('COM_DMARCREPORTS_TOOLBAR_DASHBOARD'),
            'icon-arrow-left'
        );

        return parent::display($tpl);
    }
}
