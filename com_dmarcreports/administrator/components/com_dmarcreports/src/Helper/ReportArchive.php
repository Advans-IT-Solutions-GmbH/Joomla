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
     * hostile or corrupt attachment (aggregate reports are a few hundred KB).
     */
    private const MAX_BYTES = 33554432; // 32 MiB

    /**
     * Largest total uncompressed payload accepted from a single archive, so a
     * zip bomb packing many entries cannot exhaust memory one entry at a time.
     */
    private const MAX_TOTAL_BYTES = 134217728; // 128 MiB

    /**
     * Largest number of XML documents extracted from a single archive.
     */
    private const MAX_DOCUMENTS = 256;

    /**
     * Extract every XML document contained in a raw attachment payload.
     *
     * @param   string  $payload   The raw bytes of the attachment.
     * @param   string  $filename  The attachment file name (used as a hint).
     *
     * @return  string[]  A list of XML document strings (possibly empty).
     *
     * @throws  DecoderUnavailableException  When the payload is a recognised
     *          compressed container but the PHP extension needed to decode it
     *          (zlib for gzip, zip for zip) is unavailable, so the caller can
     *          defer the message instead of dropping a still-valid report.
     */
    public static function extract(string $payload, string $filename = ''): array
    {
        if ($payload === '') {
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

            $xml = self::gunzip($payload);

            return $xml === null ? [] : [self::clean($xml)];
        }

        if ($isZip) {
            if (!class_exists(\ZipArchive::class)) {
                throw new DecoderUnavailableException(
                    'The PHP zip extension is required to read zip-compressed DMARC reports.'
                );
            }

            return self::unzip($payload);
        }

        $trimmed = ltrim($payload);

        if (str_starts_with($trimmed, '<?xml') || stripos($trimmed, '<feedback') !== false) {
            // A raw document is its own uncompressed size, so bound it directly.
            if (\strlen($payload) > self::MAX_BYTES) {
                return [];
            }

            return [self::clean($payload)];
        }

        return [];
    }

    private static function gunzip(string $payload): ?string
    {
        if (!\function_exists('gzdecode')) {
            return null;
        }

        // Bound decompression itself (ceiling + 1 byte) so a gzip bomb cannot
        // expand to an unbounded size before the size check below runs.
        $data = @gzdecode($payload, self::MAX_BYTES + 1);

        if ($data === false || \strlen($data) > self::MAX_BYTES) {
            return null;
        }

        return $data;
    }

    /**
     * @return string[]
     */
    private static function unzip(string $payload): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }

        $tmp = tempnam(sys_get_temp_dir(), 'dmarc_zip_');

        if ($tmp === false) {
            return [];
        }

        $documents = [];
        $total     = 0;

        try {
            file_put_contents($tmp, $payload);

            $zip = new \ZipArchive();

            if ($zip->open($tmp) !== true) {
                return [];
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                if (\count($documents) >= self::MAX_DOCUMENTS) {
                    break;
                }

                $entry = $zip->statIndex($i);

                if ($entry === false || $entry['size'] > self::MAX_BYTES) {
                    continue;
                }

                // Bound the cumulative uncompressed size across all entries, so a
                // zip packing many small-but-numerous files cannot exhaust memory.
                $total += (int) $entry['size'];

                if ($total > self::MAX_TOTAL_BYTES) {
                    break;
                }

                if (!str_ends_with(strtolower($entry['name']), '.xml')) {
                    continue;
                }

                $content = $zip->getFromIndex($i);

                if (\is_string($content) && $content !== '') {
                    $documents[] = self::clean($content);
                }
            }

            $zip->close();
        } finally {
            @unlink($tmp);
        }

        return $documents;
    }

    private static function clean(string $xml): string
    {
        // Strip a UTF-8 byte order mark that would otherwise break the XML parser.
        return ltrim($xml, "\xEF\xBB\xBF");
    }
}
