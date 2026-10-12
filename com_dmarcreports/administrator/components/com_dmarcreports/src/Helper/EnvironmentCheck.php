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
 * Re-runnable environment and health checks surfaced in the backend System
 * Check view. Each check returns a status (ok | warning | error) and a
 * machine readable message code that the view localises. Nothing here aborts
 * the install; hard requirements are enforced by the installer preflight.
 *
 * @since  1.0.0
 */
final class EnvironmentCheck
{
    public const OK = 'ok';

    public const WARNING = 'warning';

    public const ERROR = 'error';

    /**
     * Run all checks.
     *
     * @return  array<int, array{id: string, status: string, params: array<int, mixed>}>
     */
    public static function run(): array
    {
        return [
            self::phpVersion(),
            self::requiredExtensions(),
            self::gzip(),
            self::zip(),
            self::dns(),
            self::sockets(),
            self::crypto(),
        ];
    }

    /**
     * Whether any check reports an error.
     *
     * @param   array<int, array{status: string}>  $results
     */
    public static function hasErrors(array $results): bool
    {
        foreach ($results as $result) {
            if (($result['status'] ?? '') === self::ERROR) {
                return true;
            }
        }

        return false;
    }

    private static function phpVersion(): array
    {
        $ok = version_compare(PHP_VERSION, '8.1', '>=');

        return self::result('php_version', $ok ? self::OK : self::ERROR, [PHP_VERSION]);
    }

    private static function requiredExtensions(): array
    {
        $missing = [];

        foreach (['simplexml', 'libxml', 'mbstring', 'openssl'] as $extension) {
            if (!\extension_loaded($extension)) {
                $missing[] = $extension;
            }
        }

        return self::result(
            'required_extensions',
            $missing === [] ? self::OK : self::ERROR,
            [implode(', ', $missing)]
        );
    }

    private static function gzip(): array
    {
        return self::result(
            'gzip',
            \function_exists('gzdecode') ? self::OK : self::WARNING,
            []
        );
    }

    private static function zip(): array
    {
        return self::result(
            'zip',
            class_exists(\ZipArchive::class) ? self::OK : self::WARNING,
            []
        );
    }

    private static function dns(): array
    {
        return self::result(
            'dns',
            \function_exists('dns_get_record') ? self::OK : self::WARNING,
            []
        );
    }

    private static function sockets(): array
    {
        return self::result(
            'sockets',
            \function_exists('stream_socket_client') ? self::OK : self::WARNING,
            []
        );
    }

    private static function crypto(): array
    {
        $ciphers = \function_exists('openssl_get_cipher_methods') ? openssl_get_cipher_methods() : [];
        $ok      = \in_array('aes-256-gcm', array_map('strtolower', $ciphers), true);

        return self::result('crypto', $ok ? self::OK : self::ERROR, []);
    }

    /**
     * @param   array<int, mixed>  $params
     *
     * @return  array{id: string, status: string, params: array<int, mixed>}
     */
    private static function result(string $id, string $status, array $params): array
    {
        return ['id' => $id, 'status' => $status, 'params' => $params];
    }
}
