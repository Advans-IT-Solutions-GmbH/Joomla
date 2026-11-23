<?php
/**
 * @package     Advans Installer
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Advans\Installer\V3;

use RuntimeException;

final class FileGuard
{
    /**
     * Compare candidate files with their destinations without changing or copying file contents.
     *
     * @param array<string, string> $files Absolute destination => absolute candidate source.
     * @return array{incoming: array<string, string>, differing: array<string, array{existing: string, incoming: string}>}
     */
    public static function compare(array $files): array
    {
        $incomingHashes = [];
        $differing = [];

        foreach ($files as $destination => $source) {
            if (!is_string($destination) || !is_string($source)) {
                throw new RuntimeException('File destinations and sources must be absolute path strings.');
            }

            $destination = self::normalizePath($destination);
            $source = self::normalizePath($source);
            self::assertNoSymlinkInPath($destination);

            if (!is_file($source) || !is_readable($source)) {
                throw new RuntimeException('The candidate source is not a readable regular file.');
            }

            $incoming = hash_file('sha256', $source);
            if (!is_string($incoming)) {
                throw new RuntimeException('The candidate source could not be hashed.');
            }
            $incomingHashes[$destination] = $incoming;

            if (!file_exists($destination)) {
                continue;
            }
            if (!is_file($destination) || !is_readable($destination)) {
                throw new RuntimeException('The destination is not a readable regular file.');
            }

            $existing = hash_file('sha256', $destination);
            if (!is_string($existing)) {
                throw new RuntimeException('The destination could not be hashed.');
            }
            if (!hash_equals($existing, $incoming)) {
                $differing[$destination] = ['existing' => $existing, 'incoming' => $incoming];
            }
        }

        return ['incoming' => $incomingHashes, 'differing' => $differing];
    }

    /** Normalize an absolute path used for destinations and metadata. */
    public static function normalize(string $path): string
    {
        return self::normalizePath($path);
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Paths must be non-empty absolute paths without NUL bytes.');
        }

        $isWindowsPath = preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
            || preg_match('/^\\\\\\\\[^\\\\\\/]+[\\\\\\/]+[^\\\\\\/]+/', $path) === 1
            || (DIRECTORY_SEPARATOR === '\\'
                && preg_match('/^\\/\\/[^\\\\\\/]+[\\\\\\/]+[^\\\\\\/]+/', $path) === 1);
        $isUnixPath = str_starts_with($path, '/');
        if (!$isWindowsPath && !$isUnixPath) {
            throw new RuntimeException('Paths must be absolute.');
        }

        $separator = $isWindowsPath ? '\\' : '/';
        $path = str_replace($isWindowsPath ? '/' : '\\', $separator, $path);
        if ($isWindowsPath && preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            $root = substr($path, 0, 3);
            $remainder = substr($path, 3);
        } elseif ($isWindowsPath) {
            $parts = explode($separator, trim($path, $separator));
            if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                throw new RuntimeException('UNC paths must include a server and share.');
            }
            $root = $separator . $separator . $parts[0] . $separator . $parts[1] . $separator;
            $remainder = implode($separator, array_slice($parts, 2));
        } else {
            $root = '/';
            $remainder = substr($path, 1);
        }

        $segments = [];
        foreach (explode($separator, $remainder) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new RuntimeException('Parent-directory segments are not allowed in paths.');
            }
            $segments[] = $segment;
        }

        $normalized = $root . implode($separator, $segments);
        if (DIRECTORY_SEPARATOR === '\\' && $isWindowsPath) {
            $normalized = strtolower($normalized);
        }

        return $normalized;
    }

    private static function assertNoSymlinkInPath(string $path): void
    {
        $separator = str_contains($path, '\\') ? '\\' : '/';
        if ($separator === '\\' && preg_match('/^[a-z]:\\\\/', $path) === 1) {
            $current = substr($path, 0, 3);
            $remainder = substr($path, 3);
        } elseif ($separator === '\\' && str_starts_with($path, '\\\\')) {
            $parts = explode('\\', trim($path, '\\'));
            $current = '\\\\' . $parts[0] . '\\' . $parts[1] . '\\';
            $remainder = implode('\\', array_slice($parts, 2));
        } else {
            $current = '/';
            $remainder = ltrim($path, '/');
        }

        $segments = explode($separator, $remainder);
        foreach ($segments as $index => $segment) {
            if ($segment === '') {
                continue;
            }
            $current = rtrim($current, '\\/') . $separator . $segment;
            if (is_link($current)) {
                throw new RuntimeException('Symbolic links are not allowed in destination paths.');
            }
            if ($index < count($segments) - 1 && file_exists($current) && !is_dir($current)) {
                throw new RuntimeException('A path ancestor is not a directory.');
            }
        }
    }
}
