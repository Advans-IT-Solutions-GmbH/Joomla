<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\View\Help;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\Toolbar;
use Joomla\CMS\Toolbar\ToolbarHelper;

defined('_JEXEC') or die;

/**
 * Help and tips: what DMARC, SPF, DKIM, MTA-STS and TLS-RPT are, how the
 * extension works, every Action Center entry and the typical tasks. Reachable
 * from the component submenu and the Help button of every view; the display
 * controller already requires core.manage for it like for every other view.
 *
 * @since  1.3.0
 */
class HtmlView extends BaseHtmlView
{
    /** Whether the current user may open the component Options. */
    protected bool $canOptions = false;

    public function display($tpl = null)
    {
        $user             = Factory::getApplication()->getIdentity();
        $this->canOptions = $user->authorise('core.admin', 'com_dmarcreports')
            || $user->authorise('core.options', 'com_dmarcreports');

        ToolbarHelper::title(Text::_('COM_DMARCREPORTS_HELP_TITLE'), 'info-circle');

        $toolbar = Toolbar::getInstance('toolbar');
        $toolbar->linkButton('dashboard')
            ->url('index.php?option=com_dmarcreports&view=dashboard')
            ->icon('icon-arrow-left')
            ->text(Text::_('COM_DMARCREPORTS_TOOLBAR_DASHBOARD'));

        if ($this->canOptions) {
            $toolbar->preferences('com_dmarcreports');
        }

        return parent::display($tpl);
    }
}
