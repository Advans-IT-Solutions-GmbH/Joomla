<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\DateDisplay;
use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Router\Route;

defined('_JEXEC') or die;

/*
 * Step 3 of the booking: the confirmation. A card with the outcome, the summary of the
 * appointment, its calendar entry as an .ics download and the reschedule and cancel links
 * of the confirmation email. Only the browser session that made the booking sees it.
 */
$zone = new DateTimeZone($this->item->invitee_timezone);
$start = UtcTimestamp::parse($this->item->start_utc)->setTimezone($zone);
$end = UtcTimestamp::parse($this->item->end_utc)->setTimezone($zone);
$dates = new DateDisplay(static fn (string $key): string => Text::_($key));
$time = Text::_('COM_APPOINTMENTS_DATE_FORMAT_TIME');
$withOffset = Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET');
$location = trim((string) $this->item->location_text);
$state = (int) $this->item->state;
$mark = match ($state) {
    1 => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    2 => '<path d="M7 7l10 10M17 7 7 17"/>',
    default => '<circle cx="12" cy="12" r="8"/><path d="M12 8v4l2.5 1.5"/>',
};
?>
<div class="com-appointments com-appointments-confirmation">
    <div class="com-appointments-container">
        <?php // The redirect after the booking lands at the card; its status takes the focus. ?>
        <div id="appointment-confirmation" class="com-appointments-card com-appointments-confirmation-card com-appointments-state-<?php echo $state; ?>">
            <span class="com-appointments-confirmation-mark" aria-hidden="true"><svg viewBox="0 0 24 24" width="28" height="28" focusable="false" fill="none" stroke="currentColor" stroke-width="2.25" stroke-linecap="round" stroke-linejoin="round"><?php echo $mark; ?></svg></span>
            <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
            <p class="com-appointments-status" role="status" tabindex="-1" autofocus data-appointments-result<?php echo $this->notices ? ' aria-describedby="appointment-notices"' : ''; ?>><?php echo Text::_(match ($state) {
                0 => 'COM_APPOINTMENTS_BOOKING_PENDING',
                1 => 'COM_APPOINTMENTS_BOOKING_CONFIRMED',
                2 => 'COM_APPOINTMENTS_MANAGE_CANCELLED',
            }); ?></p>
            <?php if ($this->notices) : ?>
                <?php // Messages of this outcome (see ResultNoticesTrait), rendered like Joomla's system messages. ?>
                <div id="appointment-notices" class="com-appointments-notices">
                    <?php foreach ($this->notices as $notice) : ?>
                        <div class="alert alert-<?php echo $notice['type']; ?> com-appointments-alert"><?php echo $notice['message']; ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <h2 class="com-appointments-title"><?php echo $this->escape($this->item->title); ?></h2>
            <dl class="com-appointments-summary">
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_DATE'); ?></dt>
                <dd><?php echo $this->escape($dates->format($start, Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY'))); ?></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_TIME'); ?></dt>
                <dd><?php echo $this->escape($dates->range($start, $end, $time, Text::_('COM_APPOINTMENTS_DATE_FORMAT_DAY'), $withOffset, Text::_('COM_APPOINTMENTS_SUMMARY_TIME_RANGE'), Text::_('COM_APPOINTMENTS_DATE_TIME'))); ?></dd>
                <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_ZONE'); ?></dt>
                <dd><?php echo $this->escape($zone->getName()); ?></dd>
                <?php if ($location !== '') : ?>
                    <dt><?php echo Text::_('COM_APPOINTMENTS_SUMMARY_LOCATION'); ?></dt>
                    <dd><?php echo $this->escape($location); ?></dd>
                <?php endif; ?>
            </dl>
            <?php if ($this->item->conference_url) : ?>
                <p class="com-appointments-join">
                    <a class="btn btn-primary" href="<?php echo $this->escape($this->item->conference_url); ?>" rel="noreferrer noopener"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_JOIN'); ?></a>
                </p>
            <?php endif; ?>
            <?php if ($this->item->conference_state === 'failed') : ?>
                <p class="alert alert-warning com-appointments-alert"><?php echo Text::_('COM_APPOINTMENTS_CONFERENCE_MISSING'); ?></p>
            <?php endif; ?>
            <?php if ($this->calendarLink !== '' || $this->manageLinks !== []) : ?>
                <div class="com-appointments-actions com-appointments-confirmation-actions">
                    <?php if ($this->calendarLink !== '') : ?>
                        <a class="btn btn-secondary com-appointments-calendar-add" href="<?php echo $this->calendarLink; ?>" download>
                            <svg class="com-appointments-icon" viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4M12 13v5M9.5 15.5h5"/></svg>
                            <span><?php echo Text::_('COM_APPOINTMENTS_BOOKING_ADD_CALENDAR'); ?></span>
                        </a>
                    <?php endif; ?>
                    <?php if (isset($this->manageLinks['reschedule_url'])) : ?>
                        <a class="btn btn-outline-secondary com-appointments-reschedule" href="<?php echo $this->escape($this->manageLinks['reschedule_url']); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_RESCHEDULE'); ?></a>
                    <?php endif; ?>
                    <?php if (isset($this->manageLinks['cancel_url'])) : ?>
                        <a class="btn btn-outline-secondary com-appointments-cancel" href="<?php echo $this->escape($this->manageLinks['cancel_url']); ?>"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CANCEL'); ?></a>
                    <?php endif; ?>
                </div>
                <p class="form-text com-appointments-confirmation-note"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CONFIRMATION_NOTE'); ?></p>
            <?php endif; ?>
        </div>
        <p><a class="btn btn-secondary" href="<?php echo Route::_('index.php?option=com_appointments&view=eventtypes'); ?>"><?php echo Text::_('COM_APPOINTMENTS_BACK_TO_OVERVIEW'); ?></a></p>
    </div>
</div>
