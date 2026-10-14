<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\MtaStsDiagnostics;
use Advans\Component\DmarcReports\Administrator\Helper\PolicyFetcherInterface;
use Advans\Component\DmarcReports\Administrator\Helper\DnsResolverInterface;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the read-only MTA-STS/TLS-RPT monitoring using injected fakes.
 *
 * @since  1.1.0
 */
class MtaStsDiagnosticsTest extends TestCase
{
    /**
     * @param   array<string, array<int, string>|null>  $records
     */
    private function resolver(array $records): DnsResolverInterface
    {
        return new class ($records) implements DnsResolverInterface {
            /** @param array<string, array<int, string>|null> $records */
            public function __construct(private array $records)
            {
            }

            public function txt(string $host): ?array
            {
                $key = strtolower($host);

                return array_key_exists($key, $this->records) ? $this->records[$key] : [];
            }
        };
    }

    private function fetcher(?string $body): PolicyFetcherInterface
    {
        return new class ($body) implements PolicyFetcherInterface {
            public function __construct(private ?string $body)
            {
            }

            public function fetch(string $url): ?string
            {
                return $this->body;
            }
        };
    }

    /**
     * @param   array<string, mixed>  $overrides
     *
     * @return  array<string, mixed>
     */
    private function generated(array $overrides = []): array
    {
        return array_merge([
            'enabled'            => true,
            'domain'             => 'example.org',
            'mode'               => 'enforce',
            'max_age'            => 604800,
            'mx'                 => ['mail.example.org'],
            'id'                 => 'abc123',
            'policy_host'        => 'mta-sts.example.org',
            'well_known_url'     => 'https://mta-sts.example.org/.well-known/mta-sts.txt',
            'sts_record_host'    => '_mta-sts.example.org',
            'sts_record'         => 'v=STSv1; id=abc123',
            'tlsrpt_record_host' => '_smtp._tls.example.org',
            'tlsrpt_record'      => 'v=TLSRPTv1; rua=mailto:tls@example.org',
        ], $overrides);
    }

    private function policyBody(string $mode, array $mx, int $maxAge = 604800): string
    {
        $lines = ['version: STSv1', 'mode: ' . $mode];

        foreach ($mx as $host) {
            $lines[] = 'mx: ' . $host;
        }

        $lines[] = 'max_age: ' . $maxAge;

        return implode("\r\n", $lines);
    }

    private function codes(array $result): array
    {
        return array_map(static fn (array $f): string => $f['code'], $result['findings']);
    }

    public function testDisabledReturnsNoFindings(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $result      = $diagnostics->inspect($this->generated(['enabled' => false]), true);

        $this->assertFalse($result['enabled']);
        $this->assertSame([], $result['findings']);
    }

    public function testMissingMxIsReported(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $result      = $diagnostics->inspect($this->generated(['mx' => [], 'id' => '']), true);

        $this->assertContains('mtasts_no_mx', $this->codes($result));
    }

    public function testNetworkOffEmitsPublishGuidance(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $result      = $diagnostics->inspect($this->generated(), false);
        $codes       = $this->codes($result);

        $this->assertContains('mtasts_publish', $codes);
        $this->assertContains('tlsrpt_publish', $codes);
    }

    public function testMissingStsRecordIsReportedAsPublish(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org' => [],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_publish', $this->codes($result));
    }

    public function testLookupErrorIsReported(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org' => null,
        ]), $this->fetcher(null));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_lookup_error', $this->codes($result));
    }

    public function testRecordIdDriftIsReported(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org' => ['v=STSv1; id=old999'],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_record_drift', $this->codes($result));
    }

    public function testPolicyUnreachableIsReported(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org' => ['v=STSv1; id=abc123'],
        ]), $this->fetcher(null));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_policy_unreachable', $this->codes($result));
    }

    public function testPolicyDriftIsReported(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'  => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($this->policyBody('testing', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_policy_drift', $this->codes($result));
    }

    public function testTestingModeIsNudgedWhenConsistent(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($this->policyBody('testing', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(['mode' => 'testing']), true);

        $this->assertContains('mtasts_mode_testing', $this->codes($result));
    }

    public function testFullyConsistentEnforceHasNoFindings(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertSame([], $result['findings']);
    }

    public function testMissingTlsRptRecordIsReported(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => [],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('tlsrpt_publish', $this->codes($result));
    }

    public function testStaleMaxAgeIsReportedAsDrift(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'], 86400)));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_policy_drift', $this->codes($result));
    }

    public function testMissingVersionIsReportedAsDrift(): void
    {
        $body = "mode: enforce\r\nmx: mail.example.org\r\nmax_age: 604800";

        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($body));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_policy_drift', $this->codes($result));
    }

    public function testTlsRptRuaMismatchIsReportedAsPublish(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:stale@elsewhere.example'],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('tlsrpt_publish', $this->codes($result));
    }

    public function testForgedTlsRptVersionIsNotAccepted(): void
    {
        // v=TLSRPTv10 must not satisfy the v=TLSRPTv1 token: the record is
        // ignored and the missing TLS-RPT record is reported as publish.
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv10; rua=mailto:tls@example.org'],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('tlsrpt_publish', $this->codes($result));
    }

    public function testForgedStsVersionIsNotAccepted(): void
    {
        // v=STSv10 must not satisfy the v=STSv1 token: no record id is parsed,
        // so the STS record counts as missing and publish guidance is emitted.
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv10; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_publish', $this->codes($result));
    }

    public function testEveryFindingIsTaggedMtaStsCategory(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org' => ['v=STSv1; id=old999'],
        ]), $this->fetcher(null));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertNotSame([], $result['findings']);

        foreach ($result['findings'] as $finding) {
            $this->assertSame('mtasts', $finding['category']);
        }
    }
}
