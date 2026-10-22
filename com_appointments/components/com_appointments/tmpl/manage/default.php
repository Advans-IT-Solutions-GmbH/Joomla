<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;
$headings = ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULE', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCEL', 'erase' => 'COM_APPOINTMENTS_MANAGE_ERASE'];
$results = ['reschedule' => 'COM_APPOINTMENTS_MANAGE_RESCHEDULED', 'cancel' => 'COM_APPOINTMENTS_MANAGE_CANCELLED', 'erase' => 'COM_APPOINTMENTS_MANAGE_ERASED'];
$dates = new DateDisplay(static fn (string $key): string => Text::_($key));
$dayFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY');
$timeFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_TIME');
$withOffset = Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET');
$errors = $this->errors;
// Focus the first invalid control in page order, not in the order the checks ran.
$firstError = current(array_intersect(['reason', 'start', 'confirm'], array_keys($errors))) ?: null;
$invalid = static fn (string $key): string => isset($errors[$key]) ? ' is-invalid' : '';
$aria = static function (string $key) use ($errors, $firstError): string {
    return isset($errors[$key])
        ? ' aria-invalid="true" aria-describedby="manage-' . $key . '-error"' . ($key === $firstError ? ' autofocus' : '')
        : '';
};
$error = fn (string $key): string => isset($errors[$key])
    ? '<div id="manage-' . $key . '-error" class="invalid-feedback com-appointments-field-error">' . $this->escape(Text::_($errors[$key])) . '</div>'
    : '';
?>
<div class="com-appointments com-appointments-manage">
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
        <?php if ($this->completed) : ?>
            <p class="com-appointments-status"><?php echo Text::_($results[$this->action]); ?></p>
        <?php else : ?>
            <?php
            $start = UtcTimestamp::parse($this->item->start_utc)->setTimezone($this->zone);
            $end = UtcTimestamp::parse($this->item->end_utc)->setTimezone($this->zone);
            ?>
            <h2 class="com-appointments-title"><?php echo $this->escape($this->item->title); ?></h2>
            <dl class="com-appointments-summary">
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_DATE'); ?></dt>
                <dd><?php echo $this->escape($dates->format($start, $dayFormat)); ?></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_TIME'); ?></dt>
                <dd><?php echo $this->escape(Text::sprintf('COM_APPOINTMENTS_SUMMARY_TIME_RANGE', $dates->time($start, $timeFormat, $withOffset), $dates->time($end, $timeFormat, $withOffset))); ?></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_ZONE'); ?></dt>
                <dd><?php echo $this->escape($this->zone->getName()); ?></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_EMAIL'); ?></dt>
                <dd><?php echo $this->escape((string) $this->item->invitee_email); ?></dd>
            </dl>
            <?php if ($this->action === 'erase') : ?>
                <div class="com-appointments-notice">
                    <p><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ERASURE_DATA'); ?></p>
                    <p><?php echo Text::_($this->deleteMode ? 'COM_APPOINTMENTS_MANAGE_ERASURE_DELETE' : 'COM_APPOINTMENTS_MANAGE_ERASURE_ANONYMISE'); ?></p>
                    <p><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ERASURE_FUTURE'); ?></p>
                </div>
            <?php endif; ?>
            <?php if ($this->action === 'reschedule') : ?>
                <form class="com-appointments-zone" method="get" action="<?php echo Route::_('index.php'); ?>">
                    <input type="hidden" name="option" value="com_appointments">
                    <input type="hidden" name="view" value="manage">
                    <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                    <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                    <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
                    <div class="com-appointments-field">
                        <label class="form-label" for="manage-month"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_MONTH'); ?></label>
                        <input class="form-control" id="manage-month" type="month" name="month" value="<?php echo $this->escape($this->month); ?>" required>
                    </div>
                    <label class="form-label" for="manage-zone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE'); ?></label>
                    <div class="com-appointments-zone-row">
                        <select class="form-select" id="manage-zone" name="timezone" aria-describedby="manage-zone-note">
                            <?php foreach (DateTimeZone::listIdentifiers() as $zone) : ?>
                                <option value="<?php echo $this->escape($zone); ?>"<?php echo $zone === $this->zone->getName() ? ' selected' : ''; ?>><?php echo $this->escape($zone); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button type="submit" class="btn btn-secondary com-appointments-zone-submit"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_SHOW_TIMES'); ?></button>
                    </div>
                    <p id="manage-zone-note" class="form-text com-appointments-zone-note"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_ZONE_NOTE', $this->escape($this->zone->getName())); ?></p>
                </form>
            <?php endif; ?>
            <?php if ($this->action !== 'reschedule' || $this->slots !== []) : ?>
                <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.manage'); ?>" class="com-appointments-form com-appointments-manage-form">
                    <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                    <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                    <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
                    <?php if ($this->action === 'cancel') : ?>
                        <div class="com-appointments-field">
                            <label class="form-label" for="cancel-reason"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_REASON'); ?></label>
                            <textarea class="form-control<?php echo $invalid('reason'); ?>" id="cancel-reason" name="reason" maxlength="2000"<?php echo $aria('reason'); ?>><?php echo $this->escape($this->reason); ?></textarea>
                            <?php echo $error('reason'); ?>
                        </div>
                    <?php elseif ($this->action === 'reschedule') : ?>
                        <input type="hidden" name="month" value="<?php echo $this->escape($this->month); ?>">
                        <input type="hidden" name="timezone" value="<?php echo $this->escape($this->zone->getName()); ?>">
                        <div class="com-appointments-field">
                            <label class="form-label" for="new-start"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_NEW_SLOT'); ?></label>
                            <select class="form-select<?php echo $invalid('start'); ?>" id="new-start" name="start" required<?php echo $aria('start'); ?>>
                                <?php foreach ($this->slots as $slot) : ?>
                                    <?php $local = $slot->setTimezone($this->zone); ?>
                                    <option value="<?php echo $slot->format('Y-m-d H:i:s'); ?>"><?php echo $this->escape(Text::sprintf('COM_APPOINTMENTS_DATE_TIME', $dates->format($local, $dayFormat), $dates->time($local, $timeFormat, $withOffset))); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <?php echo $error('start'); ?>
                        </div>
                    <?php endif; ?>
                    <div class="com-appointments-field">
                        <div class="form-check com-appointments-confirm">
                            <input class="form-check-input<?php echo $invalid('confirm'); ?>" id="manage-confirm" type="checkbox" name="confirm" value="1" required<?php echo $aria('confirm'); ?>>
                            <label class="form-check-label" for="manage-confirm"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_CONFIRM'); ?></label>
                            <?php echo $error('confirm'); ?>
                        </div>
                    </div>
                    <?php echo HTMLHelper::_('form.token'); ?>
                    <div class="com-appointments-actions">
                        <button type="submit" class="btn <?php echo $this->action === 'reschedule' ? 'btn-primary' : 'btn-danger'; ?>"><?php echo Text::_($headings[$this->action]); ?></button>
                    </div>
                </form>
            <?php else : ?>
                <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NO_SLOTS'); ?></p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
