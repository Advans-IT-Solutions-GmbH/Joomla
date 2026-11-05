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
use Advans\Component\Appointments\Vendor\Sabre\DAV\ServerPlugin;

defined('_JEXEC') or die;

/**
 * Refuses the principal reports before sabre's ACL plugin answers them, so no
 * account can search or list other principals (`principal-property-search`,
 * `principal-search-property-set`, `principal-match`).
 *
 * @since  1.4.0
 */
final class GuardPlugin extends ServerPlugin
{
    private const BLOCKED_REPORTS = [
        '{DAV:}principal-property-search',
        '{DAV:}principal-search-property-set',
        '{DAV:}principal-match',
    ];

    public function initialize(Server $server)
    {
        // Before the ACL plugin (default priority 100).
        $server->on('report', [$this, 'report'], 10);
    }

    public function getPluginName()
    {
        return 'appointments-guard';
    }

    /**
     * @param   string  $reportName
     * @param   mixed   $report
     * @param   string  $path
     */
    public function report($reportName, $report, $path)
    {
        if (\in_array($reportName, self::BLOCKED_REPORTS, true)) {
            throw new Forbidden('This report is not available.');
        }

        return null;
    }
}
