<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\TlsReportArchive;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the TLS-RPT container extractor and its routing classifier.
 *
 * @since  1.1.0
 */
class TlsReportArchiveTest extends TestCase
{
    private const JSON = '{"organization-name":"Reporter","report-id":"1","policies":[]}';
    private const XML  = '<?xml version="1.0"?><feedback><report_metadata/></feedback>';

    public function testJsonFilenameIsTlsRpt(): void
    {
        $this->assertTrue(TlsReportArchive::looksLikeTlsRpt(self::JSON, 'report.json'));
    }

    public function testXmlFilenameIsNotTlsRpt(): void
    {
        $this->assertFalse(TlsReportArchive::looksLikeTlsRpt(self::XML, 'report.xml'));
    }

    public function testRawJsonBodyIsTlsRpt(): void
    {
        $this->assertTrue(TlsReportArchive::looksLikeTlsRpt(self::JSON, 'report'));
    }

    public function testRawXmlBodyIsNotTlsRpt(): void
    {
        $this->assertFalse(TlsReportArchive::looksLikeTlsRpt(self::XML, 'report'));
    }

    public function testGzipJsonBodyIsTlsRpt(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $this->assertTrue(TlsReportArchive::looksLikeTlsRpt(gzencode(self::JSON), 'report'));
    }

    /**
     * A highly compressible gzip member that begins with a JSON object must be
     * classified by inspecting only a bounded prefix, never by inflating the
     * whole payload. A ~16 MiB decompressed body stays well under the test
     * memory limit precisely because the probe is bounded.
     */
    public function testGzipBombIsProbedWithoutFullInflation(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $bomb = gzencode('{' . str_repeat(' ', 16 * 1024 * 1024) . '"a":1}');

        $this->assertTrue(TlsReportArchive::looksLikeTlsRpt($bomb, 'report'));
    }

    public function testCorruptGzipIsNotTlsRpt(): void
    {
        // Valid gzip magic bytes but a truncated, undecodable stream.
        $this->assertFalse(TlsReportArchive::looksLikeTlsRpt("\x1f\x8b\x08\x00corrupt", 'report'));
    }

    public function testZipWithJsonEntryIsTlsRpt(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }

        $this->assertTrue(TlsReportArchive::looksLikeTlsRpt($this->zip(['report.json' => self::JSON]), 'report.zip'));
    }

    public function testZipWithXmlEntryIsNotTlsRpt(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }

        $this->assertFalse(TlsReportArchive::looksLikeTlsRpt($this->zip(['report.xml' => self::XML]), 'report.zip'));
    }

    public function testMixedZipPrefersDmarcXml(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }

        $payload = $this->zip(['a.xml' => self::XML, 'b.json' => self::JSON]);

        $this->assertFalse(TlsReportArchive::looksLikeTlsRpt($payload, 'report.zip'));
    }

    public function testExtractsRawJson(): void
    {
        $documents = TlsReportArchive::extract(self::JSON, 'report.json');

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('organization-name', $documents[0]);
    }

    public function testExtractsGzipJson(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $documents = TlsReportArchive::extract(gzencode(self::JSON), 'report.json.gz');

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('organization-name', $documents[0]);
    }

    /**
     * @param   array<string, string>  $entries
     */
    private function zip(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_tlsrpt_test_zip_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
        $payload = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $payload;
    }
}
