<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Factory;

defined('_JEXEC') or die;

$counts  = $this->counts;
$isEmpty = $counts['eventtypes'] === 0 && $counts['bookings'] === 0;
$user = Factory::getApplication()->getIdentity();
$statLabels = ['bookings' => 'COM_APPOINTMENTS_STAT_BOOKINGS', 'today' => 'COM_APPOINTMENTS_STAT_TODAY',
    'upcoming' => 'COM_APPOINTMENTS_STAT_UPCOMING', 'pending' => 'COM_APPOINTMENTS_STAT_PENDING'];
?>
<div class="com-appointments-dashboard">
    <nav class="mb-3">
        <a class="btn btn-primary" href="<?php echo Route::_('index.php?option=com_appointments&view=bookings'); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKINGS_TITLE'); ?></a>
        <a class="btn btn-primary" href="<?php echo Route::_('index.php?option=com_appointments&view=eventtypes'); ?>"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPES_TITLE'); ?></a>
        <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=fields'); ?>"><?php echo Text::_('COM_APPOINTMENTS_FIELDS_TITLE'); ?></a>
        <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=schedules'); ?>"><?php echo Text::_('COM_APPOINTMENTS_SCHEDULES_TITLE'); ?></a>
        <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=blockeds'); ?>"><?php echo Text::_('COM_APPOINTMENTS_BLOCKEDS_TITLE'); ?></a>
        <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=templates'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TEMPLATES_TITLE'); ?></a>
        <?php if ($user->authorise('core.manage', 'com_appointments') && $user->authorise('core.options', 'com_appointments')) : ?>
            <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=feeds'); ?>"><?php echo Text::_('COM_APPOINTMENTS_FEEDS_TITLE'); ?></a>
            <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=system'); ?>"><?php echo Text::_('COM_APPOINTMENTS_SYSTEM_TITLE'); ?></a>
        <?php endif; ?>
    </nav>
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

    <div class="row">
        <?php foreach (['today', 'upcoming', 'pending'] as $key) : ?>
            <div class="col-md-4 mb-3"><div class="card card-body">
                <div class="h1"><?php echo (int) $counts[$key]; ?></div>
                <div><?php echo Text::_($statLabels[$key]); ?></div>
            </div></div>
        <?php endforeach; ?>
    </div>
    <?php if ($this->eventCounts) : ?>
        <table class="table">
            <caption><?php echo Text::_('COM_APPOINTMENTS_STAT_BY_EVENT'); ?></caption>
            <thead><tr>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_FIELD_EVENTTYPE'); ?></th>
                <?php foreach ($statLabels as $label) : ?><th scope="col"><?php echo Text::_($label); ?></th><?php endforeach; ?>
            </tr></thead>
            <tbody>
                <?php foreach ($this->eventCounts as $event) : ?><tr>
                    <th scope="row"><?php echo $this->escape($event['title']); ?></th>
                    <?php foreach (['bookings', 'today', 'upcoming', 'pending'] as $key) : ?><td><?php echo (int) $event[$key]; ?></td><?php endforeach; ?>
                </tr><?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

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
