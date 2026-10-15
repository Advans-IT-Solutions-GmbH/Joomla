<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\SourcePollLock;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the advisory lock naming and GET_LOCK result interpretation
 * that serialise polling of a source across the on-demand import and the
 * scheduler task. The database-backed acquire/hold/skip behaviour is exercised
 * by the integration suite; here only the pure, framework-free decisions are
 * covered.
 *
 * @since  1.1.0
 */
class SourcePollLockTest extends TestCase
{
    public function testBuildNameIsDeterministic(): void
    {
        $this->assertSame(
            SourcePollLock::buildName('site_db', 42),
            SourcePollLock::buildName('site_db', 42)
        );
    }

    public function testBuildNameStaysWithinTheGetLockLimit(): void
    {
        // GET_LOCK names are capped at 64 characters in MySQL 5.7+/8; an unusual
        // schema name or a large source id must never overflow it.
        $name = SourcePollLock::buildName(str_repeat('x', 200), PHP_INT_MAX);

        $this->assertLessThanOrEqual(64, \strlen($name));
    }

    public function testBuildNameIsNamespacedBySchema(): void
    {
        // Two sites on one MySQL server share the server-global GET_LOCK
        // namespace; the same source id in different schemas must not collide.
        $this->assertNotSame(
            SourcePollLock::buildName('site_a', 7),
            SourcePollLock::buildName('site_b', 7)
        );
    }

    public function testBuildNameDiffersPerSource(): void
    {
        $this->assertNotSame(
            SourcePollLock::buildName('site_db', 7),
            SourcePollLock::buildName('site_db', 8)
        );
    }

    public function testBuildNameHandlesAnEmptySchema(): void
    {
        // An empty DATABASE() must still yield a stable, non-empty lock name.
        $name = SourcePollLock::buildName('', 1);

        $this->assertNotSame('', $name);
        $this->assertSame($name, SourcePollLock::buildName('', 1));
    }

    public function testOnlyAnExplicitOneCountsAsAcquired(): void
    {
        // MySQL GET_LOCK returns 1 (obtained), 0 (timeout) or NULL (error).
        $this->assertTrue(SourcePollLock::acquired('1'));
        $this->assertTrue(SourcePollLock::acquired(1));
    }

    public function testTimeoutErrorAndNoiseAreNotAcquired(): void
    {
        foreach (['0', 0, null, '', ' 1', '1 ', 'x', false] as $raw) {
            $this->assertFalse(
                SourcePollLock::acquired($raw),
                'GET_LOCK result ' . var_export($raw, true) . ' must not count as acquired'
            );
        }
    }
}
