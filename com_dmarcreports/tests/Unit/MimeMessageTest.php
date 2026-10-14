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

    public function testBoundaryMatchIsAnchoredToWholeLines(): void
    {
        if (!\function_exists('gzencode')) {
            $this->markTestSkipped('zlib extension not available');
        }

        // A part body may legitimately contain a line that merely starts with
        // the boundary token (for example "--BOUNDARY..."). Such a line is NOT
        // a real MIME delimiter, so it must not split the message and truncate
        // the following attachment. The reader anchors on full delimiter lines.
        $xml     = '<?xml version="1.0"?><feedback><report_metadata/></feedback>';
        $encoded = chunk_split(base64_encode(gzencode($xml)));

        $raw = "From: dmarc@example.net\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUND\"\r\n"
            . "\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "This is a DMARC aggregate report.\r\n"
            . "--BOUNDARY is written inside the text and is not a delimiter.\r\n"
            . "More explanatory text after the look-alike line.\r\n"
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

    public function testExtractsRawXmlAttachmentWithoutFilename(): void
    {
        // Some reporters attach the raw aggregate XML with an application/xml or
        // text/xml content type and no filename. As long as it is marked as an
        // attachment it must be treated as a report, not silently dropped.
        $xml = '<?xml version="1.0"?><feedback><report_metadata/></feedback>';

        $raw = "From: dmarc@example.net\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUND\"\r\n"
            . "\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "This is a DMARC aggregate report.\r\n"
            . "--BOUND\r\n"
            . "Content-Type: application/xml\r\n"
            . "Content-Disposition: attachment\r\n"
            . "\r\n"
            . $xml . "\r\n"
            . "--BOUND--\r\n";

        $attachments = MimeMessage::attachments($raw);

        $this->assertCount(1, $attachments);

        $documents = ReportArchive::extract($attachments[0]['content'], $attachments[0]['filename']);
        $this->assertCount(1, $documents);
        $this->assertStringContainsString('<feedback>', $documents[0]);
    }

    public function testExtractsRawTlsRptJsonAttachmentWithoutFilename(): void
    {
        // RFC 8460 TLS-RPT reports arrive as application/tlsrpt+json. Some
        // reporters attach the JSON body with no filename, relying on
        // Content-Disposition: attachment alone, exactly like the raw-XML DMARC
        // case. Such a part must be surfaced so the TLS-RPT extractor can read
        // it, not silently dropped.
        $json = '{"organization-name":"Example","report-id":"1","policies":[]}';

        $raw = "From: tlsrpt@example.net\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUND\"\r\n"
            . "\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "This is a TLS-RPT report.\r\n"
            . "--BOUND\r\n"
            . "Content-Type: application/tlsrpt+json\r\n"
            . "Content-Disposition: attachment\r\n"
            . "\r\n"
            . $json . "\r\n"
            . "--BOUND--\r\n";

        $attachments = MimeMessage::attachments($raw);

        $this->assertCount(1, $attachments);
        $this->assertSame($json, $attachments[0]['content']);
    }

    public function testIgnoresInlineTlsRptJsonWithoutAttachmentDisposition(): void
    {
        // An inline application/tlsrpt+json part without an attachment
        // disposition or filename must not be slurped as a report.
        $raw = "From: tlsrpt@example.net\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUND\"\r\n"
            . "\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "Plain summary.\r\n"
            . "--BOUND\r\n"
            . "Content-Type: application/tlsrpt+json\r\n"
            . "\r\n"
            . "{\"organization-name\":\"inline\"}\r\n"
            . "--BOUND--\r\n";

        $this->assertSame([], MimeMessage::attachments($raw));
    }

    public function testIgnoresInlineXmlWithoutAttachmentDisposition(): void
    {
        // An inline application/xml part (no attachment disposition, no filename)
        // is human-facing content such as a styled summary, not the report
        // payload, and must not be slurped as an attachment.
        $raw = "From: dmarc@example.net\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"BOUND\"\r\n"
            . "\r\n"
            . "--BOUND\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "Plain summary.\r\n"
            . "--BOUND\r\n"
            . "Content-Type: application/xml\r\n"
            . "\r\n"
            . "<html-ish>inline</html-ish>\r\n"
            . "--BOUND--\r\n";

        $this->assertSame([], MimeMessage::attachments($raw));
    }

    public function testBoundsPartFloodWithoutExhaustingMemory(): void
    {
        // A crafted message that repeats a short boundary a huge number of times
        // must not make the reader materialise an unbounded segment array. The
        // parser caps the part budget, so this returns quickly with no report
        // rather than trying to allocate millions of segments.
        $raw = "From: attacker@example.net\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: multipart/mixed; boundary=\"B\"\r\n"
            . "\r\n"
            . str_repeat("--B\r\n", 500000)
            . "--B--\r\n";

        $this->assertSame([], MimeMessage::attachments($raw));
    }

    public function testBoundsDeeplyNestedMultipart(): void
    {
        // Nesting multiparts past the depth ceiling must degrade to an empty
        // result instead of recursing without bound. Each level declares a new
        // multipart boundary that opens the next level.
        $body = "deepest\r\n";

        for ($level = 40; $level >= 1; $level--) {
            $boundary = 'B' . $level;
            $body = "Content-Type: multipart/mixed; boundary=\"$boundary\"\r\n"
                . "\r\n"
                . "--$boundary\r\n"
                . $body
                . "--$boundary--\r\n";
        }

        $raw = "From: attacker@example.net\r\nMIME-Version: 1.0\r\n" . $body;

        // No attachment is present, and the depth guard prevents unbounded
        // recursion; the call simply returns an empty set.
        $this->assertSame([], MimeMessage::attachments($raw));
    }
}
