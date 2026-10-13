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
        // and for a genuine resolver/transport failure (SERVFAIL, a timeout, a
        // DNSSEC validation failure), without exposing the response code.
        // Because _dmarc.<domain> very commonly does not exist when DMARC is
        // simply absent, mapping every false to a resolver error would hide the
        // normal "no DMARC record" case; but mapping every false to an empty
        // answer would report a record as missing during an outage. Separate the
        // two conservatively: only conclude the record is genuinely absent when
        // the resolver works AND an ancestor zone of the host still answers. A
        // missing record under a healthy ancestor is NXDOMAIN (a NODATA host
        // returns an empty array above, not false), whereas a failure that also
        // takes out every ancestor spans the whole domain and is treated as an
        // ambiguous resolver error (null) rather than a missing record. A failure
        // scoped to this exact name while an ancestor answers cannot be told
        // apart from NXDOMAIN without the response code and is the residual
        // limitation here.
        if (!$this->resolverIsHealthy()) {
            return null;
        }

        if ($this->anyAncestorAnswers($host)) {
            return [];
        }

        return null;
    }

    /**
     * Walk the ancestor zones of a host, from its immediate parent up to the
     * registrable domain, and report whether any of them answers. A host such as
     * "_dmarc.mail.example.org" may sit below several non-existent parents
     * ("mail.example.org") above a healthy registrable domain ("example.org");
     * probing only the immediate parent would misread that layout as a
     * domain-wide outage and hide the intended "verify the organizational-domain
     * policy" finding. A single answering ancestor proves the zone hierarchy is
     * reachable, so a missing record below it is NXDOMAIN rather than a failure.
     */
    private function anyAncestorAnswers(string $host): bool
    {
        $parent = $this->parentZone($host);

        while ($parent !== null) {
            if ($this->zoneAnswers($parent)) {
                return true;
            }

            $parent = $this->parentZone($parent);
        }

        return false;
    }

    /**
     * Return the immediate parent zone of a host (the name with its left-most
     * label removed), or null when the host has no parent that is still a
     * registrable domain rather than a bare public suffix. "_dmarc.example.org"
     * yields "example.org"; "example.org" yields null.
     */
    private function parentZone(string $host): ?string
    {
        $host = trim($host, '.');
        $dot  = strpos($host, '.');

        if ($dot === false) {
            return null;
        }

        $parent = substr($host, $dot + 1);

        // Require the parent to still contain a dot so a bare public suffix such
        // as "org" is not probed (it always answers and would mask a failing
        // domain). This keeps the NXDOMAIN conclusion scoped to real domains.
        return strpos($parent, '.') === false ? null : $parent;
    }

    /**
     * Confirm a zone answers at all by querying the record types an existing
     * domain is expected to carry. A non-empty result proves the zone exists, so
     * a failure on a child name below it can be read as NXDOMAIN rather than a
     * domain-wide outage.
     */
    private function zoneAnswers(string $host): bool
    {
        $records = @dns_get_record($host, DNS_SOA + DNS_NS + DNS_A + DNS_AAAA + DNS_MX);

        return \is_array($records) && $records !== [];
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
