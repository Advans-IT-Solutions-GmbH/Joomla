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

defined('_JEXEC') or die;
$zone = new DateTimeZone($this->item->invitee_timezone);
$start = UtcTimestamp::parse($this->item->start_utc)->setTimezone($zone);
$end = UtcTimestamp::parse($this->item->end_utc)->setTimezone($zone);
$dates = new DateDisplay(static fn (string $key): string => Text::_($key));
$time = Text::_('COM_APPOINTMENTS_DATE_FORMAT_TIME');
$withOffset = Text::_('COM_APPOINTMENTS_TIME_WITH_OFFSET');
$location = trim((string) $this->item->location_text);
?>
<div class="com-appointments com-appointments-confirmation">
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
        <p class="com-appointments-status"><?php echo Text::_(match ((int) $this->item->state) {
            0 => 'COM_APPOINTMENTS_BOOKING_PENDING',
            1 => 'COM_APPOINTMENTS_BOOKING_CONFIRMED',
            2 => 'COM_APPOINTMENTS_MANAGE_CANCELLED',
        }); ?></p>
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
    </div>
</div>
