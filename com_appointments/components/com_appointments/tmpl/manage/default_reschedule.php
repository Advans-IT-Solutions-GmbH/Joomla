<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Advans\Component\Appointments\Site\Helper\BackLink;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/*
 * The reschedule page of a signed link, built like the booking page (tmpl/eventtype/default.php):
 * the same layout, the same calendar, times and time-zone field (its partials default_picker.php,
 * default_zone.php and default_zone_note.php are the booking page's own, loaded from there) and
 * the same two steps. The information column adds only what a reschedule needs on top of the
 * booking page: the title of the event type and the e-mail address of the appointment.
 *
 *   1. date and time   the calendar, then the times of the chosen day, each with its "Next" button.
 *   2. confirmation    the chosen time and the confirmation checkbox; the button reschedules.
 */
[$action, $hidden] = $this->formTarget();
$confirm = $this->selected !== null;
$dates = new DateDisplay(static fn (string $key): string => Text::_($key));
$dayFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY');
$withOffset = Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET');
$icon = static fn (string $paths): string => '<svg class="com-appointments-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$currentStart = UtcTimestamp::parse((string) $this->item->start_utc);
$currentEnd = UtcTimestamp::parse((string) $this->item->end_utc);
$minutes = $this->durationMinutes > 0 ? $this->durationMinutes
    : ($currentStart !== null && $currentEnd !== null ? max(1, intdiv($currentEnd->getTimestamp() - $currentStart->getTimestamp(), 60)) : 0);
$errors = $this->errors;
$invalid = isset($errors['confirm']) ? ' is-invalid' : '';
$confirmAria = isset($errors['confirm']) ? ' aria-invalid="true" aria-describedby="manage-confirm-error" autofocus' : '';
$back = BackLink::resolve(false);
$range = static function (DateDisplay $dates, \DateTimeImmutable $start, \DateTimeImmutable $end, \DateTimeZone $zone, string $timeFormat) use ($dayFormat, $withOffset): string {
    return $dates->range($start->setTimezone($zone), $end->setTimezone($zone), $timeFormat, $dayFormat, $withOffset,
        Text::_('COM_APPOINTMENTS_SUMMARY_TIME_RANGE'), Text::_('COM_APPOINTMENTS_DATE_TIME'));
};
?>
<div class="com-appointments com-appointments-eventtype com-appointments-reschedule" data-appointments-booking>
    <div class="com-appointments-container" data-appointments-view data-step="<?php echo $confirm ? 'details' : 'time'; ?>">
        <?php if ($back !== '') : ?>
            <p><a class="btn btn-outline-secondary com-appointments-return" href="<?php echo $back; ?>"><?php echo Text::_('JCANCEL'); ?></a></p>
        <?php endif; ?>
        <div id="appointment-booking" class="com-appointments-layout com-appointments-layout-<?php echo $confirm ? 'details' : 'time'; ?><?php echo !$confirm && $this->dayChosen ? ' com-appointments-layout-day' : ''; ?> com-appointments-info-event">
            <header class="com-appointments-info com-appointments-header">
                <?php if ($confirm) : ?>
                    <?php $local = $this->selected->setTimezone($this->zone); ?>
                    <a class="btn btn-outline-secondary com-appointments-back" href="<?php echo $this->bookingLink(['month' => $local->format('Y-m'), 'day' => $local->format('Y-m-d')], 'appointment-picker'); ?>" data-appointments-step>
                        <svg class="com-appointments-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="M10 3 5 8l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><?php echo Text::_('COM_APPOINTMENTS_BOOKING_BACK'); ?></span>
                    </a>
                <?php endif; ?>
                <h1 class="com-appointments-heading"><?php echo $this->escape((string) $this->item->title); ?></h1>
                <ul class="com-appointments-meta">
                    <?php if ($minutes > 0) : ?>
                        <li class="com-appointments-duration"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'); ?><span><?php echo Text::sprintf($minutes === 1 ? 'COM_APPOINTMENTS_DURATION_MINUTES_1' : 'COM_APPOINTMENTS_DURATION_MINUTES_MORE', $minutes); ?></span></li>
                    <?php endif; ?>
                    <li class="com-appointments-invitee-email"><?php echo $icon('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>'); ?><span><?php echo $this->escape((string) $this->item->invitee_email); ?></span></li>
                    <?php if ($confirm && $currentStart !== null && $currentEnd !== null) : ?>
                        <li class="com-appointments-current">
                            <?php echo $icon('<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>'); ?>
                            <span class="com-appointments-selected-text">
                                <span class="com-appointments-current-label"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_CURRENT_APPOINTMENT'); ?></span>
                                <span class="com-appointments-selected-date"><?php echo $this->escape($dates->format($currentStart->setTimezone($this->zone), $dayFormat)); ?></span>
                                <span class="com-appointments-selected-time"><?php echo $this->escape($range($dates, $currentStart, $currentEnd, $this->zone, $this->timeFormat)); ?></span>
                            </span>
                        </li>
                    <?php endif; ?>
                    <?php if ($confirm && $currentStart !== null && $currentEnd !== null) : ?>
                        <?php $newEnd = $this->selected->modify('+' . max(1, $minutes) . ' minutes'); ?>
                        <li class="com-appointments-selected">
                            <?php echo $icon('<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>'); ?>
                            <span class="com-appointments-selected-text">
                                <span class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_SELECTED'); ?></span>
                                <span class="com-appointments-selected-date"><?php echo $this->escape($dates->format($this->selected->setTimezone($this->zone), $dayFormat)); ?></span>
                                <span class="com-appointments-selected-time"><?php echo $this->escape($range($dates, $this->selected, $newEnd, $this->zone, $this->timeFormat)); ?></span>
                            </span>
                        </li>
                        <li class="com-appointments-selected-zone"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>'); ?><span><?php echo $this->escape($this->zone->getName()); ?></span></li>
                    <?php endif; ?>
                </ul>
            </header>

            <div class="com-appointments-main">
                <h2 id="appointment-step-title" class="com-appointments-step-title" tabindex="-1">
                    <span class="visually-hidden"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_STEP_OF', $confirm ? 2 : 1, 2); ?></span>
                    <?php echo Text::_($confirm ? 'COM_APPOINTMENTS_MANAGE_RESCHEDULE' : 'COM_APPOINTMENTS_BOOKING_STEP_TIME'); ?>
                </h2>

                <?php if ($confirm) : ?>
                    <form method="post" action="<?php echo Route::_('index.php?option=com_appointments&task=booking.manage'); ?>" class="com-appointments-form com-appointments-manage-form">
                        <input type="hidden" name="id" value="<?php echo (int) $this->item->id; ?>">
                        <input type="hidden" name="action" value="<?php echo $this->escape($this->action); ?>">
                        <input type="hidden" name="signature" value="<?php echo $this->escape($this->signature); ?>">
                        <input type="hidden" name="month" value="<?php echo $this->escape($this->month->format('Y-m')); ?>">
                        <input type="hidden" name="day" value="<?php echo $this->escape($this->day); ?>">
                        <input type="hidden" name="timezone" value="<?php echo $this->escape($this->zone->getName()); ?>">
                        <input type="hidden" name="start" value="<?php echo $this->escape($this->selected->format('Y-m-d H:i:s')); ?>">
                        <div class="com-appointments-field">
                            <div class="form-check com-appointments-confirm">
                                <input class="form-check-input<?php echo $invalid; ?>" id="manage-confirm" type="checkbox" name="confirm" value="1" required<?php echo $confirmAria; ?>>
                                <label class="form-check-label" for="manage-confirm"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_CONFIRM'); ?></label>
                                <?php if (isset($errors['confirm'])) : ?>
                                    <div id="manage-confirm-error" class="invalid-feedback com-appointments-field-error"><?php echo $this->escape(Text::_($errors['confirm'])); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php echo HTMLHelper::_('form.token'); ?>
                        <div class="com-appointments-actions">
                            <button type="submit" class="btn btn-primary"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_RESCHEDULE'); ?></button>
                        </div>
                    </form>
                <?php else : ?>
                    <form id="appointment-time-form" class="com-appointments-time-form" method="get" action="<?php echo $this->escape($action); ?>#appointment-booking">
                        <?php foreach ($hidden as $name => $value) : ?>
                            <input type="hidden" name="<?php echo $this->escape($name); ?>" value="<?php echo $this->escape($value); ?>">
                        <?php endforeach; ?>
                        <?php echo $this->loadTemplate('picker'); ?>
                    </form>
                    <?php echo $this->loadTemplate('zone'); ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
