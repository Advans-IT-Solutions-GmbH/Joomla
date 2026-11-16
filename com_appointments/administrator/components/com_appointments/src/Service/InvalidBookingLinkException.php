<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Joomla\CMS\Language\Text;

defined('_JEXEC') or die;

/**
 * A signed booking link that opens nothing: unknown id, wrong signature, a link of an earlier
 * version of the appointment. Always 404 with the same message, so the answer does not tell which
 * of them applies (the deliberate privacy boundary of the signed pages). The message is meant for
 * the person with the link and is shown by the site template's error page.
 */
final class InvalidBookingLinkException extends \RuntimeException
{
    public function __construct()
    {
        $message = Text::_('COM_APPOINTMENTS_MANAGE_ERROR_LINK');

        // Outside the site part (no component language loaded) the generic text, never a raw key.
        parent::__construct($message === 'COM_APPOINTMENTS_MANAGE_ERROR_LINK' ? Text::_('JERROR_PAGE_NOT_FOUND') : $message, 404);
    }
}
