<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Joomla\CMS\Language\Text;
use Joomla\CMS\MVC\Controller\BaseController;

defined('_JEXEC') or die;

/**
 * Default administrator controller. Defaults to the dashboard.
 *
 * @since  1.0.0
 */
class DisplayController extends BaseController
{
    protected $default_view = 'dashboard';

    /**
     * Views that render an editable record form. Reaching them directly through
     * view=schedule or view=blocked would otherwise show and save stored data
     * without the task controllers' permission checks, so they are authorised
     * here before being dispatched.
     *
     * @var  string[]
     */
    private const EDIT_VIEWS = ['schedule', 'blocked'];

    public function display($cachable = false, $urlparams = [])
    {
        $user = $this->app->getIdentity();

        // Every view of this component shows booking data: who booked what and
        // when, with the name, e-mail address and telephone number the invitee
        // gave. Hiding the administrator menu entry is not an authorisation
        // boundary, because option=com_appointments can be requested directly,
        // so reaching any view requires the component management permission.
        if (!$user->authorise('core.manage', 'com_appointments')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        // Lower case before the comparison. Joomla's `cmd` filter keeps the
        // case it was given, and the MVC factory builds the view class with
        // `ucfirst()`, so view=Schedule and view=schedule reach the same view.
        // A list that matched only the lower-case spelling would therefore be a
        // guard that any caller can step around by holding down shift.
        $view = strtolower($this->input->getCmd('view', $this->default_view));

        if (\in_array($view, self::EDIT_VIEWS, true)) {
            $id = $this->input->getInt('id', 0);

            // An existing record exposes stored configuration, so reading it
            // requires core.edit. Only a blank form may be reached with
            // core.create alone; an OR over both would let a create-only user
            // read records they may not edit.
            $allowed = $id > 0
                ? $user->authorise('core.edit', 'com_appointments')
                : (
                    $user->authorise('core.create', 'com_appointments')
                    || $user->authorise('core.edit', 'com_appointments')
                );

            if (!$allowed) {
                throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
            }

            if ($id > 0 && !$this->checkEditId('com_appointments.edit.' . $view, $id)) {
                throw new \Exception(Text::sprintf('JLIB_APPLICATION_ERROR_UNHELD_ID', $id), 403);
            }
        }

        return parent::display($cachable, $urlparams);
    }
}
