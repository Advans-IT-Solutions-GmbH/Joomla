<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\DmarcParser;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the RFC 7489 aggregate report parser.
 *
 * @since  1.0.0
 */
class DmarcParserTest extends TestCase
{
    private function sample(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feedback>
  <report_metadata>
    <org_name>example.net</org_name>
    <email>dmarc@example.net</email>
    <report_id>12345-abc</report_id>
    <date_range>
      <begin>1700000000</begin>
      <end>1700086400</end>
    </date_range>
  </report_metadata>
  <policy_published>
    <domain>example.org</domain>
    <adkim>s</adkim>
    <aspf>r</aspf>
    <p>none</p>
    <sp>none</sp>
    <pct>100</pct>
  </policy_published>
  <record>
    <row>
      <source_ip>192.0.2.10</source_ip>
      <count>7</count>
      <policy_evaluated>
        <disposition>none</disposition>
        <dkim>pass</dkim>
        <spf>pass</spf>
      </policy_evaluated>
    </row>
    <identifiers>
      <header_from>example.org</header_from>
    </identifiers>
  </record>
  <record>
    <row>
      <source_ip>198.51.100.5</source_ip>
      <count>3</count>
      <policy_evaluated>
        <disposition>quarantine</disposition>
        <dkim>fail</dkim>
        <spf>fail</spf>
      </policy_evaluated>
    </row>
    <identifiers>
      <header_from>example.org</header_from>
    </identifiers>
  </record>
</feedback>
XML;
    }

    public function testParsesMetadataAndPolicy(): void
    {
        $report = DmarcParser::parse($this->sample());

        $this->assertSame('example.net', $report['org_name']);
        $this->assertSame('12345-abc', $report['report_id']);
        $this->assertSame(1700000000, $report['date_begin']);
        $this->assertSame('example.org', $report['policy']['domain']);
        $this->assertSame('none', $report['policy']['p']);
        $this->assertSame('s', $report['policy']['adkim']);
        $this->assertSame('r', $report['policy']['aspf']);
        $this->assertSame(100, $report['policy']['pct']);
    }

    public function testParsesRecords(): void
    {
        $report = DmarcParser::parse($this->sample());

        $this->assertCount(2, $report['records']);

        $first = $report['records'][0];
        $this->assertSame('192.0.2.10', $first['source_ip']);
        $this->assertSame(7, $first['count']);
        $this->assertSame('none', $first['disposition']);
        $this->assertSame(1, $first['dkim_aligned']);
        $this->assertSame(1, $first['spf_aligned']);

        $second = $report['records'][1];
        $this->assertSame('quarantine', $second['disposition']);
        $this->assertSame(0, $second['dkim_aligned']);
        $this->assertSame(0, $second['spf_aligned']);
    }

    public function testMessageCountIsValidatedSumOfRecordCounts(): void
    {
        $report = DmarcParser::parse($this->sample());

        // Sample records carry counts 7 and 3; the parser carries the validated
        // total forward so the Ingestor never recomputes it with a float-prone
        // array_sum().
        $this->assertSame(10, $report['message_count']);
    }

    public function testRejectsCumulativeCountOverflow(): void
    {
        // Two individually valid counts whose sum exceeds PHP_INT_MAX would make
        // the unsigned message_count insert fail forever, so the whole report is
        // rejected up front.
        $max = (string) PHP_INT_MAX;
        $xml = str_replace('<count>7</count>', '<count>' . $max . '</count>', $this->sample());
        $xml = str_replace('<count>3</count>', '<count>' . $max . '</count>', $xml);

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testFingerprintIsStableAndDeduplicates(): void
    {
        $a = DmarcParser::parse($this->sample());
        $b = DmarcParser::parse($this->sample());

        $this->assertSame(DmarcParser::fingerprint($a), DmarcParser::fingerprint($b));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', DmarcParser::fingerprint($a));
    }

    public function testRejectsDoctypeToPreventXxe(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse('<?xml version="1.0"?><!DOCTYPE feedback [<!ENTITY x "y">]><feedback/>');
    }

    public function testRejectsNonFeedbackDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse('<?xml version="1.0"?><notfeedback/>');
    }

    public function testRejectsEmptyDocument(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse('   ');
    }

    public function testRejectsReportWithoutOrgName(): void
    {
        $xml = str_replace('<org_name>example.net</org_name>', '<org_name></org_name>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsReportWithNonPositiveBeginDate(): void
    {
        $xml = str_replace('<begin>1700000000</begin>', '<begin>0</begin>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsReportWithNonNumericBeginDate(): void
    {
        $xml = str_replace('<begin>1700000000</begin>', '<begin>1700000000junk</begin>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsReportWithImplausibleFarFutureBeginDate(): void
    {
        $xml = str_replace('<begin>1700000000</begin>', '<begin>99999999999</begin>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsOverlongOrgName(): void
    {
        $xml = str_replace('<org_name>example.net</org_name>', '<org_name>' . str_repeat('a', 256) . '</org_name>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsOverlongOrgEmail(): void
    {
        $xml = str_replace('<email>dmarc@example.net</email>', '<email>' . str_repeat('a', 256) . '</email>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsOverlongReportId(): void
    {
        $xml = str_replace('<report_id>12345-abc</report_id>', '<report_id>' . str_repeat('a', 256) . '</report_id>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsOverlongEnvelopeIdentifier(): void
    {
        $xml = str_replace(
            '<header_from>example.org</header_from>',
            '<header_from>example.org</header_from><envelope_from>' . str_repeat('a', 191) . '</envelope_from>',
            $this->sample()
        );

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsUnknownPolicyValue(): void
    {
        $xml = str_replace('<p>none</p>', '<p>bogus</p>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsMissingPolicy(): void
    {
        $xml = str_replace('<p>none</p>', '<p></p>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsNonNumericPercentage(): void
    {
        $xml = str_replace('<pct>100</pct>', '<pct>abc</pct>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsOutOfRangePercentage(): void
    {
        $xml = str_replace('<pct>100</pct>', '<pct>150</pct>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testPreservesValidPartialPercentage(): void
    {
        $xml    = str_replace('<pct>100</pct>', '<pct>25</pct>', $this->sample());
        $report = DmarcParser::parse($xml);

        $this->assertSame(25, $report['policy']['pct']);
    }

    public function testDefaultsPercentageToHundredWhenAbsent(): void
    {
        $xml    = str_replace('<pct>100</pct>', '', $this->sample());
        $report = DmarcParser::parse($xml);

        $this->assertSame(100, $report['policy']['pct']);
    }

    public function testRejectsRecordWithMalformedSourceIp(): void
    {
        $xml = str_replace('<source_ip>192.0.2.10</source_ip>', '<source_ip>not-an-ip</source_ip>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithEmptySourceIp(): void
    {
        $xml = str_replace('<source_ip>192.0.2.10</source_ip>', '<source_ip></source_ip>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithZeroCount(): void
    {
        $xml = str_replace('<count>7</count>', '<count>0</count>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithNonNumericCount(): void
    {
        $xml = str_replace('<count>7</count>', '<count>abc</count>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithNegativeCount(): void
    {
        $xml = str_replace('<count>7</count>', '<count>-5</count>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithCountBeyondIntegerRange(): void
    {
        // A digit string past PHP_INT_MAX would overflow the bound integer and
        // fail deterministically on insert, so the whole report is rejected.
        $xml = str_replace('<count>7</count>', '<count>99999999999999999999</count>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testAcceptsLargeCountWithinIntegerRange(): void
    {
        // Above the signed 32-bit limit but well within 64-bit range: valid.
        $xml    = str_replace('<count>7</count>', '<count>3000000000</count>', $this->sample());
        $report = DmarcParser::parse($xml);

        $this->assertSame(3000000000, $report['records'][0]['count']);
    }

    public function testParsesIpv6SourceAddress(): void
    {
        $xml    = str_replace('<source_ip>192.0.2.10</source_ip>', '<source_ip>2001:db8::1</source_ip>', $this->sample());
        $report = DmarcParser::parse($xml);

        $this->assertSame('2001:db8::1', $report['records'][0]['source_ip']);
    }

    public function testCanonicalizesEquivalentIpv6SourceAddress(): void
    {
        // An expanded, upper-case spelling of the same address must be stored in
        // the one canonical form so it groups with 2001:db8::1 rather than
        // creating a separate sender and a distinct Action Center key.
        $xml    = str_replace('<source_ip>192.0.2.10</source_ip>', '<source_ip>2001:0DB8:0:0:0:0:0:1</source_ip>', $this->sample());
        $report = DmarcParser::parse($xml);

        $this->assertSame('2001:db8::1', $report['records'][0]['source_ip']);
    }

    public function testRejectsInvertedDateRange(): void
    {
        $xml = str_replace('<end>1700086400</end>', '<end>1699000000</end>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsFutureEndDateBeyondClockSkew(): void
    {
        // A crafted far-future end would otherwise become the rolling-window
        // anchor and pin the displayed current policy indefinitely, so an end
        // well beyond the clock-skew allowance is rejected.
        $future = (string) (time() + 30 * 86400);
        $xml    = str_replace('<begin>1700000000</begin>', '<begin>' . (string) (time() - 86400) . '</begin>', $this->sample());
        $xml    = str_replace('<end>1700086400</end>', '<end>' . $future . '</end>', $xml);

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testAcceptsRecentEndWithinClockSkew(): void
    {
        // An end a little in the future (within the skew allowance) is tolerated
        // because reporter and local clocks are rarely perfectly aligned.
        $begin = (string) (time() - 86400);
        $end   = (string) (time() + 3600);
        $xml   = str_replace('<begin>1700000000</begin>', '<begin>' . $begin . '</begin>', $this->sample());
        $xml   = str_replace('<end>1700086400</end>', '<end>' . $end . '</end>', $xml);

        $report = DmarcParser::parse($xml);

        $this->assertSame((int) $end, $report['date_end']);
    }

    public function testRejectsPolicyDomainBeyondStorageLength(): void
    {
        // A valid-but-overlong policy domain would overflow the VARCHAR(190)
        // column and, for an auto-detected domain, make every poll fail and the
        // message retry forever, so it is rejected as a parse failure.
        $long = str_repeat('a', 191) . '.example';
        $xml  = str_replace('<domain>example.org</domain>', '<domain>' . $long . '</domain>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsPolicyDomainThatIsAUrl(): void
    {
        // A value carrying a scheme or path is not a bare domain; it must be
        // rejected rather than auto-registered as a bogus monitored domain.
        $xml = str_replace('<domain>example.org</domain>', '<domain>https://example.org/path</domain>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsPolicyDomainWithEmbeddedWhitespace(): void
    {
        $xml = str_replace('<domain>example.org</domain>', '<domain>exa mple.org</domain>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsSingleLabelPolicyDomain(): void
    {
        // A registrable domain always has at least two labels; a bare label is
        // rejected so an internal hostname cannot masquerade as a domain.
        $xml = str_replace('<domain>example.org</domain>', '<domain>localhost</domain>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testCanonicalizesPolicyDomainToLowercaseAndStripsTrailingDot(): void
    {
        $xml    = str_replace('<domain>example.org</domain>', '<domain>  Example.ORG.  </domain>', $this->sample());
        $report = DmarcParser::parse($xml);

        $this->assertSame('example.org', $report['policy']['domain']);
    }

    public function testRejectsRecordWithUnknownDisposition(): void
    {
        $xml = str_replace('<disposition>none</disposition>', '<disposition>bogus</disposition>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithMissingDisposition(): void
    {
        $xml = str_replace('<disposition>none</disposition>', '<disposition></disposition>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithUnknownDkimResult(): void
    {
        $xml = str_replace('<dkim>pass</dkim>', '<dkim>unknown</dkim>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithUnknownSpfResult(): void
    {
        $xml = str_replace('<spf>pass</spf>', '<spf>softfail</spf>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsRecordWithMissingHeaderFrom(): void
    {
        $xml = str_replace(
            "    <identifiers>\n      <header_from>example.org</header_from>\n    </identifiers>",
            "    <identifiers>\n      <header_from></header_from>\n    </identifiers>",
            $this->sample()
        );

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testRejectsUnknownAlignmentMode(): void
    {
        $xml = str_replace('<adkim>s</adkim>', '<adkim>x</adkim>', $this->sample());

        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($xml);
    }

    public function testDefaultsAlignmentToRelaxedWhenAbsent(): void
    {
        $xml    = str_replace("    <adkim>s</adkim>\n    <aspf>r</aspf>\n", '', $this->sample());
        $report = DmarcParser::parse($xml);

        $this->assertSame('r', $report['policy']['adkim']);
        $this->assertSame('r', $report['policy']['aspf']);
    }

    /**
     * Build a feedback document with the given number of identical records, used
     * to exercise the per-document record cap without materialising the full
     * production limit.
     */
    private function sampleWithRecords(int $records): string
    {
        $record = <<<'XML'
  <record>
    <row>
      <source_ip>192.0.2.10</source_ip>
      <count>1</count>
      <policy_evaluated>
        <disposition>none</disposition>
        <dkim>pass</dkim>
        <spf>pass</spf>
      </policy_evaluated>
    </row>
    <identifiers>
      <header_from>example.org</header_from>
    </identifiers>
  </record>

XML;

        $header = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feedback>
  <report_metadata>
    <org_name>example.net</org_name>
    <email>dmarc@example.net</email>
    <report_id>cap-test</report_id>
    <date_range>
      <begin>1700000000</begin>
      <end>1700086400</end>
    </date_range>
  </report_metadata>
  <policy_published>
    <domain>example.org</domain>
    <p>none</p>
  </policy_published>

XML;

        return $header . str_repeat($record, $records) . "</feedback>\n";
    }

    public function testAcceptsRecordCountUpToTheCap(): void
    {
        $report = DmarcParser::parse($this->sampleWithRecords(3), 3);

        // Exactly at the cap is accepted and every record is materialised.
        $this->assertCount(3, $report['records']);
    }

    public function testRejectsRecordCountAboveTheCap(): void
    {
        // One record past the cap is rejected outright rather than truncated.
        $this->expectException(\InvalidArgumentException::class);
        DmarcParser::parse($this->sampleWithRecords(4), 3);
    }
}
