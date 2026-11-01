<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/*
 * The date-and-time part of the booking page: month navigation, calendar, the times of
 * the chosen day and the displayed time zone. The full page includes it, and the same
 * file is the whole response of the background update (format=raw), so a template
 * override of this file applies to both. Every day and month is a plain link that ends
 * at #appointment-picker: without JavaScript the browser loads the page and lands here
 * again; with JavaScript the booking script loads only this part and replaces it.
 */
$id = (int) $this->item->id;
$zone = $this->zone->getName();
$month = $this->month->format('Y-m');
$dates = new DateDisplay(static fn (string $key): string => Text::_($key));
$link = static fn (string $m, string $day = '') => Route::_('index.php?option=com_appointments&view=eventtype&id=' . $id
    . '&timezone=' . urlencode($zone) . '&month=' . urlencode($m) . ($day !== '' ? '&day=' . urlencode($day) : '')) . '#appointment-picker';
$values = $this->inputData;
$slotError = $this->errors['start'] ?? null;
$dayFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY');
$timeFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_TIME');
$withOffset = Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET');
$selectedDay = new DateTimeImmutable($this->day . ' 00:00:00', $this->zone);
$first = new DateTimeImmutable($this->days[0], new DateTimeZone('UTC'));
// Always six weeks, so the calendar keeps its height from month to month.
$cells = array_merge(array_fill(0, (int) $first->format('N') - 1, null), $this->days);
$cells = array_merge($cells, array_fill(0, 42 - count($cells), null));
$chevron = static fn (string $path): string => '<svg class="com-appointments-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="' . $path . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
$hasSlots = !empty($this->slots[$this->day]);
?>
<div id="appointment-picker" class="com-appointments-picker" data-appointments-picker data-month="<?php echo $this->escape($month); ?>" data-zone="<?php echo $this->escape($zone); ?>" data-has-slots="<?php echo $hasSlots ? '1' : '0'; ?>">
    <div class="com-appointments-picker-grid">
        <section class="com-appointments-calendar" aria-labelledby="appointment-calendar-title">
            <nav class="com-appointments-calendar-nav" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_MONTH'); ?>">
                <?php if ($this->isFirstMonth) : ?>
                    <span class="btn btn-outline-secondary com-appointments-calendar-prev disabled" aria-hidden="true"><?php echo $chevron('M10 3 5 8l5 5'); ?></span>
                <?php else : ?>
                    <a class="btn btn-outline-secondary com-appointments-calendar-prev" href="<?php echo $link($this->month->modify('-1 month')->format('Y-m')); ?>" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_PREVIOUS'); ?>" title="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_PREVIOUS'); ?>" data-appointments-load><?php echo $chevron('M10 3 5 8l5 5'); ?></a>
                <?php endif; ?>
                <h3 id="appointment-calendar-title" class="com-appointments-calendar-title"><?php echo $this->escape($dates->format($this->month, Text::_('COM_APPOINTMENTS_DATE_FORMAT_MONTH'))); ?></h3>
                <a class="btn btn-outline-secondary com-appointments-calendar-next" href="<?php echo $link($this->month->modify('+1 month')->format('Y-m')); ?>" aria-label="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT'); ?>" title="<?php echo Text::_('COM_APPOINTMENTS_BOOKING_NEXT'); ?>" data-appointments-load><?php echo $chevron('m6 3 5 5-5 5'); ?></a>
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
                                    <?php $label = $dates->format(new DateTimeImmutable($day, new DateTimeZone('UTC')), $dayFormat); ?>
                                    <td class="com-appointments-day-cell">
                                        <?php if (!empty($this->slots[$day])) : ?>
                                            <a class="com-appointments-day com-appointments-day-available<?php echo $day === $this->day ? ' com-appointments-day-selected' : ''; ?>" href="<?php echo $link($month, $day); ?>" aria-label="<?php echo $this->escape($label); ?>"<?php echo $day === $this->day ? ' aria-current="date"' : ''; ?> data-appointments-load><?php echo (int) substr($day, 8, 2); ?></a>
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

        <div class="com-appointments-times">
            <?php if (!$hasSlots) : ?>
                <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_NO_SLOTS'); ?></p>
            <?php else : ?>
                <fieldset class="com-appointments-slots"<?php echo $slotError !== null ? ' aria-describedby="booking-start-error"' : ''; ?>>
                    <legend class="com-appointments-slots-legend"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_SLOTS_ON', $this->escape($dates->format($selectedDay, $dayFormat))); ?></legend>
                    <div class="com-appointments-slot-list">
                        <?php foreach ($this->slots[$this->day] as $i => $slot) : ?>
                            <?php $slotValue = $slot->format('Y-m-d H:i:s'); ?>
                            <div class="form-check com-appointments-slot">
                                <input class="form-check-input<?php echo $slotError !== null ? ' is-invalid' : ''; ?>" type="radio" id="booking-slot-<?php echo (int) $i; ?>" name="booking[start]" value="<?php echo $slotValue; ?>" required<?php echo ($values['start'] ?? null) === $slotValue ? ' checked' : ''; ?><?php echo $slotError !== null ? ' aria-invalid="true"' : ''; ?><?php echo $i === 0 && $slotError !== null ? ' autofocus' : ''; ?>>
                                <label class="form-check-label" for="booking-slot-<?php echo (int) $i; ?>"><?php echo $this->escape($dates->time($slot->setTimezone($this->zone), $timeFormat, $withOffset)); ?></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($slotError !== null) : ?>
                        <div id="booking-start-error" class="invalid-feedback com-appointments-field-error"><?php echo $this->escape(Text::_($slotError)); ?></div>
                    <?php endif; ?>
                </fieldset>
            <?php endif; ?>
        </div>
    </div>
    <p id="appointment-zone-note" class="form-text com-appointments-zone-note"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_ZONE_NOTE', $this->escape($zone)); ?></p>
    <input type="hidden" name="booking[timezone]" value="<?php echo $this->escape($zone); ?>">
</div>
