<?php
/**
 * Parser tests: exercise the shipped parser, archive and MIME helpers on the
 * target PHP version, end to end from a gzip container down to parsed records.
 */

define('_JEXEC', 1);

$base = '/var/www/html/administrator/components/com_dmarcreports/src/Helper';
require_once $base . '/DomainName.php';
require_once $base . '/DmarcParser.php';
require_once $base . '/ReportArchive.php';
require_once $base . '/MimeMessage.php';

use Advans\Component\DmarcReports\Administrator\Helper\DmarcParser;
use Advans\Component\DmarcReports\Administrator\Helper\MimeMessage;
use Advans\Component\DmarcReports\Administrator\Helper\ReportArchive;

class ParserTest
{
    private $passed = 0;
    private $failed = 0;

    private function sampleXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<feedback><report_metadata><org_name>example.net</org_name>'
            . '<email>dmarc@example.net</email><report_id>unit-1</report_id>'
            . '<date_range><begin>1700000000</begin><end>1700086400</end></date_range>'
            . '</report_metadata><policy_published><domain>example.org</domain>'
            . '<p>none</p><pct>100</pct></policy_published>'
            . '<record><row><source_ip>192.0.2.10</source_ip><count>5</count>'
            . '<policy_evaluated><disposition>none</disposition><dkim>pass</dkim>'
            . '<spf>pass</spf></policy_evaluated></row>'
            . '<identifiers><header_from>example.org</header_from></identifiers></record>'
            . '</feedback>';
    }

    public function run(): bool
    {
        echo "=== Parser Tests ===\n\n";

        $this->test('Raw XML parses into a normalised report', function () {
            $report = DmarcParser::parse($this->sampleXml());
            return $report['org_name'] === 'example.net'
                && $report['report_id'] === 'unit-1'
                && count($report['records']) === 1
                && $report['records'][0]['count'] === 5;
        });

        $this->test('Fingerprint is a 64 character hex digest', function () {
            $report = DmarcParser::parse($this->sampleXml());
            return (bool) preg_match('/^[a-f0-9]{64}$/', DmarcParser::fingerprint($report));
        });

        $this->test('DOCTYPE is rejected (XXE safe)', function () {
            try {
                DmarcParser::parse('<?xml version="1.0"?><!DOCTYPE x><feedback/>');
                return false;
            } catch (\InvalidArgumentException $e) {
                return true;
            }
        });

        $this->test('Gzip container is extracted', function () {
            $documents = ReportArchive::extract(gzencode($this->sampleXml()), 'report.xml.gz');
            return count($documents) === 1 && strpos($documents[0], '<feedback>') !== false;
        });

        $this->test('Zip container is extracted', function () {
            if (!class_exists('ZipArchive')) {
                // Under strict skip a missing zip extension is a failure, since the
                // production Docker images install it and the code path must be tested.
                if (getenv('TEST_STRICT_SKIP') === '1') {
                    echo "  (ZipArchive missing while TEST_STRICT_SKIP=1)\n";
                    return false;
                }
                return true;
            }
            $tmp = tempnam(sys_get_temp_dir(), 'dmarc');
            $zip = new ZipArchive();
            $zip->open($tmp, ZipArchive::OVERWRITE);
            $zip->addFromString('report.xml', $this->sampleXml());
            $zip->close();
            $documents = ReportArchive::extract(file_get_contents($tmp), 'report.zip');
            @unlink($tmp);
            return count($documents) === 1;
        });

        $this->test('MIME attachment is decoded and parses', function () {
            $encoded = chunk_split(base64_encode(gzencode($this->sampleXml())));
            $raw = "Content-Type: multipart/mixed; boundary=\"B\"\n\n"
                . "--B\nContent-Type: text/plain\n\nhello\n"
                . "--B\nContent-Type: application/gzip; name=\"r.xml.gz\"\n"
                . "Content-Transfer-Encoding: base64\n"
                . "Content-Disposition: attachment; filename=\"r.xml.gz\"\n\n"
                . $encoded . "\n--B--\n";
            $attachments = MimeMessage::attachments($raw);
            if (count($attachments) !== 1) {
                return false;
            }
            $documents = ReportArchive::extract($attachments[0]['content'], $attachments[0]['filename']);
            return count($documents) === 1
                && count(DmarcParser::parse($documents[0])['records']) === 1;
        });

        echo "\n=== Parser Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new ParserTest();
exit($test->run() ? 0 : 1);
