<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception;

defined('_JEXEC') or die;

/**
 * 413: a request body or a calendar object above the limit.
 *
 * @since  1.4.0
 */
final class RequestTooLarge extends Exception
{
    public function getHTTPCode()
    {
        return 413;
    }
}
