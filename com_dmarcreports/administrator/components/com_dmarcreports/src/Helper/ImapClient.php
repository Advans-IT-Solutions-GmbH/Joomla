<?php
/**
 * @package     DMARC Reports Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\DmarcReports\Administrator\Helper;

defined('_JEXEC') or die;

/**
 * A small IMAP4rev1 client built directly on PHP stream sockets, so the
 * component works even when the optional PHP imap extension is not installed.
 * It implements just enough of the protocol to log in, open a folder, find
 * messages and fetch their raw source. Credentials are only used to
 * authenticate and are never written to a log.
 *
 * @since  1.0.0
 */
final class ImapClient
{
    /**
     * Largest message body accepted from the server. The IMAP server announces
     * each literal's size up front; a hostile or misbehaving server could claim
     * a huge size and make the client allocate it, so anything above this
     * ceiling is drained and skipped rather than buffered.
     */
    private const MAX_LITERAL_BYTES = 33554432; // 32 MiB

    /**
     * Aggregate byte budget for a single import run. Bodies are handed to the
     * caller one at a time and released immediately, so memory never holds more
     * than one message; this secondary cap stops a run from streaming an
     * unbounded total (e.g. thousands of large reports) in one pass. Whatever is
     * left is advanced on the next run.
     */
    private const MAX_TOTAL_BYTES = 134217728; // 128 MiB

    /** @var resource|null */
    private $socket;

    private int $tag = 0;

    private int $timeout;

    public function __construct(int $timeout = 20)
    {
        $this->timeout = max(5, $timeout);
    }

    /**
     * Stream raw messages from a mailbox folder to a callback, one at a time,
     * then advance the mailbox so the same messages are not reprocessed on the
     * next run.
     *
     * Each fetched body is passed to {@see $onMessage} and then released before
     * the next is read, so memory never holds more than a single message. The
     * callback returns true when the message produced a new stored report.
     * Afterwards every processed message is flagged \Seen (so the default
     * UNSEEN search skips it next time); when the source opts into deletion the
     * successfully ingested messages are instead flagged \Deleted and expunged.
     *
     * @param   array<string, mixed>  $config     host, port, security (ssl|tls|none),
     *                                            username, password, folder, limit,
     *                                            unseen_only, delete_after_import.
     * @param   callable(array{uid: int, raw: string}): bool  $onMessage
     *
     * @return  array{seen: int, ingested: int, skipped: int}
     *
     * @throws  \RuntimeException  On any connection, authentication or protocol error.
     */
    public function process(array $config, callable $onMessage): array
    {
        $host     = (string) ($config['host'] ?? '');
        $port     = (int) ($config['port'] ?? 993);
        $security = strtolower((string) ($config['security'] ?? 'ssl'));
        $folder   = (string) ($config['folder'] ?? 'INBOX');
        $limit    = max(1, (int) ($config['limit'] ?? 50));
        $unseen   = !\array_key_exists('unseen_only', $config) || !empty($config['unseen_only']);
        $delete   = !empty($config['delete_after_import']);

        if ($host === '') {
            throw new \RuntimeException('No IMAP host configured.');
        }

        $this->connect($host, $port, $security);

        $stats = ['seen' => 0, 'ingested' => 0, 'skipped' => 0];

        try {
            $this->login((string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''));
            $this->select($folder);

            // Oldest first, then the configured batch size, so a backlog drains
            // from the front instead of repeatedly reprocessing the newest mail.
            $ids = $this->search($unseen);
            sort($ids);
            $ids = \array_slice($ids, 0, $limit);

            $handled = []; // produced a new report
            $touched = []; // processed but no new report (empty, duplicate or failed)
            $budget  = self::MAX_TOTAL_BYTES;

            foreach ($ids as $id) {
                $raw = $this->fetchBody($id);
                $stats['seen']++;

                if ($raw === '') {
                    $touched[] = $id;
                    continue;
                }

                $budget -= \strlen($raw);
                $ingested = (bool) $onMessage(['uid' => $id, 'raw' => $raw]);
                unset($raw);

                if ($ingested) {
                    $stats['ingested']++;
                    $handled[] = $id;
                } else {
                    $stats['skipped']++;
                    $touched[] = $id;
                }

                // Stop once the aggregate budget is spent; the remainder is
                // untouched and will be picked up by the next run.
                if ($budget <= 0) {
                    break;
                }
            }

            $this->advance($handled, $touched, $delete);

            return $stats;
        } finally {
            $this->logout();
        }
    }

    /**
     * Advance the mailbox after a run. Flagging happens before any EXPUNGE so
     * every STORE still references valid sequence numbers (EXPUNGE renumbers the
     * remaining messages).
     *
     * @param  int[]  $handled  Sequence numbers that produced a new report.
     * @param  int[]  $touched  Sequence numbers processed without a new report.
     */
    private function advance(array $handled, array $touched, bool $delete): void
    {
        // Messages kept in place are marked \Seen so the UNSEEN search skips
        // them next time. When deleting, the ingested ones are removed instead.
        $seen = $delete ? $touched : array_merge($handled, $touched);

        if ($seen !== []) {
            $this->markSeen($seen);
        }

        if ($delete && $handled !== []) {
            $this->deleteMessages($handled);
        }
    }

    private function markSeen(array $ids): void
    {
        $set = $this->sequenceSet($ids);

        if ($set !== '') {
            $this->command('STORE ' . $set . ' +FLAGS.SILENT (\\Seen)', 'Unable to flag processed messages.');
        }
    }

    private function deleteMessages(array $ids): void
    {
        $set = $this->sequenceSet($ids);

        if ($set === '') {
            return;
        }

        $this->command('STORE ' . $set . ' +FLAGS.SILENT (\\Deleted)', 'Unable to flag messages for deletion.');
        $this->command('EXPUNGE', 'Unable to expunge imported messages.');
    }

    /**
     * Build an ascending, de-duplicated IMAP sequence set (e.g. "1,2,5").
     *
     * @param  int[]  $ids
     */
    private function sequenceSet(array $ids): string
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);

        return implode(',', $ids);
    }

    private function connect(string $host, int $port, string $security): void
    {
        $transport = $security === 'ssl' ? 'ssl://' : 'tcp://';
        $context   = stream_context_create(
            ['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]
        );

        $errno  = 0;
        $errstr = '';

        $socket = @stream_socket_client(
            $transport . $host . ':' . $port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            throw new \RuntimeException('Unable to connect to the mail server.');
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $this->timeout);

        $greeting = $this->readLine();

        if (stripos($greeting, '* OK') !== 0) {
            throw new \RuntimeException('The mail server did not send a valid IMAP greeting.');
        }

        if ($security === 'tls') {
            $this->command('STARTTLS');

            if (!@stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('Unable to start TLS with the mail server.');
            }
        }
    }

    private function login(string $username, string $password): void
    {
        if ($username === '') {
            throw new \RuntimeException('No IMAP username configured.');
        }

        // Credentials are quoted; a protocol error here is reported without them.
        $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password), 'Authentication failed.');
    }

    private function select(string $folder): void
    {
        $this->command('SELECT ' . $this->quote($folder), 'Unable to open the mailbox folder.');
    }

    /**
     * @return int[]
     */
    private function search(bool $unseen): array
    {
        $tag   = $this->nextTag();
        $this->write($tag . ' SEARCH ' . ($unseen ? 'UNSEEN' : 'ALL'));

        $ids   = [];
        $error = 'Unable to search the mailbox.';

        while (true) {
            $line = $this->readLine();

            if (stripos($line, '* SEARCH') === 0) {
                foreach (preg_split('/\s+/', trim(substr($line, 8))) ?: [] as $token) {
                    if (ctype_digit($token)) {
                        $ids[] = (int) $token;
                    }
                }
            }

            if (str_starts_with($line, $tag . ' ')) {
                $this->assertOk($line, $error);
                break;
            }
        }

        return $ids;
    }

    private function fetchBody(int $id): string
    {
        $tag = $this->nextTag();
        $this->write($tag . ' FETCH ' . $id . ' (BODY.PEEK[])');

        $raw  = '';
        $line = $this->readLine();

        // The server announces the literal size as {NNN}.
        if (preg_match('/\{(\d+)\}\s*$/', $line, $m)) {
            $size = (int) $m[1];

            // Drain oversized literals off the socket without buffering them, so
            // one huge message cannot exhaust memory, then skip this message.
            if ($size > self::MAX_LITERAL_BYTES) {
                $this->discardBytes($size);
                $raw = '';
            } else {
                $raw = $this->readBytes($size);
            }
        }

        // Drain until the tagged completion line.
        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag . ' ')) {
                $this->assertOk($line, 'Unable to fetch a message.');
                break;
            }
        }

        return $raw;
    }

    private function logout(): void
    {
        if (!\is_resource($this->socket)) {
            return;
        }

        try {
            $tag = $this->nextTag();
            $this->write($tag . ' LOGOUT');
        } catch (\Throwable) {
            // Best effort: the connection is being torn down anyway.
        } finally {
            @fclose($this->socket);
            $this->socket = null;
        }
    }

    private function command(string $command, string $error = 'IMAP command failed.'): void
    {
        $tag = $this->nextTag();
        $this->write($tag . ' ' . $command);

        while (true) {
            $line = $this->readLine();

            if (str_starts_with($line, $tag . ' ')) {
                $this->assertOk($line, $error);

                return;
            }
        }
    }

    private function assertOk(string $line, string $error): void
    {
        if (!preg_match('/^\S+\s+OK/i', $line)) {
            throw new \RuntimeException($error);
        }
    }

    private function nextTag(): string
    {
        return 'A' . str_pad((string) (++$this->tag), 4, '0', STR_PAD_LEFT);
    }

    private function write(string $command): void
    {
        if (!\is_resource($this->socket) || @fwrite($this->socket, $command . "\r\n") === false) {
            throw new \RuntimeException('Lost connection to the mail server.');
        }
    }

    private function readLine(): string
    {
        if (!\is_resource($this->socket)) {
            throw new \RuntimeException('Lost connection to the mail server.');
        }

        $line = fgets($this->socket);
        $meta = stream_get_meta_data($this->socket);

        if ($meta['timed_out'] ?? false) {
            throw new \RuntimeException('The mail server timed out.');
        }

        if ($line === false) {
            throw new \RuntimeException('Lost connection to the mail server.');
        }

        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $buffer = '';

        while (\strlen($buffer) < $length && \is_resource($this->socket) && !feof($this->socket)) {
            $chunk = fread($this->socket, $length - \strlen($buffer));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    /**
     * Read and discard a literal of the given size in bounded chunks, keeping
     * the connection in sync without ever holding the whole literal in memory.
     */
    private function discardBytes(int $length): void
    {
        $remaining = $length;

        while ($remaining > 0 && \is_resource($this->socket) && !feof($this->socket)) {
            $chunk = fread($this->socket, min(8192, $remaining));

            if ($chunk === false || $chunk === '') {
                break;
            }

            $remaining -= \strlen($chunk);
        }
    }

    private function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
