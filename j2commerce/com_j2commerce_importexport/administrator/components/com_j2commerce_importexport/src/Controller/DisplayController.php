<?php
/**
 * @package     J2Commerce Import/Export Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\J2CommerceImportExport\Administrator\Controller;

defined('_JEXEC') or die;

use Joomla\CMS\MVC\Controller\BaseController;

class DisplayController extends BaseController
{
    protected $default_view = 'dashboard';

    /**
     * Feature names that are not views of their own.
     *
     * The component has exactly one view, `dashboard`. Import and export are
     * controller tasks (`task=import.upload`, `task=import.preview`,
     * `task=import.process`, `task=export.export`) driven from the two forms
     * that view renders; there is no View\Import or View\Export class and none
     * is registered anywhere. `&view=import` and `&view=export` therefore used
     * to end in Joomla's "View not found" error (HTTP 404). Both names now
     * render the dashboard, so the addresses a user may guess or bookmark lead
     * to the component instead of an error page.
     *
     * @var string[]
     */
    private const FEATURE_VIEWS = ['import', 'export'];

    public function display($cachable = false, $urlparams = [])
    {
        $view = strtolower((string) $this->input->get('view', '', 'cmd'));

        if (in_array($view, self::FEATURE_VIEWS, true)) {
            $this->input->set('view', $this->default_view);
        }

        return parent::display($cachable, $urlparams);
    }
}
