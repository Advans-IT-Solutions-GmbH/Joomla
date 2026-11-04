<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\BadRequest;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\Conflict;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\Forbidden;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\InsufficientStorage;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\InvalidSyncToken;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\NotFound;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\PreconditionFailed;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\ServiceUnavailable;

defined('_JEXEC') or die;

/**
 * Turns a refusal of a team service into the matching WebDAV error. The
 * message of the WebDAV error is fixed per status: the language key of the
 * refusal is internal and never reaches the client.
 *
 * @since  1.4.0
 */
final class DavErrors
{
    public static function fromTeam(TeamException $e, string $operation = ''): Exception
    {
        return match ($e->getCode()) {
            400     => new BadRequest('The calendar object is not accepted.'),
            403     => $operation === 'sync' ? new InvalidSyncToken('Invalid or unknown sync token.') : new Forbidden('Not allowed.'),
            404     => new NotFound('Not found.'),
            409     => $operation === 'put' ? new NoUidConflict('Another object of this calendar has the same UID.') : new Conflict('Conflict.'),
            412     => new PreconditionFailed('The ETag does not match.'),
            413     => new RequestTooLarge('The calendar object is too large.'),
            507     => new InsufficientStorage('The calendar is full.'),
            default => new ServiceUnavailable('Temporarily unavailable.'),
        };
    }

    public static function badRequest(): BadRequest
    {
        return new BadRequest('Bad request.');
    }
}
