<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/*
 * The time zone of the date-and-time step, below the calendar and always visible: the label
 * "Time zone" and a field that shows the zone of the times.
 *
 * Without JavaScript the field is a normal select of every zone with a button that shows the
 * times in the chosen zone (a GET form that keeps month and day). The booking script replaces
 * the select by the button [data-appointments-zone-button] and the panel
 * [data-appointments-zone-panel] below: a search field (ARIA combobox) over a list of the zones
 * (ARIA listbox) with the current time of each zone, and a switch between the 12-hour and the
 * 24-hour clock. Every text of the panel is rendered here, so it is translated and can be
 * changed in a template override; the script only fills in the list from the select and the
 * current times. A chosen zone or clock reloads the times like a day does; the clock is also a
 * parameter of the address (clock=12 or clock=24), so all times of the page follow it.
 */
[$action, $hidden] = $this->formTarget();
$zone = $this->zone->getName();
?>
<div class="com-appointments-zone-line">
    <?php echo $this->loadTemplate('zone_note'); ?>
    <form id="appointment-zone-form" class="com-appointments-zone-form" method="get" action="<?php echo $this->escape($action); ?>#appointment-picker" data-appointments-zone-form>
        <?php foreach ($hidden as $name => $value) : ?>
            <input type="hidden" name="<?php echo $this->escape($name); ?>" value="<?php echo $this->escape($value); ?>">
        <?php endforeach; ?>
        <input type="hidden" name="month" value="<?php echo $this->escape($this->month->format('Y-m')); ?>">
        <?php if ($this->dayChosen) : ?>
            <input type="hidden" name="day" value="<?php echo $this->escape($this->day); ?>">
        <?php endif; ?>
        <?php if ($this->clock !== $this->defaultClock) : ?>
            <input type="hidden" name="clock" value="<?php echo $this->escape($this->clock); ?>">
        <?php endif; ?>
        <label class="com-appointments-zone-label" id="appointment-zone-label" for="appointment-zone"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE_LABEL'); ?></label>
        <div class="com-appointments-zone-row">
            <select class="form-select com-appointments-zone-select" name="timezone" id="appointment-zone" aria-describedby="appointment-zone-note">
                <?php foreach (DateTimeZone::listIdentifiers() as $name) : ?>
                    <option value="<?php echo $this->escape($name); ?>"<?php echo $name === $zone ? ' selected' : ''; ?>><?php echo $this->escape(str_replace('_', ' ', $name)); ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-secondary com-appointments-zone-submit"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_SHOW_TIMES'); ?></button>
        </div>
        <div class="com-appointments-zone-picker" data-appointments-zone data-clock="<?php echo $this->escape($this->clock); ?>" data-default-clock="<?php echo $this->escape($this->defaultClock); ?>" hidden>
            <button type="button" class="com-appointments-zone-button" id="appointment-zone-button" aria-haspopup="dialog" aria-expanded="false" aria-controls="appointment-zone-panel" aria-labelledby="appointment-zone-label appointment-zone-button" data-appointments-zone-button>
                <svg class="com-appointments-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
                <span class="com-appointments-zone-current" data-appointments-zone-current><?php echo $this->escape(str_replace('_', ' ', $zone)); ?></span>
                <span class="com-appointments-zone-now" data-appointments-zone-now></span>
                <svg class="com-appointments-icon com-appointments-zone-chevron" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="m4 6 4 4 4-4" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </button>
            <div class="com-appointments-zone-panel" id="appointment-zone-panel" role="dialog" aria-labelledby="appointment-zone-label" data-appointments-zone-panel hidden>
                <div class="com-appointments-zone-tools">
                    <label class="visually-hidden" for="appointment-zone-search"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE_SEARCH'); ?></label>
                    <input class="form-control com-appointments-zone-search" id="appointment-zone-search" type="text" role="combobox" aria-autocomplete="list" aria-expanded="true" aria-controls="appointment-zone-list" autocomplete="off" spellcheck="false" placeholder="<?php echo $this->escape(Text::_('COM_APPOINTMENTS_BOOKING_ZONE_SEARCH')); ?>" data-appointments-zone-search>
                    <div class="com-appointments-clock" role="group" aria-label="<?php echo $this->escape(Text::_('COM_APPOINTMENTS_BOOKING_CLOCK')); ?>">
                        <button type="button" class="com-appointments-clock-option" data-clock="12" aria-pressed="<?php echo $this->clock === '12' ? 'true' : 'false'; ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CLOCK_12'); ?></button>
                        <button type="button" class="com-appointments-clock-option" data-clock="24" aria-pressed="<?php echo $this->clock === '24' ? 'true' : 'false'; ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CLOCK_24'); ?></button>
                    </div>
                </div>
                <ul class="com-appointments-zone-list" id="appointment-zone-list" role="listbox" aria-labelledby="appointment-zone-label" data-appointments-zone-list></ul>
                <p class="com-appointments-zone-empty" data-appointments-zone-empty hidden><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ZONE_NONE'); ?></p>
            </div>
        </div>
    </form>
</div>
