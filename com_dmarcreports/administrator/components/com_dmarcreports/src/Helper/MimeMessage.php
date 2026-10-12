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
     * Hard caps applied to untrusted mailbox content before any part is
     * materialised. A crafted 32 MiB message can repeat a short boundary
     * millions of times or nest multiparts arbitrarily deep; without bounds the
     * segment array alone could dwarf the raw-message limit and exhaust memory.
     * A legitimate aggregate-report mail has a handful of parts and shallow
     * nesting, so these ceilings never reject a real report.
     */
    private const MAX_DEPTH = 20;
    private const MAX_PARTS = 1000;

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

        $boundary = self::boundary($contentType);

        if ($boundary === null) {
            return [];
        }

        // A single global budget is shared across the whole recursion so nested
        // multiparts cannot multiply the work past the overall part ceiling.
        $budget = self::MAX_PARTS;

        return self::walk($body, $boundary, 1, $budget);
    }

    /**
     * Read the boundary parameter from a Content-Type header. RFC 2045 allows a
     * boundary to be either a bare token or a quoted string, and a quoted value
     * may legally contain spaces (for example boundary="report part"). The two
     * forms are matched separately so a quoted boundary is not truncated at its
     * first space, which would otherwise split none of the parts and silently
     * drop the whole report.
     */
    private static function boundary(string $contentType): ?string
    {
        if (preg_match('/boundary="([^"]+)"/i', $contentType, $m)) {
            return $m[1];
        }

        if (preg_match('/boundary=([^";\s]+)/i', $contentType, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * @param   int  $depth   Current nesting depth (1 at the top-level body).
     * @param   int  $budget  Remaining global part budget, decremented in place.
     *
     * @return array<int, array{filename: string, content: string}>
     */
    private static function walk(string $body, string $boundary, int $depth, int &$budget): array
    {
        // Stop recursing past the nesting ceiling or once the shared part budget
        // is spent, so a deeply nested or part-flooded message degrades to an
        // empty result instead of exhausting memory.
        if ($depth > self::MAX_DEPTH || $budget <= 0) {
            return [];
        }

        $attachments = [];

        // RFC 2046 boundary delimiters only ever appear on their own line: the
        // literal "--boundary" must be followed by optional whitespace and then
        // the end of the line (optionally with a closing "--"). Splitting on a
        // bare "--boundary" substring would also cut on boundary-like text that
        // legitimately appears *inside* a raw or quoted-printable XML attachment
        // (e.g. a line such as "--boundary is part of the report body"),
        // truncating the report; the IMAP path would then mark the message
        // processed as unparseable. The pattern is newline-anchored (a leading
        // newline is prepended so a delimiter at the very start of the body is
        // matched too) and matches both the opening and the closing delimiter;
        // the epilogue left after the closing one carries no part headers and is
        // skipped below. The split is still capped by the remaining budget:
        // preg_split() allocates its whole array up front, so an unbounded split
        // on a short, heavily repeated boundary is the core exhaustion vector.
        $pattern  = '/\n--' . preg_quote($boundary, '/') . '(?:--)?[\t ]*(?=\n|$)/';
        $segments = preg_split($pattern, "\n" . $body, $budget + 1);

        if ($segments === false) {
            return [];
        }

        // Drop the preamble before the opening delimiter; it is never a part.
        array_shift($segments);

        foreach ($segments as $segment) {
            // Strip the remainder of the delimiter line (its trailing CR/LF and
            // any transport padding) before the part headers begin.
            $segment = ltrim($segment, "\r\n");

            if (trim($segment) === '') {
                continue;
            }

            if ($budget <= 0) {
                break;
            }

            $budget--;

            [$headers, $partBody] = self::split($segment);

            $contentType = $headers['content-type'] ?? '';
            $childBoundary = stripos($contentType, 'multipart/') !== false
                ? self::boundary($contentType)
                : null;

            if ($childBoundary !== null) {
                $attachments = array_merge($attachments, self::walk($partBody, $childBoundary, $depth + 1, $budget));
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

        // Some reporters attach the raw report as application/xml or text/xml
        // with no file name, relying on Content-Disposition: attachment alone.
        // Such a part is a report too, so XML media types count when the part is
        // flagged as an attachment (the disposition keeps inline human-readable
        // XML, which is not a report, from being picked up).
        $isAttachment = stripos($disposition, 'attachment') !== false;
        $isXml        = stripos($contentType, 'application/xml') !== false
            || stripos($contentType, 'text/xml') !== false;

        $looksLikeReport = $filename !== ''
            || stripos($contentType, 'application/gzip') !== false
            || stripos($contentType, 'application/zip') !== false
            || stripos($contentType, 'application/x-gzip') !== false
            || ($isXml && $isAttachment);

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
