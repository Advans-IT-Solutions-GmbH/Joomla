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
     * Parse one aggregate report document.
     *
     * @param   string  $xml  The raw XML of a single <feedback> document.
     *
     * @return  array<string, mixed>  Normalised report data.
     *
     * @throws  \InvalidArgumentException  When the document is not a usable report.
     */
    public static function parse(string $xml): array
    {
        $xml = trim($xml);

        if ($xml === '') {
            throw new \InvalidArgumentException('Empty DMARC report document.');
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

        $begin = (int) ($meta->date_range->begin ?? 0);
        $end   = (int) ($meta->date_range->end ?? 0);

        $report = [
            'org_name'   => self::text($meta->org_name ?? null),
            'org_email'  => self::text($meta->email ?? null),
            'report_id'  => self::text($meta->report_id ?? null),
            'date_begin' => $begin,
            'date_end'   => $end,
            'policy'     => [
                'domain' => strtolower(self::text($policy->domain ?? null)),
                'p'      => self::policyDisposition($policy->p ?? null, true),
                'sp'     => self::policyDisposition($policy->sp ?? null, false),
                'pct'    => self::percentage($policy->pct ?? null),
                'adkim'  => self::alignmentMode($policy->adkim ?? null),
                'aspf'   => self::alignmentMode($policy->aspf ?? null),
            ],
            'records'    => [],
        ];

        if ($report['report_id'] === '' || $report['policy']['domain'] === '') {
            throw new \InvalidArgumentException('The report is missing a report id or published policy domain.');
        }

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

        foreach ($feedback->record as $record) {
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

            $report['records'][] = [
                'source_ip'     => $sourceIp,
                'count'         => $count,
                'disposition'   => self::disposition($evaluated->disposition ?? null),
                'dkim_result'   => strtolower(self::text($evaluated->dkim ?? null)),
                'spf_result'    => strtolower(self::text($evaluated->spf ?? null)),
                'dkim_aligned'  => strtolower(self::text($evaluated->dkim ?? null)) === 'pass' ? 1 : 0,
                'spf_aligned'   => strtolower(self::text($evaluated->spf ?? null)) === 'pass' ? 1 : 0,
                'header_from'   => strtolower(self::text($identifier->header_from ?? null)),
                'envelope_from' => strtolower(self::text($identifier->envelope_from ?? null)),
                'envelope_to'   => strtolower(self::text($identifier->envelope_to ?? null)),
            ];
        }

        if ($report['records'] === []) {
            throw new \InvalidArgumentException('The report contains no records.');
        }

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
        return hash(
            'sha256',
            strtolower((string) ($report['org_name'] ?? '')) . '|'
            . (string) ($report['report_id'] ?? '') . '|'
            . (string) ($report['date_begin'] ?? '0')
        );
    }

    private static function text($node): string
    {
        return trim((string) ($node ?? ''));
    }

    private static function alignmentMode($node): string
    {
        $value = strtolower(self::text($node));

        return $value === 's' ? 's' : 'r';
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

    private static function disposition($node): string
    {
        $value = strtolower(self::text($node));

        return \in_array($value, ['quarantine', 'reject'], true) ? $value : 'none';
    }
}
