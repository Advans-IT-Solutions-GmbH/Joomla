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
     * Extract every XML document contained in a raw attachment payload.
     *
     * @param   string  $payload   The raw bytes of the attachment.
     * @param   string  $filename  The attachment file name (used as a hint).
     *
     * @return  string[]  A list of XML document strings (possibly empty).
     */
    public static function extract(string $payload, string $filename = ''): array
    {
        if ($payload === '') {
            return [];
        }

        $name = strtolower($filename);

        // gzip magic number 0x1f 0x8b.
        if (str_starts_with($payload, "\x1f\x8b") || str_ends_with($name, '.gz')) {
            $xml = self::gunzip($payload);

            return $xml === null ? [] : [self::clean($xml)];
        }

        // zip magic number "PK\x03\x04".
        if (str_starts_with($payload, "PK\x03\x04") || str_ends_with($name, '.zip')) {
            return self::unzip($payload);
        }

        $trimmed = ltrim($payload);

        if (str_starts_with($trimmed, '<?xml') || stripos($trimmed, '<feedback') !== false) {
            return [self::clean($payload)];
        }

        return [];
    }

    private static function gunzip(string $payload): ?string
    {
        if (!\function_exists('gzdecode')) {
            return null;
        }

        $data = @gzdecode($payload);

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

        try {
            file_put_contents($tmp, $payload);

            $zip = new \ZipArchive();

            if ($zip->open($tmp) !== true) {
                return [];
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);

                if ($entry === false || $entry['size'] > self::MAX_BYTES) {
                    continue;
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
