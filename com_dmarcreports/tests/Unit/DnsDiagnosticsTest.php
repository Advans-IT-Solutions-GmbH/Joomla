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

    public function testSubdomainWithoutDmarcPromptsOrganizationalCheck(): void
    {
        // A subdomain may inherit the organizational domain's policy, so its
        // own missing record is reported as an informational prompt, not a hard
        // missing finding, and no record is suggested for it.
        $diagnostics = new DnsDiagnostics($this->resolver([
            'mail.example.org' => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('mail.example.org');

        $codes = $this->codes($result);
        $this->assertContains('dns_dmarc_subdomain_check', $codes);
        $this->assertNotContains('dns_dmarc_missing', $codes);
        $this->assertFalse($result['dmarc']['present']);
        $this->assertSame('', $result['suggested']);
    }

    public function testSubdomainReportsNoInheritanceClaim(): void
    {
        // The check never asserts coverage: even when a parent publishes DMARC,
        // the subdomain is only prompted to verify, never marked as covered.
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject'],
            'mail.example.org'   => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('mail.example.org');

        $this->assertContains('dns_dmarc_subdomain_check', $this->codes($result));
        $this->assertFalse($result['dmarc']['present']);
        $this->assertArrayNotHasKey('inherited', $result['dmarc']);
    }

    public function testSubdomainWithOwnDmarcIsEvaluatedDirectly(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.mail.example.org' => ['v=DMARC1; p=none'],
            'mail.example.org'        => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('mail.example.org');

        $codes = $this->codes($result);
        $this->assertNotContains('dns_dmarc_subdomain_check', $codes);
        // Its own p=none record is evaluated on its own merits.
        $this->assertContains('dns_dmarc_policy_none', $codes);
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

    public function testDuplicateDmarcRecordsAreReportedAsInvalid(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => [
                'v=DMARC1; p=reject; rua=mailto:dmarc@example.org',
                'v=DMARC1; p=none',
            ],
            'example.org'        => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('example.org');
        $codes  = $this->codes($result);

        $this->assertTrue($result['dmarc']['duplicate']);
        $this->assertContains('dns_dmarc_duplicate', $codes);
        // A broken (duplicate) record must not be read as a healthy policy.
        $this->assertNotContains('dns_dmarc_policy_none', $codes);
    }

    public function testDuplicateSpfRecordsAreReportedAsInvalid(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org'],
            'example.org'        => [
                'v=spf1 include:_spf.example.net -all',
                'v=spf1 -all',
            ],
        ]));
        $result = $diagnostics->inspect('example.org');

        $this->assertTrue($result['spf']['duplicate']);
        $this->assertContains('dns_spf_duplicate', $this->codes($result));
    }

    public function testMalformedPolicyValueIsReportedAsInvalid(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=bogus; rua=mailto:dmarc@example.org'],
            'example.org'        => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('example.org');
        $codes  = $this->codes($result);

        $this->assertFalse($result['dmarc']['valid']);
        $this->assertContains('dns_dmarc_invalid', $codes);
        // An unusable record must not be read as a healthy or policy-none record.
        $this->assertNotContains('dns_dmarc_policy_none', $codes);
    }

    public function testOutOfRangePctIsReportedAsInvalid(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org; pct=150'],
            'example.org'        => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('example.org');
        $codes  = $this->codes($result);

        $this->assertFalse($result['dmarc']['valid']);
        $this->assertContains('dns_dmarc_invalid', $codes);
    }

    public function testNonNumericPctIsReportedAsInvalid(): void
    {
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org; pct=abc'],
            'example.org'        => ['v=spf1 -all'],
        ]));

        $this->assertContains('dns_dmarc_invalid', $this->codes($diagnostics->inspect('example.org')));
    }

    public function testMisorderedPolicyTagIsReportedAsInvalid(): void
    {
        // RFC 7489 §6.3 requires v first and p immediately after it. A record
        // that defers p ("v=DMARC1; rua=...; p=reject") is rejected by conformant
        // receivers, so it must not be reported as a healthy reject policy.
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; rua=mailto:dmarc@example.org; p=reject'],
            'example.org'        => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('example.org');
        $codes  = $this->codes($result);

        $this->assertFalse($result['dmarc']['valid']);
        $this->assertContains('dns_dmarc_invalid', $codes);
    }

    public function testDuplicateTagsInOneRecordAreReportedAsInvalid(): void
    {
        // RFC 7489 §6.3 permits each tag once: "p=reject; p=none" conflicts and
        // must be rejected rather than silently read as the last value (p=none).
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject; p=none; rua=mailto:dmarc@example.org'],
            'example.org'        => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('example.org');
        $codes  = $this->codes($result);

        $this->assertFalse($result['dmarc']['valid']);
        $this->assertContains('dns_dmarc_invalid', $codes);
        // The conflicting record must not be surfaced as a healthy policy.
        $this->assertNotContains('dns_dmarc_policy_none', $codes);
    }

    public function testVersionTokenLookalikeIsNotTreatedAsDmarcRecord(): void
    {
        // "v=DMARC10" must not satisfy the "v=DMARC1" version prefix check.
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC10; p=reject'],
            'example.org'        => ['v=spf1 -all'],
        ]));
        $result = $diagnostics->inspect('example.org');

        $this->assertFalse($result['dmarc']['present']);
        $this->assertContains('dns_dmarc_missing', $this->codes($result));
    }

    public function testSpfVersionTokenLookalikeIsNotTreatedAsSpfRecord(): void
    {
        // "v=spf10" must not satisfy the "v=spf1" version prefix check.
        $diagnostics = new DnsDiagnostics($this->resolver([
            '_dmarc.example.org' => ['v=DMARC1; p=reject; rua=mailto:dmarc@example.org'],
            'example.org'        => ['v=spf10 -all'],
        ]));
        $result = $diagnostics->inspect('example.org');

        $this->assertFalse($result['spf']['present']);
        $this->assertContains('dns_spf_missing', $this->codes($result));
    }

    public function testResolverFailureIsReportedAsLookupErrorNotMissingRecords(): void    {
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
