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
 * found. It never changes DNS and transmits nothing.
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

        if (!$dmarc['present']) {
            $findings[] = ['code' => 'dns_dmarc_missing', 'severity' => 3, 'params' => [$domain]];
        } else {
            if ($dmarc['policy'] === '' || $dmarc['policy'] === 'none') {
                $findings[] = ['code' => 'dns_dmarc_policy_none', 'severity' => 2, 'params' => [$domain]];
            }

            if (!$dmarc['rua']) {
                $findings[] = ['code' => 'dns_dmarc_no_rua', 'severity' => 1, 'params' => [$domain]];
            }

            if ($dmarc['pct'] < 100 && $dmarc['pct'] > 0) {
                $findings[] = ['code' => 'dns_dmarc_pct', 'severity' => 1, 'params' => [$dmarc['pct']]];
            }
        }

        if (!$spf['present']) {
            $findings[] = ['code' => 'dns_spf_missing', 'severity' => 2, 'params' => [$domain]];
        }

        return [
            'domain'    => $domain,
            'dmarc'     => $dmarc,
            'spf'       => $spf,
            'findings'  => $findings,
            'suggested' => $dmarc['present']
                ? ''
                : 'v=DMARC1; p=none; rua=mailto:dmarc@' . $domain . '; fo=1',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectDmarc(string $domain): array
    {
        $record = $this->firstMatching('_dmarc.' . $domain, 'v=dmarc1');

        $result = ['present' => false, 'record' => '', 'policy' => '', 'pct' => 100, 'rua' => false];

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
        $record = $this->firstMatching($domain, 'v=spf1');

        return ['present' => $record !== null, 'record' => $record ?? ''];
    }

    private function firstMatching(string $host, string $prefix): ?string
    {
        foreach ($this->resolver->txt($host) as $txt) {
            if (stripos(ltrim($txt), $prefix) === 0) {
                return trim($txt);
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
