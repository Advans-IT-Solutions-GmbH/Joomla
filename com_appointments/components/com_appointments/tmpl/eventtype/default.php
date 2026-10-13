<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Language\Text;
use Joomla\Filter\InputFilter;

defined('_JEXEC') or die;
?>
<div class="com-appointments">
    <h1><?php echo $this->escape($this->item->title); ?></h1>
    <div class="com-appointments-description"><?php echo InputFilter::getInstance([], [], 1, 1)->clean((string) $this->item->description, 'html'); ?></div>
    <p><?php echo Text::sprintf((int) $this->item->duration_minutes === 1 ? 'COM_APPOINTMENTS_DURATION_MINUTES_1' : 'COM_APPOINTMENTS_DURATION_MINUTES_MORE', (int) $this->item->duration_minutes); ?></p>
    <p><?php echo $this->escape((string) $this->item->location_text); ?></p>
</div>
