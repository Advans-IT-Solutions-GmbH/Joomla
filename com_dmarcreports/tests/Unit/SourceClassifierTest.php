<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\ActionStatus;
use Advans\Component\DmarcReports\Administrator\Helper\SourceClassifier;
use Advans\Component\DmarcReports\Administrator\Helper\SystemReverseDnsResolver;
use PHPUnit\Framework\TestCase;

/**
 * How the software judges a sending source by itself: only aligned DKIM confirms
 * it, SPF alone never does (shared gateways of a provider pass SPF for every
 * customer), and failing mail the policy blocked needs nothing.
 *
 * @since  1.4.0
 */
class SourceClassifierTest extends TestCase
{
    /**
     * @return array<string, array{array<string, int>, string}>
     */
    public static function cases(): array
    {
        return [
            'DKIM aligned (also SPF aligned)'        => [['dkim' => 12], SourceClassifier::DKIM],
            'SPF aligned only'                        => [['spf_only' => 4], SourceClassifier::SPF_ONLY],
            'failing, rejected or quarantined'        => [['fail' => 9, 'fail_delivered' => 0], SourceClassifier::REJECTED],
            'failing, delivered (p=none)'             => [['fail' => 9, 'fail_delivered' => 9], SourceClassifier::UNAUTHENTICATED],
            'mixed DKIM and SPF only'                 => [['dkim' => 50, 'spf_only' => 1], SourceClassifier::SPF_ONLY],
            'mixed DKIM and rejected'                 => [['dkim' => 5, 'fail' => 2], SourceClassifier::DKIM],
            'mixed DKIM and delivered failing'        => [['dkim' => 5, 'fail' => 2, 'fail_delivered' => 1], SourceClassifier::UNAUTHENTICATED],
            'mixed SPF only and delivered failing'    => [['spf_only' => 1, 'fail' => 2, 'fail_delivered' => 2], SourceClassifier::SPF_ONLY],
            'delivered never exceeds failing'         => [['dkim' => 3, 'fail' => 0, 'fail_delivered' => 4], SourceClassifier::DKIM],
            'no mail'                                 => [[], ''],
        ];
    }

    /**
     * @dataProvider cases
     *
     * @param   array<string, int>  $counts
     */
    public function testClassify(array $counts, string $expected): void
    {
        $this->assertSame($expected, SourceClassifier::classify($counts));
    }

    public function testCurrentWindowFiguresWinWhenTheSourceSentInTheWindow(): void
    {
        // SPF-only mail long ago, DKIM-signed mail now: judged on the current mail.
        $row = [
            'total' => 30, 'dkim' => 20, 'spf_only' => 10, 'fail' => 0, 'fail_delivered' => 0,
            'w_total' => 20, 'w_dkim' => 20, 'w_spf_only' => 0, 'w_fail' => 0, 'w_fail_delivered' => 0,
        ];

        $this->assertSame(SourceClassifier::DKIM, SourceClassifier::classify(SourceClassifier::current($row)));
    }

    public function testLifetimeFiguresApplyWhenTheSourceIsAbsentFromTheWindow(): void
    {
        $row = ['total' => 10, 'dkim' => 0, 'spf_only' => 10, 'fail' => 0, 'fail_delivered' => 0, 'w_total' => 0];

        $this->assertSame(SourceClassifier::SPF_ONLY, SourceClassifier::classify(SourceClassifier::current($row)));
    }

    public function testAnchorsAreValidIdsAndDistinctForIpv4AndIpv6(): void
    {
        $this->assertSame('source-192-0-2-61', SourceClassifier::anchor('192.0.2.61'));
        $this->assertSame('source-2001_db8__1', SourceClassifier::anchor('2001:DB8::1'));
        // Only hex digits, dots and colons survive, so no markup reaches the id.
        $this->assertMatchesRegularExpression('/^source-[0-9a-f_-]+$/', SourceClassifier::anchor('1.2.3.4"><script>'));
        $this->assertNotSame(SourceClassifier::anchor('::1.2.3.4'), SourceClassifier::anchor('1.2.3.4'));
    }

    public function testBadgeColours(): void
    {
        $this->assertSame('success', SourceClassifier::badgeClass(SourceClassifier::DKIM));
        $this->assertSame('danger', SourceClassifier::badgeClass(SourceClassifier::SPF_ONLY));
        $this->assertSame('warning', SourceClassifier::badgeClass(SourceClassifier::UNAUTHENTICATED));
        $this->assertSame('secondary', SourceClassifier::badgeClass(SourceClassifier::REJECTED));
    }

    public function testStoredActionKeysNameTheirSource(): void
    {
        $this->assertSame('192.0.2.61', ActionStatus::sourceOf('new_source:4:192.0.2.61', 4));
        $this->assertSame('2001:db8::1', ActionStatus::sourceOf('new_source:4:2001:db8::1', 4));
        // A key of another domain, a key without an address and garbage are ignored.
        $this->assertSame('', ActionStatus::sourceOf('new_source:5:192.0.2.61', 4));
        $this->assertSame('', ActionStatus::sourceOf('no_data:4', 4));
        $this->assertSame('', ActionStatus::sourceOf('new_source:4:not-an-ip', 4));
    }

    public function testReverseNames(): void
    {
        $this->assertSame('61.2.0.192.in-addr.arpa', SystemReverseDnsResolver::reverseName('192.0.2.61'));
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa',
            SystemReverseDnsResolver::reverseName('2001:db8::1')
        );
        $this->assertNull(SystemReverseDnsResolver::reverseName('not-an-ip'));
    }
}
