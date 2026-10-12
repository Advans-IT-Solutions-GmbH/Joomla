<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * The default resolver, backed by PHP's dns_get_record(). It performs only
 * read-only TXT lookups and never writes to DNS. It does, however, transmit
 * the host being queried: like any DNS lookup, each call discloses the
 * monitored domain (and its _dmarc subdomain) to the system resolver and the
 * upstream DNS servers it consults, consistent with the note on
 * {@see DnsDiagnostics}. No other site data is sent.
 *
 * @since  1.0.0
 */
final class SystemDnsResolver implements DnsResolverInterface
{
    public function txt(string $host): ?array
    {
        // Without the function the lookup cannot be performed at all, which is a
        // resolver error (null), not evidence that the host has no records.
        if (!\function_exists('dns_get_record')) {
            return null;
        }

        $records = @dns_get_record($host, DNS_TXT);

        if (\is_array($records)) {
            // A successful answer, including an empty array meaning the host
            // genuinely publishes no TXT records (NODATA).
            return $this->flatten($records);
        }

        // dns_get_record() returns false both for a non-existent host (NXDOMAIN)
        // and for a genuine resolver/transport failure, without exposing the
        // response code. Because _dmarc.<domain> very commonly does not exist
        // when DMARC is simply absent, mapping every false to a resolver error
        // would hide the normal "no DMARC record" case. Probe the resolver with
        // a neutral query that discloses nothing about the monitored domain: if
        // the resolver can answer it, the earlier false was NXDOMAIN/no-data (a
        // successful empty answer); if even this fails, it is a real resolver
        // failure (null).
        return $this->resolverIsHealthy() ? [] : null;
    }

    /**
     * Confirm the system resolver can answer at all, using the root zone SOA as
     * a neutral probe that never names the monitored domain. A working resolver
     * answers it; a dead or unreachable one does not.
     */
    private function resolverIsHealthy(): bool
    {
        $root = @dns_get_record('.', DNS_SOA);

        return \is_array($root) && $root !== [];
    }

    /**
     * Flatten dns_get_record() TXT rows into plain strings.
     *
     * @param   array<int, array<string, mixed>>  $records
     *
     * @return  string[]
     */
    private function flatten(array $records): array
    {
        $out = [];

        foreach ($records as $record) {
            if (isset($record['entries']) && \is_array($record['entries'])) {
                $out[] = implode('', $record['entries']);
            } elseif (isset($record['txt'])) {
                $out[] = (string) $record['txt'];
            }
        }

        return $out;
    }
}
