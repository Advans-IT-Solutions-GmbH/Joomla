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

use Joomla\Registry\Registry;

/**
 * Generates an MTA-STS policy document (RFC 8461) and the DNS records the
 * administrator must publish, including the TLS-RPT record (RFC 8460). The class
 * is deliberately free of DNS writes: it only produces the strings to publish
 * and to validate against, so it can be reused by the policy-serving system
 * plugin, the Systemcheck view and the Action Center.
 *
 * @since  1.1.0
 */
final class MtaStsPolicy
{
    /**
     * @var string
     * @since 1.1.0
     */
    public const MODE_NONE = 'none';

    /**
     * @var string
     * @since 1.1.0
     */
    public const MODE_TESTING = 'testing';

    /**
     * @var string
     * @since 1.1.0
     */
    public const MODE_ENFORCE = 'enforce';

    /**
     * Build the policy document body served at /.well-known/mta-sts.txt.
     *
     * @param   string    $mode    One of the MODE_* constants.
     * @param   string[]  $mx      MX host patterns (one policy line each).
     * @param   int       $maxAge  Policy lifetime in seconds.
     *
     * @return  string  CRLF-terminated policy body, or empty when no MX is set.
     *
     * @since 1.1.0
     */
    public static function policyBody(string $mode, array $mx, int $maxAge): string
    {
        $mode = self::normalizeMode($mode);
        $mx   = self::normalizeMxList($mx);

        if ($mx === []) {
            return '';
        }

        $maxAge = max(86400, min(31557600, $maxAge));

        $lines   = [];
        $lines[] = 'version: STSv1';
        $lines[] = 'mode: ' . $mode;

        foreach ($mx as $host) {
            $lines[] = 'mx: ' . $host;
        }

        $lines[] = 'max_age: ' . $maxAge;

        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * Derive the policy id from the policy body. The id is content-derived so it
     * changes whenever the policy changes, which is exactly what the _mta-sts
     * TXT record must reflect; the Systemcheck can recompute and compare it to
     * detect drift between the published record and the served policy.
     *
     * @param   string  $body  The policy body from {@see self::policyBody()}.
     *
     * @return  string  A 21-character id using the RFC 8461 id alphabet, or an
     *                  empty string when the body is empty.
     *
     * @since 1.1.0
     */
    public static function policyId(string $body): string
    {
        if ($body === '') {
            return '';
        }

        // 1 prefix + 20 hex chars stays within the 32-character id limit and the
        // allowed [A-Za-z0-9] alphabet.
        return 'v' . substr(hash('sha256', $body), 0, 20);
    }

    /**
     * The TXT record value for `_mta-sts.<domain>`.
     *
     * @since 1.1.0
     */
    public static function mtaStsRecord(string $id): string
    {
        return 'v=STSv1; id=' . $id;
    }

    /**
     * The TXT record value for `_smtp._tls.<domain>` (TLS-RPT, RFC 8460), or an
     * empty string when no reporting address is configured.
     *
     * @since 1.1.0
     */
    public static function tlsRptRecord(string $rua): string
    {
        $endpoints = self::normalizeTlsRptEndpoints($rua);

        if ($endpoints === []) {
            return '';
        }

        return 'v=TLSRPTv1; rua=' . implode(',', $endpoints);
    }

    /**
     * Normalize the configured TLS-RPT reporting addresses (RFC 8460 `rua`) into
     * a list of valid `mailto:`/`https:` endpoints. Comma- or newline-separated
     * entries are accepted, a bare mail address is promoted to `mailto:`, and
     * any entry that is not a valid e-mail or HTTPS URI is dropped, so an invalid
     * configuration yields an empty list (and therefore no published record)
     * rather than a malformed TXT value.
     *
     * @return  string[]
     *
     * @since 1.1.0
     */
    private static function normalizeTlsRptEndpoints(string $rua): array
    {
        $out = [];

        foreach (self::splitLines($rua) as $candidate) {
            $endpoint = self::normalizeTlsRptEndpoint($candidate);

            if ($endpoint !== '' && !in_array($endpoint, $out, true)) {
                $out[] = $endpoint;
            }
        }

        return $out;
    }

    /**
     * Validate and normalize one TLS-RPT endpoint, or return an empty string
     * when it is neither a valid e-mail address nor a valid HTTPS URI.
     *
     * @since 1.1.0
     */
    private static function normalizeTlsRptEndpoint(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Accept a bare mail address and normalize it to a mailto: target.
        if (!preg_match('~^(mailto:|https:)~i', $value) && str_contains($value, '@')) {
            $value = 'mailto:' . $value;
        }

        if (stripos($value, 'mailto:') === 0) {
            $address = substr($value, 7);

            return filter_var($address, FILTER_VALIDATE_EMAIL) ? 'mailto:' . $address : '';
        }

        if (stripos($value, 'https:') === 0) {
            return filter_var($value, FILTER_VALIDATE_URL) ? $value : '';
        }

        return '';
    }

    /**
     * Produce the full set of generated artifacts for one receiving domain.
     *
     * @param   Registry  $params  The com_dmarcreports parameters.
     * @param   string    $domain  The receiving domain (canonical, no trailing dot).
     *
     * @return  array{
     *     enabled:bool, tlsrpt_enabled:bool, domain:string, mode:string, max_age:int, mx:string[],
     *     body:string, id:string,
     *     policy_host:string, well_known_url:string,
     *     sts_record_host:string, sts_record:string,
     *     tlsrpt_record_host:string, tlsrpt_record:string
     * }
     *
     * @since 1.1.0
     */
    public static function generate(Registry $params, string $domain): array
    {
        $domain  = strtolower(trim($domain, " \t\n\r\0\x0B."));
        $enabled = (int) $params->get('mtasts_enable', 0) === 1;
        $tlsRpt  = (int) $params->get('tlsrpt_enable', 0) === 1;
        $mode    = self::normalizeMode((string) $params->get('mtasts_mode', self::MODE_TESTING));
        $maxAge  = (int) $params->get('mtasts_max_age', 604800);
        $mx      = self::normalizeMxList(self::splitLines((string) $params->get('mtasts_mx', '')));
        $rua     = (string) $params->get('tlsrpt_rua', '');

        $body = self::policyBody($mode, $mx, $maxAge);
        $id   = self::policyId($body);

        return [
            'enabled'            => $enabled,
            'tlsrpt_enabled'     => $tlsRpt,
            'domain'             => $domain,
            'mode'               => $mode,
            'max_age'            => max(86400, min(31557600, $maxAge)),
            'mx'                 => $mx,
            'body'               => $body,
            'id'                 => $id,
            'policy_host'        => $domain === '' ? '' : 'mta-sts.' . $domain,
            'well_known_url'     => $domain === '' ? '' : 'https://mta-sts.' . $domain . '/.well-known/mta-sts.txt',
            'sts_record_host'    => $domain === '' ? '' : '_mta-sts.' . $domain,
            'sts_record'         => $id === '' ? '' : self::mtaStsRecord($id),
            'tlsrpt_record_host' => $domain === '' ? '' : '_smtp._tls.' . $domain,
            'tlsrpt_record'      => self::tlsRptRecord($rua),
        ];
    }

    /**
     * Normalize a mode string to a known MODE_* value.
     *
     * @since 1.1.0
     */
    public static function normalizeMode(string $mode): string
    {
        $mode = strtolower(trim($mode));

        return match ($mode) {
            self::MODE_ENFORCE => self::MODE_ENFORCE,
            self::MODE_NONE    => self::MODE_NONE,
            default            => self::MODE_TESTING,
        };
    }

    /**
     * @param   string[]  $mx
     *
     * @return  string[]
     *
     * @since 1.1.0
     */
    private static function normalizeMxList(array $mx): array
    {
        $out = [];

        foreach ($mx as $host) {
            $host = strtolower(trim((string) $host, " \t\n\r\0\x0B."));

            if ($host === '') {
                continue;
            }

            // Only a host pattern is valid in a policy line; reject anything with
            // whitespace or characters outside a hostname/wildcard.
            if (!preg_match('~^(\*\.)?[a-z0-9]([a-z0-9\-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9\-]*[a-z0-9])?)*$~', $host)) {
                continue;
            }

            if (!in_array($host, $out, true)) {
                $out[] = $host;
            }
        }

        return $out;
    }

    /**
     * @return string[]
     *
     * @since 1.1.0
     */
    private static function splitLines(string $value): array
    {
        $parts = preg_split('~[\r\n,]+~', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn ($v) => $v !== ''));
    }
}
