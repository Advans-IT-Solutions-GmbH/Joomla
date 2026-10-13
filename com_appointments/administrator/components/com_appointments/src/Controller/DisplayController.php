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

    public function display($cachable = false, $urlparams = [])
    {
        // Every view of this component shows booking data: who booked what and
        // when, with the name, e-mail address and telephone number the invitee
        // gave. Hiding the administrator menu entry is not an authorisation
        // boundary, because option=com_appointments can be requested directly,
        // so reaching any view requires the component management permission.
        if (!$this->app->getIdentity()->authorise('core.manage', 'com_appointments')) {
            throw new \Exception(Text::_('JERROR_ALERTNOAUTHOR'), 403);
        }

        return parent::display($cachable, $urlparams);
    }
}
