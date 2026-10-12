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
}
