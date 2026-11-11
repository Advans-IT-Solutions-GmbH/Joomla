<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Site\Helper\BackLink;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/*
 * The booking page in steps, one step at a time:
 *
 *   1. date and time   default_picker.php: the month calendar, then the times of the chosen day.
 *                      Choosing a time shows its "Next" button; the button opens step 2.
 *   2. details         default_details.php: the visitor's details for the chosen time.
 *   3. confirmation    the booking view (tmpl/booking/default.php).
 *
 * Beside the step stands the information column of the event type, in the layout chosen in
 * its "Display" settings: default_info_logo.php or default_info_person.php, both with the
 * event details of default_info_event.php, or default_info_event.php alone ("Appointment
 * only", for a site whose header already shows the logo). The title of the event type is the
 * one main heading of the page, in the information column or, with "Title: above the card",
 * above the card. The time-zone field stands below the times (default_zone.php, with the
 * sentence of default_zone_note.php that is also sent with the background update of the
 * times), the privacy notice at the foot of the information column (default_privacy_note.php).
 * Each file can be overridden on its own through a template override
 * (html/com_appointments/eventtype/).
 *
 * Every step is a normal address (month, day, start) and every change a link or a form, so
 * the page works without JavaScript and keeps its step on reload and with the back button.
 * The booking script (media/com_appointments/js/booking.js) only loads the next step in the
 * background and writes it into the browser history.
 *
 * Template-neutral markup: Joomla's standard form classes plus com-appointments-* hooks. The
 * component stylesheet draws a neutral look from the custom properties on .com-appointments,
 * which a site template sets to its own colours and fonts.
 */
[$action, $hidden] = $this->formTarget();
// The way back, '' on a menu item of this one event type unless its options show it (BackLink).
$back = BackLink::resolve(true);
$details = $this->step === 'details';
?>
<div class="com-appointments com-appointments-eventtype" data-appointments-booking>
    <div class="com-appointments-container" data-appointments-view data-step="<?php echo $details ? 'details' : 'time'; ?>">
        <?php if ($back !== '') : ?>
            <p><a class="btn btn-outline-secondary com-appointments-return" href="<?php echo $back; ?>"><?php echo Text::_($details ? 'JCANCEL' : 'COM_APPOINTMENTS_BACK_TO_OVERVIEW'); ?></a></p>
        <?php endif; ?>
        <?php if ($this->titleAbove) : ?>
            <h1 class="com-appointments-heading com-appointments-page-title"><?php echo $this->escape($this->pageHeading); ?></h1>
        <?php endif; ?>
        <div id="appointment-booking" class="com-appointments-layout com-appointments-layout-<?php echo $details ? 'details' : 'time'; ?><?php echo !$details && $this->dayChosen ? ' com-appointments-layout-day' : ''; ?> com-appointments-info-<?php echo $this->escape($this->infoLayout); ?>">
            <header class="com-appointments-info com-appointments-header">
                <?php if ($details) : ?>
                    <?php $local = $this->selected->setTimezone($this->zone); ?>
                    <a class="btn btn-outline-secondary com-appointments-back" href="<?php echo $this->bookingLink(['month' => $local->format('Y-m'), 'day' => $local->format('Y-m-d')], 'appointment-picker'); ?>" data-appointments-step>
                        <svg class="com-appointments-icon" viewBox="0 0 16 16" width="16" height="16" aria-hidden="true" focusable="false"><path d="M10 3 5 8l5 5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                        <span><?php echo Text::_('COM_APPOINTMENTS_BOOKING_BACK'); ?></span>
                    </a>
                <?php endif; ?>
                <?php echo $this->loadTemplate('info_' . $this->infoLayout); ?>
                <?php echo $this->loadTemplate('privacy_note'); ?>
            </header>

            <div class="com-appointments-main">
                <h2 id="appointment-step-title" class="com-appointments-step-title" tabindex="-1">
                    <span class="visually-hidden"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_STEP_OF', $details ? 2 : 1, 3); ?></span>
                    <?php echo Text::_($details ? 'COM_APPOINTMENTS_BOOKING_STEP_DETAILS' : 'COM_APPOINTMENTS_BOOKING_STEP_TIME'); ?>
                </h2>

                <?php if ($details) : ?>
                    <?php echo $this->loadTemplate('details'); ?>
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
