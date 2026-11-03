<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

/*
 * Information column, layout "Person with photo": the photo, name and optional role of the
 * person the visitor meets, then the event details (default_info_event.php). Each part is
 * left out when it is empty. The name stands next to the photo, so the photo's text
 * alternative is empty.
 */
$photo = $this->infoImage((string) ($this->item->info_photo ?? ''));
$name = trim((string) ($this->item->info_name ?? ''));
$role = trim((string) ($this->item->info_role ?? ''));
?>
<?php if ($photo !== null || $name !== '' || $role !== '') : ?>
    <div class="com-appointments-info-media com-appointments-host">
        <?php if ($photo !== null) : ?>
            <img class="com-appointments-host-photo" src="<?php echo $this->escape($photo['src']); ?>" alt=""<?php echo $photo['width'] > 0 ? ' width="' . $photo['width'] . '"' : ''; ?><?php echo $photo['height'] > 0 ? ' height="' . $photo['height'] . '"' : ''; ?> decoding="async">
        <?php endif; ?>
        <?php if ($name !== '' || $role !== '') : ?>
            <p class="com-appointments-host-text">
                <?php if ($name !== '') : ?>
                    <span class="com-appointments-host-name"><?php echo $this->escape($name); ?></span>
                <?php endif; ?>
                <?php if ($role !== '') : ?>
                    <span class="com-appointments-host-role"><?php echo $this->escape($role); ?></span>
                <?php endif; ?>
            </p>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php echo $this->loadTemplate('info_event'); ?>
