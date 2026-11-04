<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

defined('_JEXEC') or die;

/**
 * Team calendars. Devices of all users: block with clean-up, revoke at once. Every task checks the form token and `core.admin`.
 *
 * Work package F adds the tasks. Until then the controller has none, so no
 * request can change anything through it.
 *
 * @since  1.4.0
 */
class DevicesController extends BaseController
{
}
