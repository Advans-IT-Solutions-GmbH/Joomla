<?php
/**
 * @package     Appointments CalDAV Plugin
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\AppointmentsDav\Dav;

defined('_JEXEC') or die;

/**
 * What the CalDAV gateway needs from the web server and Joomla for one
 * request, collected by {@see DavRequestHandler}, so the gateway itself runs
 * without Joomla (unit tests).
 *
 * @since  1.4.0
 */
final class DavEnvironment
{
    /**
     * @param   bool                             $https               Whether the request came over HTTPS (load balancer aware).
     * @param   string                           $ip                  Client address as Joomla determines it.
     * @param   string                           $realm               Realm of the Basic challenge (site name).
     * @param   \Closure(int): string            $busySummary         Text of a redacted busy block in the user's language.
     * @param   \Closure(\Throwable): void|null  $onError             Records an unexpected error.
     */
    public function __construct(
        public readonly bool $https,
        public readonly string $ip,
        public readonly string $realm,
        public readonly DavAddress $address,
        public readonly \Closure $busySummary,
        public readonly ?\Closure $onError = null
    ) {
    }
}
