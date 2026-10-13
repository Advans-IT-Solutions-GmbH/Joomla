<?php
/**
 * @package     DMARC MTA-STS Policy System Plugin
 * @subpackage  Support
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\DmarcMtaSts\Support;

defined('_JEXEC') or die;

/**
 * Pure, Joomla-free matcher for incoming MTA-STS policy requests.
 *
 * RFC 8461 requires the policy to be served from
 * https://mta-sts.<domain>/.well-known/mta-sts.txt. This helper decides whether
 * a given request host and path target that endpoint and, if so, which base
 * domain the policy is requested for. It is deliberately free of any framework
 * dependency so the matching rules can be unit tested without a running Joomla.
 *
 * @since  1.0.0
 */
final class PolicyRequest
{
    /**
     * The well-known path an MTA-STS policy is served from (RFC 8461 §3.3).
     *
     * @var    string
     * @since  1.0.0
     */
    public const WELL_KNOWN_PATH = '/.well-known/mta-sts.txt';

    /**
     * The required host label prefix for an MTA-STS policy host (RFC 8461 §3.1).
     *
     * @var    string
     * @since  1.0.0
     */
    public const HOST_PREFIX = 'mta-sts.';

    /**
     * Resolve the base domain an MTA-STS request targets.
     *
     * @param   string  $host  The request host (case-insensitive).
     * @param   string  $path  The request path, possibly with a base sub-path.
     *
     * @return  string|null  The base domain (host without the mta-sts. prefix)
     *                       when the request targets the well-known endpoint on
     *                       an mta-sts. host, or null otherwise.
     *
     * @since 1.0.0
     */
    public static function domainFor(string $host, string $path): ?string
    {
        if (!self::pathMatches($path)) {
            return null;
        }

        $host = strtolower(trim($host));

        if (strncmp($host, self::HOST_PREFIX, \strlen(self::HOST_PREFIX)) !== 0) {
            return null;
        }

        $domain = substr($host, \strlen(self::HOST_PREFIX));

        return $domain === '' ? null : $domain;
    }

    /**
     * Whether the given request path ends with the well-known MTA-STS path.
     *
     * Matching the suffix keeps the plugin working when Joomla is installed in a
     * base sub-directory.
     *
     * @param   string  $path  The request path.
     *
     * @return  bool
     *
     * @since 1.0.0
     */
    public static function pathMatches(string $path): bool
    {
        return substr($path, -\strlen(self::WELL_KNOWN_PATH)) === self::WELL_KNOWN_PATH;
    }
}
