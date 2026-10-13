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
 * Extracts DMARC aggregate report XML documents from the containers mail
 * providers use: gzip (.gz), zip (.zip) and raw XML. Detection is by magic
 * bytes first and file name second, so a mislabelled attachment still works.
 *
 * Like the parser this class has no Joomla dependency and never reaches the
 * network, which keeps it unit testable.
 *
 * @since  1.0.0
 */
final class ReportArchive
{
    /**
     * Largest single decompressed document accepted, to bound memory use on a
     * hostile or corrupt attachment (real aggregate reports are a few hundred
     * KB, so 8 MiB is already generous). This is the figure that drives XML
     * parser expansion: the caller parses one document at a time with SimpleXML,
     * which can hold several times the document's byte size in memory, so the
     * ceiling is kept well below a conservative PHP memory_limit.
     */
    private const MAX_BYTES = 8388608; // 8 MiB

    /**
     * Largest total uncompressed payload buffered from a single archive. The
     * extracted documents are returned as strings and the caller parses them
     * one at a time (extract -> parse -> release), so peak memory is this
     * buffered total plus a single document's parser expansion. Both terms are
     * bounded deliberately small so a zip bomb packing many entries cannot
     * accumulate enough XML to exhaust memory before parsing even begins.
     */
    private const MAX_TOTAL_BYTES = 16777216; // 16 MiB

    /**
     * Largest number of XML documents extracted from a single archive.
     */
    public const MAX_DOCUMENTS = 256;

    /**
     * Largest number of entries examined in a single archive. A zip can stay
     * within the byte and document limits while packing hundreds of thousands
     * of empty or non-XML entries; capping the entry count keeps mailbox
     * ingestion from spending unbounded time scanning a hostile archive.
     */
    private const MAX_ENTRIES = 4096;

    /**
     * Extract every XML document contained in a raw attachment payload.
     *
     * @param   string      $payload   The raw bytes of the attachment.
     * @param   string      $filename  The attachment file name (used as a hint).
     * @param   array|null  $stats     Optional out-parameter. When a variable is
     *          passed it is filled with diagnostics for the caller that needs to
     *          explain an empty result: 'reason' is one of '' (documents were
     *          extracted), 'empty' (no payload), 'format' (not a recognised or
     *          decodable report) or 'bomb' (a size/decompression limit tripped);
     *          'dropped' is the number of valid XML reports skipped because the
     *          archive held more than {@see self::MAX_DOCUMENTS}.
     *
     * @return  string[]  A list of XML document strings (possibly empty).
     *
     * @throws  DecoderUnavailableException  When the payload is a recognised
     *          compressed container but the PHP extension needed to decode it
     *          (zlib for gzip, zip for zip) is unavailable, so the caller can
     *          defer the message instead of dropping a still-valid report.
     * @throws  ArchiveExtractionException  When a temporary scratch file needed
     *          to read a zip container cannot be created or fully written, a
     *          transient host failure the caller must retry rather than skip.
     */
    public static function extract(string $payload, string $filename = '', ?array &$stats = null): array
    {
        $stats = ['reason' => '', 'dropped' => 0];

        if ($payload === '') {
            $stats['reason'] = 'empty';

            return [];
        }

        $name = strtolower($filename);

        // Determine the container from its magic bytes first, so a mislabelled
        // attachment (for example a real ZIP named "report.gz") is decoded by its
        // actual format instead of being discarded in the wrong branch. The
        // filename extension is only a fallback when no known signature matches.
        $isGzip = str_starts_with($payload, "\x1f\x8b");   // gzip magic 0x1f 0x8b
        $isZip  = str_starts_with($payload, "PK\x03\x04");  // zip magic "PK\x03\x04"

        if (!$isGzip && !$isZip) {
            $isGzip = str_ends_with($name, '.gz');
            $isZip  = str_ends_with($name, '.zip');
        }

        if ($isGzip) {
            if (!\function_exists('gzdecode')) {
                throw new DecoderUnavailableException(
                    'The PHP zlib extension is required to read gzip-compressed DMARC reports.'
                );
            }

            // Decompress through a bounded read path so an oversized gzip is
            // reported as a size-limit (bomb) while a corrupt stream is reported
            // as the wrong format — and, crucially, without ever materialising
            // more than MAX_BYTES + 1 uncompressed bytes. A zlib.inflate stream
            // filter lets the output be pulled in a single bounded read: a gzip
            // bomb is stopped at the ceiling instead of being expanded in full.
            $stream = fopen('php://temp', 'r+b');

            if ($stream === false) {
                // No scratch stream: treat as transient so the message is retried
                // rather than being misclassified as a bad upload.
                throw new ArchiveExtractionException(
                    'Unable to open a temporary stream to read a gzip-compressed DMARC report.'
                );
            }

            fwrite($stream, $payload);
            rewind($stream);

            if (@stream_filter_append($stream, 'zlib.inflate', STREAM_FILTER_READ, ['window' => 31]) === false) {
                fclose($stream);
                $stats['reason'] = 'format';

                return [];
            }

            // A corrupt deflate stream raises a warning from the filter rather
            // than returning an error code, so capture it to tell malformed input
            // apart from a genuinely oversized (but valid) report.
            $corrupt = false;
            set_error_handler(
                static function () use (&$corrupt): bool {
                    $corrupt = true;

                    return true;
                }
            );

            try {
                // Read at most MAX_BYTES + 1 decompressed bytes. The filter emits
                // at most one input block of output per internal step, so peak
                // memory stays bounded no matter how high the compression ratio.
                $data = stream_get_contents($stream, self::MAX_BYTES + 1);
            } finally {
                restore_error_handler();
                fclose($stream);
            }

            if ($data === false || $corrupt || $data === '') {
                // Not a decodable gzip stream: corrupt or not actually gzip.
                $stats['reason'] = 'format';

                return [];
            }

            if (\strlen($data) > self::MAX_BYTES) {
                $stats['reason'] = 'bomb';

                return [];
            }

            return [self::clean($data)];
        }

        if ($isZip) {
            if (!class_exists(\ZipArchive::class)) {
                throw new DecoderUnavailableException(
                    'The PHP zip extension is required to read zip-compressed DMARC reports.'
                );
            }

            return self::unzip($payload, $stats);
        }

        $trimmed = ltrim($payload);

        if (str_starts_with($trimmed, '<?xml') || stripos($trimmed, '<feedback') !== false) {
            // A raw document is its own uncompressed size, so bound it directly.
            if (\strlen($payload) > self::MAX_BYTES) {
                $stats['reason'] = 'bomb';

                return [];
            }

            return [self::clean($payload)];
        }

        $stats['reason'] = 'format';

        return [];
    }

    /**
     * @param   array  $stats  Diagnostics out-parameter shared with extract().
     *
     * @return string[]
     */
    private static function unzip(string $payload, array &$stats): array
    {
        if (!class_exists(\ZipArchive::class)) {
            $stats['reason'] = 'format';

            return [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_zip_');

        if ($tmp === false) {
            // A scratch file could not even be created. The payload may still be
            // a valid report, so signal a transient failure rather than an empty
            // result, letting the caller retry the same message on the next run.
            throw new ArchiveExtractionException(
                'Unable to create a temporary file to read a zip-compressed DMARC report.'
            );
        }

        $documents   = [];
        $total       = 0;
        $sizeLimited = false;

        try {
            $written = file_put_contents($tmp, $payload);

            if ($written === false || $written < \strlen($payload)) {
                // The scratch file was not written in full, so ZipArchive would
                // read a truncated archive and silently drop valid entries. Treat
                // this as transient so the message is retried, not skipped.
                throw new ArchiveExtractionException(
                    'Unable to write a temporary file to read a zip-compressed DMARC report.'
                );
            }

            $zip = new \ZipArchive();

            if ($zip->open($tmp) !== true) {
                $stats['reason'] = 'format';

                return [];
            }

            // Reject an archive with an implausible number of entries before
            // iterating: without this a zip within the byte and document limits
            // could still force hundreds of thousands of statIndex() calls.
            if ($zip->numFiles > self::MAX_ENTRIES) {
                $zip->close();
                $stats['reason'] = 'bomb';

                return [];
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (\count($documents) >= self::MAX_DOCUMENTS) {
                    // The per-archive document cap is reached. Count the valid XML
                    // reports still ahead so the caller can report how many were
                    // dropped instead of letting them disappear silently.
                    $stats['dropped'] += self::countRemainingXml($zip, $i);

                    break;
                }

                $entry = $zip->statIndex($i);

                if ($entry === false) {
                    continue;
                }

                // Only XML entries are ever read into memory, so only they may
                // consume the cumulative budget. Charging a large README or
                // signature file that is never materialised would let it push the
                // running total past the limit and abandon a valid report that
                // sits behind it in the archive.
                if (!str_ends_with(strtolower($entry['name']), '.xml')) {
                    continue;
                }

                // A single XML report larger than the per-document ceiling is a
                // size-limit skip, not a wrong-format upload. Record it so an
                // archive whose only reports are oversized is reported to the
                // operator as a size/bomb problem rather than "not a report".
                if ((int) $entry['size'] > self::MAX_BYTES) {
                    $sizeLimited = true;

                    continue;
                }

                // Bound the cumulative uncompressed size across the XML reports
                // that are actually extracted, so a zip packing many small report
                // files cannot exhaust memory. Skip — rather than stop at — an XML
                // entry that would overflow the budget, so a later, smaller report
                // in the same archive can still be processed.
                if ($total + (int) $entry['size'] > self::MAX_TOTAL_BYTES) {
                    $sizeLimited = true;

                    continue;
                }

                $total += (int) $entry['size'];

                $content = $zip->getFromIndex($i);

                if (\is_string($content) && $content !== '') {
                    $documents[] = self::clean($content);
                }
            }

            $zip->close();
        } finally {
            @unlink($tmp);
        }

        if ($documents === [] && $stats['reason'] === '') {
            // No usable XML report came out. Distinguish a size/bomb skip (every
            // candidate XML entry was over the per-document or cumulative ceiling)
            // from a genuine wrong-format upload, so the operator gets the right
            // guidance instead of a blanket "not a recognised report".
            $stats['reason'] = $sizeLimited ? 'bomb' : 'format';
        }

        return $documents;
    }

    /**
     * Count the XML entries from index $from onward that would themselves be
     * extractable, used to report how many reports were dropped once the
     * per-archive document cap is reached. Only entries that meet the same
     * per-entry criteria as the extraction loop (a non-empty .xml within the
     * per-document size ceiling) are counted, so the "dropped" figure reflects
     * real reports and does not over-count empty or oversized entries.
     */
    private static function countRemainingXml(\ZipArchive $zip, int $from): int
    {
        $dropped = 0;

        for ($i = $from; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);

            if ($entry === false) {
                continue;
            }

            if (!str_ends_with(strtolower($entry['name']), '.xml')) {
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

    private static function clean(string $xml): string
    {
        // Strip a UTF-8 byte order mark that would otherwise break the XML parser.
        return ltrim($xml, "\xEF\xBB\xBF");
    }
}
