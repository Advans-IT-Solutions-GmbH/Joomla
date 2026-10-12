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
 * Read-only DNS diagnostics for a monitored domain. It inspects the published
 * DMARC and SPF records and reports machine readable finding codes (mapped to
 * localised text at display time) plus a suggested DMARC record when none is
 * found. It never changes DNS, but it does resolve TXT records for the domain
 * and its _dmarc subdomain, so the configured resolver (and any upstream DNS
 * server) learns which domains are being inspected.
 *
 * @since  1.0.0
 */
final class DnsDiagnostics
{
    private DnsResolverInterface $resolver;

    public function __construct(?DnsResolverInterface $resolver = null)
    {
        $this->resolver = $resolver ?? new SystemDnsResolver();
    }

    /**
     * Inspect a single domain.
     *
     * @param   string  $domain  The domain to inspect (e.g. "example.org").
     *
     * @return  array<string, mixed>
     */
    public function inspect(string $domain): array
    {
        $domain = strtolower(trim($domain, ". \t"));

        $dmarc    = $this->inspectDmarc($domain);
        $spf      = $this->inspectSpf($domain);
        $findings = [];

        if ($dmarc['error']) {
            // A resolver failure is reported as its own finding so a transient
            // outage is not mistaken for a domain that never published DMARC.
            $findings[] = ['code' => 'dns_lookup_error', 'severity' => 2, 'params' => [$domain]];
        } elseif (!$dmarc['present']) {
            $findings[] = ['code' => 'dns_dmarc_missing', 'severity' => 3, 'params' => [$domain]];
        } else {
            if ($dmarc['policy'] === '' || $dmarc['policy'] === 'none') {
                $findings[] = ['code' => 'dns_dmarc_policy_none', 'severity' => 2, 'params' => [$domain]];
            }

            if (!$dmarc['rua']) {
                $findings[] = ['code' => 'dns_dmarc_no_rua', 'severity' => 1, 'params' => [$domain]];
            }

            // Any pct below 100 only partially enforces the policy, including
            // pct=0, which disables enforcement entirely while still publishing.
            if ($dmarc['pct'] < 100) {
                $findings[] = ['code' => 'dns_dmarc_pct', 'severity' => 1, 'params' => [$dmarc['pct']]];
            }
        }

        if ($spf['error']) {
            // Only add a lookup-error finding when DMARC did not already raise
            // one for this domain, so a resolver outage is reported once.
            if (!$dmarc['error']) {
                $findings[] = ['code' => 'dns_lookup_error', 'severity' => 2, 'params' => [$domain]];
            }
        } elseif (!$spf['present']) {
            $findings[] = ['code' => 'dns_spf_missing', 'severity' => 2, 'params' => [$domain]];
        }

        return [
            'domain'    => $domain,
            'dmarc'     => $dmarc,
            'spf'       => $spf,
            'findings'  => $findings,
            // Only suggest a record when the lookup succeeded and found none; a
            // failed lookup must not imply the domain lacks a DMARC record.
            'suggested' => (!$dmarc['error'] && !$dmarc['present'])
                ? 'v=DMARC1; p=none; rua=mailto:dmarc@' . $domain . '; fo=1'
                : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectDmarc(string $domain): array
    {
        $records = $this->resolver->txt('_dmarc.' . $domain);

        $result = ['present' => false, 'error' => false, 'record' => '', 'policy' => '', 'pct' => 100, 'rua' => false];

        if ($records === null) {
            $result['error'] = true;

            return $result;
        }

        $record = $this->firstMatching($records, 'v=dmarc1');

        if ($record === null) {
            return $result;
        }

        $result['present'] = true;
        $result['record']  = $record;
        $tags              = $this->tags($record);

        $result['policy'] = strtolower($tags['p'] ?? '');
        $result['rua']    = isset($tags['rua']) && $tags['rua'] !== '';

        if (isset($tags['pct']) && is_numeric($tags['pct'])) {
            $result['pct'] = (int) $tags['pct'];
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectSpf(string $domain): array
    {
        $records = $this->resolver->txt($domain);

        if ($records === null) {
            return ['present' => false, 'error' => true, 'record' => ''];
        }

        $record = $this->firstMatching($records, 'v=spf1');

        return ['present' => $record !== null, 'error' => false, 'record' => $record ?? ''];
    }

    /**
     * @param   string[]  $records
     */
    private function firstMatching(array $records, string $prefix): ?string
    {
        foreach ($records as $txt) {
            if (stripos(ltrim((string) $txt), $prefix) === 0) {
                return trim((string) $txt);
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function tags(string $record): array
    {
        $tags = [];

        foreach (explode(';', $record) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $part, 2);
            $tags[strtolower(trim($key))] = trim($value);
        }

        return $tags;
    }
}
