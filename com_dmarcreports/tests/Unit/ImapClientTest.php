<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\ImapClient;
use PHPUnit\Framework\TestCase;

/**
 * Protocol-level tests for the dependency-free IMAP client.
 *
 * The client talks IMAP4rev1 over a raw stream socket. These tests drive it
 * against a scripted in-memory server: a connected {@see stream_socket_pair()}
 * whose server endpoint is pre-loaded with the exact responses a real server
 * would send, injected through the client's test-only transport factory. This
 * exercises the real command/response parser, literal framing, UID handling
 * and mailbox advancement without a live mailbox or the PHP imap extension.
 *
 * @since  1.0.0
 */
class ImapClientTest extends TestCase
{
    /**
     * Drive the client through a full run against a scripted server.
     *
     * @param   string                                  $serverScript  Raw bytes the fake server sends.
     * @param   array<string, mixed>                    $config        Connection config overrides.
     * @param   callable(array{uid:int,raw:string}):string  $onMessage  Per-message outcome callback.
     *
     * @return  array{stats: array<string,int>, sent: string, seen: array<int,string>}
     */
    private function drive(string $serverScript, array $config, callable $onMessage): array
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair(AF_UNIX) is not available on this platform.');
        }

        [$clientEnd, $serverEnd] = $pair;

        // Pre-load the entire scripted conversation. It is far smaller than the
        // socket buffer, so the client can read it in lock-step while its own
        // writes accumulate on the server endpoint for later inspection.
        fwrite($serverEnd, $serverScript);

        $config += [
            'host'     => 'mail.example.test',
            'port'     => 993,
            'security' => 'ssl',
            'username' => 'mailbox@example.test',
            'password' => 'secret',
            'folder'   => 'INBOX',
            'limit'    => 50,
        ];

        $seen   = [];
        $client = new ImapClient(5, static function () use ($clientEnd) {
            return $clientEnd;
        });

        $stats = $client->process($config, static function (array $message) use (&$seen, $onMessage): string {
            $seen[$message['uid']] = $message['raw'];

            return $onMessage($message);
        });

        // The client closed its endpoint on logout; drain everything it sent.
        stream_set_blocking($serverEnd, false);
        $sent = '';

        while (($chunk = fread($serverEnd, 8192)) !== '' && $chunk !== false) {
            $sent .= $chunk;
        }

        fclose($serverEnd);

        return ['stats' => $stats, 'sent' => $sent, 'seen' => $seen];
    }

    /**
     * Assemble one untagged FETCH response carrying a literal body, framed by
     * byte count exactly as a real server frames it.
     */
    private function fetchResponse(int $seq, int $uid, string $body, string $tag): string
    {
        return '* ' . $seq . ' FETCH (UID ' . $uid . ' BODY[] {' . \strlen($body) . "}\r\n"
            . $body . ")\r\n"
            . $tag . " OK FETCH completed\r\n";
    }

    public function testStreamsMessagesAndMarksThemSeen(): void
    {
        $body1 = "Return-Path: <dmarc@ex.test>\r\nSubject: Report 1\r\n\r\nbody-one";
        $body2 = "Subject: Report 2\r\n\r\nbody-two";

        // SEARCH returns UIDs out of order; the client must fetch them oldest
        // first (ascending), so 1 before 2.
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1 UIDPLUS\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK [READ-WRITE] SELECT completed\r\n"
            . "* SEARCH 2 1\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, $body1, 'A0005')
            . $this->fetchResponse(2, 2, $body2, 'A0006')
            . "A0007 OK STORE completed\r\n"
            . "* BYE server logging out\r\nA0008 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0],
            static fn (array $m): string => $m['uid'] === 1 ? 'new' : 'skip'
        );

        $this->assertSame(
            ['seen' => 2, 'ingested' => 1, 'skipped' => 1, 'deferred' => 0],
            $result['stats']
        );

        // Bodies must survive literal framing byte-for-byte, including the CRLFs
        // embedded in the message source.
        $this->assertSame($body1, $result['seen'][1] ?? null);
        $this->assertSame($body2, $result['seen'][2] ?? null);

        // Processed messages are flagged \Seen in a single combined UID STORE so
        // the next UNSEEN search skips them.
        $this->assertStringContainsString('UID SEARCH UNSEEN', $result['sent']);
        $this->assertStringContainsString('UID FETCH 1 (BODY.PEEK[])', $result['sent']);
        $this->assertStringContainsString('UID FETCH 2 (BODY.PEEK[])', $result['sent']);
        $this->assertStringContainsString('UID STORE 1,2 +FLAGS.SILENT (\\Seen)', $result['sent']);
        $this->assertStringNotContainsString('EXPUNGE', $result['sent']);

        // The password is only ever sent inside the quoted LOGIN argument.
        $this->assertStringContainsString('LOGIN "mailbox@example.test" "secret"', $result['sent']);
    }

    public function testLeavesDeferredMessagesUntouched(): void
    {
        $body = "Subject: Report\r\n\r\nbody";

        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH 1\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, $body, 'A0005')
            . "* BYE\r\nA0006 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0],
            static fn (): string => 'retry'
        );

        $this->assertSame(
            ['seen' => 1, 'ingested' => 0, 'skipped' => 0, 'deferred' => 1],
            $result['stats']
        );

        // A deferred (transient failure) message must not be advanced: no STORE
        // keeps it in the UNSEEN set for the next run.
        $this->assertStringNotContainsString('UID STORE', $result['sent']);
    }

    public function testDeletesImportedMessagesWithUidplus(): void
    {
        $body = "Subject: Report\r\n\r\nbody";

        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1 UIDPLUS\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH 1\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, $body, 'A0005')
            . "A0006 OK STORE completed\r\n"
            . "A0007 OK EXPUNGE completed\r\n"
            . "* BYE\r\nA0008 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 1],
            static fn (): string => 'new'
        );

        $this->assertSame(1, $result['stats']['ingested']);

        // With UIDPLUS the imported message is removed with a targeted
        // UID EXPUNGE, never a blanket EXPUNGE.
        $this->assertStringContainsString('UID STORE 1 +FLAGS.SILENT (\\Deleted)', $result['sent']);
        $this->assertStringContainsString('UID EXPUNGE 1', $result['sent']);
    }

    public function testDefersDeletionWithoutUidplus(): void
    {
        $body = "Subject: Report\r\n\r\nbody";

        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH 1\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, $body, 'A0005')
            . "A0006 OK STORE completed\r\n"
            . "* BYE\r\nA0007 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 1],
            static fn (): string => 'new'
        );

        $this->assertSame(1, $result['stats']['ingested']);

        // Without UIDPLUS a plain EXPUNGE would remove other clients' \Deleted
        // messages too, so removal is deferred: the message is flagged
        // \Deleted and \Seen but never expunged by us.
        $this->assertStringContainsString('UID STORE 1 +FLAGS.SILENT (\\Deleted \\Seen)', $result['sent']);
        $this->assertStringNotContainsString('EXPUNGE', $result['sent']);
    }

    public function testRejectsPlaintextTransport(): void
    {
        // The client must refuse an unencrypted session before opening any
        // socket, so credentials can never travel in the clear.
        $client = new ImapClient(5, static fn () => fopen('php://memory', 'r+'));

        $this->expectException(\RuntimeException::class);

        $client->process(
            ['host' => 'mail.example.test', 'security' => 'none'],
            static fn (): string => 'skip'
        );
    }

    public function testFramesLiteralBodiesByByteCount(): void
    {
        // A body that itself contains lines resembling IMAP responses (a tagged
        // "OK", an untagged line, a stray close paren) must be read purely by
        // the announced literal length, not by scanning for protocol tokens.
        $body = "Subject: Tricky\r\n"
            . "\r\n"
            . "A0006 OK this line is part of the body\r\n"
            . "* 1 FETCH pretend untagged line\r\n"
            . ") trailing paren and {braces}\r\n";

        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH 1\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, $body, 'A0005')
            . "A0006 OK STORE completed\r\n"
            . "* BYE\r\nA0007 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0],
            static fn (): string => 'new'
        );

        $this->assertSame(1, $result['stats']['seen']);
        $this->assertSame($body, $result['seen'][1] ?? null);
    }
}
