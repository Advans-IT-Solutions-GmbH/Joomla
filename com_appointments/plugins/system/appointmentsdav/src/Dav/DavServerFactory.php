<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Vendor\Sabre\CalDAV\Plugin as CalDavPlugin;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Auth\Plugin as AuthPlugin;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Server;
use Advans\Component\Appointments\Vendor\Sabre\DAV\SimpleCollection;
use Advans\Component\Appointments\Vendor\Sabre\DAV\Sync\Plugin as SyncPlugin;
use Advans\Component\Appointments\Vendor\Sabre\DAVACL\Plugin as AclPlugin;
use Advans\Component\Appointments\Vendor\Sabre\DAVACL\PrincipalCollection;

defined('_JEXEC') or die;

/**
 * The sabre/dav server of one request, with exactly the plugins of the
 * specification (8): authentication (already done, see {@see AuthBackend}),
 * ACL with the user's own principal only, CalDAV and WebDAV sync. Not loaded:
 * the HTML browser, locks, property storage, scheduling (no iTIP mail from the
 * server), sharing and the ICS export.
 *
 * @since  1.4.0
 */
final class DavServerFactory
{
    public static function create(DavContext $context, string $basePath): Server
    {
        Server::$exposeVersion = false;

        $principals = new PrincipalBackend($context);
        $calendars = new CalendarBackend($context);

        $principalCollection = new PrincipalCollection($principals, 'principals');
        $calendarRoot = new CalendarTree($principals, $calendars, 'principals');

        $server = new Server(new SimpleCollection('root', [$principalCollection, $calendarRoot]), new SilentSapi());
        $server->setBaseUri($basePath);
        $server->debugExceptions = false;

        $server->addPlugin(new AuthPlugin(new AuthBackend($context)));

        $acl = new AclPlugin();
        $acl->allowUnauthenticatedAccess = false;
        $acl->hideNodesFromListings = true;
        $acl->principalCollectionSet = ['principals'];
        $acl->principalSearchPropertySet = [];
        // Nodes without an ACL of their own (the root) can be read, nothing else.
        $acl->setDefaultAcl([['principal' => '{DAV:}authenticated', 'privilege' => '{DAV:}read', 'protected' => true]]);
        $server->addPlugin($acl);

        $server->addPlugin(new CalDavPlugin());
        $server->addPlugin(new SyncPlugin());
        $server->addPlugin(new GuardPlugin());

        return $server;
    }
}
