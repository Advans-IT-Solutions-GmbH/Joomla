<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Factory;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$user = Factory::getApplication()->getIdentity();
$siteEdit = $user->authorise('core.options', 'com_appointments') || $user->authorise('core.admin', 'com_appointments');
$purposeKeys = [
    'confirmation' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CONFIRMATION',
    'pending' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_PENDING',
    'approved' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_APPROVED',
    'reschedule' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_RESCHEDULE',
    'cancellation' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CANCELLATION',
    'reminder' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_REMINDER',
    'host_notice' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_HOST_NOTICE',
    'host_approved' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_HOST_APPROVED',
    'host_reschedule' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_HOST_RESCHEDULE',
    'host_cancellation' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_HOST_CANCELLATION',
    'host_reminder' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_HOST_REMINDER',
    'erasure_notice' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_ERASURE_NOTICE',
    'guest_confirmation' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_GUEST_CONFIRMATION',
    'guest_pending' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_GUEST_PENDING',
    'guest_approved' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_GUEST_APPROVED',
    'guest_reschedule' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_GUEST_RESCHEDULE',
    'guest_cancellation' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_GUEST_CANCELLATION',
    'guest_reminder' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_GUEST_REMINDER',
    'calendar_booked' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CALENDAR_BOOKED',
    'calendar_approved' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CALENDAR_APPROVED',
    'calendar_rescheduled' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CALENDAR_RESCHEDULED',
    'calendar_cancelled' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CALENDAR_CANCELLED',
    'calendar_reminder' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CALENDAR_REMINDER',
    'calendar_busy' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CALENDAR_BUSY',
    'calendar_busy_moved' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CALENDAR_BUSY_MOVED',
    'calendar_free' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_CALENDAR_FREE',
    'team_device_added' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_TEAM_DEVICE_ADDED',
    'team_invitation' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_TEAM_INVITATION',
    'team_invitation_accepted' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_TEAM_INVITATION_ACCEPTED',
    'team_grant_changed' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_TEAM_GRANT_CHANGED',
    'team_access_suspended' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_TEAM_ACCESS_SUSPENDED',
    'team_calendar_deleted' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_TEAM_CALENDAR_DELETED',
    'team_account_locked' => 'COM_APPOINTMENTS_TEMPLATE_PURPOSE_TEAM_ACCOUNT_LOCKED',
];
?>
<form action="<?php echo Route::_('index.php?option=com_appointments&view=templates'); ?>" method="post" name="adminForm" id="adminForm">
    <div class="mb-3">
        <label for="filter_eventtype_id"><?php echo Text::_('COM_APPOINTMENTS_TEMPLATE_SCOPE'); ?></label>
        <input type="number" name="filter_eventtype_id" id="filter_eventtype_id" min="-1"
               value="<?php echo (int) $this->state->get('filter.eventtype_id', -1); ?>">
        <span><?php echo Text::_('COM_APPOINTMENTS_TEMPLATE_FILTER_DESC'); ?></span>
        <button type="submit" class="btn btn-secondary"><?php echo Text::_('JSEARCH_FILTER_SUBMIT'); ?></button>
    </div>
    <?php if (!$this->items) : ?>
        <p class="alert alert-info"><?php echo Text::_('COM_APPOINTMENTS_TEMPLATES_EMPTY'); ?></p>
    <?php else : ?>
        <div class="table-responsive">
        <table class="table">
            <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_TEMPLATES_TITLE'); ?></caption>
            <thead><tr>
                <td><?php echo HTMLHelper::_('grid.checkall'); ?></td>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEMPLATE_SUBJECT'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEMPLATE_SCOPE'); ?></th>
                <th scope="col"><?php echo Text::_('COM_APPOINTMENTS_TEMPLATE_PURPOSE'); ?></th>
                <th scope="col"><?php echo Text::_('JFIELD_LANGUAGE_LABEL'); ?></th>
                <th scope="col"><?php echo Text::_('JSTATUS'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($this->items as $i => $item) : ?>
                <?php $canEdit = (int) $item->eventtype_id === 0 ? $siteEdit : $user->authorise('core.edit', 'com_appointments.eventtype.' . (int) $item->eventtype_id); ?>
                <tr>
                    <td><?php echo HTMLHelper::_('grid.id', $i, (int) $item->id); ?></td>
                    <td>
                    <?php if ($canEdit) : ?>
                        <a href="<?php echo Route::_('index.php?option=com_appointments&task=template.edit&id=' . (int) $item->id); ?>"><?php echo $this->escape((string) $item->subject); ?></a>
                    <?php else : ?>
                        <?php echo $this->escape((string) $item->subject); ?>
                    <?php endif; ?>
                    </td>
                    <td><?php echo (int) $item->eventtype_id === 0 ? Text::_('COM_APPOINTMENTS_TEMPLATE_SITE_WIDE') : $this->escape((string) $item->eventtype_title); ?></td>
                    <td><?php echo $this->escape(isset($purposeKeys[$item->purpose]) ? Text::_($purposeKeys[$item->purpose]) : (string) $item->purpose); ?></td>
                    <td><?php echo $this->escape((string) $item->language); ?></td>
                    <td><?php echo Text::_((int) $item->published === 1 ? 'JPUBLISHED' : 'JUNPUBLISHED'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <?php echo $this->pagination->getListFooter(); ?>
    <?php endif; ?>
    <input type="hidden" name="task" value="">
    <input type="hidden" name="boxchecked" value="0">
    <?php echo HTMLHelper::_('form.token'); ?>
</form>
