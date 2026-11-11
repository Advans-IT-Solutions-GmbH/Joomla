<?php
// Loopback SMTP capture for the isolated acceptance stack only. It accepts every
// message, delivers nothing and writes each one as {"envelope": [...], "mime": "..."}
// into the capture directory read by the CJS suites. Usage:
//   php mail-capture.php 127.0.0.1 <port> <capture dir> <spool dir on the same filesystem>
declare(strict_types=1);

[, $host, $port, $capture, $spool] = $argv + [null, null, null, null, null];
if (PHP_SAPI !== 'cli' || $host !== '127.0.0.1' || !ctype_digit((string) $port)
    || !is_dir((string) $capture) || !is_dir((string) $spool)) {
    fwrite(STDERR, "Usage: php mail-capture.php 127.0.0.1 <port> <capture dir> <spool dir>\n");
    exit(2);
}
$server = stream_socket_server("tcp://$host:$port", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "Cannot listen on $host:$port: $error\n");
    exit(1);
}
$sequence = 0;
while (true) {
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }
    stream_set_timeout($client, 30);
    $reply = static function (string $line) use ($client): void {
        fwrite($client, $line . "\r\n");
    };
    $reply('220 privacy-fixture ESMTP capture');
    $envelope = [];
    while (($line = fgets($client)) !== false) {
        $command = rtrim($line, "\r\n");
        $verb = strtoupper(explode(' ', $command, 2)[0]);
        if ($verb === 'EHLO') {
            $reply('250-privacy-fixture');
            $reply('250-8BITMIME');
            $reply('250-SMTPUTF8');
            $reply('250 SIZE 26214400');
        } elseif ($verb === 'HELO') {
            $reply('250 privacy-fixture');
        } elseif ($verb === 'MAIL') {
            $envelope = [$command];
            $reply('250 OK');
        } elseif ($verb === 'RCPT') {
            $envelope[] = $command;
            $reply('250 OK');
        } elseif ($verb === 'DATA') {
            $reply('354 End data with <CR><LF>.<CR><LF>');
            $mime = '';
            while (($chunk = fgets($client)) !== false && rtrim($chunk, "\r\n") !== '.') {
                // RFC 5321 4.5.2: remove the transparency dot.
                $mime .= str_starts_with($chunk, '.') ? substr($chunk, 1) : $chunk;
            }
            $name = sprintf('%s-%d-%06d.json', gmdate('YmdHis'), getmypid(), ++$sequence);
            $json = json_encode(['envelope' => $envelope, 'mime' => $mime],
                JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
            // Write in the spool and rename, so a reader never sees a partial message.
            if (file_put_contents("$spool/$name", $json) !== strlen($json) || !rename("$spool/$name", "$capture/$name")) {
                $reply('451 Capture failed');
                continue;
            }
            $envelope = [];
            $reply('250 OK captured');
        } elseif ($verb === 'RSET') {
            $envelope = [];
            $reply('250 OK');
        } elseif ($verb === 'NOOP') {
            $reply('250 OK');
        } elseif ($verb === 'QUIT') {
            $reply('221 Bye');
            break;
        } else {
            $reply('502 Command not implemented');
        }
    }
    fclose($client);
}
