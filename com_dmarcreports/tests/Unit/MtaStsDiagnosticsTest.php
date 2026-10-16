<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\MtaStsDiagnostics;
use Advans\Component\DmarcReports\Administrator\Helper\MtaStsPolicy;
use Advans\Component\DmarcReports\Administrator\Helper\ActionCenter;
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
     * @param   array<string, array<int, string>|null>  $mx
     */
    private function resolver(array $records, array $mx = ['example.org' => ['mail.example.org']]): DnsResolverInterface
    {
        return new class ($records, $mx) implements DnsResolverInterface {
            /** @param array<string, array<int, string>|null> $records */
            public function __construct(private array $records, private array $mx)
            {
            }

            public function mx(string $host): ?array
            {
                return array_key_exists($host, $this->mx) ? $this->mx[$host] : [];
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
            'tlsrpt_enabled'     => true,
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
        $result      = $diagnostics->inspect($this->generated(['enabled' => false, 'tlsrpt_enabled' => false]), true);

        $this->assertFalse($result['enabled']);
        $this->assertFalse($result['tlsrpt_enabled']);
        $this->assertSame([], $result['findings']);
    }

    public function testTlsRptEnabledWithoutMtaStsStillInspects(): void
    {
        // TLS-RPT reporting must be configurable and monitored on its own, so a
        // domain with MTA-STS off but TLS-RPT on still receives the _smtp._tls
        // publish guidance and no MTA-STS findings.
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $result      = $diagnostics->inspect(
            $this->generated(['enabled' => false, 'tlsrpt_enabled' => true]),
            false
        );
        $codes       = $this->codes($result);

        $this->assertFalse($result['enabled']);
        $this->assertTrue($result['tlsrpt_enabled']);
        $this->assertContains('tlsrpt_publish', $codes);
        $this->assertNotContains('mtasts_publish', $codes);
    }

    public function testMtaStsEnabledWithoutTlsRptEmitsNoTlsRptFinding(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $result      = $diagnostics->inspect(
            $this->generated(['enabled' => true, 'tlsrpt_enabled' => false]),
            false
        );
        $codes       = $this->codes($result);

        $this->assertContains('mtasts_publish', $codes);
        $this->assertNotContains('tlsrpt_publish', $codes);
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

    public function testUncoveredMxBlocksEnforceAdviceAndReachesActionCenter(): void
    {
        foreach (['testing' => 2, 'enforce' => 3] as $mode => $severity) {
            $diagnostics = new MtaStsDiagnostics($this->resolver([
                '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
                '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
            ], ['example.org' => ['mail.example.org', 'backup.example.net']]),
                $this->fetcher($this->policyBody($mode, ['mail.example.org'])));
            $result = $diagnostics->inspect($this->generated(['mode' => $mode]), true);

            $this->assertSame(['mtasts_mx_uncovered'], $this->codes($result));
            $this->assertSame($severity, $result['findings'][0]['severity']);
            $this->assertSame(['example.org', 'backup.example.net'], $result['findings'][0]['params']);
            $this->assertSame('mtasts', $result['findings'][0]['category']);

            $actions = ActionCenter::generate(['domain_id' => 1, 'total' => 0], [], $result['findings']);
            $matching = array_values(array_filter($actions, static fn (array $item): bool => $item['code'] === 'mtasts_mx_uncovered'));
            $this->assertCount(1, $matching);
            $this->assertSame($result['findings'][0]['params'], $matching[0]['params']);
        }
    }

    public function testMxLookupFailureAndEmptyAnswerCannotRecommendEnforce(): void
    {
        foreach (['mtasts_mx_lookup_error' => null, 'mtasts_mx_no_answer' => []] as $code => $hosts) {
            $diagnostics = new MtaStsDiagnostics($this->resolver([
                '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
                '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
            ], ['example.org' => $hosts]), $this->fetcher($this->policyBody('testing', ['mail.example.org'])));

            $result = $diagnostics->inspect($this->generated(['mode' => 'testing']), true);

            $this->assertSame([$code], $this->codes($result));
            $this->assertSame(['example.org'], $result['findings'][0]['params']);
            $this->assertSame(2, $result['findings'][0]['severity']);
        }
    }

    public function testMxCoverageIsCheckedEvenBeforeStsRecordIsPublished(): void
    {
        $diagnostics = new MtaStsDiagnostics(
            $this->resolver([], ['example.org' => ['backup.example.net']]),
            $this->fetcher(null)
        );
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_mx_uncovered', $this->codes($result));
        $this->assertContains('mtasts_publish', $this->codes($result));
    }

    public function testSharedUnionPolicyChecksEachDomainsActualMxInSummary(): void
    {
        $patterns = ['*.example.org', 'mail.example.net'];
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org' => ['v=STSv1; id=abc123'],
            '_mta-sts.example.net' => ['v=STSv1; id=abc123'],
            '_mta-sts.example.com' => ['v=STSv1; id=abc123'],
        ], [
            'example.org' => ['MAIL.EXAMPLE.ORG.', 'backup.example.org'],
            'example.net' => ['mail.example.net'],
            'example.com' => ['mail.eu.example.org'],
        ]), $this->fetcher(MtaStsPolicy::policyBody('testing', $patterns, 604800)));

        $generated = [];

        foreach (['example.org', 'example.net', 'example.com'] as $domain) {
            $generated[] = $this->generated([
                'domain'          => $domain,
                'sts_record_host' => '_mta-sts.' . $domain,
                'mode'            => 'testing',
                'mx'              => $patterns,
                'tlsrpt_enabled'  => false,
            ]);
        }

        $summary = $diagnostics->summarize(true, $generated, true);

        $this->assertCount(3, $summary['domains']);
        $this->assertSame(['mtasts_mode_testing'], $this->codes($summary['domains'][0]));
        $this->assertSame(['mtasts_mode_testing'], $this->codes($summary['domains'][1]));
        $this->assertSame(['mtasts_mx_uncovered'], $this->codes($summary['domains'][2]));
        $this->assertSame(['example.com', 'mail.eu.example.org'], $summary['domains'][2]['findings'][0]['params']);
    }

    public function testOptOutAndDisabledMtaStsNeverQueryMxOrFetchPolicy(): void
    {
        $resolver = new class implements DnsResolverInterface {
            public function txt(string $host): ?array
            {
                throw new \LogicException('Unexpected DNS query.');
            }

            public function mx(string $host): ?array
            {
                throw new \LogicException('Unexpected MX query.');
            }
        };
        $fetcher = new class implements PolicyFetcherInterface {
            public function fetch(string $url): ?string
            {
                throw new \LogicException('Unexpected HTTPS request.');
            }
        };
        $diagnostics = new MtaStsDiagnostics($resolver, $fetcher);

        $this->assertSame(
            ['mtasts_publish', 'tlsrpt_publish'],
            $this->codes($diagnostics->inspect($this->generated(), false))
        );
        $this->assertSame([], $diagnostics->inspect(
            $this->generated(['enabled' => false, 'tlsrpt_enabled' => false]),
            true
        )['findings']);
    }

    public function testTlsRptOnlyNeverChecksMxCoverage(): void
    {
        $resolver = new class implements DnsResolverInterface {
            public function txt(string $host): ?array
            {
                return ['v=TLSRPTv1; rua=mailto:tls@example.org'];
            }

            public function mx(string $host): ?array
            {
                throw new \LogicException('TLS-RPT alone must not query MX.');
            }
        };
        $diagnostics = new MtaStsDiagnostics($resolver, $this->fetcher(null));

        $this->assertSame([], $diagnostics->inspect($this->generated(['enabled' => false]), true)['findings']);
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

    public function testEnabledTlsRptWithoutReportingAddressIsReported(): void
    {
        // TLS-RPT is enabled but no (valid) reporting address is configured, so
        // the generated record is empty: there is nothing to publish and reports
        // would silently never arrive. A dedicated missing-rua finding tells the
        // administrator to set an address rather than staying silent.
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $resultOffline = $diagnostics->inspect($this->generated(['tlsrpt_record' => '']), false);
        $resultOnline  = $diagnostics->inspect($this->generated(['tlsrpt_record' => '']), true);

        $this->assertContains('tlsrpt_missing_rua', $this->codes($resultOffline));
        $this->assertContains('tlsrpt_missing_rua', $this->codes($resultOnline));
        $this->assertNotContains('tlsrpt_publish', $this->codes($resultOffline));
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

    public function testMultipleStsRecordsAreReportedAsPublish(): void
    {
        // RFC 8461 allows exactly one _mta-sts record; two valid records are an
        // invalid configuration, so no trusted id is derived and publish
        // guidance is emitted rather than trusting the first.
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123', 'v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_publish', $this->codes($result));
    }

    public function testMultipleTlsRptRecordsAreReportedAsPublish(): void
    {
        // RFC 8460 allows exactly one _smtp._tls record; a duplicate makes the
        // configuration invalid even when both carry the expected rua.
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => [
                'v=TLSRPTv1; rua=mailto:tls@example.org',
                'v=TLSRPTv1; rua=mailto:tls@example.org',
            ],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('tlsrpt_publish', $this->codes($result));
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

    public function testDuplicateSingletonPolicyFieldIsReportedAsDrift(): void
    {
        // RFC 8461 allows each of version/mode/max_age exactly once. A policy
        // that repeats mode — even when the last occurrence matches the expected
        // enforce value — is malformed and must be reported as drift rather than
        // silently accepted by keeping the last line.
        $body = "version: STSv1\r\nmode: testing\r\nmode: enforce\r\nmx: mail.example.org\r\nmax_age: 604800";

        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($body));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_policy_drift', $this->codes($result));
    }

    public function testDuplicateMaxAgeIsReportedAsDrift(): void
    {
        // A repeated max_age is malformed even when the last value is correct.
        $body = "version: STSv1\r\nmode: enforce\r\nmx: mail.example.org\r\nmax_age: 86400\r\nmax_age: 604800";

        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($body));
        $result = $diagnostics->inspect($this->generated(), true);

        $this->assertContains('mtasts_policy_drift', $this->codes($result));
    }

    public function testSummarizeDisabledReturnsNoDomains(): void
    {
        // When neither switch is on the System Check model passes enabled=false
        // and an empty generated list; the roll-up must report the feature as
        // off and surface no per-domain rows.
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $summary     = $diagnostics->summarize(false, [], true);

        $this->assertFalse($summary['enabled']);
        $this->assertTrue($summary['network']);
        $this->assertSame([], $summary['domains']);
    }

    public function testSummarizeSkipsDomainsWithNothingToPublish(): void
    {
        // A domain whose generated artifacts have both switches off (e.g. no MX,
        // reporting disabled) contributes no monitoring rows even though the
        // feature is globally enabled.
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $summary     = $diagnostics->summarize(true, [
            $this->generated(['enabled' => false, 'tlsrpt_enabled' => false]),
        ], false);

        $this->assertTrue($summary['enabled']);
        $this->assertSame([], $summary['domains']);
    }

    public function testSummarizeReportsPerDomainFindingsOffline(): void
    {
        // Enabled feature, network off: every monitored domain appears with the
        // "publish these records" guidance and no live lookup is attempted.
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $summary     = $diagnostics->summarize(true, [
            $this->generated(['domain' => 'a.example']),
            $this->generated(['domain' => 'b.example']),
        ], false);

        $this->assertTrue($summary['enabled']);
        $this->assertFalse($summary['network']);
        $this->assertCount(2, $summary['domains']);
        $this->assertSame('a.example', $summary['domains'][0]['domain']);
        $this->assertSame('b.example', $summary['domains'][1]['domain']);

        $codes = array_map(static fn (array $f): string => $f['code'], $summary['domains'][0]['findings']);
        $this->assertContains('mtasts_publish', $codes);
        $this->assertContains('tlsrpt_publish', $codes);
    }

    public function testSummarizeConsistentDomainHasEmptyFindings(): void
    {
        // A fully consistent enforce deployment yields an empty findings list,
        // which the System Check view renders as a green "consistent" badge.
        $diagnostics = new MtaStsDiagnostics($this->resolver([
            '_mta-sts.example.org'   => ['v=STSv1; id=abc123'],
            '_smtp._tls.example.org' => ['v=TLSRPTv1; rua=mailto:tls@example.org'],
        ]), $this->fetcher($this->policyBody('enforce', ['mail.example.org'])));
        $summary = $diagnostics->summarize(true, [$this->generated()], true);

        $this->assertTrue($summary['network']);
        $this->assertCount(1, $summary['domains']);
        $this->assertSame([], $summary['domains'][0]['findings']);
    }

    public function testSummarizeSkipsEmptyDomainName(): void
    {
        $diagnostics = new MtaStsDiagnostics($this->resolver([]), $this->fetcher(null));
        $summary     = $diagnostics->summarize(true, [
            $this->generated(['domain' => '']),
        ], false);

        $this->assertSame([], $summary['domains']);
    }
}
