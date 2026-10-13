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

/**
 * A duration of one minute must not read "1 minutes", and the same holds in
 * German and French. The two forms are selected from a map of complete keys
 * rather than through Text::plural(), because this repository requires every
 * language key to be written out in full so the language lint sees it: a key
 * Joomla assembles at runtime is invisible to that check, and a key no code
 * names is reported as unused. The map gives the same result for the three
 * shipped languages, all of which have one singular form at exactly one.
 */
$durationKeys = [
    'one'  => 'COM_APPOINTMENTS_DURATION_MINUTES_1',
    'many' => 'COM_APPOINTMENTS_DURATION_MINUTES_MORE',
];
?>
<div class="com-appointments com-appointments-eventtypes">
    <h1 class="com-appointments-heading"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPES_HEADING'); ?></h1>

    <?php if ($this->items === []) : ?>
        <p class="com-appointments-empty"><?php echo Text::_('COM_APPOINTMENTS_EVENTTYPES_EMPTY'); ?></p>
    <?php else : ?>
        <ul class="com-appointments-list">
            <?php foreach ($this->items as $item) : ?>
                <?php
                $minutes     = (int) $item->duration_minutes;
                $durationKey = $minutes === 1 ? $durationKeys['one'] : $durationKeys['many'];
                ?>
                <li class="com-appointments-list-item">
                    <span class="com-appointments-list-title">
                        <?php echo htmlspecialchars($item->title, ENT_QUOTES, 'UTF-8'); ?>
                    </span>
                    <span class="com-appointments-duration">
                        <?php echo Text::sprintf($durationKey, $minutes); ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
