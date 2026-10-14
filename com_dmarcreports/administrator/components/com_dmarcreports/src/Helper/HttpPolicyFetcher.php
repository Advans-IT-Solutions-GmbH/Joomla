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

/**
 * Default {@see PolicyFetcherInterface}. It performs a single HTTPS GET to the
 * published MTA-STS policy with a short timeout and only accepts an HTTP 200
 * response; a policy served with an invalid certificate is treated as
 * unreachable (null), which is exactly the MTA-STS failure the monitoring must
 * surface. It never writes anything and discloses only the monitored domain to
 * its own policy host.
 *
 * The target host (mta-sts.<domain>) derives from monitored domains that can be
 * auto-created from ingested reports, so it is attacker-influenced. To close the
 * resulting server-side request forgery (SSRF) vector the fetcher:
 *   - accepts only the https scheme on the standard port 443, with no userinfo;
 *   - resolves the host up front and refuses to connect when any resolved
 *     address is not a public unicast address ({@see PrivateAddressFilter});
 *   - pins the connection to exactly those validated IPs via CURLOPT_RESOLVE, so
 *     a second DNS lookup cannot rebind the host to a private address between
 *     the check and the connect (TOCTOU / DNS rebinding); and
 *   - disables redirect following entirely, so a 3xx cannot bounce the request
 *     to an unvalidated internal destination.
 *
 * It fails closed: without the curl extension, or on any resolution/validation
 * failure, it returns null and the policy is reported as unreachable rather than
 * reached through an unchecked path.
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

    /**
     * @var int Maximum number of policy bytes to buffer before aborting.
     * @since 1.1.0
     */
    private const MAX_POLICY_BYTES = 65536;

    public function __construct(int $timeout = 10)
    {
        $this->timeout = max(1, $timeout);
    }

    public function fetch(string $url): ?string
    {
        $host = $this->validateUrl($url);

        if ($host === null) {
            return null;
        }

        $addresses = $this->resolvePublicAddresses($host);

        if ($addresses === []) {
            // Either the host did not resolve or at least one resolved address
            // is non-public; in both cases the fetch must not proceed.
            return null;
        }

        return $this->request($url, $host, $addresses);
    }

    /**
     * Validate the URL shape and return its host, or null when the URL is not a
     * plain https URL on the default port without credentials.
     */
    private function validateUrl(string $url): ?string
    {
        $parsed = parse_url($url);

        if (!\is_array($parsed)) {
            return null;
        }

        if (strtolower((string) ($parsed['scheme'] ?? '')) !== 'https') {
            return null;
        }

        // Credentials in the authority are never legitimate here and are a known
        // way to disguise the real host.
        if (isset($parsed['user']) || isset($parsed['pass'])) {
            return null;
        }

        // Only the implicit or explicit standard HTTPS port is allowed.
        if (isset($parsed['port']) && (int) $parsed['port'] !== 443) {
            return null;
        }

        $host = (string) ($parsed['host'] ?? '');

        // parse_url keeps the brackets around an IPv6 literal host; strip them so
        // the value can be resolved/validated as a plain address.
        if (\strlen($host) >= 2 && $host[0] === '[' && substr($host, -1) === ']') {
            $host = substr($host, 1, -1);
        }

        return $host === '' ? null : $host;
    }

    /**
     * Resolve the host to the set of IP addresses to connect to and return it
     * only when every resolved address is a public unicast address. An IP
     * literal host is validated directly. An empty result means "do not
     * connect" — either nothing resolved or at least one address was non-public,
     * which fails the whole fetch closed rather than connecting to the public
     * subset of a rebinding answer.
     *
     * @return  string[]
     */
    private function resolvePublicAddresses(string $host): array
    {
        if (@inet_pton($host) !== false) {
            return PrivateAddressFilter::isPublicAddress($host) ? [$host] : [];
        }

        $addresses = [];

        if (\function_exists('dns_get_record')) {
            foreach ([\DNS_A, \DNS_AAAA] as $type) {
                $records = @dns_get_record($host, $type);

                if (!\is_array($records)) {
                    continue;
                }

                foreach ($records as $record) {
                    $ip = (string) ($record['ip'] ?? $record['ipv6'] ?? '');

                    if ($ip !== '') {
                        $addresses[$ip] = true;
                    }
                }
            }
        }

        if ($addresses === [] && \function_exists('gethostbynamel')) {
            $ipv4 = @gethostbynamel($host);

            if (\is_array($ipv4)) {
                foreach ($ipv4 as $ip) {
                    $addresses[$ip] = true;
                }
            }
        }

        $resolved = array_keys($addresses);

        foreach ($resolved as $ip) {
            if (!PrivateAddressFilter::isPublicAddress($ip)) {
                return [];
            }
        }

        return $resolved;
    }

    /**
     * Bracket every IPv6 literal so a comma-joined CURLOPT_RESOLVE entry stays
     * parseable. IPv4 addresses are returned unchanged.
     *
     * @param   string[]  $addresses  Validated public IPs.
     *
     * @return  string[]
     *
     * @since   1.1.0
     */
    private static function bracketResolveAddresses(array $addresses): array
    {
        return array_map(
            static fn (string $ip): string => strpos($ip, ':') !== false ? '[' . $ip . ']' : $ip,
            $addresses
        );
    }

    /**
     * Perform the pinned HTTPS GET and return the body only on HTTP 200.
     *
     * @param  string[]  $addresses  Validated public IPs to pin the host to.
     */
    private function request(string $url, string $host, array $addresses): ?string
    {
        if (!\function_exists('curl_init')) {
            // Fail closed: without curl the connection cannot be pinned to the
            // validated addresses, so the SSRF guarantees cannot be upheld.
            return null;
        }

        $handle = curl_init();

        if ($handle === false) {
            return null;
        }

        $body    = '';
        $maxSize = self::MAX_POLICY_BYTES;

        // libcurl's CURLOPT_RESOLVE host:port:addr syntax is comma-separated, so
        // every IPv6 literal must be bracketed to keep its own colons from being
        // read as field separators; otherwise a dual-stack host's AAAA address
        // makes the whole entry unparseable and the fetch fails on IPv6 hosts.
        $resolveAddresses = self::bracketResolveAddresses($addresses);

        try {
            curl_setopt_array($handle, [
                \CURLOPT_URL            => $url,
                \CURLOPT_FOLLOWLOCATION => false,
                \CURLOPT_PORT           => 443,
                \CURLOPT_CONNECTTIMEOUT => $this->timeout,
                \CURLOPT_TIMEOUT        => $this->timeout,
                \CURLOPT_SSL_VERIFYPEER => true,
                \CURLOPT_SSL_VERIFYHOST => 2,
                \CURLOPT_PROTOCOLS      => \CURLPROTO_HTTPS,
                // Pin the host to the addresses we validated so a concurrent DNS
                // change cannot rebind it to a private address before connect.
                \CURLOPT_RESOLVE        => [$host . ':443:' . implode(',', $resolveAddresses)],
                // Buffer the body through a bounded callback so an
                // attacker-controlled host cannot exhaust memory with a huge
                // response: abort the transfer once the small policy ceiling is
                // exceeded.
                \CURLOPT_WRITEFUNCTION  => static function ($ch, string $chunk) use (&$body, $maxSize): int {
                    $body .= $chunk;

                    if (\strlen($body) > $maxSize) {
                        return -1;
                    }

                    return \strlen($chunk);
                },
            ]);

            $result = curl_exec($handle);
            $code   = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
        } catch (\Throwable) {
            return null;
        } finally {
            curl_close($handle);
        }

        if ($result === false || $code !== 200) {
            return null;
        }

        return $body;
    }
}
