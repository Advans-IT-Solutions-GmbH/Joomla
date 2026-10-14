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
 * Read-only MTA-STS (RFC 8461) and TLS-RPT (RFC 8460) monitoring for a
 * monitored domain. Given the artifacts the component would publish (from
 * {@see MtaStsPolicy::generate()}) it inspects the live `_mta-sts` TXT record,
 * the hosted policy document and the `_smtp._tls` TLS-RPT record and returns the
 * same machine readable finding shape the Action Center already consumes for
 * DNS findings ({code, severity, params}).
 *
 * It never writes DNS and never serves a policy; it only reads what is published
 * and compares it against what the component generates, so drift (an outdated
 * record id, a policy still in testing mode, an unreachable policy host, a
 * missing TLS-RPT record) becomes visible without any manual checking. The
 * single step the component cannot perform for the administrator — creating the
 * `mta-sts` host with a valid TLS certificate and publishing the TXT records —
 * is surfaced as an explicit, copy-ready action item.
 *
 * @since  1.1.0
 */
final class MtaStsDiagnostics
{
    private DnsResolverInterface $resolver;

    private PolicyFetcherInterface $fetcher;

    public function __construct(?DnsResolverInterface $resolver = null, ?PolicyFetcherInterface $fetcher = null)
    {
        $this->resolver = $resolver ?? new SystemDnsResolver();
        $this->fetcher  = $fetcher ?? new HttpPolicyFetcher();
    }

    /**
     * Inspect one domain's MTA-STS/TLS-RPT deployment.
     *
     * @param   array<string, mixed>  $generated    The output of {@see MtaStsPolicy::generate()}.
     * @param   bool                  $withNetwork  When false, no DNS or HTTP
     *                                              lookup is performed and only
     *                                              the "publish these records"
     *                                              guidance is produced, so the
     *                                              monitoring honours the same
     *                                              opt-in that gates DNS
     *                                              diagnostics.
     *
     * @return  array{enabled:bool, generated:array<string,mixed>, findings:array<int, array{code:string, severity:int, params:array<int,mixed>}>}
     */
    public function inspect(array $generated, bool $withNetwork): array
    {
        $result = ['enabled' => (bool) ($generated['enabled'] ?? false), 'generated' => $generated, 'findings' => []];

        if (!$result['enabled']) {
            return $result;
        }

        $domain       = (string) ($generated['domain'] ?? '');
        $mx           = (array) ($generated['mx'] ?? []);
        $generatedId  = (string) ($generated['id'] ?? '');
        $stsHost      = (string) ($generated['sts_record_host'] ?? '');
        $stsRecord    = (string) ($generated['sts_record'] ?? '');
        $wellKnownUrl = (string) ($generated['well_known_url'] ?? '');
        $tlsRptHost   = (string) ($generated['tlsrpt_record_host'] ?? '');
        $tlsRptRecord = (string) ($generated['tlsrpt_record'] ?? '');

        // Without at least one MX host the component cannot build a policy body,
        // so there is nothing to publish or validate yet.
        if ($mx === [] || $generatedId === '') {
            $result['findings'][] = $this->finding('mtasts_no_mx', 2, [$domain]);

            return $result;
        }

        if (!$withNetwork) {
            // Lookups are opt-in. Still tell the administrator exactly what to
            // publish, at a softer severity because drift cannot be confirmed.
            $result['findings'][] = $this->finding('mtasts_publish', 1, [$stsHost, $stsRecord, $wellKnownUrl]);

            if ($tlsRptRecord !== '') {
                $result['findings'][] = $this->finding('tlsrpt_publish', 1, [$tlsRptHost, $tlsRptRecord]);
            }

            return $result;
        }

        $result['findings'] = array_merge(
            $result['findings'],
            $this->inspectStsRecord($generated),
            $this->inspectTlsRptRecord($tlsRptHost, $tlsRptRecord)
        );

        return $result;
    }

    /**
     * Validate the `_mta-sts` TXT record and, when its id matches, the hosted
     * policy document.
     *
     * @param   array<string, mixed>  $generated
     *
     * @return  array<int, array{code:string, severity:int, params:array<int,mixed>}>
     */
    private function inspectStsRecord(array $generated): array
    {
        $domain       = (string) ($generated['domain'] ?? '');
        $generatedId  = (string) ($generated['id'] ?? '');
        $stsHost      = (string) ($generated['sts_record_host'] ?? '');
        $stsRecord    = (string) ($generated['sts_record'] ?? '');
        $wellKnownUrl = (string) ($generated['well_known_url'] ?? '');

        $records = $this->resolver->txt($stsHost);

        if ($records === null) {
            return [$this->finding('mtasts_lookup_error', 2, [$domain])];
        }

        $publishedId = $this->stsRecordId($records);

        if ($publishedId === null) {
            return [$this->finding('mtasts_publish', 2, [$stsHost, $stsRecord, $wellKnownUrl])];
        }

        if ($publishedId !== $generatedId) {
            return [$this->finding('mtasts_record_drift', 2, [$publishedId, $generatedId, $stsRecord])];
        }

        return $this->inspectHostedPolicy($generated);
    }

    /**
     * Fetch and compare the hosted policy once the record id is confirmed.
     *
     * @param   array<string, mixed>  $generated
     *
     * @return  array<int, array{code:string, severity:int, params:array<int,mixed>}>
     */
    private function inspectHostedPolicy(array $generated): array
    {
        $domain       = (string) ($generated['domain'] ?? '');
        $mode         = (string) ($generated['mode'] ?? '');
        $maxAge       = (int) ($generated['max_age'] ?? 0);
        $mx           = array_map('strval', (array) ($generated['mx'] ?? []));
        $wellKnownUrl = (string) ($generated['well_known_url'] ?? '');

        $body = $this->fetcher->fetch($wellKnownUrl);

        if ($body === null) {
            return [$this->finding('mtasts_policy_unreachable', 2, [$wellKnownUrl])];
        }

        $hosted = $this->parsePolicy($body);

        sort($mx);
        $hostedMx = $hosted['mx'];
        sort($hostedMx);

        // Compare every field the generated policy fixes: a wrong or missing
        // version, a stale max_age or a changed mode/mx all mean the served
        // policy no longer matches what the record id promises. Comparing only
        // mode and mx would report a drifted policy as consistent, breaking the
        // diagnostics contract.
        if ($hosted['version'] !== 'stsv1'
            || $hosted['mode'] !== $mode
            || $hosted['max_age'] !== $maxAge
            || $hostedMx !== $mx) {
            return [$this->finding('mtasts_policy_drift', 2, [$wellKnownUrl])];
        }

        // Record, policy and reporting are consistent. A policy left in testing
        // mode never makes receivers enforce TLS; nudge the operator to switch
        // to enforce once they are confident, mirroring the DMARC rollout advice.
        if ($mode === MtaStsPolicy::MODE_TESTING) {
            return [$this->finding('mtasts_mode_testing', 1, [$domain])];
        }

        return [];
    }

    /**
     * Validate the `_smtp._tls` TLS-RPT TXT record when reporting is configured.
     *
     * @return  array<int, array{code:string, severity:int, params:array<int,mixed>}>
     */
    private function inspectTlsRptRecord(string $host, string $record): array
    {
        if ($record === '' || $host === '') {
            return [];
        }

        $records = $this->resolver->txt($host);

        // A resolver failure here is already implied by any mta-sts lookup error
        // for the same domain, so a null result is treated as "cannot confirm"
        // and reported as a publish reminder rather than a second error.
        if ($records === null) {
            return [$this->finding('tlsrpt_publish', 1, [$host, $record])];
        }

        foreach ($records as $txt) {
            $value = ltrim((string) $txt);

            if (!self::startsWithVersion($value, 'v=tlsrptv1')) {
                continue;
            }

            // A record carrying the right version but a different rua points
            // reports at an obsolete or wrong address, so treat it as drift
            // rather than consistent. Compare the normalized rua target set.
            if (self::ruaTargets($value) === self::ruaTargets($record)) {
                return [];
            }
        }

        return [$this->finding('tlsrpt_publish', 1, [$host, $record])];
    }

    /**
     * Extract the normalized, sorted set of `rua` targets from a TLS-RPT TXT
     * record so a published record can be compared against the generated one by
     * reporting destination rather than by exact string.
     *
     * @return  string[]
     */
    private static function ruaTargets(string $record): array
    {
        $targets = [];

        foreach (explode(';', $record) as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }

            [$key, $val] = explode('=', $part, 2);

            if (strtolower(trim($key)) !== 'rua') {
                continue;
            }

            foreach (explode(',', $val) as $target) {
                $target = strtolower(trim($target));

                if ($target !== '') {
                    $targets[] = $target;
                }
            }
        }

        sort($targets);

        return $targets;
    }

    /**
     * Check that a TXT record begins with the exact version token and that the
     * token ends at a field delimiter, whitespace or the record end. Without the
     * boundary a prefix match would wrongly accept forged versions such as
     * `v=STSv10` or `v=TLSRPTv1BAD`.
     */
    private static function startsWithVersion(string $value, string $token): bool
    {
        if (strncasecmp($value, $token, \strlen($token)) !== 0) {
            return false;
        }

        $next = substr($value, \strlen($token), 1);

        return $next === '' || $next === ';' || ctype_space($next);
    }

    /**
     * Extract the id value from the first valid `_mta-sts` TXT record, or null
     * when no `v=STSv1` record with a non-empty id is present.
     *
     * @param   string[]  $records
     */
    private function stsRecordId(array $records): ?string
    {
        foreach ($records as $txt) {
            $value = trim((string) $txt);

            if (!self::startsWithVersion($value, 'v=stsv1')) {
                continue;
            }

            foreach (explode(';', $value) as $part) {
                if (!str_contains($part, '=')) {
                    continue;
                }

                [$key, $val] = explode('=', $part, 2);

                if (strtolower(trim($key)) === 'id') {
                    $id = trim($val);

                    if ($id !== '') {
                        return $id;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Parse a hosted MTA-STS policy body into the fields the generated policy
     * fixes: its version tag, mode, MX host set and max_age.
     *
     * @return  array{version:string, mode:string, mx:string[], max_age:int}
     */
    private function parsePolicy(string $body): array
    {
        $version = '';
        $mode    = '';
        $mx      = [];
        $maxAge  = 0;

        foreach (preg_split('~[\r\n]+~', $body) ?: [] as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$key, $val] = explode(':', $line, 2);
            $key         = strtolower(trim($key));
            $val         = strtolower(trim($val, " \t.\r\n"));

            if ($key === 'version') {
                $version = $val;
            } elseif ($key === 'mode') {
                $mode = $val;
            } elseif ($key === 'mx' && $val !== '') {
                $mx[] = $val;
            } elseif ($key === 'max_age' && preg_match('~^\d+$~', $val)) {
                $maxAge = (int) $val;
            }
        }

        return ['version' => $version, 'mode' => $mode, 'mx' => $mx, 'max_age' => $maxAge];
    }

    /**
     * @param   array<int, mixed>  $params
     *
     * @return  array{code:string, severity:int, category:string, params:array<int,mixed>}
     */
    private function finding(string $code, int $severity, array $params): array
    {
        return ['code' => $code, 'severity' => $severity, 'category' => 'mtasts', 'params' => array_values($params)];
    }
}
