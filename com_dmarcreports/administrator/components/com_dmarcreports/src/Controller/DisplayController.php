<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Controller;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;

defined('_JEXEC') or die;

/**
 * Default controller. Routes guessable views to their read-only displays and
 * defaults to the dashboard.
 *
 * @since  1.0.0
 */
class DisplayController extends BaseController
{
    protected $default_view = 'dashboard';

    /**
     * Views that render editable record forms. Reaching them directly via
     * view=domain/source would otherwise load and display mailbox configuration
     * and domain notes without the task controllers' ACL checks, so they are
     * authorised here before being dispatched.
     *
     * @var  string[]
     */
    private const EDIT_VIEWS = ['domain', 'source'];

    public function display($cachable = false, $urlparams = [])
    {
        $user = $this->app->getIdentity();

        // Every view in this component renders sensitive operational data —
        // report volumes, sending-source IP addresses, pass/fail breakdowns and
        // mailbox host/user/folder configuration — so reaching ANY view requires
        // the component management permission. Hiding the admin menu entry is not
        // an authorization boundary: a backend user without core.manage could
        // still request option=com_dmarcreports (or view=detail/domains/sources/
        // systemcheck) directly. Enforce core.manage for all views first, then
        // apply the finer create/edit checks to the editable forms below.
        if (!$user->authorise('core.manage', 'com_dmarcreports')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        $view = $this->input->getCmd('view', $this->default_view);

        if (\in_array($view, self::EDIT_VIEWS, true)) {
            $id = $this->input->getInt('id', 0);

            // An existing record (id > 0) exposes stored configuration — mailbox
            // host/user/folder and domain notes — so reading it requires
            // core.edit. Only a blank/new form (no id) may be reached with
            // core.create alone. Using an OR for both cases would let a
            // create-only user read existing configuration they may not edit.
            $allowed = $id > 0
                ? $user->authorise('core.edit', 'com_dmarcreports')
                : (
                    $user->authorise('core.create', 'com_dmarcreports')
                    || $user->authorise('core.edit', 'com_dmarcreports')
                );

            if (!$allowed) {
                throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }
        }

        return parent::display($cachable, $urlparams);
    }
}
