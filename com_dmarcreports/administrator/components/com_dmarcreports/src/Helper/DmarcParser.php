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
                'p'      => strtolower(self::text($policy->p ?? null)),
                'sp'     => strtolower(self::text($policy->sp ?? null)),
                'pct'    => isset($policy->pct) && self::text($policy->pct) !== '' ? (int) $policy->pct : 100,
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

        foreach ($feedback->record as $record) {
            $row        = $record->row;
            $evaluated  = $row->policy_evaluated;
            $identifier = $record->identifiers;

            $report['records'][] = [
                'source_ip'     => self::text($row->source_ip ?? null),
                'count'         => max(0, (int) ($row->count ?? 0)),
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

    private static function disposition($node): string
    {
        $value = strtolower(self::text($node));

        return \in_array($value, ['quarantine', 'reject'], true) ? $value : 'none';
    }
}
