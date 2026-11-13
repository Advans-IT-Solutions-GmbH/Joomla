<?php
/**
 * Ingestion-limit tests against the installed component and a real database.
 *
 * The public reporting address lets anybody inject mail. These tests drive the
 * shipped Ingestor (and, for the run continuation, the shipped ImapClient over a
 * scripted in-memory IMAP server) with crafted messages and assert:
 *
 *   - a message under the limits is stored as before,
 *   - a message at the attachment limit is stored, one over it is held,
 *   - a message with many report parts, an archive with too many reports, a
 *     gzip bomb, a zip bomb and a message over the unpacked size are held:
 *     nothing of them is stored and the log names only UID, limit and counts,
 *   - a run that reaches its report limit postpones the next message, and the
 *     next run imports it, both directly and through the IMAP client.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

// Load the installed component classes directly from the site, so the suite
// exercises exactly the files the package installed.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Advans\\Component\\DmarcReports\\Administrator\\';

    if (strncmp($class, $prefix, \strlen($prefix)) !== 0) {
        return;
    }

    $file = JPATH_ADMINISTRATOR . '/components/com_dmarcreports/src/'
        . str_replace('\\', '/', substr($class, \strlen($prefix))) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

use Advans\Component\DmarcReports\Administrator\Helper\ImapClient;
use Advans\Component\DmarcReports\Administrator\Helper\IngestLimits;
use Advans\Component\DmarcReports\Administrator\Helper\Ingestor;
use Joomla\CMS\Factory;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Log\LogEntry;
use Joomla\Database\DatabaseInterface;

class IngestLimitsIntegrationTest
{
    private const SENDER = 'reporter-limits@example.net';
    private const MIB    = 1048576;

    private DatabaseInterface $db;
    private int $passed = 0;
    private int $failed = 0;
    private int $domainId = 0;
    private string $domain;
    private string $suffix;

    /** @var string[] */
    private array $logged = [];

    public function __construct()
    {
        $this->db     = Factory::getContainer()->get(DatabaseInterface::class);
        $this->suffix = substr(hash('sha256', (string) getmypid() . microtime()), 0, 10);
        $this->domain = 'limits-' . $this->suffix . '.example';

        // Capture the component's log entries in memory.
        Log::addLogger(
            [
                'logger'   => 'callback',
                'callback' => function (LogEntry $entry): void {
                    $this->logged[] = (string) $entry->message;
                },
            ],
            Log::ALL,
            ['com_dmarcreports']
        );
    }

    public function run(): bool
    {
        echo "=== Ingestion Limit Tests ===\n\n";

        try {
            $this->domainId = $this->seedDomain();
            $this->test('test domain seeded', $this->domainId > 0);

            $this->testUnderTheLimits();
            $this->testAttachmentLimit();
            $this->testPartCeiling();
            $this->testReportsPerArchive();
            $this->testGzipBomb();
            $this->testConsecutiveBombsStopAtTheRunBudget();
            $this->testZipBomb();
            $this->testUnpackedSizePerMessage();
            $this->testRunLimitAndContinuation();
            $this->testRunContinuationThroughImap();
            $this->testLogHoldsNoContent();
        } catch (\Throwable $e) {
            $this->test('suite ran without an exception', false, \get_class($e) . ': ' . $e->getMessage());
        } finally {
            $this->cleanup();
        }

        echo "\n=== Ingestion Limit Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    private function testUnderTheLimits(): void
    {
        echo "--- Under the limits ---\n";

        $ingestor = $this->ingestor(IngestLimits::defaults());
        $summary  = $ingestor->newSummary();
        $id       = 'under-' . $this->suffix;
        $result   = $ingestor->ingestMessage(['uid' => 11, 'raw' => $this->message([$this->gz($id)])], null, $this->domainId, $summary);

        $this->test('message with one report is stored', $result === Ingestor::RESULT_NEW && $this->stored($id) === 1, $result);
        $this->test('nothing is held or postponed', $summary['held'] === 0 && $summary['postponed'] === 0);
    }

    private function testAttachmentLimit(): void
    {
        echo "\n--- Report attachments per message ---\n";

        $limits = IngestLimits::defaults();

        $at = [];

        for ($i = 0; $i < $limits->maxAttachments(); $i++) {
            $at[] = $this->gz('at-' . $i . '-' . $this->suffix);
        }

        $ingestor = $this->ingestor($limits);
        $summary  = $ingestor->newSummary();
        $result   = $ingestor->ingestMessage(['uid' => 21, 'raw' => $this->message($at)], null, $this->domainId, $summary);

        $this->test('message at the attachment limit is stored', $result === Ingestor::RESULT_NEW && $this->storedLike('at-%-' . $this->suffix) === $limits->maxAttachments(), $result);

        // Many parts: one more report attachment than allowed, plus filler
        // parts that are not reports.
        $over = [];

        for ($i = 0; $i <= $limits->maxAttachments(); $i++) {
            $over[] = $this->gz('over-' . $i . '-' . $this->suffix);
        }

        $ingestor = $this->ingestor(IngestLimits::defaults());
        $summary  = $ingestor->newSummary();
        $result   = $ingestor->ingestMessage(['uid' => 22, 'raw' => $this->message($over, 200)], null, $this->domainId, $summary);

        $this->test('message with many report parts is held', $result === Ingestor::RESULT_HELD, $result);
        $this->test('nothing of the held message is stored', $this->storedLike('over-%-' . $this->suffix) === 0);
        $this->test('held message is counted with its UID', $summary['held'] === 1 && $summary['held_uids'] === [22]);
        $this->test('log names UID and limit', $this->logContains('UID 22 held') && $this->logContains('limit: attachments'));
    }

    private function testPartCeiling(): void
    {
        echo "\n--- MIME part ceiling ---\n";

        // 1100 filler parts push the report past the MIME reader's ceiling of
        // 1000 parts. The message must be held, not treated as report-free and
        // marked read.
        $ingestor = $this->ingestor(IngestLimits::defaults());
        $summary  = $ingestor->newSummary();
        $result   = $ingestor->ingestMessage(['uid' => 25, 'raw' => $this->message([$this->gz('parts-' . $this->suffix)], 1100)], null, $this->domainId, $summary);

        $this->test('message cut by the part ceiling is held', $result === Ingestor::RESULT_HELD, $result);
        $this->test('nothing of it is stored', $this->stored('parts-' . $this->suffix) === 0);
        $this->test('log names the parts limit', $this->logContains('UID 25 held') && $this->logContains('limit: parts'));
    }

    private function testReportsPerArchive(): void
    {
        echo "\n--- Reports per message (archive) ---\n";

        if (!class_exists(\ZipArchive::class)) {
            $this->test('ZipArchive available', getenv('TEST_STRICT_SKIP') !== '1');

            return;
        }

        $limits  = IngestLimits::defaults();
        $entries = [];

        for ($i = 0; $i <= $limits->maxMessageReports(); $i++) {
            $entries['r' . $i . '.xml'] = $this->xml('zip-' . $i . '-' . $this->suffix);
        }

        $ingestor = $this->ingestor($limits);
        $summary  = $ingestor->newSummary();
        $result   = $ingestor->ingestMessage(['uid' => 31, 'raw' => $this->message([$this->zip($entries)])], null, $this->domainId, $summary);

        $this->test('archive with too many reports is held', $result === Ingestor::RESULT_HELD, $result);
        $this->test('none of its reports is stored', $this->storedLike('zip-%-' . $this->suffix) === 0);
        $this->test('log names the reports limit', $this->logContains('UID 31 held') && $this->logContains('limit: reports'));

        // An archive with more than the archive helper's 256 documents is held
        // rather than truncated.
        $entries = [];

        for ($i = 0; $i < 300; $i++) {
            $entries['m' . $i . '.xml'] = $this->xml('many-' . $i . '-' . $this->suffix);
        }

        $ingestor = $this->ingestor(new IngestLimits(10, 256, 64 * self::MIB, 20000, 2048 * self::MIB));
        $summary  = $ingestor->newSummary();
        $result   = $ingestor->ingestMessage(['uid' => 32, 'raw' => $this->message([$this->zip($entries)])], null, $this->domainId, $summary);

        $this->test('archive over 256 documents is held, not truncated', $result === Ingestor::RESULT_HELD && $summary['dropped'] === 0, $result);
        $this->test('none of its 300 reports is stored', $this->storedLike('many-%-' . $this->suffix) === 0);
    }

    private function testGzipBomb(): void
    {
        echo "\n--- Gzip bomb ---\n";

        // About 9 KB compressed, 9 MiB unpacked: over the 8 MiB per-document
        // ceiling of the archive helper.
        $bomb = gzencode('<?xml version="1.0"?><feedback><!--' . str_repeat('A', 9 * self::MIB) . '--></feedback>', 9);

        $ingestor = $this->ingestor(IngestLimits::defaults());
        $summary  = $ingestor->newSummary();
        $result   = $ingestor->ingestMessage(['uid' => 41, 'raw' => $this->message([['name' => 'bomb.xml.gz', 'type' => 'application/gzip', 'data' => $bomb]])], null, $this->domainId, $summary);

        $this->test('gzip bomb is small on the wire', \strlen($bomb) < 64 * 1024);
        $this->test('gzip bomb is held for review, not marked read', $result === Ingestor::RESULT_HELD, $result);
        $this->test('log names the archive limit', $this->logContains('UID 41 held') && $this->logContains('limit: archive_limit'));
    }

    private function testConsecutiveBombsStopAtTheRunBudget(): void
    {
        echo "\n--- Consecutive held messages and the run budget ---\n";

        // Each bomb inflates just over 8 MiB before it is discarded. With a
        // 10 MiB run budget the second bomb is still inspected (and held), the
        // third is postponed without being unpacked.
        $ingestor = $this->ingestor(new IngestLimits(10, 20, 2 * self::MIB, 1000, 10 * self::MIB));
        $summary  = $ingestor->newSummary();
        $results  = [];

        foreach ([81, 82, 83] as $uid) {
            $bomb          = gzencode('<?xml version="1.0"?><feedback><!--' . str_repeat('B', 9 * self::MIB) . '--></feedback>', 9);
            $results[$uid] = $ingestor->ingestMessage(['uid' => $uid, 'raw' => $this->message([['name' => 'bomb-' . $uid . '.xml.gz', 'type' => 'application/gzip', 'data' => $bomb]])], null, $this->domainId, $summary);
        }

        $this->test('first two bombs are held', $results[81] === Ingestor::RESULT_HELD && $results[82] === Ingestor::RESULT_HELD, json_encode($results));
        $this->test('the discarded bytes spend the run budget, the third is postponed', $results[83] === Ingestor::RESULT_POSTPONE, json_encode($results));
        $this->test('held and postponed are counted', $summary['held'] === 2 && $summary['postponed'] === 1);
        $this->test('log names the postponed UID and the run size', $this->logContains('UID 83 postponed') && $this->logContains('limit: run_size'));
    }

    private function testZipBomb(): void
    {
        echo "\n--- Zip bomb ---\n";

        if (!class_exists(\ZipArchive::class)) {
            $this->test('ZipArchive available', getenv('TEST_STRICT_SKIP') !== '1');

            return;
        }

        // One valid report next to an entry that unpacks to 20 MiB. The small
        // report must not be stored alone: the message is held as a whole.
        $zip = $this->zip([
            'small.xml' => $this->xml('zipbomb-' . $this->suffix),
            'large.xml' => '<?xml version="1.0"?><feedback><!--' . str_repeat('A', 20 * self::MIB) . '--></feedback>',
        ]);

        $ingestor = $this->ingestor(IngestLimits::defaults());
        $summary  = $ingestor->newSummary();
        $result   = $ingestor->ingestMessage(['uid' => 51, 'raw' => $this->message([$zip])], null, $this->domainId, $summary);

        $this->test('zip bomb is small on the wire', \strlen($zip['data']) < 256 * 1024);
        $this->test('zip bomb is held', $result === Ingestor::RESULT_HELD, $result);
        $this->test('the small report next to it is not half imported', $this->stored('zipbomb-' . $this->suffix) === 0);
    }

    private function testUnpackedSizePerMessage(): void
    {
        echo "\n--- Unpacked size per message ---\n";

        $limits = new IngestLimits(10, 20, self::MIB, 1000, 256 * self::MIB);
        $big    = function (string $id): array {
            return $this->gz($id, 600 * 1024);
        };

        $ingestor = $this->ingestor($limits);
        $summary  = $ingestor->newSummary();
        $result   = $ingestor->ingestMessage(['uid' => 61, 'raw' => $this->message([$big('size-a-' . $this->suffix)])], null, $this->domainId, $summary);
        $this->test('message under the unpacked size is stored', $result === Ingestor::RESULT_NEW, $result);

        $result = $ingestor->ingestMessage(['uid' => 62, 'raw' => $this->message([$big('size-b-' . $this->suffix), $big('size-c-' . $this->suffix)])], null, $this->domainId, $summary);
        $this->test('message over the unpacked size is held', $result === Ingestor::RESULT_HELD, $result);
        $this->test('nothing of it is stored', $this->stored('size-b-' . $this->suffix) === 0 && $this->stored('size-c-' . $this->suffix) === 0);
        $this->test('log names the size limit', $this->logContains('UID 62 held') && $this->logContains('limit: size'));
    }

    private function testRunLimitAndContinuation(): void
    {
        echo "\n--- Run limit and continuation ---\n";

        $messages = [];

        foreach ([71, 72, 73] as $uid) {
            $messages[$uid] = $this->message([$this->gz('run-' . $uid . '-' . $this->suffix)]);
        }

        $ingestor = $this->ingestor(new IngestLimits(10, 1, 32 * self::MIB, 2, 256 * self::MIB));
        $summary  = $ingestor->newSummary();
        $results  = [];

        foreach ($messages as $uid => $raw) {
            $results[$uid] = $ingestor->ingestMessage(['uid' => $uid, 'raw' => $raw], null, $this->domainId, $summary);
        }

        $this->test('the run stores up to its report limit', $results[71] === Ingestor::RESULT_NEW && $results[72] === Ingestor::RESULT_NEW);
        $this->test('the next message is postponed, not stored', $results[73] === Ingestor::RESULT_POSTPONE && $this->stored('run-73-' . $this->suffix) === 0);
        $this->test('the postponement is counted and logged', $summary['postponed'] === 1 && $this->logContains('UID 73 postponed'));

        // The next run has a fresh budget and imports the postponed message.
        $next    = $this->ingestor(new IngestLimits(10, 1, 32 * self::MIB, 2, 256 * self::MIB));
        $summary = $next->newSummary();
        $result  = $next->ingestMessage(['uid' => 73, 'raw' => $messages[73]], null, $this->domainId, $summary);

        $this->test('the next run imports the postponed message', $result === Ingestor::RESULT_NEW && $this->stored('run-73-' . $this->suffix) === 1, $result);
    }

    private function testRunContinuationThroughImap(): void
    {
        echo "\n--- Run continuation through the IMAP client ---\n";

        $bodies = [];

        foreach ([1, 2, 3] as $uid) {
            $bodies[$uid] = $this->message([$this->gz('imap-' . $uid . '-' . $this->suffix)]);
        }

        $first = $this->imapRun(
            [1, 2, 3],
            $bodies,
            0,
            "A0008 OK STORE completed\r\n* BYE\r\nA0009 OK LOGOUT completed\r\n"
        );

        $this->test('first run stores two reports and stops', $first['stats']['ingested'] === 2 && $first['stats']['last_uid'] === 2, json_encode($first['stats']));
        $this->test('the third message stays untouched in the mailbox', $this->stored('imap-3-' . $this->suffix) === 0
            && !str_contains($first['sent'], 'UID STORE 1,2,3'));

        $second = $this->imapRun(
            [3],
            $bodies,
            (int) $first['stats']['last_uid'],
            "A0006 OK STORE completed\r\n* BYE\r\nA0007 OK LOGOUT completed\r\n"
        );

        $this->test('second run resumes after the cursor', str_contains($second['sent'], 'UID SEARCH UID 3:*'));
        $this->test('second run imports the postponed message', $second['stats']['ingested'] === 1 && $this->stored('imap-3-' . $this->suffix) === 1, json_encode($second['stats']));
    }

    private function testLogHoldsNoContent(): void
    {
        echo "\n--- Log without content or sender data ---\n";

        $all = implode("\n", $this->logged);

        $this->test('log entries were written', $this->logged !== []);
        $this->test('log holds no sender address', !str_contains($all, self::SENDER) && !str_contains($all, '@'));
        $this->test('log holds no subject or report content', !str_contains($all, 'Report Domain') && !str_contains($all, '<feedback') && !str_contains($all, $this->domain));
    }

    // -------------------------------------------------------------------------

    /**
     * One IMAP run over a scripted server with a real Ingestor whose run limit
     * admits two reports.
     *
     * @param   int[]                $uids
     * @param   array<int, string>   $bodies
     *
     * @return  array{stats: array<string, mixed>, sent: string}
     */
    private function imapRun(array $uids, array $bodies, int $lastUid, string $tail): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        [$clientEnd, $serverEnd] = $pair;

        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . '* SEARCH ' . implode(' ', $uids) . "\r\nA0004 OK SEARCH completed\r\n";
        $tag = 5;

        foreach ($uids as $seq => $uid) {
            $body    = $bodies[$uid];
            $script .= '* ' . ($seq + 1) . ' FETCH (UID ' . $uid . ' BODY[] {' . \strlen($body) . "}\r\n"
                . $body . ")\r\n"
                . sprintf('A%04d', $tag++) . " OK FETCH completed\r\n";
        }

        $script .= $tail;

        // The scripted conversation (a few small messages) fits the socket
        // buffer, so the server side is pre-loaded like in the unit tests.
        fwrite($serverEnd, $script);

        $client   = new ImapClient(5, static fn () => $clientEnd);
        $ingest   = $this->ingestor(new IngestLimits(10, 1, 32 * self::MIB, 2, 256 * self::MIB));
        $sum      = $ingest->newSummary();
        $domainId = $this->domainId;

        $stats = $client->process(
            [
                'host'                => 'mail.example.test',
                'port'                => 993,
                'security'            => 'ssl',
                'username'            => 'mailbox@example.test',
                'password'            => 'not-a-secret',
                'folder'              => 'INBOX',
                'limit'               => 50,
                'delete_after_import' => 0,
                'last_uid'            => $lastUid,
            ],
            static function (array $message) use ($ingest, $domainId, &$sum): string {
                return $ingest->ingestMessage($message, null, $domainId, $sum);
            }
        );

        // The client closed its end on logout; read everything it sent.
        stream_set_blocking($serverEnd, false);
        $sent = '';

        while (($chunk = fread($serverEnd, 8192)) !== '' && $chunk !== false) {
            $sent .= $chunk;
        }

        fclose($serverEnd);

        return ['stats' => $stats, 'sent' => $sent];
    }

    private function ingestor(IngestLimits $limits): Ingestor
    {
        $ingestor = new Ingestor($this->db, false);
        $ingestor->setLimits($limits);

        return $ingestor;
    }

    private function xml(string $reportId): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<feedback><report_metadata><org_name>example.net</org_name>'
            . '<email>dmarc@example.net</email><report_id>' . $reportId . '</report_id>'
            . '<date_range><begin>1700000000</begin><end>1700086400</end></date_range>'
            . '</report_metadata><policy_published><domain>' . $this->domain . '</domain>'
            . '<p>none</p><pct>100</pct></policy_published>'
            . '<record><row><source_ip>192.0.2.10</source_ip><count>5</count>'
            . '<policy_evaluated><disposition>none</disposition><dkim>pass</dkim>'
            . '<spf>pass</spf></policy_evaluated></row>'
            . '<identifiers><header_from>' . $this->domain . '</header_from></identifiers></record>'
            . '</feedback>';
    }

    /**
     * @return  array{name: string, type: string, data: string}
     */
    private function gz(string $reportId, int $padding = 0): array
    {
        // Random padding so the compressed size grows with the unpacked size.
        $xml = $padding > 0
            ? str_replace('<feedback>', '<!--' . bin2hex(random_bytes(intdiv($padding, 2))) . '--><feedback>', $this->xml($reportId))
            : $this->xml($reportId);

        return ['name' => $reportId . '.xml.gz', 'type' => 'application/gzip', 'data' => gzencode($xml)];
    }

    /**
     * @param   array<string, string>  $entries
     *
     * @return  array{name: string, type: string, data: string}
     */
    private function zip(array $entries): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_limits_');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);

        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }

        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);

        return ['name' => 'reports.zip', 'type' => 'application/zip', 'data' => $data];
    }

    /**
     * Build a multipart report mail with the given attachments and optional
     * filler parts that are not reports.
     *
     * @param   array<int, array{name: string, type: string, data: string}>  $attachments
     */
    private function message(array $attachments, int $fillers = 0): string
    {
        $boundary = 'b-' . bin2hex(random_bytes(6));
        $raw      = 'From: ' . self::SENDER . "\r\n"
            . "To: rua@example.org\r\n"
            . 'Subject: Report Domain: ' . $this->domain . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . 'Content-Type: multipart/mixed; boundary="' . $boundary . "\"\r\n\r\n"
            . '--' . $boundary . "\r\nContent-Type: text/plain\r\n\r\nThis is a DMARC aggregate report.\r\n";

        for ($i = 0; $i < $fillers; $i++) {
            $raw .= '--' . $boundary . "\r\nContent-Type: text/plain\r\n\r\nfiller " . $i . "\r\n";
        }

        foreach ($attachments as $attachment) {
            $raw .= '--' . $boundary . "\r\n"
                . 'Content-Type: ' . $attachment['type'] . '; name="' . $attachment['name'] . "\"\r\n"
                . 'Content-Disposition: attachment; filename="' . $attachment['name'] . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($attachment['data']), 76, "\r\n");
        }

        return $raw . '--' . $boundary . "--\r\n";
    }

    private function seedDomain(): int
    {
        $row = (object) [
            'domain'    => $this->domain,
            'title'     => 'Limits ' . $this->suffix,
            'published' => 1,
            'created'   => date('Y-m-d H:i:s'),
        ];
        $this->db->insertObject('#__dmarc_domains', $row, 'id');

        return (int) $row->id;
    }

    private function stored(string $reportId): int
    {
        return (int) $this->db->setQuery(
            $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__dmarc_reports'))
                ->where($this->db->quoteName('report_id') . ' = ' . $this->db->quote($reportId))
        )->loadResult();
    }

    private function storedLike(string $pattern): int
    {
        return (int) $this->db->setQuery(
            $this->db->getQuery(true)
                ->select('COUNT(*)')
                ->from($this->db->quoteName('#__dmarc_reports'))
                ->where($this->db->quoteName('report_id') . ' LIKE ' . $this->db->quote($pattern))
        )->loadResult();
    }

    private function logContains(string $needle): bool
    {
        foreach ($this->logged as $line) {
            if (str_contains($line, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function cleanup(): void
    {
        if ($this->domainId <= 0) {
            return;
        }

        foreach (['#__dmarc_records', '#__dmarc_reports', '#__dmarc_actions'] as $table) {
            $this->db->setQuery(
                $this->db->getQuery(true)
                    ->delete($this->db->quoteName($table))
                    ->where($this->db->quoteName('domain_id') . ' = ' . (int) $this->domainId)
            )->execute();
        }

        $this->db->setQuery(
            $this->db->getQuery(true)
                ->delete($this->db->quoteName('#__dmarc_domains'))
                ->where($this->db->quoteName('id') . ' = ' . (int) $this->domainId)
        )->execute();
    }

    private function test(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            echo "✓ {$name}\n";
            $this->passed++;
        } else {
            echo "✗ {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
            $this->failed++;
        }
    }
}

$test = new IngestLimitsIntegrationTest();
exit($test->run() ? 0 : 1);
