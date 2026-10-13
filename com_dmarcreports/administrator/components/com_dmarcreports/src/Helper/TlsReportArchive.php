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
 * Extracts SMTP TLS Reporting (RFC 8460) JSON documents from the containers
 * reporters use: gzip (.json.gz), zip and raw JSON. TLS-RPT reports arrive in
 * the same mailbox as DMARC aggregate reports but carry a JSON body instead of
 * XML, so they need their own container decoder that yields JSON strings.
 *
 * The bounded-read discipline mirrors {@see ReportArchive} — magic-byte
 * detection, a per-document ceiling and a cumulative budget — but is kept
 * separate so the hardened DMARC/XML path is never altered to accommodate JSON.
 * Like the DMARC extractor it has no Joomla dependency and never reaches the
 * network, which keeps it unit testable.
 *
 * @since  1.1.0
 */
final class TlsReportArchive
{
    /**
     * Largest single decompressed JSON document accepted. TLS-RPT reports are a
     * few KB to a few hundred KB, so 8 MiB bounds a hostile payload generously.
     */
    private const MAX_BYTES = 8388608; // 8 MiB

    /**
     * Largest total uncompressed payload buffered from a single archive.
     */
    private const MAX_TOTAL_BYTES = 16777216; // 16 MiB

    /**
     * Largest number of JSON documents extracted from a single archive.
     */
    public const MAX_DOCUMENTS = 256;

    /**
     * Largest number of entries examined in a single archive.
     */
    private const MAX_ENTRIES = 4096;

    /**
     * Whether an attachment looks like a TLS-RPT report rather than a DMARC
     * aggregate report, decided by name first and a cheap content sniff second.
     * A TLS-RPT container is named "<org>!<domain>!<start>!<end>[!unique].json"
     * optionally with a .gz suffix (RFC 8460 §5.1), or carries a JSON body.
     */
    public static function looksLikeTlsRpt(string $payload, string $filename): bool
    {
        $name = strtolower($filename);

        if (str_contains($name, '.json')) {
            return true;
        }

        if (str_ends_with($name, '.xml') || str_ends_with($name, '.xml.gz') || str_ends_with($name, '.zip')) {
            return false;
        }

        // No decisive name: sniff the (possibly compressed) body. A gzip member
        // is decoded with a short bounded read purely to inspect its first
        // non-space byte; a raw body is inspected directly. A leading '{' marks
        // JSON, '<' marks XML.
        $probe = $payload;

        if (str_starts_with($payload, "\x1f\x8b") && \function_exists('gzdecode')) {
            $decoded = @gzdecode($payload);

            if (\is_string($decoded)) {
                $probe = $decoded;
            }
        }

        $probe = ltrim($probe);

        return $probe !== '' && $probe[0] === '{';
    }

    /**
     * Extract every JSON document contained in a raw attachment payload.
     *
     * @param   string      $payload   The raw bytes of the attachment.
     * @param   string      $filename  The attachment file name (used as a hint).
     * @param   array|null  $stats     Optional out-parameter filled with 'reason'
     *          ('' | 'empty' | 'format' | 'bomb') and 'dropped'.
     *
     * @return  string[]  A list of JSON document strings (possibly empty).
     *
     * @throws  DecoderUnavailableException  When the payload is a recognised
     *          compressed container but the PHP extension needed to decode it is
     *          unavailable.
     * @throws  ArchiveExtractionException  When a temporary scratch file needed
     *          to read a zip container cannot be created or fully written.
     */
    public static function extract(string $payload, string $filename = '', ?array &$stats = null): array
    {
        $stats = ['reason' => '', 'dropped' => 0];

        if ($payload === '') {
            $stats['reason'] = 'empty';

            return [];
        }

        $name = strtolower($filename);

        $isGzip = str_starts_with($payload, "\x1f\x8b");
        $isZip  = str_starts_with($payload, "PK\x03\x04");

        if (!$isGzip && !$isZip) {
            $isGzip = str_ends_with($name, '.gz');
            $isZip  = str_ends_with($name, '.zip');
        }

        if ($isGzip) {
            $data = self::gunzip($payload, $stats);

            if ($data === null) {
                return [];
            }

            return [$data];
        }

        if ($isZip) {
            return self::unzip($payload, $stats);
        }

        $trimmed = ltrim($payload);

        if ($trimmed !== '' && $trimmed[0] === '{') {
            if (\strlen($payload) > self::MAX_BYTES) {
                $stats['reason'] = 'bomb';

                return [];
            }

            return [$trimmed];
        }

        $stats['reason'] = 'format';

        return [];
    }

    /**
     * Decompress a gzip member through a bounded read, returning the decoded
     * JSON or null with a reason recorded in $stats. Mirrors the hardened read
     * path used for DMARC gzip so a TLS-RPT bomb is stopped at the ceiling.
     *
     * @param   array  $stats  Diagnostics out-parameter shared with extract().
     */
    private static function gunzip(string $payload, array &$stats): ?string
    {
        if (!\function_exists('gzdecode')) {
            throw new DecoderUnavailableException(
                'The PHP zlib extension is required to read gzip-compressed TLS-RPT reports.'
            );
        }

        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new ArchiveExtractionException(
                'Unable to open a temporary stream to read a gzip-compressed TLS-RPT report.'
            );
        }

        $written = fwrite($stream, $payload);

        if ($written === false || $written < \strlen($payload)) {
            fclose($stream);

            throw new ArchiveExtractionException(
                'Unable to buffer a gzip-compressed TLS-RPT report for decompression.'
            );
        }

        rewind($stream);

        if (@stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]) === false) {
            fclose($stream);
            $stats['reason'] = 'format';

            return null;
        }

        $corrupt = false;
        set_error_handler(
            static function () use (&$corrupt): bool {
                $corrupt = true;

                return true;
            }
        );

        try {
            $data = stream_get_contents($stream, self::MAX_BYTES + 1);
        } finally {
            restore_error_handler();
            fclose($stream);
        }

        if ($data === false || $corrupt || $data === '') {
            $stats['reason'] = 'format';

            return null;
        }

        if (\strlen($data) > self::MAX_BYTES) {
            $stats['reason'] = 'bomb';

            return null;
        }

        $data = ltrim($data, "\xEF\xBB\xBF");

        if (ltrim($data) === '' || ltrim($data)[0] !== '{') {
            $stats['reason'] = 'format';

            return null;
        }

        return $data;
    }

    /**
     * @param   array  $stats  Diagnostics out-parameter shared with extract().
     *
     * @return string[]
     */
    private static function unzip(string $payload, array &$stats): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new DecoderUnavailableException(
                'The PHP zip extension is required to read zip-compressed TLS-RPT reports.'
            );
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_tlsrpt_');

        if ($tmp === false) {
            throw new ArchiveExtractionException(
                'Unable to create a temporary file to read a zip-compressed TLS-RPT report.'
            );
        }

        $documents   = [];
        $total       = 0;
        $sizeLimited = false;

        try {
            $bytes = file_put_contents($tmp, $payload);

            if ($bytes === false || $bytes < \strlen($payload)) {
                throw new ArchiveExtractionException(
                    'Unable to write a temporary file to read a zip-compressed TLS-RPT report.'
                );
            }

            $zip = new \ZipArchive();

            if ($zip->open($tmp) !== true) {
                $stats['reason'] = 'format';

                return [];
            }

            if ($zip->numFiles > self::MAX_ENTRIES) {
                $zip->close();
                $stats['reason'] = 'bomb';

                return [];
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (\count($documents) >= self::MAX_DOCUMENTS) {
                    $stats['dropped'] += self::countRemainingJson($zip, $i);

                    break;
                }

                $entry = $zip->statIndex($i);

                if ($entry === false) {
                    continue;
                }

                if (!str_contains(strtolower($entry['name']), '.json')) {
                    continue;
                }

                if ((int) $entry['size'] > self::MAX_BYTES) {
                    $sizeLimited = true;

                    continue;
                }

                if ($total + (int) $entry['size'] > self::MAX_TOTAL_BYTES) {
                    $sizeLimited = true;

                    continue;
                }

                $total  += (int) $entry['size'];
                $content = $zip->getFromIndex($i);

                if (\is_string($content) && ltrim($content) !== '') {
                    $documents[] = ltrim($content, "\xEF\xBB\xBF");
                }
            }

            $zip->close();
        } finally {
            @unlink($tmp);
        }

        if ($documents === [] && $stats['reason'] === '') {
            $stats['reason'] = $sizeLimited ? 'bomb' : 'format';
        }

        return $documents;
    }

    /**
     * Count the JSON entries from index $from onward that would be extractable,
     * used to report how many reports were dropped once the document cap hits.
     */
    private static function countRemainingJson(\ZipArchive $zip, int $from): int
    {
        $dropped = 0;

        for ($i = $from; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);

            if ($entry === false) {
                continue;
            }

            if (!str_contains(strtolower($entry['name']), '.json')) {
                continue;
            }

            $size = (int) $entry['size'];

            if ($size <= 0 || $size > self::MAX_BYTES) {
                continue;
            }

            $dropped++;
        }

        return $dropped;
    }
}
