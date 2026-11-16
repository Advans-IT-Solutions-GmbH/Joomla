<?php

final class AppointmentsMail
{
    public static function snapshot(): array
    {
        return glob('/tmp/appointments-mail/*.eml') ?: [];
    }

    public static function messagesSince(array $before): array
    {
        $messages = [];
        foreach (array_diff(self::snapshot(), $before) as $file) {
            $mime = file_get_contents($file);
            if ($mime === false) {
                throw new RuntimeException('A captured MIME message cannot be read.');
            }
            $messages[] = $mime;
        }

        return $messages;
    }

    /**
     * The decoded content of every text/calendar part of a message, in order.
     *
     * @return list<string>
     */
    public static function calendars(string $mime): array
    {
        return self::parts($mime, 'text/calendar');
    }

    /**
     * The decoded content of every part of $type (such as text/html) of a message, in order.
     *
     * @return list<string>
     */
    public static function parts(string $mime, string $type): array
    {
        $calendars = [];
        foreach (preg_split('/\r?\n--[^\r\n]+(?:\r?\n|$)/', $mime) as $part) {
            $pieces = preg_split('/\r?\n\r?\n/', $part, 2);
            if (count($pieces) !== 2 || preg_match('/^Content-Type:\s*' . preg_quote($type, '/') . '\b/mi', preg_replace('/\r?\n[ \t]+/', ' ', $pieces[0])) !== 1) {
                continue;
            }
            if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $pieces[0])) {
                $content = base64_decode(preg_replace('/\s+/', '', trim($pieces[1])), true);
            } elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $pieces[0])) {
                $content = quoted_printable_decode($pieces[1]);
            } else {
                $content = $pieces[1];
            }
            if (!is_string($content)) {
                throw new RuntimeException('A captured ' . $type . ' part cannot be decoded.');
            }
            // PHPMailer ends lines of an unencoded part with the local line end for sendmail (the MTA
            // writes CRLF on the wire). The calendar is compared in its RFC 5545 form, CRLF.
            $calendars[] = preg_replace('/\r?\n/', "\r\n", rtrim($content, "\r\n")) . "\r\n";
        }

        return $calendars;
    }

    /**
     * Whether a message carries its invitation as mail clients present one: exactly one text/calendar
     * part with the method, inside multipart/alternative next to text/plain and text/html, and no
     * calendar as an attachment (no application/ics, no invite.ics file name).
     */
    public static function isInvitation(string $mime, string $method): bool
    {
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', $mime);

        return preg_match('/^Content-Type:\s*multipart\/alternative;/mi', $headers) === 1
            && preg_match_all('/^Content-Type:\s*text\/calendar\b/mi', $headers) === 1
            && preg_match('/^Content-Type:\s*text\/calendar;\s*method=' . preg_quote($method, '/') . ';/mi', $headers) === 1
            && preg_match('/^Content-Type:\s*text\/plain\b/mi', $headers) === 1
            && preg_match('/^Content-Type:\s*text\/html\b/mi', $headers) === 1
            && stripos($headers, 'application/ics') === false && stripos($headers, 'invite.ics') === false
            && count(self::calendars($mime)) === 1;
    }

    public static function decoded(string $mime): string
    {
        $decoded = $mime;
        foreach (preg_split('/\r?\n--[^\r\n]+(?:\r?\n|$)/', $mime) as $part) {
            $pieces = preg_split('/\r?\n\r?\n/', $part, 2);
            if (count($pieces) !== 2) {
                continue;
            }
            if (preg_match('/Content-Transfer-Encoding:\s*base64/i', $pieces[0])) {
                $content = base64_decode(preg_replace('/\s+/', '', trim($pieces[1])), true);
                if ($content === false) {
                    throw new RuntimeException('A captured MIME attachment cannot be decoded.');
                }
                $decoded .= "\n" . $content;
            } elseif (preg_match('/Content-Transfer-Encoding:\s*quoted-printable/i', $pieces[0])) {
                $decoded .= "\n" . quoted_printable_decode($pieces[1]);
            }
        }

        // An unencoded invitation (the text/calendar alternative) once more in its CRLF form, so
        // content-line checks hold whatever line end the transport used (calendars()).
        foreach (self::calendars($mime) as $calendar) {
            if (!str_contains($decoded, $calendar)) {
                $decoded .= "\n" . $calendar;
            }
        }

        return $decoded;
    }
}
