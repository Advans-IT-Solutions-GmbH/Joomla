<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Site\Controller;

use Joomla\CMS\MVC\Controller\BaseController;

defined('_JEXEC') or die;

/**
 * Team calendars. «My calendars»: add a device (name, client, usage rule checkbox), rename, remove (starts the clean-up). Every task checks the form token, a signed-in user and an unlocked key session.
 *
 * Work package D adds the tasks. Until then the controller has none, so no
 * request can change anything through it.
 *
 * @since  1.4.0
 */
class DevicesController extends BaseController
{
    protected $default_view = 'mycalendars';
}
