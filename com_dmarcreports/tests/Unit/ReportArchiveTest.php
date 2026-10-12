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

    public function testDetectsGzipByMagicBytesDespiteWrongName(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $documents = ReportArchive::extract(gzencode(self::XML), 'mislabelled.dat');

        $this->assertCount(1, $documents);
        $this->assertStringContainsString('<feedback>', $documents[0]);
    }
}
