<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Joomla\CMS\Language\Text;
use Joomla\Filter\InputFilter;

defined('_JEXEC') or die;

/*
 * The event details of the information column: the title, duration, location type,
 * description and location text. On the details step it adds the chosen date, time and time
 * zone. The layouts "logo" and "person" show it below their image; the layout "event"
 * ("Appointment only") shows it alone, so it carries the page heading itself.
 */
$icon = static fn (string $paths): string => '<svg class="com-appointments-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">' . $paths . '</svg>';
$locationTypes = [
    'phone_host_calls' => ['COM_APPOINTMENTS_LOCATION_TYPE_PHONE_HOST', '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>'],
    'phone_invitee_calls' => ['COM_APPOINTMENTS_LOCATION_TYPE_PHONE_INVITEE', '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2"/>'],
    'in_person' => ['COM_APPOINTMENTS_LOCATION_TYPE_IN_PERSON', '<path d="M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12z"/><circle cx="12" cy="9" r="2.5"/>'],
    'conference' => ['COM_APPOINTMENTS_LOCATION_TYPE_CONFERENCE', '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3"/>'],
];
$locationType = $locationTypes[(string) $this->item->location_type] ?? null;
$location = trim((string) $this->item->location_text);
$description = trim((new InputFilter([], [], 1, 1))->clean((string) $this->item->description, 'html'));
$minutes = (int) $this->item->duration_minutes;
$selected = $this->selected;
?>
<?php if ($selected !== null) : ?>
    <?php
    $local = $selected->setTimezone($this->zone);
    // The duration is added in UTC, like the saved booking, so a clock change in between counts.
    $end = $selected->modify('+' . $minutes . ' minutes')->setTimezone($this->zone);
    $dates = new DateDisplay(static fn (string $key): string => Text::_($key));
    $dayFormat = Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY');
    ?>
<?php endif; ?>
<h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
<ul class="com-appointments-meta">
    <li class="com-appointments-duration"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'); ?><span><?php echo Text::sprintf($minutes === 1 ? 'COM_APPOINTMENTS_DURATION_MINUTES_1' : 'COM_APPOINTMENTS_DURATION_MINUTES_MORE', $minutes); ?></span></li>
    <?php if ($locationType !== null) : ?>
        <li class="com-appointments-location-type"><?php echo $icon($locationType[1]); ?><span><?php echo Text::_($locationType[0]); ?></span></li>
    <?php endif; ?>
    <?php if ($selected !== null) : ?>
        <li class="com-appointments-selected">
            <?php echo $icon('<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>'); ?>
            <span class="com-appointments-selected-text">
                <span class="visually-hidden"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_SELECTED'); ?></span>
                <span class="com-appointments-selected-date"><?php echo $this->escape($dates->format($local, $dayFormat)); ?></span>
                <span class="com-appointments-selected-time"><?php echo $this->escape($dates->range($local, $end, Text::_('COM_APPOINTMENTS_DATE_FORMAT_TIME'), $dayFormat, Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET'), Text::_('COM_APPOINTMENTS_SUMMARY_TIME_RANGE'), Text::_('COM_APPOINTMENTS_DATE_TIME'))); ?></span>
            </span>
        </li>
        <li class="com-appointments-selected-zone"><?php echo $icon('<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>'); ?><span><?php echo $this->escape($this->zone->getName()); ?></span></li>
    <?php endif; ?>
</ul>
<?php if ($description !== '') : ?>
    <div class="com-appointments-description"><?php echo $description; ?></div>
<?php endif; ?>
<?php if ($location !== '') : ?>
    <p class="com-appointments-location"><?php echo $this->escape($location); ?></p>
<?php endif; ?>
