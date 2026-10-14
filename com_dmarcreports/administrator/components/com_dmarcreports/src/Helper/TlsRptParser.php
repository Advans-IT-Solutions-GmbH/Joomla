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
 * Parser for RFC 8460 SMTP TLS Reporting (TLS-RPT) JSON reports.
 *
 * Like {@see DmarcParser} this class has no Joomla dependency: it turns one
 * TLS-RPT JSON document into a normalised array and never touches the database,
 * the filesystem or the network, which keeps it fully unit testable and safe to
 * run on untrusted input. A report bundles one or more policy results, each with
 * success/failure session counts and optional per-failure detail rows.
 *
 * @since  1.1.0
 */
final class TlsRptParser
{
    /**
     * Upper bound for a report date-range timestamp (2100-01-01 UTC). A value
     * beyond this is treated as malformed rather than a real Unix timestamp.
     */
    private const MAX_TIMESTAMP = 4102444800;

    /**
     * Clock-skew allowance for a report's end timestamp, in seconds.
     */
    private const MAX_CLOCK_SKEW = 172800;

    /**
     * Maximum stored length of a domain value, matching the VARCHAR(190) column.
     */
    private const MAX_DOMAIN_LENGTH = 190;

    /**
     * Maximum stored length of a metadata value, matching VARCHAR(255) columns.
     */
    private const MAX_METADATA_LENGTH = 255;

    /**
     * Maximum number of policy blocks accepted from a single report.
     */
    private const MAX_POLICIES = 1000;

    /**
     * Maximum number of failure-detail rows accepted across a single report. A
     * permitted document can pack a very large number of tiny detail rows, each
     * materialised as a nested PHP array; legitimate reports stay far below this.
     */
    private const MAX_FAILURES = 100000;

    /**
     * Parse-failure codes carried on the thrown InvalidArgumentException so a
     * caller can classify the failure without matching on the English text.
     */
    public const ERROR_EMPTY      = 1;
    public const ERROR_JSON       = 2;
    public const ERROR_NOT_REPORT = 4;

    /**
     * Parse one TLS-RPT report document.
     *
     * @param   string  $json  The raw JSON of a single TLS-RPT report.
     *
     * @return  array<string, mixed>  Normalised report data.
     *
     * @throws  \InvalidArgumentException  When the document is not a usable report.
     */
    public static function parse(string $json): array
    {
        $json = trim($json);

        if ($json === '') {
            throw new \InvalidArgumentException('Empty TLS-RPT report document.', self::ERROR_EMPTY);
        }

        if (strpos($json, "\0") !== false) {
            throw new \InvalidArgumentException('TLS-RPT reports must be UTF-8 JSON without NUL bytes.', self::ERROR_JSON);
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('The TLS-RPT report JSON could not be parsed.', self::ERROR_JSON);
        }

        if (!\is_array($data)) {
            throw new \InvalidArgumentException('The document is not a TLS-RPT report.', self::ERROR_NOT_REPORT);
        }

        $range = \is_array($data['date-range'] ?? null) ? $data['date-range'] : [];
        $begin = self::timestamp($range['start-datetime'] ?? null);
        $end   = self::timestamp($range['end-datetime'] ?? null);

        // Reject over-length identity metadata before it is stored. These
        // untrusted values feed the deduplication fingerprint (org name and
        // report id); clipping them would alias two distinct reports that only
        // differ past the column width, dropping the second as a duplicate.
        // Mirror {@see DmarcParser}, which rejects rather than truncates.
        $orgName     = trim((string) ($data['organization-name'] ?? ''));
        $contactInfo = trim((string) ($data['contact-info'] ?? ''));
        $reportId    = trim((string) ($data['report-id'] ?? ''));

        foreach (['organization-name' => $orgName, 'contact-info' => $contactInfo, 'report-id' => $reportId] as $label => $value) {
            if (self::length($value) > self::MAX_METADATA_LENGTH) {
                throw new \InvalidArgumentException(
                    'The TLS-RPT report ' . $label . ' exceeds the supported length.',
                    self::ERROR_NOT_REPORT
                );
            }
        }

        $report = [
            'org_name'     => $orgName,
            'contact_info' => $contactInfo,
            'report_id'    => $reportId,
            'date_begin'   => $begin,
            'date_end'     => $end,
            'policies'     => [],
        ];

        if ($report['report_id'] === '') {
            throw new \InvalidArgumentException('The TLS-RPT report is missing a report id.', self::ERROR_NOT_REPORT);
        }

        // Reject an implausible date range before storage, matching the DMARC
        // parser's integrity checks: a missing or unparseable begin collapses to
        // 0, an inverted range would misplace the report on the timeline, and an
        // end beyond a small clock-skew allowance could anchor the rolling
        // windows on a crafted far-future report.
        if ($report['date_begin'] <= 0) {
            throw new \InvalidArgumentException('The TLS-RPT report has a missing or invalid begin date.', self::ERROR_NOT_REPORT);
        }

        if ($report['date_end'] < $report['date_begin']) {
            throw new \InvalidArgumentException('The TLS-RPT report has an inverted date range.', self::ERROR_NOT_REPORT);
        }

        if ($report['date_end'] > time() + self::MAX_CLOCK_SKEW) {
            throw new \InvalidArgumentException('The TLS-RPT report end date is implausibly far in the future.', self::ERROR_NOT_REPORT);
        }

        $policies = $data['policies'] ?? null;

        if (!\is_array($policies) || $policies === []) {
            throw new \InvalidArgumentException('The TLS-RPT report carries no policy results.', self::ERROR_NOT_REPORT);
        }

        // Reject rather than truncate an over-sized report: a silently clipped
        // report would be stored and deduplicated under the real report id with
        // incomplete session totals, permanently recording wrong figures and
        // blocking a corrected re-import. Mirror the DMARC parser, which rejects
        // reports that exceed its resource ceilings.
        if (\count($policies) > self::MAX_POLICIES) {
            throw new \InvalidArgumentException('The TLS-RPT report exceeds the supported number of policies.', self::ERROR_NOT_REPORT);
        }

        $failureBudget = self::MAX_FAILURES;

        foreach ($policies as $policy) {
            if (!\is_array($policy)) {
                continue;
            }

            $report['policies'][] = self::parsePolicy($policy, $failureBudget);
        }

        if ($report['policies'] === []) {
            throw new \InvalidArgumentException('The TLS-RPT report carries no usable policy results.', self::ERROR_NOT_REPORT);
        }

        return $report;
    }

    /**
     * @param   array<string, mixed>  $policy
     * @param   int                   $failureBudget  Remaining detail rows allowed
     *                                                across the whole report.
     *
     * @return  array<string, mixed>
     */
    private static function parsePolicy(array $policy, int &$failureBudget): array
    {
        $block   = \is_array($policy['policy'] ?? null) ? $policy['policy'] : [];
        $summary = \is_array($policy['summary'] ?? null) ? $policy['summary'] : [];

        $policyDomain = (string) DomainName::canonicalize((string) ($block['policy-domain'] ?? ''));

        $result = [
            'policy_type'   => self::clip(strtolower((string) ($block['policy-type'] ?? '')), 20),
            'policy_domain' => $policyDomain,
            'success_count' => self::count($summary['total-successful-session-count'] ?? 0),
            'failure_count' => self::count($summary['total-failure-session-count'] ?? 0),
            'failures'      => [],
        ];

        $details = $policy['failure-details'] ?? null;

        if (!\is_array($details)) {
            return $result;
        }

        foreach ($details as $detail) {
            if ($failureBudget <= 0) {
                // Reject rather than silently drop detail rows: a truncated
                // report would still be stored under its real identity with an
                // incomplete failure list. Mirror the policy-cap handling above.
                throw new \InvalidArgumentException('The TLS-RPT report exceeds the supported number of failure details.', self::ERROR_NOT_REPORT);
            }

            if (!\is_array($detail)) {
                continue;
            }

            $failureBudget--;
            $result['failures'][] = [
                'result_type'          => self::clip((string) ($detail['result-type'] ?? ''), 60),
                'sending_mta_ip'       => self::clip((string) ($detail['sending-mta-ip'] ?? ''), 45),
                'receiving_mx_host'    => self::clip((string) ($detail['receiving-mx-hostname'] ?? ''), 190),
                'receiving_ip'         => self::clip((string) ($detail['receiving-ip'] ?? ''), 45),
                'failed_session_count' => self::count($detail['failed-session-count'] ?? 0),
                'additional_info'      => self::clip((string) ($detail['additional-information'] ?? ''), 1024),
                'failure_reason_code'  => self::clip((string) ($detail['failure-reason-code'] ?? ''), 190),
            ];
        }

        return $result;
    }

    /**
     * Content fingerprint used to deduplicate a report across repeated imports.
     * Length-prefix every field so distinct reports cannot collide through an
     * ambiguous delimiter, mirroring {@see DmarcParser::fingerprint()}.
     *
     * @param   array<string, mixed>  $report
     */
    public static function fingerprint(array $report): string
    {
        $fields = [
            strtolower((string) ($report['org_name'] ?? '')),
            (string) ($report['report_id'] ?? ''),
            (string) ($report['date_begin'] ?? '0'),
            (string) ($report['date_end'] ?? '0'),
        ];

        $canonical = '';

        foreach ($fields as $field) {
            $canonical .= \strlen($field) . ':' . $field . '|';
        }

        return hash('sha256', $canonical);
    }

    /**
     * Convert an RFC 3339 / ISO 8601 date-time string to a Unix timestamp,
     * rejecting an implausible value. Returns 0 when the value is unusable.
     */
    private static function timestamp($value): int
    {
        if (!\is_string($value) || $value === '') {
            return 0;
        }

        $ts = strtotime($value);

        if ($ts === false) {
            return 0;
        }

        return ($ts >= 0 && $ts <= self::MAX_TIMESTAMP) ? $ts : 0;
    }

    /**
     * Coerce an untrusted session-count value to a non-negative integer. A digit
     * string larger than the signed integer PHP binds (and the database stores)
     * is rejected rather than saturated, so a crafted count cannot overflow the
     * running totals and keep an IMAP UID retrying forever. Mirrors the DMARC
     * parser's out-of-range rejection.
     *
     * @throws  \InvalidArgumentException  When a numeric string is out of range.
     */
    private static function count($value): int
    {
        if (\is_int($value)) {
            return $value < 0 ? 0 : $value;
        }

        if (\is_string($value) && preg_match('/^\d+$/', $value)) {
            if (self::exceedsIntegerRange($value)) {
                throw new \InvalidArgumentException('A TLS-RPT session count is out of range.', self::ERROR_NOT_REPORT);
            }

            return (int) $value;
        }

        return 0;
    }

    /**
     * Whether a non-negative digit string is larger than the signed integer PHP
     * binds and the database stores, compared as text to avoid float rounding.
     * Copied from {@see DmarcParser::exceedsIntegerRange()} so both ingestion
     * paths reject out-of-range counts identically.
     */
    private static function exceedsIntegerRange(string $digits): bool
    {
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return false;
        }

        $max = (string) PHP_INT_MAX;
        $len = \strlen($digits);
        $cap = \strlen($max);

        return $len > $cap || ($len === $cap && strcmp($digits, $max) > 0);
    }

    /**
     * Length of a string in characters, preferring the multibyte count so an
     * internationalised value is measured by characters rather than bytes.
     */
    private static function length(string $value): int
    {
        return \function_exists('mb_strlen') ? mb_strlen($value) : \strlen($value);
    }

    /**
     * Trim a value to at most $max characters so it fits its database column.
     */
    private static function clip(string $value, int $max): string
    {
        $value = trim($value);

        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
