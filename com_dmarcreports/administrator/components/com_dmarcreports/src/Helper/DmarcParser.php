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
 * Parser for RFC 7489 DMARC aggregate report XML.
 *
 * The parser is deliberately free of any Joomla dependency: it turns one
 * aggregate report document into a normalised array and never touches the
 * database, the filesystem or the network, which keeps it fully unit testable
 * and safe to run on untrusted input. External entities and DOCTYPE
 * declarations are rejected to prevent XXE and billion-laughs attacks.
 *
 * @since  1.0.0
 */
final class DmarcParser
{
    /**
     * Clock-skew allowance for a report's end timestamp, in seconds. Reporter
     * and local clocks are rarely perfectly aligned, so an end slightly in the
     * future is tolerated; anything beyond this is rejected as implausible.
     */
    private const MAX_CLOCK_SKEW = 172800;

    /**
     * Upper bound for a report date-range timestamp (2100-01-01 UTC). A value
     * beyond this is treated as malformed rather than a real Unix timestamp.
     */
    private const MAX_TIMESTAMP = 4102444800;

    /**
     * Maximum stored length of a domain value, matching the VARCHAR(190) domain
     * and header_from columns. A syntactically valid domain can be longer, but
     * storing it would overflow the column; for an auto-detected policy domain
     * the insert then fails on every poll and the message is retried forever, so
     * an over-length domain is rejected here as a parse failure instead.
     */
    private const MAX_DOMAIN_LENGTH = 190;

    /**
     * Maximum stored length of a report metadata value, matching the VARCHAR(255)
     * org_name, org_email and report_id columns. An over-length value would
     * overflow the column so the insert fails on every poll and the message is
     * retried forever; it is rejected here as a parse failure instead.
     */
    private const MAX_METADATA_LENGTH = 255;

    /**
     * Maximum number of <record> rows accepted from a single document. The byte
     * ceiling (MAX_TOTAL_BYTES) bounds the input size but not the row count: a
     * permitted document can still pack a very large number of tiny records,
     * each materialised as a nested PHP array here, so an untrusted attachment
     * could exhaust worker memory. Legitimate aggregate reports stay far below
     * this, so a document exceeding it is rejected as a parse failure.
     */
    private const MAX_RECORDS = 100000;

    /**
     * Parse one aggregate report document.
     *
     * @param   string    $xml         The raw XML of a single <feedback> document.
     * @param   int|null  $maxRecords  Maximum <record> rows to accept; defaults
     *                                 to MAX_RECORDS. Exposed only so the record
     *                                 cap can be exercised cheaply in tests.
     *
     * @return  array<string, mixed>  Normalised report data.
     *
     * @throws  \InvalidArgumentException  When the document is not a usable report.
     */
    public static function parse(string $xml, ?int $maxRecords = null): array
    {
        $maxRecords = ($maxRecords !== null && $maxRecords > 0) ? $maxRecords : self::MAX_RECORDS;
        $xml        = trim($xml);

        if ($xml === '') {
            throw new \InvalidArgumentException('Empty DMARC report document.');
        }

        // A legitimate aggregate report is UTF-8 (or ASCII) XML, and XML 1.0
        // forbids the NUL character outright. A UTF-16/UTF-32 encoded document
        // spreads its ASCII markup — including a DOCTYPE — across NUL-padded
        // multi-byte sequences, so the byte-level DOCTYPE guard below would not
        // match it while simplexml_load_string() still honours the encoding
        // declaration and could expand a DTD. Reject any NUL byte (and therefore
        // any UTF-16/UTF-32 payload) up front so the DOCTYPE invariant cannot be
        // bypassed by re-encoding the document.
        if (strpos($xml, "\0") !== false) {
            throw new \InvalidArgumentException('DMARC reports must be UTF-8 XML without NUL bytes.');
        }

        // Reject any DOCTYPE outright: a legitimate aggregate report never has one,
        // and forbidding it removes the whole class of entity-expansion attacks.
        if (preg_match('/<!DOCTYPE/i', $xml)) {
            throw new \InvalidArgumentException('DOCTYPE is not allowed in DMARC reports.');
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $feedback = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$feedback instanceof \SimpleXMLElement || strtolower($feedback->getName()) !== 'feedback') {
            throw new \InvalidArgumentException('The document is not a DMARC aggregate report.');
        }

        $meta   = $feedback->report_metadata;
        $policy = $feedback->policy_published;

        $begin = self::timestamp($meta->date_range->begin ?? null);
        $end   = self::timestamp($meta->date_range->end ?? null);

        $report = [
            'org_name'   => self::text($meta->org_name ?? null),
            'org_email'  => self::text($meta->email ?? null),
            'report_id'  => self::text($meta->report_id ?? null),
            'date_begin' => $begin,
            'date_end'   => $end,
            'policy'     => [
                'domain' => self::text($policy->domain ?? null),
                'p'      => self::policyDisposition($policy->p ?? null, true),
                'sp'     => self::policyDisposition($policy->sp ?? null, false),
                'pct'    => self::percentage($policy->pct ?? null),
                'adkim'  => self::alignmentMode($policy->adkim ?? null),
                'aspf'   => self::alignmentMode($policy->aspf ?? null),
            ],
            'records'    => [],
            'message_count' => 0,
        ];

        // Canonicalize the published policy domain (lowercase, IDNA/ASCII, DNS
        // label validation and the 190-character storage limit) before it can be
        // trusted or auto-registered as a monitored domain. An untrusted value
        // such as a URL, embedded whitespace or control characters canonicalizes
        // to null and is rejected here as a parse failure.
        $report['policy']['domain'] = (string) DomainName::canonicalize((string) $report['policy']['domain']);

        if ($report['report_id'] === '' || $report['policy']['domain'] === '') {
            throw new \InvalidArgumentException('The report is missing a report id or a valid published policy domain.');
        }

        // Reject over-length report metadata before storage. These untrusted
        // values land in VARCHAR(255) columns; an over-length value would fail
        // the insert on every poll and the message would be retried forever
        // (see MAX_METADATA_LENGTH), so it is rejected as a parse failure here.
        foreach (['org_name', 'org_email', 'report_id'] as $field) {
            if (self::length((string) $report[$field]) > self::MAX_METADATA_LENGTH) {
                throw new \InvalidArgumentException('The report ' . $field . ' exceeds the supported length.');
            }
        }

        // The published policy domain is canonicalized and length-checked by
        // DomainName::canonicalize() above, so no separate length guard is
        // needed here.

        // The fingerprint is built from org name, report id and begin date; a
        // report missing either of the remaining two parts cannot be deduplicated
        // reliably and is rejected so distinct reports never collide on one hash.
        if ($report['org_name'] === '' || $report['date_begin'] <= 0) {
            throw new \InvalidArgumentException('The report is missing an organisation name or a valid begin date.');
        }

        // An inverted or absent end date would misplace the report on the
        // timeline and could anchor the rolling windows incorrectly, so a report
        // whose range does not run forwards is rejected before it is stored.
        if ($report['date_end'] < $report['date_begin']) {
            throw new \InvalidArgumentException('The report has an inverted date range.');
        }

        // A report range cannot plausibly end in the future. Metrics anchor the
        // rolling windows and the displayed current policy on the maximum
        // date_end, so a crafted far-future report could otherwise push every
        // legitimate report outside the Action Center windows and pin the shown
        // policy indefinitely. Reject end timestamps beyond a small clock-skew
        // allowance (reporter and local clocks are rarely perfectly aligned).
        if ($report['date_end'] > time() + self::MAX_CLOCK_SKEW) {
            throw new \InvalidArgumentException('The report end date is implausibly far in the future.');
        }

        $total = 0;
        $recordCount = 0;

        foreach ($feedback->record as $record) {
            // Enforce the record cap before materialising the row, so a document
            // packed with many tiny records cannot exhaust memory one array at a
            // time. The whole report is rejected rather than truncated, because a
            // partial store would commit the fingerprint and discard a corrected
            // copy later as a duplicate.
            if (++$recordCount > $maxRecords) {
                throw new \InvalidArgumentException('The report contains more records than are supported.');
            }

            $row        = $record->row;
            $evaluated  = $row->policy_evaluated;
            $identifier = $record->identifiers;

            $sourceIp = self::text($row->source_ip ?? null);

            // A row is only meaningful with a routable source address and a
            // positive message count. Coercing a malformed value to 0 or '' and
            // storing it would commit the report fingerprint while keeping an
            // invalid row, so a corrected copy would later be rejected as a
            // duplicate. Reject the whole report instead.
            if (filter_var($sourceIp, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('A report record has a missing or malformed source IP.');
            }

            // Canonicalise the validated address so equivalent spellings are
            // stored as one sender. IPv6 in particular has many textual forms
            // for the same address (for example 2001:0DB8:0:0:0:0:0:1 and
            // 2001:db8::1); without normalisation they would group into
            // separate top/new/unaligned sources and produce distinct Action
            // Center keys. inet_pton() reduces the value to its packed bytes and
            // inet_ntop() renders the single canonical text form. Any address
            // FILTER_VALIDATE_IP accepts round-trips, so the guarded fallback
            // only preserves the original on an unexpected failure.
            $packed = @inet_pton($sourceIp);

            if ($packed !== false) {
                $canonical = @inet_ntop($packed);

                if ($canonical !== false) {
                    $sourceIp = $canonical;
                }
            }

            $countText = self::text($row->count ?? null);

            // The value must be a positive integer that also fits the signed
            // range PHP binds and the database column stores. A digit string
            // beyond that range (for example "3000000000" on a 32-bit build, or
            // a value past PHP_INT_MAX) would otherwise be clamped or overflow
            // and fail deterministically on insert, making the message retry
            // forever, so the whole report is rejected here instead.
            if (!ctype_digit($countText) || self::exceedsIntegerRange($countText)) {
                throw new \InvalidArgumentException('A report record has a missing, non-numeric or out-of-range message count.');
            }

            $count = (int) $countText;

            if ($count < 1) {
                throw new \InvalidArgumentException('A report record has a non-positive message count.');
            }

            // Required evaluated fields are validated, not coerced. A missing or
            // unknown disposition / DKIM / SPF result, or a missing header_from,
            // would otherwise be stored as a misleading default (none / fail /
            // empty) and commit the report fingerprint, so a later corrected
            // copy would be discarded as a duplicate while the dashboard shows
            // inaccurate metrics. The whole report is rejected instead.
            $disposition = self::evaluatedDisposition($evaluated->disposition ?? null);
            $dkimResult  = self::evaluatedResult($evaluated->dkim ?? null, 'DKIM');
            $spfResult   = self::evaluatedResult($evaluated->spf ?? null, 'SPF');
            $headerFrom  = strtolower(self::text($identifier->header_from ?? null));

            if ($headerFrom === '') {
                throw new \InvalidArgumentException('A report record is missing the header_from identifier.');
            }

            if (self::length($headerFrom) > self::MAX_DOMAIN_LENGTH) {
                throw new \InvalidArgumentException('A report record header_from exceeds the supported length.');
            }

            // Guard the running total with overflow-safe addition. Individually
            // valid counts can still sum past PHP_INT_MAX, where array_sum()
            // would spill into a float and casting it back yields PHP_INT_MIN,
            // so the unsigned message_count insert would fail deterministically
            // and the message would be retried forever. Reject the whole report
            // up front and carry the validated total forward for storage.
            if ($count > PHP_INT_MAX - $total) {
                throw new \InvalidArgumentException('The report total message count exceeds the storable range.');
            }

            $total += $count;

            $envelopeFrom = strtolower(self::text($identifier->envelope_from ?? null));
            $envelopeTo   = strtolower(self::text($identifier->envelope_to ?? null));

            // Envelope identifiers land in VARCHAR(190) columns; reject an
            // over-length value as a parse failure so the insert cannot fail on
            // every poll and retry the message forever.
            if (self::length($envelopeFrom) > self::MAX_DOMAIN_LENGTH
                || self::length($envelopeTo) > self::MAX_DOMAIN_LENGTH) {
                throw new \InvalidArgumentException('A report record envelope identifier exceeds the supported length.');
            }

            $report['records'][] = [
                'source_ip'     => $sourceIp,
                'count'         => $count,
                'disposition'   => $disposition,
                'dkim_result'   => $dkimResult,
                'spf_result'    => $spfResult,
                'dkim_aligned'  => $dkimResult === 'pass' ? 1 : 0,
                'spf_aligned'   => $spfResult === 'pass' ? 1 : 0,
                'header_from'   => $headerFrom,
                'envelope_from' => $envelopeFrom,
                'envelope_to'   => $envelopeTo,
            ];
        }

        if ($report['records'] === []) {
            throw new \InvalidArgumentException('The report contains no records.');
        }

        $report['message_count'] = $total;

        return $report;
    }

    /**
     * A stable fingerprint of a report for idempotent storage: the same report,
     * imported twice, produces the same hash and is stored only once.
     *
     * @param   array<string, mixed>  $report  A parsed report.
     *
     * @return  string  A 64 character hex digest.
     */
    public static function fingerprint(array $report): string
    {
        // org_name and report_id are untrusted strings that may contain any
        // character, so a plain delimiter would be ambiguous: ("a|b", "c") and
        // ("a", "b|c") would collide and one distinct report would be dropped as
        // a duplicate. Length-prefix every field so the boundaries are explicit.
        //
        // The policy domain is part of the fingerprint because the hash is
        // global (not scoped to a domain row): a reporter can legitimately reuse
        // the same report id and window for different policy domains, so without
        // it the second domain's report would be discarded as a duplicate.
        $fields = [
            strtolower((string) ($report['policy']['domain'] ?? '')),
            strtolower((string) ($report['org_name'] ?? '')),
            (string) ($report['report_id'] ?? ''),
            (string) ($report['date_begin'] ?? '0'),
        ];

        $canonical = '';

        foreach ($fields as $field) {
            $canonical .= \strlen($field) . ':' . $field . '|';
        }

        return hash('sha256', $canonical);
    }

    private static function text($node): string
    {
        return trim((string) ($node ?? ''));
    }

    /**
     * Convert a report date-range value to a Unix timestamp, accepting only a
     * plain non-negative integer in a plausible range. A bare (int) cast would
     * silently accept numeric-prefixed junk such as "1700000000x" and store a
     * valid-looking date; because the begin date feeds the dedup fingerprint and
     * date_end anchors the rolling windows, an invalid value is rejected (mapped
     * to 0) so the surrounding begin/end sanity checks reject the whole report.
     */
    private static function timestamp($node): int
    {
        $text = self::text($node);

        if ($text === '' || !ctype_digit($text)) {
            return 0;
        }

        $ts = (int) $text;

        return ($ts >= 0 && $ts <= self::MAX_TIMESTAMP) ? $ts : 0;
    }

    private static function alignmentMode($node): string
    {
        $value = strtolower(self::text($node));

        // An absent tag inherits the RFC 7489 default of relaxed alignment.
        if ($value === '') {
            return 'r';
        }

        // A present but unknown mode is rejected rather than silently coerced to
        // relaxed, which would misrepresent the published alignment policy.
        if ($value !== 's' && $value !== 'r') {
            throw new \InvalidArgumentException('The report publishes an unknown DMARC alignment mode.');
        }

        return $value;
    }

    /**
     * Validates a published policy disposition tag (p or sp). The main policy is
     * required and must be one of the three RFC 7489 values; the subdomain
     * policy is optional and an absent value simply inherits the main policy.
     * An unknown value is rejected rather than stored, because the latest stored
     * policy drives the dashboard and the rollout guidance: one malformed report
     * must not be able to display a bogus policy and suppress all guidance.
     *
     * @param   \SimpleXMLElement|null  $node      The policy tag, if present.
     * @param   bool                    $required  Whether an empty value is an error.
     *
     * @return  string  A validated disposition, or '' for an absent optional tag.
     */
    private static function policyDisposition($node, bool $required): string
    {
        $value = strtolower(self::text($node));

        if ($value === '') {
            if ($required) {
                throw new \InvalidArgumentException('The report does not publish a DMARC policy (p).');
            }

            return '';
        }

        if (!\in_array($value, ['none', 'quarantine', 'reject'], true)) {
            throw new \InvalidArgumentException('The report publishes an unknown DMARC policy value.');
        }

        return $value;
    }

    /**
     * Validates the published policy percentage. When absent it defaults to 100
     * (the RFC default); when present it must be a whole number from 0 through
     * 100. A malformed value such as "abc" or an out-of-range one such as 150 is
     * rejected instead of being coerced to a misleading number.
     *
     * @param   \SimpleXMLElement|null  $node  The pct tag, if present.
     *
     * @return  int  A percentage from 0 to 100.
     */
    private static function percentage($node): int
    {
        $value = self::text($node);

        if ($value === '') {
            return 100;
        }

        if (!ctype_digit($value) || (int) $value > 100) {
            throw new \InvalidArgumentException('The published policy percentage must be a whole number between 0 and 100.');
        }

        return (int) $value;
    }

    /**
     * Whether a non-negative digit string is larger than the signed integer PHP
     * binds and the database stores, compared as text to avoid float rounding.
     */
    private static function exceedsIntegerRange(string $digits): bool
    {
        // Normalise leading zeros so magnitude is judged by significant digits
        // only ("007" is 7, not a 3-digit number). An all-zero string collapses
        // to "0", which is never over the cap.
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
     * Validates a record's evaluated disposition (policy_evaluated/disposition).
     * It is a required field and must be one of the three RFC 7489 values. An
     * absent or unknown value is rejected rather than coerced to 'none', which
     * would understate the applied policy and still fingerprint the report so a
     * later corrected copy would be dropped as a duplicate.
     *
     * @param   \SimpleXMLElement|null  $node  The disposition tag, if present.
     *
     * @return  string  A validated disposition.
     */
    private static function evaluatedDisposition($node): string
    {
        $value = strtolower(self::text($node));

        if ($value === '') {
            throw new \InvalidArgumentException('A report record is missing its evaluated disposition.');
        }

        if (!\in_array($value, ['none', 'quarantine', 'reject'], true)) {
            throw new \InvalidArgumentException('A report record has an unknown evaluated disposition.');
        }

        return $value;
    }

    /**
     * Validates a record's evaluated DKIM or SPF result. Both are required and
     * must be 'pass' or 'fail'; any other value (including an absent tag) is
     * rejected rather than being treated as a failure, because a wrong result
     * skews the pass-rate metrics and rollout guidance.
     *
     * @param   \SimpleXMLElement|null  $node   The result tag, if present.
     * @param   string                  $label  Human label for the error message.
     *
     * @return  string  Either 'pass' or 'fail'.
     */
    private static function evaluatedResult($node, string $label): string
    {
        $value = strtolower(self::text($node));

        if ($value === '') {
            throw new \InvalidArgumentException('A report record is missing its evaluated ' . $label . ' result.');
        }

        if ($value !== 'pass' && $value !== 'fail') {
            throw new \InvalidArgumentException('A report record has an unknown evaluated ' . $label . ' result.');
        }

        return $value;
    }

    /**
     * Length of a string in characters, preferring the multibyte count so an
     * internationalised domain is measured by characters rather than bytes.
     */
    private static function length(string $value): int
    {
        return \function_exists('mb_strlen') ? mb_strlen($value) : \strlen($value);
    }
}
