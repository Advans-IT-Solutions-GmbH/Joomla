<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$headings = ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULE', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCEL', 'erase' => 'COM_APPOINTMENTS_MANAGE_ERASE'];
$results = ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULED', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCELLED', 'erase' => 'COM_APPOINTMENTS_MANAGE_ERASED'];
?>
<div class="com-appointments">
    <h1><?php echo Text::_($headings[$this->action]); ?></h1>
    <?php if ($this->completed) : ?>
        <p><?php echo Text::_($results[$this->action]); ?></p>
    <?php else : ?>
        <h2><?php echo $this->escape($this->item->title); ?></h2>
        <p><?php echo $this->escape($this->item->start_utc . ' UTC - ' . $this->item->end_utc . ' UTC'); ?></p>
        <p><?php echo $this->escape($this->item->invitee_email); ?></p>
        <?php if ($this->action === 'erase') : ?>
            <p><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ERASURE_DATA'); ?></p>
            <p><?php echo Text::_($this->deleteMode ? 'COM_APPOINTMENTS_MANAGE_ERASURE_DELETE' : 'COM_APPOINTMENTS_MANAGE_ERASURE_ANONYMISE'); ?></p>
            <p><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ERASURE_FUTURE'); ?></p>
        <?php endif; ?>
        <?php if ($this->action === 'reschedule') : ?>
            <form method="get" action="<?php echo Route::_('index.php'); ?>">
                <input type="hidden" name="option" value="com_appointments">
                <input type="hidden" name="view" value="manage">
                <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
                <label for="manage-month"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_MONTH'); ?></label>
                <input id="manage-month" type="month" name="month" value="<?php echo $this->escape($this->month); ?>" required>
                <label for="manage-zone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE'); ?></label>
                <select id="manage-zone" name="timezone">
                    <?php foreach (DateTimeZone::listIdentifiers() as $zone) : ?>
                        <option value="<?php echo $this->escape($zone); ?>"<?php echo $zone === $this->zone->getName() ? ' selected' : ''; ?>><?php echo $this->escape($zone); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CHANGE_ZONE'); ?></button>
            </form>
        <?php endif; ?>
        <?php if ($this->action !== 'reschedule' || $this->slots !== []) : ?>
            <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.manage'); ?>" class="com-appointments-booking">
                <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
                <?php if ($this->action === 'cancel') : ?>
                    <label for="cancel-reason"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_REASON'); ?></label>
                    <textarea id="cancel-reason" name="reason" maxlength="2000"><?php echo $this->escape($this->reason); ?></textarea>
                <?php elseif ($this->action === 'reschedule') : ?>
                    <input type="hidden" name="month" value="<?php echo $this->escape($this->month); ?>">
                    <input type="hidden" name="timezone" value="<?php echo $this->escape($this->zone->getName()); ?>">
                    <label for="new-start"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_NEW_SLOT'); ?></label>
                    <select id="new-start" name="start" required>
                        <?php foreach ($this->slots as $slot) : ?>
                            <option value="<?php echo $slot->format('Y-m-d H:i:s'); ?>"><?php echo $this->escape($slot->setTimezone($this->zone)->format('Y-m-d H:i T')); ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php endif; ?>
                <label><input type="checkbox" name="confirm" value="1" required> <?php echo Text::_('COM_APPOINTMENTS_MANAGE_CONFIRM'); ?></label>
                <button type="submit"><?php echo Text::_($headings[$this->action]); ?></button>
                <?php echo HTMLHelper::_('form.token'); ?>
            </form>
        <?php else : ?>
            <p><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NO_SLOTS'); ?></p>
        <?php endif; ?>
    <?php endif; ?>
</div>
