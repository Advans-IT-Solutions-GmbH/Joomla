<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\HTTP\ResponseInterface;
use Advans\Component\Appointments\Vendor\Sabre\HTTP\Sapi;

defined('_JEXEC') or die;

/**
 * sabre/dav sends some responses itself (a failed precondition). The gateway
 * adds its own headers to every response and sends it once at the end, so the
 * server's own sending does nothing.
 *
 * @since  1.4.0
 */
final class SilentSapi extends Sapi
{
    public static function sendResponse(ResponseInterface $response)
    {
    }
}
