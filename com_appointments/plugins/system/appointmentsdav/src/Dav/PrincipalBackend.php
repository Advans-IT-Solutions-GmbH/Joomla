<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\DAV\Exception\Forbidden;
use Advans\Component\Appointments\Vendor\Sabre\DAV\PropPatch;
use Advans\Component\Appointments\Vendor\Sabre\DAVACL\PrincipalBackend\BackendInterface;

defined('_JEXEC') or die;

/**
 * Exactly one principal exists for a request: the signed-in device's own user,
 * `principals/u<id>`. Every other principal is unknown (404), there is no
 * principal search, no group and no proxy, so no account learns about another.
 *
 * @since  1.4.0
 */
final class PrincipalBackend implements BackendInterface
{
    public function __construct(private readonly DavContext $context)
    {
    }

    public function getPrincipalsByPrefix($prefixPath)
    {
        return trim((string) $prefixPath, '/') === 'principals' ? [$this->own()] : [];
    }

    public function getPrincipalByPath($path)
    {
        return trim((string) $path, '/') === $this->context->principal() ? $this->own() : null;
    }

    public function updatePrincipal($path, PropPatch $propPatch)
    {
        // Nothing of a principal can be changed over CalDAV: every property stays unhandled (403).
    }

    public function searchPrincipals($prefixPath, array $searchProperties, $test = 'allof')
    {
        return [];
    }

    public function findByUri($uri, $principalPrefix)
    {
        return null;
    }

    public function getGroupMemberSet($principal)
    {
        return [];
    }

    public function getGroupMembership($principal)
    {
        return [];
    }

    public function setGroupMemberSet($principal, array $members)
    {
        throw new Forbidden('Not allowed.');
    }

    /**
     * @return  array<string, string>
     */
    private function own(): array
    {
        return [
            'uri'               => $this->context->principal(),
            '{DAV:}displayname' => $this->context->session->username,
        ];
    }
}
