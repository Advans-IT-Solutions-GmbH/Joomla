<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\TlsRptParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RFC 8460 TLS-RPT JSON parser.
 *
 * @since  1.1.0
 */
class TlsRptParserTest extends TestCase
{
    private function sample(array $overrides = []): string
    {
        $report = array_merge([
            'organization-name' => 'Reporter Inc.',
            'date-range'        => [
                'start-datetime' => '2026-01-01T00:00:00Z',
                'end-datetime'   => '2026-01-01T23:59:59Z',
            ],
            'contact-info' => 'tlsrpt@reporter.example',
            'report-id'    => '2026-01-01-example.org',
            'policies'     => [
                [
                    'policy' => [
                        'policy-type'   => 'sts',
                        'policy-domain' => 'example.org',
                    ],
                    'summary' => [
                        'total-successful-session-count' => 100,
                        'total-failure-session-count'    => 3,
                    ],
                    'failure-details' => [
                        [
                            'result-type'          => 'certificate-expired',
                            'sending-mta-ip'       => '203.0.113.9',
                            'receiving-mx-hostname' => 'mx.example.org',
                            'failed-session-count' => 3,
                        ],
                    ],
                ],
            ],
        ], $overrides);

        return json_encode($report, JSON_THROW_ON_ERROR);
    }

    public function testParsesAValidReport(): void
    {
        $report = TlsRptParser::parse($this->sample());

        $this->assertSame('Reporter Inc.', $report['org_name']);
        $this->assertSame('2026-01-01-example.org', $report['report_id']);
        $this->assertGreaterThan(0, $report['date_begin']);
        $this->assertGreaterThan($report['date_begin'], $report['date_end']);
        $this->assertCount(1, $report['policies']);

        $policy = $report['policies'][0];
        $this->assertSame('example.org', $policy['policy_domain']);
        $this->assertSame(100, $policy['success_count']);
        $this->assertSame(3, $policy['failure_count']);
        $this->assertCount(1, $policy['failures']);
        $this->assertSame('certificate-expired', $policy['failures'][0]['result_type']);
        $this->assertSame(3, $policy['failures'][0]['failed_session_count']);
    }

    public function testEmptyDocumentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_EMPTY);
        TlsRptParser::parse('   ');
    }

    public function testInvalidJsonIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_JSON);
        TlsRptParser::parse('{not json');
    }

    public function testMissingReportIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample(['report-id' => '']));
    }

    public function testReportWithoutPoliciesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample(['policies' => []]));
    }

    public function testNegativeSessionCountsAreClamped(): void
    {
        $report = TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'tlsa', 'policy-domain' => 'example.org'],
                    'summary' => [
                        'total-successful-session-count' => -5,
                        'total-failure-session-count'    => 'abc',
                    ],
                ],
            ],
        ]));

        $this->assertSame(0, $report['policies'][0]['success_count']);
        $this->assertSame(0, $report['policies'][0]['failure_count']);
    }

    public function testFingerprintIsStableAndContentSensitive(): void
    {
        $a = TlsRptParser::parse($this->sample());
        $b = TlsRptParser::parse($this->sample());
        $c = TlsRptParser::parse($this->sample(['report-id' => 'different-id']));

        $this->assertSame(TlsRptParser::fingerprint($a), TlsRptParser::fingerprint($b));
        $this->assertNotSame(TlsRptParser::fingerprint($a), TlsRptParser::fingerprint($c));
    }

    public function testOverlongOrganizationNameIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample(['organization-name' => str_repeat('a', 256)]));
    }

    public function testOverlongReportIdIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample(['report-id' => str_repeat('b', 256)]));
    }

    public function testOutOfRangeSessionCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary' => [
                        'total-successful-session-count' => '99999999999999999999999999',
                        'total-failure-session-count'    => 0,
                    ],
                ],
            ],
        ]));
    }

    public function testMissingBeginDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'date-range' => [
                'start-datetime' => 'not-a-date',
                'end-datetime'   => '2026-01-01T23:59:59Z',
            ],
        ]));
    }

    public function testInvertedDateRangeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'date-range' => [
                'start-datetime' => '2026-01-02T00:00:00Z',
                'end-datetime'   => '2026-01-01T00:00:00Z',
            ],
        ]));
    }

    public function testFarFutureEndDateIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'date-range' => [
                'start-datetime' => '2099-01-01T00:00:00Z',
                'end-datetime'   => '2099-01-01T23:59:59Z',
            ],
        ]));
    }

    public function testMultiplePoliciesArePreserved(): void
    {
        $report = TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary' => [
                        'total-successful-session-count' => 100,
                        'total-failure-session-count'    => 2,
                    ],
                    'failure-details' => [
                        ['result-type' => 'certificate-expired', 'failed-session-count' => 2],
                    ],
                ],
                [
                    'policy'  => ['policy-type' => 'tlsa', 'policy-domain' => 'mail.example.org'],
                    'summary' => [
                        'total-successful-session-count' => 50,
                        'total-failure-session-count'    => 1,
                    ],
                    'failure-details' => [
                        ['result-type' => 'validation-failure', 'failed-session-count' => 1],
                    ],
                ],
            ],
        ]));

        $this->assertCount(2, $report['policies']);
        $this->assertSame('sts', $report['policies'][0]['policy_type']);
        $this->assertSame('example.org', $report['policies'][0]['policy_domain']);
        $this->assertSame('tlsa', $report['policies'][1]['policy_type']);
        $this->assertSame('mail.example.org', $report['policies'][1]['policy_domain']);
        $this->assertSame(50, $report['policies'][1]['success_count']);
        $this->assertSame('validation-failure', $report['policies'][1]['failures'][0]['result_type']);
    }
}
