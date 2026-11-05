<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

/*
 * Information column, layout "Company logo and appointment": the logo of the event type,
 * then the event details (default_info_event.php). Without a logo only the details show.
 * The logo is decorative next to the title, so its text alternative is empty.
 */
$logo = $this->infoImage((string) ($this->item->info_logo ?? ''));
?>
<?php if ($logo !== null) : ?>
    <div class="com-appointments-info-media com-appointments-info-logo">
        <img class="com-appointments-logo" src="<?php echo $this->escape($logo['src']); ?>" alt=""<?php echo $logo['width'] > 0 ? ' width="' . $logo['width'] . '"' : ''; ?><?php echo $logo['height'] > 0 ? ' height="' . $logo['height'] . '"' : ''; ?> decoding="async">
    </div>
<?php endif; ?>
<?php echo $this->loadTemplate('info_event'); ?>
