<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\FlaggedSourceLimit;
use PHPUnit\Framework\TestCase;

/**
 * The dashboard loads a bounded number of flagged sources per domain, cut in SQL.
 *
 * @since  1.4.0
 */
class FlaggedSourceLimitTest extends TestCase
{
    public function testQueryKeepsOnlyTheTopRankedRowsPerDomain(): void
    {
        $sql = FlaggedSourceLimit::wrap('SELECT 1 AS domain_id', 'g.spf_only DESC', 50);

        $this->assertStringContainsString('ROW_NUMBER() OVER (PARTITION BY g.domain_id ORDER BY g.spf_only DESC)', $sql);
        $this->assertStringContainsString('WHERE ranked.flag_rank <= 50', $sql);
        $this->assertStringContainsString('COUNT(*) OVER (PARTITION BY g.domain_id) AS flag_total', $sql);
        $this->assertStringContainsString('(SELECT 1 AS domain_id)', $sql);
    }

    public function testDefaultLimitIsUsedForMissingOrInvalidValues(): void
    {
        $this->assertSame(FlaggedSourceLimit::PER_DOMAIN, FlaggedSourceLimit::clamp(null));
        $this->assertSame(FlaggedSourceLimit::PER_DOMAIN, FlaggedSourceLimit::clamp(0));
        $this->assertSame(FlaggedSourceLimit::PER_DOMAIN, FlaggedSourceLimit::clamp(-5));
    }

    public function testLimitCanNeverExceedTheHardMaximum(): void
    {
        $this->assertSame(FlaggedSourceLimit::MAX, FlaggedSourceLimit::clamp(100000));
        $this->assertStringContainsString(
            'flag_rank <= ' . FlaggedSourceLimit::MAX,
            FlaggedSourceLimit::wrap('SELECT 1', 'x', 1000000)
        );
        $this->assertLessThan(FlaggedSourceLimit::MAX, FlaggedSourceLimit::PER_DOMAIN);
    }

    public function testHandledSourcesAreCappedSeparatelyFromOpenOnes(): void
    {
        $sql = FlaggedSourceLimit::wrap('SELECT 1', 'x', 50, "g.source_ip IN ('192.0.2.1')");

        $this->assertStringContainsString(
            "PARTITION BY g.domain_id, (CASE WHEN g.source_ip IN ('192.0.2.1') THEN 1 ELSE 0 END)",
            $sql
        );
        $this->assertStringContainsString('flag_rank <= 50', $sql);
    }
}
