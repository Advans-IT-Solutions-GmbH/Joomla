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
        <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=calendars'); ?>"><?php echo Text::_('COM_APPOINTMENTS_TEAM_C_NAV_CALENDARS'); ?></a>
        <a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=help'); ?>"><?php echo Text::_('COM_APPOINTMENTS_HELP_TITLE'); ?></a>
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

    <?php if ($user->authorise('core.admin', 'com_appointments')) : ?>
        <section class="card card-body mb-3">
            <h2><?php echo Text::_('COM_APPOINTMENTS_CLOSE_TITLE'); ?></h2>
            <p><?php echo Text::_($this->closeEnabled ? 'COM_APPOINTMENTS_CLOSE_ENABLED' : 'COM_APPOINTMENTS_CLOSE_DISABLED'); ?></p>
            <?php if (!$this->closeConfigured) : ?>
                <p class="alert alert-warning"><?php echo Text::_('COM_APPOINTMENTS_CLOSE_CONFIG_MISSING'); ?></p>
            <?php endif; ?>
            <p><?php echo Text::_('COM_APPOINTMENTS_CLOSE_DELIVERY_HELP'); ?></p>
            <form action="<?php echo Route::_('index.php?option=com_appointments&task=close.retry'); ?>" method="post">
                <button class="btn btn-secondary" type="submit"><?php echo Text::_('COM_APPOINTMENTS_CLOSE_RETRY'); ?></button>
                <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
            </form>
            <?php if ($this->closeRows) :
                $deliveryStates = ['pending' => 'COM_APPOINTMENTS_CLOSE_PENDING', 'retry' => 'COM_APPOINTMENTS_CLOSE_RETRY_STATE',
                    'done' => 'COM_APPOINTMENTS_CLOSE_DONE', 'blocked' => 'COM_APPOINTMENTS_CLOSE_BLOCKED',
                    'uncertain' => 'COM_APPOINTMENTS_CLOSE_UNCERTAIN']; ?>
                <div class="table-responsive"><table class="table">
                    <caption><?php echo Text::_('COM_APPOINTMENTS_CLOSE_RECENT'); ?></caption>
                    <thead><tr><th><?php echo Text::_('JGRID_HEADING_ID'); ?></th><th><?php echo Text::_('JSTATUS'); ?></th><th><?php echo Text::_('COM_APPOINTMENTS_CLOSE_REASON'); ?></th></tr></thead>
                    <tbody><?php foreach ($this->closeRows as $delivery) : ?><tr>
                        <td><?php echo (int) $delivery['booking_id']; ?></td>
                        <td><?php echo Text::_($deliveryStates[$delivery['state']] ?? 'COM_APPOINTMENTS_CLOSE_BLOCKED'); ?></td>
                        <td><code><?php echo $this->escape($delivery['reason']); ?></code>
                            <?php if ($delivery['state'] === 'uncertain') : ?>
                                <form action="<?php echo Route::_('index.php?option=com_appointments&task=close.reconcile'); ?>" method="post">
                                    <label for="close-remote-<?php echo (int) $delivery['booking_id']; ?>"><?php echo Text::_('COM_APPOINTMENTS_CLOSE_REMOTE_ID'); ?></label>
                                    <input class="form-control" id="close-remote-<?php echo (int) $delivery['booking_id']; ?>" name="remote_id" maxlength="110" required>
                                    <input type="hidden" name="booking_id" value="<?php echo (int) $delivery['booking_id']; ?>">
                                    <button class="btn btn-secondary" type="submit"><?php echo Text::_('COM_APPOINTMENTS_CLOSE_RECONCILE'); ?></button>
                                    <?php echo \Joomla\CMS\HTML\HTMLHelper::_('form.token'); ?>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr><?php endforeach; ?></tbody>
                </table></div>
            <?php endif; ?>
        </section>
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
