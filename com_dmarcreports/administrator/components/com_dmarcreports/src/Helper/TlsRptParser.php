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
        // Mirror {@see DmarcParser}, which rejects rather than truncates. RFC
        // 8460 requires each as a non-empty JSON string, so a missing key, a
        // non-string value or an empty string is rejected before casting.
        $orgName     = self::requiredString($data, 'organization-name');
        $contactInfo = self::requiredString($data, 'contact-info');
        $reportId    = self::requiredString($data, 'report-id');

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
                throw new \InvalidArgumentException('A TLS-RPT policy result is not an object.', self::ERROR_NOT_REPORT);
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

        // RFC 8460 requires every policy object to carry a valid policy-domain.
        // A missing or malformed value canonicalizes to null; rejecting the whole
        // report here prevents an invalid policy from being stored under the
        // report's primary domain with an empty domain name. Mirrors the DMARC
        // parser, which rejects a report whose published policy domain is invalid.
        $policyDomain = DomainName::canonicalize((string) ($block['policy-domain'] ?? ''));

        if ($policyDomain === null) {
            throw new \InvalidArgumentException('A TLS-RPT policy is missing a valid policy domain.', self::ERROR_NOT_REPORT);
        }

        // RFC 8460 makes policy-type mandatory. A missing or non-string value
        // would otherwise be stored as an empty string, and because the report's
        // content fingerprint deduplicates repeated imports, a corrected copy
        // could never repair the row. Reject the whole report, mirroring the
        // policy-domain handling above.
        $policyType = $block['policy-type'] ?? null;

        if (!\is_string($policyType) || trim($policyType) === '') {
            throw new \InvalidArgumentException('A TLS-RPT policy is missing a valid policy-type.', self::ERROR_NOT_REPORT);
        }

        $result = [
            'policy_type'   => self::clip(strtolower($policyType), 20),
            'policy_domain' => $policyDomain,
            'success_count' => self::requiredCount($summary, 'total-successful-session-count'),
            'failure_count' => self::requiredCount($summary, 'total-failure-session-count'),
            'failures'      => [],
        ];

        $details = $policy['failure-details'] ?? null;

        if ($details === null) {
            // RFC 8460 makes failure-details optional, so an absent (or null)
            // field legitimately means "no detail rows".
            return $result;
        }

        if (!\is_array($details)) {
            // A present but non-array failure-details value is malformed. Reject
            // rather than store the report with its summary counts but no detail
            // rows: the report hash would then permanently deduplicate a
            // corrected copy, so the detail rows could never be imported.
            throw new \InvalidArgumentException('A TLS-RPT report has a malformed failure-details value.', self::ERROR_NOT_REPORT);
        }

        foreach ($details as $detail) {
            if ($failureBudget <= 0) {
                // Reject rather than silently drop detail rows: a truncated
                // report would still be stored under its real identity with an
                // incomplete failure list. Mirror the policy-cap handling above.
                throw new \InvalidArgumentException('The TLS-RPT report exceeds the supported number of failure details.', self::ERROR_NOT_REPORT);
            }

            if (!\is_array($detail)) {
                // Reject rather than silently skip a malformed failure entry:
                // the surviving rows would still be stored under the report's
                // permanent fingerprint, so a corrected copy could never replace
                // the partial import. Mirror the policy and budget handling.
                throw new \InvalidArgumentException('A TLS-RPT failure detail is not an object.', self::ERROR_NOT_REPORT);
            }

            $failureBudget--;
            // RFC 8460 makes result-type mandatory in every failure detail. An
            // empty/non-string value stored as '' would, via the report's
            // content fingerprint, permanently deduplicate a corrected copy, so
            // reject the whole report rather than persist a detail row without
            // the result that gives it meaning.
            $resultType = $detail['result-type'] ?? null;

            if (!\is_string($resultType) || trim($resultType) === '') {
                throw new \InvalidArgumentException('A TLS-RPT failure detail is missing a valid result-type.', self::ERROR_NOT_REPORT);
            }

            $result['failures'][] = [
                'result_type'          => self::clip($resultType, 60),
                'sending_mta_ip'       => self::clip((string) ($detail['sending-mta-ip'] ?? ''), 45),
                'receiving_mx_host'    => self::clip((string) ($detail['receiving-mx-hostname'] ?? ''), 190),
                'receiving_ip'         => self::clip((string) ($detail['receiving-ip'] ?? ''), 45),
                'failed_session_count' => self::requiredCount($detail, 'failed-session-count'),
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
     * Read a required session-count field from an untrusted summary or failure
     * detail. RFC 8460 requires the session totals in every policy summary and a
     * failed-session-count in every failure detail; a missing field is rejected
     * rather than defaulted to zero, so understated metrics are never stored
     * under the report's permanent fingerprint and a corrected retransmission
     * can still be imported. A present value is validated by {@see self::count()}.
     *
     * @param   array<string, mixed>  $source
     *
     * @throws  \InvalidArgumentException  When the field is missing or invalid.
     */
    private static function requiredCount(array $source, string $key): int
    {
        if (!\array_key_exists($key, $source)) {
            throw new \InvalidArgumentException('A required TLS-RPT session count is missing.', self::ERROR_NOT_REPORT);
        }

        return self::count($source[$key]);
    }

    /**
     * Read a required identity string (organization-name, contact-info,
     * report-id) from the untrusted report root. RFC 8460 requires each as a
     * JSON string, so a missing key, a non-string value (an array, object,
     * number or boolean) or an empty/whitespace-only string is rejected rather
     * than coerced: a bare (string) cast would silently turn an array into the
     * literal "Array" and accept a missing field as "", storing a bogus value
     * under the report's permanent fingerprint.
     *
     * @param   array<string, mixed>  $data
     *
     * @throws  \InvalidArgumentException  When the field is missing, not a
     *                                     string or empty.
     */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (!\is_string($value)) {
            throw new \InvalidArgumentException('The TLS-RPT report ' . $key . ' must be a string.', self::ERROR_NOT_REPORT);
        }

        $value = trim($value);

        if ($value === '') {
            throw new \InvalidArgumentException('The TLS-RPT report ' . $key . ' is missing.', self::ERROR_NOT_REPORT);
        }

        return $value;
    }

    /**
     * Validate a present, untrusted session-count value as a non-negative
     * integer. TLS-RPT counts may legitimately be zero, so only malformed values
     * — a negative integer, a non-numeric or negative string, a float or a
     * boolean — are rejected rather than coerced to zero: normalising one would
     * store understated totals under the report's permanent fingerprint, so a
     * corrected retransmission could never repair the metrics. A digit string
     * beyond the signed-integer range is likewise rejected rather than saturated.
     * Missing fields are caught earlier by {@see self::requiredCount()}.
     *
     * @throws  \InvalidArgumentException  When the value is not a valid count.
     */
    private static function count($value): int
    {
        if (\is_int($value)) {
            if ($value < 0) {
                throw new \InvalidArgumentException('A TLS-RPT session count is negative.', self::ERROR_NOT_REPORT);
            }

            return $value;
        }

        if (\is_string($value) && preg_match('/^\d+$/', $value)) {
            if (self::exceedsIntegerRange($value)) {
                throw new \InvalidArgumentException('A TLS-RPT session count is out of range.', self::ERROR_NOT_REPORT);
            }

            return (int) $value;
        }

        throw new \InvalidArgumentException('A TLS-RPT session count is not a valid non-negative integer.', self::ERROR_NOT_REPORT);
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
