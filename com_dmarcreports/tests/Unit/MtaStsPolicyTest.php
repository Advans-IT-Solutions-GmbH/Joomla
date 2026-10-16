<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\MtaStsPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RFC 8461 policy and DNS-record generation. Only the pure
 * static builders are exercised here; the Registry-driven generate() is covered
 * by the integration suite.
 *
 * @since  1.1.0
 */
class MtaStsPolicyTest extends TestCase
{
    public function testMxMatchingFollowsRfc8461LabelBoundaries(): void
    {
        $cases = [
            ['MAIL.EXAMPLE.ORG.', ['mail.example.org'], true],
            ['mail.example.org', ['MAIL.EXAMPLE.ORG.'], true],
            ['mail.example.org', ['*.example.org'], true],
            ['example.org', ['*.example.org'], false],
            ['mail.eu.example.org', ['*.example.org'], false],
            ['mail.eu.example.org', ['*.eu.example.org'], true],
            ['notexample.org', ['*.example.org'], false],
            ['mail.example.org.attacker.example', ['*.example.org'], false],
            ['mail.example.org', ['mail.*.org', 'm*.example.org', '*'], false],
            ['mail.example.org', ['other.example.org', '*.example.org'], true],
            ['mail.example.org', [], false],
            ['.', ['*.example.org'], false],
            ['bad_host.example.org', ['*.example.org'], false],
        ];

        foreach ($cases as [$host, $patterns, $expected]) {
            $this->assertSame($expected, MtaStsPolicy::matchesMx($host, $patterns), $host . ' / ' . implode(', ', $patterns));
        }
    }

    public function testPolicyBodyContainsVersionModeAndMx(): void
    {
        $body = MtaStsPolicy::policyBody('enforce', ['mx1.example.org', 'mx2.example.org'], 604800);

        $this->assertStringContainsString('version: STSv1', $body);
        $this->assertStringContainsString('mode: enforce', $body);
        $this->assertStringContainsString('mx: mx1.example.org', $body);
        $this->assertStringContainsString('mx: mx2.example.org', $body);
        $this->assertStringContainsString('max_age: 604800', $body);
        $this->assertStringEndsWith("\r\n", $body);
    }

    public function testPolicyBodyIsEmptyWithoutMx(): void
    {
        $this->assertSame('', MtaStsPolicy::policyBody('enforce', [], 604800));
    }

    public function testMaxAgeIsClamped(): void
    {
        $low  = MtaStsPolicy::policyBody('testing', ['mx.example.org'], 1);
        $high = MtaStsPolicy::policyBody('testing', ['mx.example.org'], 99999999);

        $this->assertStringContainsString('max_age: 86400', $low);
        $this->assertStringContainsString('max_age: 31557600', $high);
    }

    public function testPolicyIdIsContentDerivedAndStable(): void
    {
        $body = MtaStsPolicy::policyBody('enforce', ['mx.example.org'], 604800);
        $id   = MtaStsPolicy::policyId($body);

        $this->assertSame($id, MtaStsPolicy::policyId($body));
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{1,32}$/', $id);
        $this->assertSame('', MtaStsPolicy::policyId(''));
    }

    public function testPolicyIdChangesWhenPolicyChanges(): void
    {
        $a = MtaStsPolicy::policyId(MtaStsPolicy::policyBody('testing', ['mx.example.org'], 604800));
        $b = MtaStsPolicy::policyId(MtaStsPolicy::policyBody('enforce', ['mx.example.org'], 604800));

        $this->assertNotSame($a, $b);
    }

    public function testMtaStsRecordFormat(): void
    {
        $this->assertSame('v=STSv1; id=abc123', MtaStsPolicy::mtaStsRecord('abc123'));
    }

    public function testTlsRptRecordNormalisesBareAddress(): void
    {
        $this->assertSame('v=TLSRPTv1; rua=mailto:tls@example.org', MtaStsPolicy::tlsRptRecord('tls@example.org'));
    }

    public function testTlsRptRecordKeepsExplicitMailto(): void
    {
        $this->assertSame('v=TLSRPTv1; rua=mailto:tls@example.org', MtaStsPolicy::tlsRptRecord('mailto:tls@example.org'));
    }

    public function testTlsRptRecordIsEmptyWithoutAddress(): void
    {
        $this->assertSame('', MtaStsPolicy::tlsRptRecord('   '));
    }

    public function testTlsRptRecordKeepsHttpsEndpoint(): void
    {
        $this->assertSame(
            'v=TLSRPTv1; rua=https://reports.example.org/tlsrpt',
            MtaStsPolicy::tlsRptRecord('https://reports.example.org/tlsrpt')
        );
    }

    public function testTlsRptRecordDropsInvalidEndpoints(): void
    {
        $this->assertSame('', MtaStsPolicy::tlsRptRecord('not-an-address'));
        $this->assertSame('', MtaStsPolicy::tlsRptRecord('mailto:not an email'));
        $this->assertSame('', MtaStsPolicy::tlsRptRecord('http://insecure.example.org/report'));
        $this->assertSame('', MtaStsPolicy::tlsRptRecord('ftp://example.org'));
    }

    public function testTlsRptRecordKeepsOnlyValidOfMixedList(): void
    {
        $this->assertSame(
            'v=TLSRPTv1; rua=mailto:tls@example.org',
            MtaStsPolicy::tlsRptRecord('tls@example.org, garbage, mailto:also bad')
        );
    }

    public function testTlsRptRecordCombinesMultipleValidEndpoints(): void
    {
        $this->assertSame(
            'v=TLSRPTv1; rua=mailto:tls@example.org,https://reports.example.org/tlsrpt',
            MtaStsPolicy::tlsRptRecord("tls@example.org\nhttps://reports.example.org/tlsrpt")
        );
    }
}
