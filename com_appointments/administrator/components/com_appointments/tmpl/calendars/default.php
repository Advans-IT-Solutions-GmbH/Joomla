<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

use Joomla\CMS\Language\Text;

/** @var \Advans\Component\Appointments\Administrator\View\Calendars\HtmlView $this */
?>
<div class="alert alert-info" role="status">
    <?php echo Text::_($this->enabled ? 'COM_APPOINTMENTS_TEAM_NOT_READY' : 'COM_APPOINTMENTS_TEAM_DISABLED'); ?>
</div>
