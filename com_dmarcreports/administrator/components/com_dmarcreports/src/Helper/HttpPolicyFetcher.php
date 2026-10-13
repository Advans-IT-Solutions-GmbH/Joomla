<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Helper
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

use Joomla\CMS\Http\HttpFactory;

/**
 * Default {@see PolicyFetcherInterface} backed by Joomla's HTTP client. It
 * performs a single HTTPS GET with a short timeout and only accepts an HTTP 200
 * response; TLS verification is left at the client default so a policy served
 * with an invalid certificate is treated as unreachable, which is exactly the
 * MTA-STS failure mode the monitoring must surface. It never writes anything and
 * discloses only the monitored domain to its own policy host.
 *
 * @since  1.1.0
 */
final class HttpPolicyFetcher implements PolicyFetcherInterface
{
    /**
     * @var int Request timeout in seconds.
     * @since 1.1.0
     */
    private int $timeout;

    public function __construct(int $timeout = 10)
    {
        $this->timeout = max(1, $timeout);
    }

    public function fetch(string $url): ?string
    {
        // Only the policy host over HTTPS is ever contacted; anything else is a
        // misconfiguration and must not trigger an outbound request.
        if (stripos($url, 'https://') !== 0) {
            return null;
        }

        try {
            $http     = HttpFactory::getHttp();
            $response = $http->get($url, [], $this->timeout);
        } catch (\Throwable) {
            return null;
        }

        if ((int) $response->getStatusCode() !== 200) {
            return null;
        }

        return (string) $response->getBody();
    }
}
