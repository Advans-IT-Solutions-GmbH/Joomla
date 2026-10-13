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
        } elseif ($dmarc['inherited']) {
            // The subdomain has no record of its own but inherits a valid policy
            // from an ancestor, so it is covered; surface it as an informational
            // note rather than a missing-record problem.
            $findings[] = ['code' => 'dns_dmarc_inherited', 'severity' => 1, 'params' => [$domain, $dmarc['inheritedFrom']]];
        } elseif (!$dmarc['present']) {
            $findings[] = ['code' => 'dns_dmarc_missing', 'severity' => 3, 'params' => [$domain]];
        } elseif ($dmarc['duplicate']) {
            // More than one DMARC record is invalid per RFC 7489; receivers apply
            // error handling, so this must not be read as a healthy policy.
            $findings[] = ['code' => 'dns_dmarc_duplicate', 'severity' => 3, 'params' => [$domain]];
        } elseif (!$dmarc['valid']) {
            // A malformed required tag (an unknown p value, or a pct outside the
            // 0-100 integer range) leaves receivers unable to apply the intended
            // policy, so the record is reported as invalid rather than healthy.
            $findings[] = ['code' => 'dns_dmarc_invalid', 'severity' => 3, 'params' => [$domain]];
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
            // SPF is published at the envelope (MAIL FROM/HELO) domain, which a
            // DMARC-aligned message may authenticate via DKIM instead; the From
            // domain having no SPF record is therefore advisory, not a fault, so
            // it is reported at the lowest severity.
            $findings[] = ['code' => 'dns_spf_missing', 'severity' => 1, 'params' => [$domain]];
        } elseif ($spf['duplicate']) {
            // Multiple SPF records are invalid (RFC 7208) and cause a permerror.
            $findings[] = ['code' => 'dns_spf_duplicate', 'severity' => 2, 'params' => [$domain]];
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

        $result = ['present' => false, 'error' => false, 'duplicate' => false, 'valid' => true, 'record' => '', 'policy' => '', 'pct' => 100, 'rua' => false, 'inherited' => false, 'inheritedFrom' => ''];

        if ($records === null) {
            $result['error'] = true;

            return $result;
        }

        $matching = $this->allMatching($records, 'v=dmarc1');

        if ($matching === []) {
            // RFC 7489 lets a subdomain inherit the organizational domain's
            // policy, so the absence of a record at _dmarc.<domain> does not
            // mean the subdomain is unprotected. Look for the nearest ancestor
            // that publishes a valid DMARC record and report inheritance rather
            // than a false "missing" finding. True organizational-domain
            // selection needs the Public Suffix List, which this in-host check
            // deliberately does not bundle; the nearest-ancestor heuristic
            // covers the common sub.example.org -> example.org case and stops at
            // the two-label registrable minimum so it never walks into a public
            // suffix such as co.uk.
            $inheritedFrom = $this->findInheritedDmarc($domain);

            if ($inheritedFrom !== null) {
                $result['present']       = true;
                $result['inherited']     = true;
                $result['inheritedFrom'] = $inheritedFrom;
            }

            return $result;
        }

        $record = $matching[0];

        $result['present']   = true;
        $result['duplicate'] = \count($matching) > 1;
        $result['record']    = $record;
        $tags                = $this->tags($record);

        $result['policy'] = strtolower($tags['p'] ?? '');
        $result['rua']    = isset($tags['rua']) && $tags['rua'] !== '';

        // The required p tag must name a known disposition; anything else (a
        // typo such as p=bogus, or no p tag at all) is an invalid record.
        $valid = \in_array($result['policy'], ['none', 'quarantine', 'reject'], true);

        if (isset($tags['pct'])) {
            // pct is an integer percentage 0-100; a non-numeric or out-of-range
            // value (pct=abc, pct=101) makes the record invalid rather than a
            // silently ignored healthy policy.
            $pct = trim($tags['pct']);

            if (ctype_digit($pct) && (int) $pct <= 100) {
                $result['pct'] = (int) $pct;
            } else {
                $valid = false;
            }
        }

        $result['valid'] = $valid;

        return $result;
    }

    /**
     * Find the nearest ancestor domain that publishes a valid DMARC record, so
     * a subdomain inheriting a parent policy is not reported as missing one.
     * Walks from the immediate parent down to the two-label registrable minimum
     * and returns the first ancestor whose _dmarc record names a valid policy,
     * or null when none is found (or a resolver error makes the walk
     * inconclusive). A receiver performs organizational-domain selection via the
     * Public Suffix List rather than this nearest-ancestor walk, so an
     * intermediate subdomain that happens to publish its own record is reported
     * here even though a receiver would consult the organizational domain; the
     * finding is advisory and only replaces a false "missing" verdict.
     */
    private function findInheritedDmarc(string $domain): ?string
    {
        $parts = explode('.', $domain);
        $count = \count($parts);

        // Only a name with at least three labels can inherit from an ancestor
        // that is still a registrable (two-label) domain or deeper.
        for ($i = 1; $i <= $count - 2; $i++) {
            $ancestor = implode('.', \array_slice($parts, $i));
            $records  = $this->resolver->txt('_dmarc.' . $ancestor);

            if ($records === null) {
                // A resolver error higher up makes the inheritance check
                // inconclusive; stop rather than guess the subdomain is covered.
                return null;
            }

            $matching = $this->allMatching($records, 'v=dmarc1');

            if ($matching === []) {
                continue;
            }

            $policy = strtolower($this->tags($matching[0])['p'] ?? '');

            if (\in_array($policy, ['none', 'quarantine', 'reject'], true)) {
                return $ancestor;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function inspectSpf(string $domain): array
    {
        $records = $this->resolver->txt($domain);

        if ($records === null) {
            return ['present' => false, 'error' => true, 'duplicate' => false, 'record' => ''];
        }

        $matching = $this->allMatching($records, 'v=spf1');

        return [
            'present'   => $matching !== [],
            'error'     => false,
            'duplicate' => \count($matching) > 1,
            'record'    => $matching[0] ?? '',
        ];
    }

    /**
     * Return every record that begins with the given version prefix. More than
     * one match is itself a finding: duplicate DMARC or SPF records are invalid.
     *
     * @param   string[]  $records
     *
     * @return  string[]
     */
    private function allMatching(array $records, string $prefix): array
    {
        $matches = [];
        $length  = strlen($prefix);

        foreach ($records as $txt) {
            $value = ltrim((string) $txt);

            if (stripos($value, $prefix) !== 0) {
                continue;
            }

            // The version token must end at the prefix: anything directly after
            // it has to be a tag separator or whitespace, otherwise "v=DMARC10"
            // or "v=spf10" would be mistaken for a valid "v=DMARC1"/"v=spf1".
            $next = $value[$length] ?? '';

            if ($next !== '' && $next !== ';' && !ctype_space($next)) {
                continue;
            }

            $matches[] = trim($value);
        }

        return $matches;
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
