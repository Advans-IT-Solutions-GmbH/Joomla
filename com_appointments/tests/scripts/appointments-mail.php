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

        return $decoded;
    }
}
