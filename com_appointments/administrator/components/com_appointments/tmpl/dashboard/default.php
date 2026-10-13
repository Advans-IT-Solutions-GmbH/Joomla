<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

$counts  = $this->counts;
$isEmpty = $counts['eventtypes'] === 0 && $counts['bookings'] === 0;
?>
<div class="com-appointments-dashboard">
    <div class="row">
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="h1 mb-0"><?php echo (int) $counts['eventtypes']; ?></div>
                    <div class="text-muted"><?php echo Text::_('COM_APPOINTMENTS_STAT_EVENTTYPES'); ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-6 mb-3">
            <div class="card h-100">
                <div class="card-body">
                    <div class="h1 mb-0"><?php echo (int) $counts['bookings']; ?></div>
                    <div class="text-muted"><?php echo Text::_('COM_APPOINTMENTS_STAT_BOOKINGS'); ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($isEmpty) : ?>
        <div class="card">
            <div class="card-header">
                <h2 class="mb-0"><?php echo Text::_('COM_APPOINTMENTS_DASHBOARD_EMPTY_HEADING'); ?></h2>
            </div>
            <div class="card-body">
                <p class="mb-0"><?php echo Text::_('COM_APPOINTMENTS_DASHBOARD_EMPTY_INTRO'); ?></p>
            </div>
        </div>
    <?php endif; ?>
</div>
