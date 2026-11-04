<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\Registry\Registry;

defined('_JEXEC') or die;

/**
 * Entry point of the CalDAV server (work package A).
 *
 * Contract for the implementation:
 * - matches(): true for `/.well-known/caldav` and for the configured DAV path
 *   and everything below it, compared against the request path relative to
 *   the site root, for every HTTP method.
 * - handle(): HTTPS only (anything else 403 without a body), then lock-out,
 *   HTTP Basic with an app password, then the sabre/dav server with the
 *   backends of this namespace. Responses carry `Cache-Control: private,
 *   no-store` and `X-Robots-Tag: noindex, nofollow`, and no Joomla session
 *   cookie.
 *
 * Until work package A delivers, this handler matches nothing, so the plugin
 * is inert even when enabled.
 *
 * @since  1.4.0
 */
final class DavRequestHandler
{
    public function __construct(
        private readonly CMSApplicationInterface $app,
        private readonly Registry $params,
        private readonly TeamServices $services
    ) {
    }

    public function matches(): bool
    {
        return false;
    }

    public function handle(): void
    {
    }
}
