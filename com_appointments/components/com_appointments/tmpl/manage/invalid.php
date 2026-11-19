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
 * A signed link that opens nothing (HtmlView::displayInvalid()): the same page for an earlier link
 * of a rescheduled or cancelled appointment, a used, tampered or unknown link. Nothing of the
 * appointment is shown, only what to do now.
 */
?>
<div class="com-appointments com-appointments-manage com-appointments-invalid-link">
    <div class="com-appointments-container">
        <h1 class="com-appointments-heading"><?php echo $this->escape($this->pageHeading); ?></h1>
        <p class="com-appointments-status" role="status" tabindex="-1" autofocus><?php echo Text::_('COM_APPOINTMENTS_MANAGE_ERROR_LINK'); ?></p>
        <p><?php echo Text::_('COM_APPOINTMENTS_MANAGE_INVALID_HELP'); ?></p>
        <?php if ($this->bookAgain !== '') : ?>
            <p><a class="btn btn-primary com-appointments-book-again" href="<?php echo $this->bookAgain; ?>"><?php echo Text::_('COM_APPOINTMENTS_MANAGE_BOOK_AGAIN'); ?></a></p>
        <?php endif; ?>
    </div>
</div>
