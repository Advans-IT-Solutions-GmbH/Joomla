<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\DnsDiagnostics;
use Advans\Component\DmarcReports\Administrator\Helper\DnsResolverInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the read-only DNS diagnostics using an injected fake resolver.
 *
 * @since  1.0.0
 */
class DnsDiagnosticsTest extends TestCase
{
    /**
     * @param   array<string, array<int, string>>  $records
     */
    private function resolver(array $records): DnsResolverInterface
    {
        return new class ($records) implements DnsResolverInterface {
            /** @param array<string, array<int, string>> $records */
            public function __construct(private array $records)
            {
            }

            public function txt(string $host): array
            {
                return $this->records[strtolower($host)] ?? [];
            }
        };
    }

    private function codes(array $result): array
    {
        return array_map(static fn (array $f): string => $f['code'], $result['findings']);
    }

    public function testMissingDmarcIsReportedWithSuggestion(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([]));
        $result      = $diagnostics->inspect('example.org');

        $this->assertFalse($result['dmarc']['present']);
        $this->assertContains('dns_dmarc_missing', $this->codes($result));
        $this->assertStringContainsString('v=DMARC1', $result['suggested']);
        $this->assertStringContainsString('example.org', $result['suggested']);
    }

    public function testPolicyNoneAndMissingRuaAreFlagged(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=none'],
            'example.org'        => ['v=spf1 include:_spf.example.net -all'],
        ]));
        $result = $diagnostics->inspect('example.org');

        $codes = $this->codes($result);
        $this->assertContains('dns_dmarc_policy_none', $codes);
        $this->assertContains('dns_dmarc_no_rua', $codes);
        $this->assertSame('', $result['suggested']);
        $this->assertTrue($result['spf']['present']);
    }

    public function testHealthyConfigurationHasNoFindings(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org; pct=100'],
            'example.org'        => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('example.org');

        $this->assertSame([], $result['findings']);
    }

    public function testPartialPctIsFlagged(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org; pct=50'],
            'example.org'        => ['v=spf1 -all'],
        ]));

        $this->assertContains('dns_dmarc_pct', $this->codes($diagnostics->inspect('example.org')));
    }

    public function testMissingSpfIsFlagged(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org'],
        ]));

        $this->assertContains('dns_spf_missing', $this->codes($diagnostics->inspect('example.org')));
    }

    public function testResolverFailureIsReportedAsLookupErrorNotMissingRecords(): void
    {
        $resolver = new class implements DnsResolverInterface {
            public function txt(string $host): ?array
            {
                return null;
            }
        };

        $diagnostics = new DnsDiagnostics($resolver);
        $result      = $diagnostics->inspect('example.org');
        $codes       = $this->codes($result);

        $this->assertContains('dns_lookup_error', $codes);
        $this->assertNotContains('dns_dmarc_missing', $codes);
        $this->assertNotContains('dns_spf_missing', $codes);
        $this->assertTrue($result['dmarc']['error']);
        $this->assertTrue($result['spf']['error']);
        // A failed lookup must not imply a missing record, so no record is suggested.
        $this->assertSame('', $result['suggested']);
        // Both lookups failed, but the error is reported once for the domain.
        $this->assertSame(1, \count(array_filter($codes, static fn (string $c): bool => $c === 'dns_lookup_error')));
    }
}
