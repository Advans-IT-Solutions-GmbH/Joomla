<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\Forbidden;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Server;

defined('_JEXEC') or die;

/**
 * RFC 4791, 5.3.2.1: the precondition `CALDAV:no-uid-conflict` failed, another
 * object of the same calendar carries the UID. The conflicting object's address
 * is not named.
 *
 * @since  1.4.0
 */
final class NoUidConflict extends Forbidden
{
    public function serialize(Server $server, \DOMElement $errorNode)
    {
        $errorNode->appendChild($errorNode->ownerDocument->createElementNS('urn:ietf:params:xml:ns:caldav', 'cal:no-uid-conflict'));
    }
}
