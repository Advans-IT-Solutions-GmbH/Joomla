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
        $view = $this->input->getCmd('view', $this->default_view);

        if (\in_array($view, self::EDIT_VIEWS, true)) {
            $user = $this->app->getIdentity();
            $id   = $this->input->getInt('id', 0);

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
