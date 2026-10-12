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
 * A minimal, dependency free RFC 822 / MIME reader. It exists only to pull the
 * report attachments out of the raw messages the IMAP client fetches, so it
 * walks multipart bodies and decodes the transfer encodings that DMARC
 * reporters actually use. It is not a general purpose mail parser.
 *
 * @since  1.0.0
 */
final class MimeMessage
{
    /**
     * Extract every decoded attachment from a raw message.
     *
     * @param   string  $raw  The raw RFC 822 message.
     *
     * @return  array<int, array{filename: string, content: string}>
     */
    public static function attachments(string $raw): array
    {
        $raw = str_replace("\r\n", "\n", $raw);

        [$headers, $body] = self::split($raw);

        $contentType = $headers['content-type'] ?? 'text/plain';

        if (stripos($contentType, 'multipart/') === false) {
            $part = self::leaf($headers, $body);

            return $part === null ? [] : [$part];
        }

        if (!preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $m)) {
            return [];
        }

        return self::walk($body, $m[1]);
    }

    /**
     * @return array<int, array{filename: string, content: string}>
     */
    private static function walk(string $body, string $boundary): array
    {
        $attachments = [];
        $segments    = explode('--' . $boundary, $body);

        foreach ($segments as $segment) {
            $segment = ltrim($segment, "\n");
            $segment = preg_replace('/^-+\n?/', '', $segment) ?? $segment;

            if (trim($segment) === '') {
                continue;
            }

            [$headers, $partBody] = self::split($segment);

            $contentType = $headers['content-type'] ?? '';

            if (stripos($contentType, 'multipart/') !== false
                && preg_match('/boundary="?([^";\s]+)"?/i', $contentType, $m)) {
                $attachments = array_merge($attachments, self::walk($partBody, $m[1]));
                continue;
            }

            $leaf = self::leaf($headers, $partBody);

            if ($leaf !== null) {
                $attachments[] = $leaf;
            }
        }

        return $attachments;
    }

    /**
     * @param   array<string, string>  $headers
     *
     * @return  array{filename: string, content: string}|null
     */
    private static function leaf(array $headers, string $body): ?array
    {
        $disposition = $headers['content-disposition'] ?? '';
        $contentType = $headers['content-type'] ?? '';
        $filename    = self::filename($disposition) ?: self::filename($contentType);

        $looksLikeReport = $filename !== ''
            || stripos($contentType, 'application/gzip') !== false
            || stripos($contentType, 'application/zip') !== false
            || stripos($contentType, 'application/x-gzip') !== false;

        if (!$looksLikeReport) {
            return null;
        }

        $encoding = strtolower(trim($headers['content-transfer-encoding'] ?? ''));
        $content  = self::decode($body, $encoding);

        if ($content === '') {
            return null;
        }

        return ['filename' => $filename !== '' ? $filename : 'report', 'content' => $content];
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private static function split(string $segment): array
    {
        $pos = strpos($segment, "\n\n");

        if ($pos === false) {
            return [[], $segment];
        }

        $rawHeaders = substr($segment, 0, $pos);
        $body       = substr($segment, $pos + 2);

        // Unfold folded header lines (continuation lines start with whitespace).
        $rawHeaders = preg_replace('/\n[ \t]+/', ' ', $rawHeaders) ?? $rawHeaders;

        $headers = [];

        foreach (explode("\n", $rawHeaders) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        return [$headers, $body];
    }

    private static function filename(string $header): string
    {
        if (preg_match('/(?:file)?name\*?=(?:"([^"]+)"|([^";\s]+))/i', $header, $m)) {
            return trim($m[1] !== '' ? $m[1] : ($m[2] ?? ''));
        }

        return '';
    }

    private static function decode(string $body, string $encoding): string
    {
        return match ($encoding) {
            'base64'           => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', true),
            'quoted-printable' => quoted_printable_decode($body),
            default            => $body,
        };
    }
}
