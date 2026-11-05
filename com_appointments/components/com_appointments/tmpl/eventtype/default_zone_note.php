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
 * The time zone of the times shown, at the start of the time-zone line below them. The
 * background update of the times (format=raw) sends it along, so the line names the zone of
 * the times just loaded.
 */
?>
<p id="appointment-zone-note" class="form-text com-appointments-zone-note"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_ZONE_NOTE', $this->escape($this->zone->getName())); ?></p>
