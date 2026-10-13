<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\ReportArchive;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the attachment container extractor (raw XML, gzip, zip).
 *
 * @since  1.0.0
 */
class ReportArchiveTest extends TestCase
{
    private const XML = '<?xml version="1.0"?><feedback><report_metadata/></feedback>';

    public function testExtractsRawXml(): void
    {
        $documents = ReportArchive::extract(self::XML, 'report.xml');

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('<feedback>', $documents[0]);
    }

    public function testExtractsGzip(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $documents = ReportArchive::extract(gzencode(self::XML), 'report.xml.gz');

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('<feedback>', $documents[0]);
    }

    public function testExtractsZip(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_test_zip_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('report.xml', self::XML);
        $zip->close();
        $payload = file_get_contents($tmp);
        @unlink($tmp);

        $documents = ReportArchive::extract($payload, 'report.zip');

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('<feedback>', $documents[0]);
    }

    public function testStripsByteOrderMark(): void
    {
        $documents = ReportArchive::extract("\xEF\xBB\xBF" . self::XML, 'report.xml');

        $this->assertStringStartsWith('<?xml', $documents[0]);
    }

    public function testUnknownPayloadYieldsNothing(): void
    {
        $this->assertSame([], ReportArchive::extract('just some text', 'note.txt'));
        $this->assertSame([], ReportArchive::extract('', 'empty'));
    }

    public function testOversizedRawXmlIsRejected(): void
    {
        // A raw document larger than the decompressed-size ceiling must be
        // refused outright rather than buffered, to bound memory on a hostile
        // attachment. The payload below is comfortably over the 8 MiB ceiling.
        $payload = '<?xml version="1.0"?><feedback>' . str_repeat('A', 9437184) . '</feedback>';

        $this->assertSame([], ReportArchive::extract($payload, 'huge.xml'));
    }

    public function testOversizedGzipIsRejected(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        // Decompresses to just over the ceiling; decompression itself is
        // bounded, so the payload must be refused rather than fully expanded.
        $payload = gzencode('<?xml version="1.0"?><feedback>' . str_repeat('A', 9437184) . '</feedback>');

        $this->assertSame([], ReportArchive::extract($payload, 'bomb.xml.gz'));
    }

    public function testZipDocumentCountIsCapped(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_test_zip_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        // Pack more XML entries than the per-archive document cap (256).
        for ($i = 0; $i < 300; $i++) {
            $zip->addFromString('report-' . $i . '.xml', self::XML);
        }

        $zip->close();
        $payload = file_get_contents($tmp);
        @unlink($tmp);

        $documents = ReportArchive::extract($payload, 'many.zip');

        $this->assertLessThanOrEqual(256, \count($documents));
    }

    public function testZipWithTooManyEntriesIsRejected(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_test_zip_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        // Pack more entries than the per-archive entry cap (4096) with tiny
        // non-XML files, so the archive stays within the byte and document
        // limits but would force an unbounded number of statIndex() calls.
        for ($i = 0; $i < 4100; $i++) {
            $zip->addFromString('noise-' . $i . '.txt', 'x');
        }

        $zip->close();
        $payload = file_get_contents($tmp);
        @unlink($tmp);

        $this->assertSame([], ReportArchive::extract($payload, 'flood.zip'));
    }

    public function testDetectsGzipByMagicBytesDespiteWrongName(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $documents = ReportArchive::extract(gzencode(self::XML), 'mislabelled.dat');

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('<feedback>', $documents[0]);
    }

    public function testDetectsZipByMagicBytesDespiteGzExtension(): void
    {
        if (!class_exists(\ZipArchive::class)) {
            $this->markTestSkipped('zip extension not available');
        }

        // A real ZIP archive carrying a ".gz" filename must be decoded as a ZIP
        // (by its magic bytes) rather than being handed to the gzip branch and
        // silently discarded.
        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_test_zip_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $zip->addFromString('report.xml', self::XML);
        $zip->close();
        $payload = file_get_contents($tmp);
        @unlink($tmp);

        $documents = ReportArchive::extract($payload, 'report.gz');

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('<feedback>', $documents[0]);
    }

    public function testMalformedGzipYieldsNothingWhenDecoderAvailable(): void
    {
        if (!\function_exists('gzdecode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        // Recognised as gzip by its magic bytes but not valid gzip data. With
        // the decoder present this is a malformed payload, which must yield an
        // empty result rather than the decoder-unavailable exception: the two
        // outcomes are deliberately distinct so a caller only defers the latter.
        $this->assertSame([], ReportArchive::extract("\x1f\x8bnot really gzip data", 'broken.xml.gz'));
    }
}
