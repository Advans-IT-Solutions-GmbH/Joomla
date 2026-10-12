<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\MimeMessage;
use Advans\Component\DmarcReports\Administrator\Helper\ReportArchive;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the minimal MIME attachment reader.
 *
 * @since  1.0.0
 */
class MimeMessageTest extends TestCase
{
    public function testExtractsBase64GzipAttachment(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        $xml     = '<?xml version="1.0"?><feedback><report_metadata/></feedback>';
        $encoded = chunk_split(base64_encode(gzencode($xml)));

        $raw = "From: dmarc@example.net\r\n"
            . "Subject: Report Domain: example.org\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUND\"\r\n"
            . "\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "This is a DMARC aggregate report.\r\n"
            . "--BOUND\r\n"
            . "Content-Type: application/gzip; name=\"example.org!1700000000.xml.gz\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"example.org!1700000000.xml.gz\"\r\n"
            . "\r\n"
            . $encoded . "\r\n"
            . "--BOUND--\r\n";

        $attachments = MimeMessage::attachments($raw);

        $this->assertCount(1, $attachments);
        $this->assertStringEndsWith('.xml.gz', $attachments[0]['filename']);

        // The decoded attachment must be a usable gzip the archive layer reads.
        $documents = ReportArchive::extract($attachments[0]['content'], $attachments[0]['filename']);
        $this->assertCount(1, $documents);
        $this->assertStringContainsString('<feedback>', $documents[0]);
    }

    public function testIgnoresMessageWithoutAttachments(): void
    {
        $raw = "From: someone@example.net\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "Just a plain message, nothing to extract.\r\n";

        $this->assertSame([], MimeMessage::attachments($raw));
    }

    public function testExtractsAttachmentWithQuotedBoundaryContainingSpaces(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        // A quoted boundary may legally contain spaces; the parser must not
        // truncate it at the first space, which would drop the whole report.
        $xml     = '<?xml version="1.0"?><feedback><report_metadata/></feedback>';
        $encoded = chunk_split(base64_encode(gzencode($xml)));

        $raw = "From: dmarc@example.net\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"report part\"\r\n"
            . "\r\n"
            . "--report part\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "This is a DMARC aggregate report.\r\n"
            . "--report part\r\n"
            . "Content-Type: application/gzip; name=\"example.org!1700000000.xml.gz\"\r\n"
            . "Content-Transfer-Encoding: base64\r\n"
            . "Content-Disposition: attachment; filename=\"example.org!1700000000.xml.gz\"\r\n"
            . "\r\n"
            . $encoded . "\r\n"
            . "--report part--\r\n";

        $attachments = MimeMessage::attachments($raw);

        $this->assertCount(1, $attachments);
        $this->assertStringEndsWith('.xml.gz', $attachments[0]['filename']);
    }
}
