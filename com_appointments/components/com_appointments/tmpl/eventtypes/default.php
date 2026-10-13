<?php
/**
 * @package     Appointments Component
 * @subpackage  Site
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;
?>
<div class="com-appointments com-appointments-eventtypes">
    <h1 class="com-appointments-heading"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPES_HEADING'); ?></h1>

    <?php if ($this->items === []) : ?>
        <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPES_EMPTY'); ?></p>
    <?php else : ?>
        <ul class="com-appointments-list">
            <?php foreach ($this->items as $item) : ?>
                <li class="com-appointments-list-item">
                    <span class="com-appointments-list-title">
                        <?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span class="com-appointments-duration">
                        <?php echo Text::sprintf('COM_APPOINTMENTS_DURATION_MINUTES', (int) $item->duration_minutes); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
