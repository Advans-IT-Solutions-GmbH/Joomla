<?php
/**
 * @package     J2Commerce Import/Export Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\View\Help;

defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\View\HtmlView as BaseHtmlView;
use Joomla\CMS\Toolbar\ToolbarHelper;

/**
 * The help page of the component.
 *
 * It needs no right beyond the access to the component (core.manage), which
 * Joomla's component dispatcher checks for every view, so everybody who can
 * open the dashboard can read the help. The toolbar Help button of the
 * dashboard opens it in Joomla's help popup (tmpl=component).
 */
class HtmlView extends BaseHtmlView
{
    /**
     * Whether the user may open the Options of the component.
     */
    protected bool $canOptions = false;

    /**
     * Whether the page is shown in the help popup, where links to other pages
     * would navigate the popup away.
     */
    protected bool $isPopup = false;

    public function display($tpl = null)
    {
        $app  = Factory::getApplication();
        $user = $app->getIdentity();

        $this->canOptions = $user->authorise('core.admin', 'com_j2commerce_importexport')
            || $user->authorise('core.options', 'com_j2commerce_importexport');
        $this->isPopup = $app->getInput()->getCmd('tmpl', '') === 'component';

        ToolbarHelper::title(Text::_('COM_J2COMMERCE_IMPORTEXPORT_HELP_TITLE'), 'info-circle');
        ToolbarHelper::link('index.php?option=com_j2commerce_importexport', 'JTOOLBAR_BACK', 'arrow-left');

        if ($this->canOptions) {
            ToolbarHelper::preferences('com_j2commerce_importexport');
        }

        return parent::display($tpl);
    }
}
