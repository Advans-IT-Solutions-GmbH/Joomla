<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
namespace Advans\Component\Appointments\Administrator\Controller;

use Joomla\CMS\MVC\Controller\FormController;

defined('_JEXEC') or die;

/**
 * Edit controller for a single blocked period.
 *
 * @since  1.0.0
 */
class BlockedController extends FormController
{
    protected $view_list = 'blockeds';
}