<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Tests\Unit;

use Advans\Component\DmarcReports\Administrator\Helper\ImapClient;
use Advans\Component\DmarcReports\Administrator\Helper\ImapException;
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
            ['seen' => 2, 'ingested' => 1, 'skipped' => 1, 'transport_skipped' => 0, 'deferred' => 0, 'blocked' => 0, 'uidvalidity' => 0, 'last_uid' => 2, 'deferred_uids' => []],
            $result['stats']
        );

        // Bodies must survive literal framing byte-for-byte, including the CRLFs
        // embedded in the message source.
        $this->assertSame($body1, $result['seen'][1] ?? null);
        $this->assertSame($body2, $result['seen'][2] ?? null);

        // The import tracks its own progress by UID, so the search asks for the
        // whole UID range above the stored high-water mark (0 here → 1:*) rather
        // than depending on the mailbox \Seen state.
        $this->assertStringContainsString('UID SEARCH UID 1:*', $result['sent']);
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
            ['seen' => 1, 'ingested' => 0, 'skipped' => 0, 'transport_skipped' => 0, 'deferred' => 1, 'blocked' => 0, 'uidvalidity' => 0, 'last_uid' => 0, 'deferred_uids' => []],
            $result['stats']
        );

        // A deferred (transient failure) message must not advance the import
        // cursor: last_uid stays at 0 and no STORE is sent, so the next run
        // re-fetches it from the same UID range instead of losing it.
        $this->assertStringNotContainsString('UID STORE', $result['sent']);
    }

    public function testAdvancesPastBlockedMessages(): void
    {
        $body1 = "Subject: Report 1\r\n\r\nbody-one";
        $body2 = "Subject: Report 2\r\n\r\nbody-two";

        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH 1 2\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, $body1, 'A0005')
            . $this->fetchResponse(2, 2, $body2, 'A0006')
            . "A0007 OK STORE completed\r\n"
            . "* BYE\r\nA0008 OK LOGOUT completed\r\n";

        // UID 1 is blocked (the host cannot decode its container); UID 2 imports.
        $result = $this->drive(
            $script,
            ['delete_after_import' => 0],
            static fn (array $m): string => $m['uid'] === 1 ? 'blocked' : 'new'
        );

        // Unlike a transient retry, a blocked message does not pin the cursor: it
        // is counted, flagged \Seen and the high-water mark advances past it to
        // UID 2, so a single undecodable report cannot starve the newer one. Its
        // UID is kept in the retry set so it is re-fetched (not lost) once the
        // decoder extension is installed.
        $this->assertSame(
            ['seen' => 2, 'ingested' => 1, 'skipped' => 0, 'transport_skipped' => 0, 'deferred' => 0, 'blocked' => 1, 'uidvalidity' => 0, 'last_uid' => 2, 'deferred_uids' => [1]],
            $result['stats']
        );

        $this->assertStringContainsString('UID STORE 1,2 +FLAGS.SILENT (\\Seen)', $result['sent']);
    }

    public function testRetriesPreviouslyDeferredUidBelowCursor(): void
    {
        $body = "Subject: Late report\r\n\r\nnow-decodable";

        // The cursor is already at UID 5 and the scan finds nothing newer, but a
        // previous run recorded UID 1 as awaiting decode support. The client must
        // fetch that below-cursor UID explicitly and, now that it decodes, store
        // it and drop it from the retry set.
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, $body, 'A0005')
            . "A0006 OK STORE completed\r\n"
            . "* BYE\r\nA0007 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0, 'last_uid' => 5, 'deferred_uids' => [1]],
            static fn (array $m): string => 'new'
        );

        // The retry succeeded: the report is ingested, the cursor stays at 5
        // (the retried UID is below it and never governs the high-water mark),
        // and the UID is removed from the retry set.
        $this->assertSame(
            ['seen' => 1, 'ingested' => 1, 'skipped' => 0, 'transport_skipped' => 0, 'deferred' => 0, 'blocked' => 0, 'uidvalidity' => 0, 'last_uid' => 5, 'deferred_uids' => []],
            $result['stats']
        );

        // The below-cursor UID was fetched explicitly and flagged \Seen.
        $this->assertStringContainsString('UID FETCH 1 (BODY.PEEK[])', $result['sent']);
        $this->assertStringContainsString('UID STORE 1 +FLAGS.SILENT (\\Seen)', $result['sent']);
        $this->assertSame($body, $result['seen'][1] ?? null);
    }

    public function testKeepsDeferredUidWhenStillUndecodable(): void
    {
        $body = "Subject: Still zipped\r\n\r\nstill-blocked";

        // UID 1 remains undecodable (the extension is still missing): it must be
        // re-fetched, counted as blocked and kept in the retry set for next time.
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, $body, 'A0005')
            . "A0006 OK STORE completed\r\n"
            . "* BYE\r\nA0007 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0, 'last_uid' => 5, 'deferred_uids' => [1]],
            static fn (array $m): string => 'blocked'
        );

        $this->assertSame(
            ['seen' => 1, 'ingested' => 0, 'skipped' => 0, 'transport_skipped' => 0, 'deferred' => 0, 'blocked' => 1, 'uidvalidity' => 0, 'last_uid' => 5, 'deferred_uids' => [1]],
            $result['stats']
        );
    }

    public function testDropsDeferredUidThatNoLongerExists(): void
    {
        // The retried UID was expunged from the mailbox since it was recorded, so
        // the FETCH returns no body. The client must stop tracking it rather than
        // retrying a vanished message forever.
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH\r\nA0004 OK SEARCH completed\r\n"
            . "A0005 OK FETCH completed\r\n"
            . "* BYE\r\nA0006 OK LOGOUT completed\r\n";

        $called = 0;

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0, 'last_uid' => 5, 'deferred_uids' => [1]],
            static function () use (&$called): string {
                $called++;

                return 'new';
            }
        );

        $this->assertSame(0, $called);
        $this->assertSame(
            ['seen' => 0, 'ingested' => 0, 'skipped' => 0, 'transport_skipped' => 0, 'deferred' => 0, 'blocked' => 0, 'uidvalidity' => 0, 'last_uid' => 5, 'deferred_uids' => []],
            $result['stats']
        );
    }

    public function testRejectsTruncatedLiteralBody(): void
    {
        // The server announces a 100-byte literal but delivers only five bytes
        // and then closes its write side mid-literal, modelling a peer close or
        // reset during the fetch. The client must not treat the partial read as
        // a complete (empty/skip) message and advance the cursor; it must raise
        // so the import aborts with the cursor unchanged and retries next run.
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair(AF_UNIX) is not available on this platform.');
        }

        [$clientEnd, $serverEnd] = $pair;

        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH 1\r\nA0004 OK SEARCH completed\r\n"
            . "* 1 FETCH (UID 1 BODY[] {100}\r\n"
            . 'short';

        fwrite($serverEnd, $script);

        // Half-close the server's write side: the buffered script is still
        // delivered, then the client's literal read hits EOF after five bytes
        // while the server can still receive the client's own commands.
        stream_socket_shutdown($serverEnd, STREAM_SHUT_WR);

        $config = [
            'host'                => 'mail.example.test',
            'port'                => 993,
            'security'            => 'ssl',
            'username'            => 'mailbox@example.test',
            'password'            => 'secret',
            'folder'              => 'INBOX',
            'limit'               => 50,
            'delete_after_import' => 0,
        ];

        $client = new ImapClient(5, static function () use ($clientEnd) {
            return $clientEnd;
        });

        try {
            $this->expectException(\RuntimeException::class);
            $client->process($config, static fn (): string => 'new');
        } finally {
            if (\is_resource($serverEnd)) {
                fclose($serverEnd);
            }
        }
    }

    public function testCountsEmptyBodyAsTransportSkipWithoutCallback(): void
    {
        // A zero-length literal models an empty body (or one dropped by the
        // per-message cap in fetchBody): the callback must never run, yet the
        // message is advanced and surfaced as a transport skip.
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 OK SELECT completed\r\n"
            . "* SEARCH 1\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(1, 1, '', 'A0005')
            . "A0006 OK STORE completed\r\n"
            . "* BYE\r\nA0007 OK LOGOUT completed\r\n";

        $called = 0;

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0],
            static function () use (&$called): string {
                $called++;

                return 'new';
            }
        );

        $this->assertSame(0, $called);
        $this->assertSame(
            ['seen' => 1, 'ingested' => 0, 'skipped' => 1, 'transport_skipped' => 1, 'deferred' => 0, 'blocked' => 0, 'uidvalidity' => 0, 'last_uid' => 1, 'deferred_uids' => []],
            $result['stats']
        );

        // The empty message is still flagged \Seen so it drops out of the next run.
        $this->assertStringContainsString('UID STORE 1 +FLAGS.SILENT (\\Seen)', $result['sent']);
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
        // socket, so credentials can never travel in the clear. The failure is
        // a configuration problem, so the operator is pointed at the security
        // setting rather than at an unreachable server.
        $client = new ImapClient(5, static fn () => fopen('php://memory', 'r+'));

        try {
            $client->process(
                ['host' => 'mail.example.test', 'security' => 'none'],
                static fn (): string => 'skip'
            );
            $this->fail('Expected ImapException was not thrown.');
        } catch (ImapException $e) {
            $this->assertSame(ImapException::CATEGORY_CONFIG, $e->getCategory());
        }
    }

    /**
     * Drive the client against a scripted server that only sends a failure
     * response, returning the ImapException it raises so its category can be
     * asserted. The server's write side is half-closed after the script so the
     * client still hits a clean end-of-input, while its own commands are
     * accepted on the read side.
     *
     * @param   array<string, mixed>  $config  Connection config overrides.
     */
    private function expectImapFailure(string $serverScript, array $config): ImapException
    {
        $pair = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        if ($pair === false) {
            $this->markTestSkipped('stream_socket_pair(AF_UNIX) is not available on this platform.');
        }

        [$clientEnd, $serverEnd] = $pair;

        fwrite($serverEnd, $serverScript);
        stream_socket_shutdown($serverEnd, STREAM_SHUT_WR);

        $config += [
            'host'     => 'mail.example.test',
            'port'     => 993,
            'security' => 'ssl',
            'username' => 'mailbox@example.test',
            'password' => 'secret',
            'folder'   => 'INBOX',
            'limit'    => 50,
        ];

        $client = new ImapClient(5, static function () use ($clientEnd) {
            return $clientEnd;
        });

        try {
            $client->process($config, static fn (): string => 'new');
            $this->fail('Expected ImapException was not thrown.');
        } catch (ImapException $e) {
            return $e;
        } finally {
            if (\is_resource($serverEnd)) {
                fclose($serverEnd);
            }
        }
    }

    public function testInvalidGreetingIsCategorisedAsConnection(): void
    {
        // The server never sends a valid "* OK" greeting: an unreachable or
        // non-IMAP endpoint, surfaced as a connection problem.
        $exception = $this->expectImapFailure("* BAD server unavailable\r\n", []);

        $this->assertSame(ImapException::CATEGORY_CONNECTION, $exception->getCategory());
    }

    public function testLoginFailureIsCategorisedAsLogin(): void
    {
        // Authentication is rejected: a credentials problem. The same command()
        // path carries the TLS category for a refused STARTTLS, so proving the
        // category argument propagates here also covers that path.
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 NO [AUTHENTICATIONFAILED] Invalid credentials\r\n";

        $exception = $this->expectImapFailure($script, []);

        $this->assertSame(ImapException::CATEGORY_LOGIN, $exception->getCategory());

        // The password is never echoed back in the surfaced error.
        $this->assertStringNotContainsString('secret', $exception->getMessage());
    }

    public function testRejectedSelectIsCategorisedAsConfig(): void
    {
        // TLS and login have already succeeded, so a refused SELECT means the
        // configured folder is missing or inaccessible — a configuration
        // problem, pointing the operator at the folder setting.
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1\r\nA0002 OK CAPABILITY completed\r\n"
            . "A0003 NO [NONEXISTENT] Mailbox does not exist\r\n";

        $exception = $this->expectImapFailure($script, []);

        $this->assertSame(ImapException::CATEGORY_CONFIG, $exception->getCategory());
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

    public function testResumesAboveStoredCursorAndReportsHighWater(): void
    {
        $body6 = "Subject: Report 6\r\n\r\nbody-six";
        $body7 = "Subject: Report 7\r\n\r\nbody-seven";

        // The folder reports the same UIDVALIDITY the cursor was stored under,
        // so the client resumes above the stored high-water UID (5) and asks for
        // the 6:* range only.
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1 UIDPLUS\r\nA0002 OK CAPABILITY completed\r\n"
            . "* OK [UIDVALIDITY 42] UIDs valid\r\nA0003 OK [READ-WRITE] SELECT completed\r\n"
            . "* SEARCH 6 7\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(6, 6, $body6, 'A0005')
            . $this->fetchResponse(7, 7, $body7, 'A0006')
            . "A0007 OK STORE completed\r\n"
            . "* BYE\r\nA0008 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0, 'uidvalidity' => 42, 'last_uid' => 5],
            static fn (): string => 'new'
        );

        // Only UIDs above the stored cursor are searched, and the returned
        // cursor advances to the highest contiguously handled UID.
        $this->assertStringContainsString('UID SEARCH UID 6:*', $result['sent']);
        $this->assertStringNotContainsString('UID SEARCH UID 1:*', $result['sent']);
        $this->assertSame(42, $result['stats']['uidvalidity']);
        $this->assertSame(7, $result['stats']['last_uid']);
        $this->assertSame(2, $result['stats']['ingested']);
    }

    public function testResetsCursorWhenUidValidityChanges(): void
    {
        $body = "Subject: Report\r\n\r\nbody";

        // The folder now reports a different UIDVALIDITY than the cursor was
        // stored under: the mailbox was renumbered, so the stored high-water UID
        // is meaningless and the client must rescan from the start (1:*).
        $script = "* OK IMAP4rev1 ready\r\n"
            . "A0001 OK LOGIN completed\r\n"
            . "* CAPABILITY IMAP4rev1 UIDPLUS\r\nA0002 OK CAPABILITY completed\r\n"
            . "* OK [UIDVALIDITY 99] UIDs valid\r\nA0003 OK [READ-WRITE] SELECT completed\r\n"
            . "* SEARCH 3\r\nA0004 OK SEARCH completed\r\n"
            . $this->fetchResponse(3, 3, $body, 'A0005')
            . "A0006 OK STORE completed\r\n"
            . "* BYE\r\nA0007 OK LOGOUT completed\r\n";

        $result = $this->drive(
            $script,
            ['delete_after_import' => 0, 'uidvalidity' => 42, 'last_uid' => 100],
            static fn (): string => 'new'
        );

        // Cursor reset: search starts from 1:* despite the stored last_uid of
        // 100, and the returned cursor reflects the new UIDVALIDITY.
        $this->assertStringContainsString('UID SEARCH UID 1:*', $result['sent']);
        $this->assertSame(99, $result['stats']['uidvalidity']);
        $this->assertSame(3, $result['stats']['last_uid']);
        $this->assertSame(1, $result['stats']['ingested']);
    }
}
