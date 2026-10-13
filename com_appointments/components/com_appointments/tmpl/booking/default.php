<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\UtcTimestamp;
use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;
$zone = new DateTimeZone($this->item->invitee_timezone);
$start = UtcTimestamp::parse($this->item->start_utc)->setTimezone($zone);
?>
<div class="com-appointments">
    <h1><?php echo Text::_('COM_APPOINTMENTS_BOOKING_CONFIRMED_HEADING'); ?></h1>
    <p><?php echo Text::_((int) $this->item->state === 0 ? 'COM_APPOINTMENTS_BOOKING_PENDING' : 'COM_APPOINTMENTS_BOOKING_CONFIRMED'); ?></p>
    <h2><?php echo $this->escape($this->item->title); ?></h2>
    <p><?php echo $this->escape($start->format('Y-m-d H:i') . ' ' . $zone->getName()); ?></p>
    <p><?php echo $this->escape((string) $this->item->location_text); ?></p>
    <?php if ($this->item->conference_url) : ?>
        <a href="<?php echo $this->escape($this->item->conference_url); ?>" rel="noreferrer noopener"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_JOIN'); ?></a>
    <?php endif; ?>
</div>
