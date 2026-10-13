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
            if ($this->isSubdomain($domain)) {
                // A subdomain with no record of its own may still be covered by
                // the organizational domain's policy (RFC 7489 falls back to the
                // Organizational Domain, selected via the Public Suffix List).
                // This in-host check does not bundle a PSL, so it cannot resolve
                // that domain or confirm coverage; rather than claim the policy
                // is missing (it may not be) or claim it is covered (it may not
                // be), it prompts the admin to verify the organizational
                // domain's DMARC record.
                //
                // Choosing the softer prompt here is deliberate: without a PSL a
                // multi-label registrable name under a compound public suffix
                // (for example example.co.uk) cannot be told apart from a true
                // subdomain. Erring towards the prompt is the safer failure mode
                // because the opposite default would emit a missing-record
                // finding and suggest publishing "p=none" at a genuine
                // subdomain, which overrides and weakens the organizational
                // domain's "sp" policy. Under-prompting a rare registrable name
                // beats handing out advice that relaxes enforcement.
                $findings[] = ['code' => 'dns_dmarc_subdomain_check', 'severity' => 1, 'params' => [$domain]];
            } else {
                $findings[] = ['code' => 'dns_dmarc_missing', 'severity' => 3, 'params' => [$domain]];
            }
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
            // Whether the monitored name is a subdomain that may inherit the
            // organizational domain's DMARC policy. Governs how the absence of a
            // record is presented, never a claim that a policy is in effect.
            'subdomain' => $this->isSubdomain($domain),
            // Only suggest a record when the lookup succeeded and found none at
            // an organizational (registrable) domain. A failed lookup must not
            // imply the domain lacks a record, and a subdomain may inherit the
            // organizational domain's policy, so neither case suggests one.
            'suggested' => (!$dmarc['error'] && !$dmarc['present'] && !$this->isSubdomain($domain))
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

        $result = ['present' => false, 'error' => false, 'duplicate' => false, 'valid' => true, 'record' => '', 'policy' => '', 'pct' => 100, 'rua' => false];

        if ($records === null) {
            $result['error'] = true;

            return $result;
        }

        $matching = $this->allMatching($records, 'v=dmarc1');

        if ($matching === []) {
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

        // RFC 7489 §6.3 permits each tag at most once; a record repeating a tag
        // (for example "p=reject; p=none") is syntactically invalid. tags() keeps
        // the last value for a repeated key, so without this check a conflicting
        // record would be read as a healthy policy. Reject it as invalid instead.
        if ($this->hasDuplicateTags($record)) {
            $valid = false;
        }

        $result['valid'] = $valid;

        return $result;
    }

    /**
     * Whether the monitored domain is a subdomain rather than an organizational
     * (registrable) domain. Without the Public Suffix List the exact
     * organizational boundary cannot be determined, so this uses the label
     * count as a conservative signal: a name with more than two labels is
     * treated as a subdomain that may inherit a parent policy. This only governs
     * how the absence of a DMARC record is reported (an informational
     * verify-the-organizational-domain prompt versus a missing-record finding);
     * it never asserts that a policy is or is not in effect, so a multi-label
     * registrable name under a compound public suffix (for example
     * example.co.uk) is at worst reported more softly, never incorrectly marked
     * as covered.
     */
    private function isSubdomain(string $domain): bool
    {
        return substr_count($domain, '.') > 1;
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

    /**
     * Whether a DMARC record repeats any tag name. RFC 7489 §6.3 allows each
     * tag at most once, so a duplicate makes the record invalid. Detected
     * separately from tags(), which collapses a repeated key to its last value.
     */
    private function hasDuplicateTags(string $record): bool
    {
        $seen = [];

        foreach (explode(';', $record) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key] = explode('=', $part, 2);
            $key   = strtolower(trim($key));

            if ($key === '') {
                continue;
            }

            if (isset($seen[$key])) {
                return true;
            }

            $seen[$key] = true;
        }

        return false;
    }
}
