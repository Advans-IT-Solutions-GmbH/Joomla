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

    public function testNonStringOrganizationNameIsRejected(): void
    {
        // An array value must be rejected, not cast to the literal "Array".
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample(['organization-name' => ['Reporter Inc.']]));
    }

    public function testEmptyContactInfoIsRejected(): void
    {
        // RFC 8460 requires a non-empty contact-info string.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample(['contact-info' => '   ']));
    }

    public function testMissingContactInfoIsRejected(): void
    {
        $report = json_decode($this->sample(), true, 64, JSON_THROW_ON_ERROR);
        unset($report['contact-info']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse(json_encode($report, JSON_THROW_ON_ERROR));
    }

    public function testReportWithoutPoliciesIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample(['policies' => []]));
    }

    public function testNegativeSessionCountIsRejected(): void
    {
        // A present negative count is malformed and must reject the whole
        // report rather than normalise to zero, so a corrected retransmission
        // can later repair the metrics.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'tlsa', 'policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => -5, 'total-failure-session-count' => 0],
                ],
            ],
        ]));
    }

    public function testNonNumericSessionCountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'tlsa', 'policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => 1, 'total-failure-session-count' => 'abc'],
                ],
            ],
        ]));
    }

    public function testMissingSessionCountIsRejected(): void
    {
        // RFC 8460 requires both session totals in every summary. A summary that
        // omits one is rejected rather than defaulted to zero, so a corrected
        // retransmission can repair the metrics instead of a partial import
        // being pinned under the report's permanent fingerprint.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'tlsa', 'policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => 5],
                ],
            ],
        ]));
    }

    public function testMissingFailedSessionCountIsRejected(): void
    {
        // A failure detail must carry its failed-session-count; a missing count
        // is rejected like a missing summary total.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'          => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary'         => ['total-successful-session-count' => 1, 'total-failure-session-count' => 1],
                    'failure-details' => [
                        ['result-type' => 'certificate-expired'],
                    ],
                ],
            ],
        ]));
    }

    public function testZeroSessionCountsAreAccepted(): void
    {
        // Zero is a legitimate TLS-RPT total (no sessions in the window) and
        // must be preserved, unlike a missing or malformed value.
        $report = TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'tlsa', 'policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => 0, 'total-failure-session-count' => 0],
                ],
            ],
        ]));

        $this->assertSame(0, $report['policies'][0]['success_count']);
        $this->assertSame(0, $report['policies'][0]['failure_count']);
    }

    public function testReportExceedingPolicyCapIsRejected(): void
    {
        $policies = [];

        for ($i = 0; $i <= 1000; $i++) {
            $policies[] = [
                'policy'  => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                'summary' => ['total-successful-session-count' => 1, 'total-failure-session-count' => 0],
            ];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample(['policies' => $policies]));
    }

    public function testReportExceedingFailureBudgetIsRejected(): void
    {
        $details = [];

        for ($i = 0; $i <= 100000; $i++) {
            $details[] = ['result-type' => 'certificate-expired', 'failed-session-count' => 1];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'          => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary'         => ['total-successful-session-count' => 1, 'total-failure-session-count' => 1],
                    'failure-details' => $details,
                ],
            ],
        ]));
    }

    public function testMissingPolicyTypeIsRejected(): void
    {
        // RFC 8460 makes policy-type mandatory. A policy that omits it must be
        // rejected rather than stored with an empty type, because the report's
        // content fingerprint would then permanently deduplicate a corrected
        // retransmission, leaving the empty type unrepairable.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => 10, 'total-failure-session-count' => 0],
                ],
            ],
        ]));
    }

    public function testEmptyPolicyTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => '   ', 'policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => 10, 'total-failure-session-count' => 0],
                ],
            ],
        ]));
    }

    public function testNonStringPolicyTypeIsRejected(): void
    {
        // An array value must be rejected, not cast to the literal "Array".
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => ['sts'], 'policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => 10, 'total-failure-session-count' => 0],
                ],
            ],
        ]));
    }

    public function testMissingResultTypeInFailureDetailIsRejected(): void
    {
        // RFC 8460 makes result-type mandatory in every failure detail. A detail
        // that omits it must reject the whole report rather than persist a
        // failure row with an empty result, which the report fingerprint would
        // then pin against a corrected copy.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'          => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary'         => ['total-successful-session-count' => 1, 'total-failure-session-count' => 1],
                    'failure-details' => [
                        ['failed-session-count' => 1],
                    ],
                ],
            ],
        ]));
    }

    public function testEmptyResultTypeInFailureDetailIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'          => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary'         => ['total-successful-session-count' => 1, 'total-failure-session-count' => 1],
                    'failure-details' => [
                        ['result-type' => '   ', 'failed-session-count' => 1],
                    ],
                ],
            ],
        ]));
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

    public function testPolicyWithoutValidDomainIsRejected(): void
    {
        // RFC 8460 requires every policy object to carry a valid policy-domain.
        // A mixed report with one valid and one invalid domain must be rejected
        // so the invalid policy is never stored under the valid report domain.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => 10, 'total-failure-session-count' => 0],
                ],
                [
                    'policy'  => ['policy-type' => 'tlsa', 'policy-domain' => 'https://not-a-domain'],
                    'summary' => ['total-successful-session-count' => 5, 'total-failure-session-count' => 0],
                ],
            ],
        ]));
    }

    public function testNonObjectPolicyEntryRejectsTheWholeReport(): void
    {
        // A non-object policy entry must reject the whole report rather than be
        // silently skipped: the surviving policies would otherwise be stored and
        // deduplicated under the real report id, permanently recording a partial
        // import that a corrected copy can no longer replace.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'  => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary' => ['total-successful-session-count' => 10, 'total-failure-session-count' => 0],
                ],
                'not-an-object',
            ],
        ]));
    }

    public function testNonArrayFailureDetailsRejectsTheWholeReport(): void
    {
        // A present failure-details value that is not an array (for example a
        // string or number) is malformed. It must reject the whole report
        // rather than be treated as absent: storing the summary counts without
        // the detail rows would pin a partial import under the report's
        // permanent fingerprint so a corrected copy could never replace it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(TlsRptParser::ERROR_NOT_REPORT);
        TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'          => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary'         => ['total-successful-session-count' => 1, 'total-failure-session-count' => 1],
                    'failure-details' => 'certificate-expired',
                ],
            ],
        ]));
    }

    public function testAbsentFailureDetailsIsAccepted(): void
    {
        // An absent failure-details field is legitimate (no detail rows) and
        // must still parse, unlike a present malformed value.
        $report = json_decode($this->sample(), true, 64, JSON_THROW_ON_ERROR);
        unset($report['policies'][0]['failure-details']);

        $parsed = TlsRptParser::parse(json_encode($report, JSON_THROW_ON_ERROR));

        $this->assertCount(1, $parsed['policies']);
        $this->assertSame([], $parsed['policies'][0]['failures']);
    }

    public function testNullFailureDetailsIsAccepted(): void
    {
        // An explicit null failure-details is treated the same as an absent one.
        $report = TlsRptParser::parse($this->sample([
            'policies' => [
                [
                    'policy'          => ['policy-type' => 'sts', 'policy-domain' => 'example.org'],
                    'summary'         => ['total-successful-session-count' => 1, 'total-failure-session-count' => 0],
                    'failure-details' => null,
                ],
            ],
        ]));

        $this->assertSame([], $report['policies'][0]['failures']);
    }
}
