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
 * The booking page while bookings are not possible: the site has no valid public site URL, so the
 * component sends no e-mail and therefore takes no booking (HtmlView::unavailable()). A neutral
 * notice for the visitor; the reason is shown to the administrator in the system checks.
 */
?>
<div class="com-appointments com-appointments-eventtype com-appointments-unavailable">
    <div class="com-appointments-container">
        <div class="alert alert-info" role="status">
            <p class="mb-0"><?php echo Text::_('COM_APPOINTMENTS_BOOKING_UNAVAILABLE'); ?></p>
        </div>
    </div>
</div>
