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
 * The privacy notice at the foot of the information column, in every step: one short sentence
 * with a link to the privacy notice configured in the component options (menu item or address).
 * Without a configured notice nothing is shown. It is the only privacy text of the page with the
 * option "Privacy: Notice only" (default); the two checkbox options add their checkbox to the
 * details step as well.
 */
if ($this->privacyLink === '') {
    return;
}
?>
<p class="com-appointments-privacy-note"><?php echo Text::sprintf('COM_APPOINTMENTS_BOOKING_PRIVACY_NOTE', '<a class="com-appointments-privacy-link" href="' . $this->escape($this->privacyLink) . '" target="_blank" rel="noopener">' . Text::_('COM_APPOINTMENTS_BOOKING_PRIVACY_LINK') . '<span class="visually-hidden"> ' . Text::_('COM_APPOINTMENTS_BOOKING_NEW_WINDOW') . '</span></a>'); ?></p>
