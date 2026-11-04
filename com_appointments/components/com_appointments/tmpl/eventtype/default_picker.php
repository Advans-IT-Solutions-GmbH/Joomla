<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/*
 * Step 1 of the booking page: month navigation, calendar and, once a day is chosen, the
 * times of that day; before that a hint to choose a day stands where the times will be, so
 * the card keeps its width. The full page includes it inside the GET form
 * #appointment-time-form, and the same file is the response of the background update
 * (format=raw, together with default_zone_note.php), so a template override of this file
 * applies to both.
 *
 * Every day and month is a plain link that ends at #appointment-picker. A time is a radio
 * button named "start" with its own "Next" button: the stylesheet shows the button of the
 * chosen time only, and pressing it opens step 2 at the address with start=<UTC time>.
 * On a narrow screen the chosen day shows its times instead of the calendar, with a link
 * back to the calendar; on a wide screen both stand side by side.
 */
$zone = $this->zone->getName();
$month = $this->month->format('Y-m');
$dates = new DateDisplay(static fn (string $key): string => Text::_($key));
$values = $this->inputData;
$slotError = $this->errors['start'] ?? null;
$dayFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY');
// The legend shows the day without the year, which stands in the month title above it.
$dayMonthFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY_MONTH');
$timeFormat = $this->timeFormat;
$withOffset = Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET');
$selectedDay = new DateTimeImmutable($this->day . ' 00:00:00', $this->zone);
$first = new DateTimeImmutable($this->days[0], new DateTimeZone('UTC'));
// Always six weeks, so the calendar keeps its height from month to month.
$cells = array_merge(array_fill(0, (int) $first->format('N') - 1, null), $this->days);
$cells = array_merge($cells, array_fill(0, 42 - count($cells), null));
$chevron = static fn (string $path): string => '<svg class="com-appointments-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$chosen = $this->dayChosen;
$hasSlots = $chosen && !empty($this->slots[$this->day]);
$checked = is_string($values['start'] ?? null) ? $values['start'] : '';
?>
<div id="appointment-picker" class="com-appointments-picker<?php echo $chosen ? ' com-appointments-picker-day' : ''; ?>" data-appointments-picker data-month="<?php echo $this->escape($month); ?>" data-day="<?php echo $chosen ? $this->escape($this->day) : ''; ?>" data-zone="<?php echo $this->escape($zone); ?>" data-clock="<?php echo $this->escape($this->clock); ?>" data-has-slots="<?php echo $hasSlots ? '1' : '0'; ?>">
    <?php if ($slotError !== null && !$chosen) : ?>
        <?php /* A refusal without a usable time names no day: the reason stands above the calendar. */ ?>
        <div id="booking-start-error" class="invalid-feedback com-appointments-field-error" role="alert"><?php echo $this->escape(Text::_($slotError)); ?></div>
    <?php endif; ?>
    <div class="com-appointments-picker-grid">
        <section class="com-appointments-calendar" aria-labelledby="appointment-calendar-title">
            <nav class="com-appointments-calendar-nav" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_MONTH'); ?>">
                <?php if ($this->isFirstMonth) : ?>
                    <span class="btn btn-outline-secondary com-appointments-calendar-prev disabled" aria-hidden="true"><?php echo $chevron('M10 3 5 8l5 5'); ?></span>
                <?php else : ?>
                    <a class="btn btn-outline-secondary com-appointments-calendar-prev" href="<?php echo $this->bookingLink(['month' => $this->month->modify('-1 month')->format('Y-m')], 'appointment-picker'); ?>" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_PREVIOUS'); ?>" title="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_PREVIOUS'); ?>" data-appointments-load><?php echo $chevron('M10 3 5 8l5 5'); ?></a>
                <?php endif; ?>
                <h3 id="appointment-calendar-title" class="com-appointments-calendar-title"><?php echo $this->escape($dates->format($this->month, Text::_('COM_APPOINTMENTS_DATE_FORMAT_MONTH'))); ?></h3>
                <a class="btn btn-outline-secondary com-appointments-calendar-next" href="<?php echo $this->bookingLink(['month' => $this->month->modify('+1 month')->format('Y-m')], 'appointment-picker'); ?>" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT'); ?>" title="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT'); ?>" data-appointments-load><?php echo $chevron('m6 3 5 5-5 5'); ?></a>
            </nav>
            <table class="com-appointments-calendar-grid">
                <caption class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CHOOSE_DAY'); ?></caption>
                <thead>
                    <tr>
                        <?php foreach ($dates->weekdays() as $weekday) : ?>
                            <th scope="col" class="com-appointments-weekday"><abbr title="<?php echo $this->escape($weekday['full']); ?>"><?php echo $this->escape($weekday['short']); ?></abbr></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_chunk($cells, 7) as $week) : ?>
                        <tr>
                            <?php foreach ($week as $day) : ?>
                                <?php if ($day === null) : ?>
                                    <td class="com-appointments-day-cell com-appointments-day-empty"></td>
                                <?php else : ?>
                                    <?php
                                    $label = $dates->format(new DateTimeImmutable($day, new DateTimeZone('UTC')), $dayFormat);
                                    $isSelected = $chosen && $day === $this->day;
                                    ?>
                                    <td class="com-appointments-day-cell">
                                        <?php if (!empty($this->slots[$day])) : ?>
                                            <a class="com-appointments-day com-appointments-day-available<?php echo $isSelected ? ' com-appointments-day-selected' : ''; ?>" href="<?php echo $this->bookingLink(['month' => $month, 'day' => $day], 'appointment-picker'); ?>" aria-label="<?php echo $this->escape($label); ?>"<?php echo $isSelected ? ' aria-current="date"' : ''; ?> data-appointments-load><?php echo (int) substr($day, 8, 2); ?></a>
                                        <?php else : ?>
                                            <span class="com-appointments-day com-appointments-day-unavailable"><?php echo (int) substr($day, 8, 2); ?></span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

        <?php if ($chosen) : ?>
            <div id="appointment-times" class="com-appointments-times" tabindex="-1">
                <a class="btn btn-outline-secondary com-appointments-back com-appointments-times-back" href="<?php echo $this->bookingLink(['month' => $month], 'appointment-picker'); ?>" data-appointments-load>
                    <?php echo $chevron('M10 3 5 8l5 5'); ?>
                    <span><?php echo Text::_('COM_APPOINTMENTS_BOOKING_BACK_CALENDAR'); ?></span>
                </a>
                <?php if ($slotError !== null && !$hasSlots) : ?>
                    <div id="booking-start-error" class="invalid-feedback com-appointments-field-error" role="alert"><?php echo $this->escape(Text::_($slotError)); ?></div>
                <?php endif; ?>
                <?php if (!$hasSlots) : ?>
                    <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NO_SLOTS'); ?></p>
                <?php else : ?>
                    <fieldset class="com-appointments-slots"<?php echo $slotError !== null ? ' aria-describedby="booking-start-error"' : ''; ?>>
                        <legend class="com-appointments-slots-legend"><span class="visually-hidden com-appointments-slots-legend-full"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_SLOTS_ON', $this->escape($dates->format($selectedDay, $dayFormat))); ?></span><span class="com-appointments-slots-legend-day" aria-hidden="true"><?php echo $this->escape($dates->format($selectedDay, $dayMonthFormat)); ?></span></legend>
                        <?php if ($slotError !== null) : ?>
                            <div id="booking-start-error" class="invalid-feedback com-appointments-field-error"><?php echo $this->escape(Text::_($slotError)); ?></div>
                        <?php endif; ?>
                        <div class="com-appointments-slot-list">
                            <?php foreach ($this->slots[$this->day] as $i => $slot) : ?>
                                <?php $slotValue = $slot->format('Y-m-d H:i:s'); ?>
                                <div class="form-check com-appointments-slot">
                                    <input class="form-check-input com-appointments-slot-input<?php echo $slotError !== null ? ' is-invalid' : ''; ?>" type="radio" id="booking-slot-<?php echo (int) $i; ?>" name="start" value="<?php echo $slotValue; ?>" required<?php echo $checked === $slotValue ? ' checked' : ''; ?><?php echo $slotError !== null ? ' aria-invalid="true"' : ''; ?><?php echo $i === 0 && $slotError !== null ? ' autofocus' : ''; ?>>
                                    <label class="form-check-label com-appointments-slot-time" id="booking-slot-<?php echo (int) $i; ?>-label" for="booking-slot-<?php echo (int) $i; ?>"><?php echo $this->escape($dates->time($slot->setTimezone($this->zone), $timeFormat, $withOffset)); ?></label>
                                    <button type="submit" class="btn btn-primary com-appointments-slot-next" aria-describedby="booking-slot-<?php echo (int) $i; ?>-label"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT_STEP'); ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <div class="com-appointments-times-hint">
                <p class="com-appointments-times-hint-text"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_TIMES_HINT'); ?></p>
            </div>
        <?php endif; ?>
    </div>
    <input type="hidden" name="timezone" value="<?php echo $this->escape($zone); ?>">
    <?php if ($this->clock !== $this->defaultClock) : ?>
        <input type="hidden" name="clock" value="<?php echo $this->escape($this->clock); ?>">
    <?php endif; ?>
</div>
