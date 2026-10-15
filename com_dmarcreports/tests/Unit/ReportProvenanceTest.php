<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\ReportProvenance;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the shared report-provenance helper that both the source
 * delete and the domain-cascade delete route through. The transactional UPDATE
 * against the live tables is exercised by the integration storage suite; here
 * only the pure, framework-free decisions are covered: which tables carry a
 * source_id, and how a raw id list is reduced to the values worth unbinding.
 *
 * The regression this guards: a report table carrying a source_id must be
 * unbound on BOTH delete paths. Because both call {@see ReportProvenance}, the
 * table set lives in one place, and this test pins it so a newly added
 * provenance table cannot silently be dropped from the contract.
 *
 * @since  1.1.0
 */
class ReportProvenanceTest extends TestCase
{
    public function testBothProvenanceTablesAreCovered(): void
    {
        // Every table with a source_id column must be listed; missing one would
        // leave dangling provenance when a source or its owning domain is
        // deleted. DMARC aggregate reports and TLS-RPT reports both carry it.
        $tables = ReportProvenance::tables();

        $this->assertContains('#__dmarc_reports', $tables);
        $this->assertContains('#__dmarc_tlsrpt_reports', $tables);
        $this->assertCount(2, $tables);
    }

    public function testNormalizeKeepsOnlyPositiveIds(): void
    {
        $this->assertSame(
            [5, 7],
            ReportProvenance::normalizeSourceIds([5, 7])
        );
    }

    public function testNormalizeDropsTheZeroSentinelAndNegatives(): void
    {
        // 0 is the unbound sentinel itself and must never appear in the IN ()
        // clause; negative and non-numeric entries are not real source rows.
        $this->assertSame(
            [3],
            ReportProvenance::normalizeSourceIds([0, -1, '0', 'x', 3, null])
        );
    }

    public function testNormalizeCoercesNumericStringsAndDeduplicates(): void
    {
        // Ids arriving from loadColumn() are strings; duplicates would only
        // bloat the IN () clause.
        $this->assertSame(
            [4, 9],
            ReportProvenance::normalizeSourceIds(['4', 4, '9'])
        );
    }

    public function testNormalizeReturnsEmptyForNoUsableIds(): void
    {
        // An all-invalid list must collapse to empty so the caller skips the
        // UPDATE entirely rather than emitting an invalid empty IN () clause.
        $this->assertSame([], ReportProvenance::normalizeSourceIds([0, -5, 'nope']));
    }
}
