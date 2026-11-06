<?php
/**
 * @package     Advans Installer
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Advans\Installer;

use JsonException;
use RuntimeException;

final class FileGuard
{
    private const BACKUP_HEADER = "<?php http_response_code(404); exit; ?>\n";

    /**
     * Inspect candidate files, preserving changed destinations before reporting conflicts.
     *
     * @param array<string, string> $files    Absolute destination => absolute candidate source.
     * @param array<string, array{existing: string, incoming: string}> $approved
     * @return array<int, array{destination: string, existing: string, incoming: string, backup: string, approved: bool}>
     */
    public static function inspect(array $files, string $backupRoot, array $approved = []): array
    {
        $entries = [];

        foreach ($files as $destination => $source) {
            if (!is_string($destination) || !is_string($source)) {
                throw new RuntimeException('File destinations and sources must be absolute path strings.');
            }

            $destination = self::normalizePath($destination);
            $source = self::normalizePath($source);
            self::assertNoSymlinkInPath($destination);

            $entries[] = [
                'destination' => $destination,
                'source'      => $source,
            ];
        }

        $backupRoot = self::normalizePath($backupRoot);
        self::assertNoSymlinkInPath($backupRoot);

        if (!is_dir($backupRoot)) {
            throw new RuntimeException('The backup root must be an existing directory.');
        }

        $conflicts = [];

        foreach ($entries as $entry) {
            $destination = $entry['destination'];
            $source = $entry['source'];

            if (!is_file($source) || !is_readable($source)) {
                throw new RuntimeException('The candidate source is not a readable regular file.');
            }

            $incoming = hash_file('sha256', $source);

            if (!is_string($incoming)) {
                throw new RuntimeException('The candidate source could not be hashed.');
            }

            self::assertNoSymlinkInPath($destination);

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

            if (hash_equals($existing, $incoming)) {
                continue;
            }

            $approvedEntry = $approved[$destination] ?? null;
            $isApproved = is_array($approvedEntry)
                && isset($approvedEntry['existing'], $approvedEntry['incoming'])
                && is_string($approvedEntry['existing'])
                && is_string($approvedEntry['incoming'])
                && hash_equals($existing, $approvedEntry['existing'])
                && hash_equals($incoming, $approvedEntry['incoming']);

            $backup = self::preserveDestination($destination, $existing, $backupRoot);

            $conflicts[] = [
                'destination' => $destination,
                'existing'    => $existing,
                'incoming'    => $incoming,
                'backup'      => $backup,
                'approved'    => $isApproved,
            ];
        }

        return $conflicts;
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
                throw new RuntimeException('Symbolic links are not allowed in destination or backup paths.');
            }

            if ($index < count($segments) - 1 && file_exists($current) && !is_dir($current)) {
                throw new RuntimeException('A path ancestor is not a directory.');
            }
        }
    }

    private static function preserveDestination(string $destination, string $expectedHash, string $backupRoot): string
    {
        $bytes = file_get_contents($destination);

        if (!is_string($bytes) || !hash_equals($expectedHash, hash('sha256', $bytes))) {
            throw new RuntimeException('The destination changed or could not be read while being backed up.');
        }

        $backupPath = rtrim($backupRoot, '\\/') . DIRECTORY_SEPARATOR
            . hash('sha256', $destination . "\0" . $expectedHash) . '.php';

        if (is_link($backupPath)) {
            throw new RuntimeException('The deterministic backup path is a symbolic link.');
        }

        if (file_exists($backupPath)) {
            self::verifyBackup($backupPath, $destination, $expectedHash);

            return $backupPath;
        }

        $payload = json_encode([
            'destination' => $destination,
            'sha256'      => $expectedHash,
            'content'     => base64_encode($bytes),
        ]);

        if (!is_string($payload)) {
            throw new RuntimeException('The destination backup could not be encoded.');
        }

        $handle = fopen($backupPath, 'x+b');

        if ($handle === false) {
            if (is_link($backupPath)) {
                throw new RuntimeException('The deterministic backup path became a symbolic link.');
            }

            if (file_exists($backupPath)) {
                self::verifyBackup($backupPath, $destination, $expectedHash);

                return $backupPath;
            }

            throw new RuntimeException('The destination backup could not be created exclusively.');
        }

        $contents = self::BACKUP_HEADER . $payload;
        $offset = 0;
        $writeSucceeded = true;

        while ($offset < strlen($contents)) {
            $written = fwrite($handle, substr($contents, $offset));

            if (!is_int($written) || $written <= 0) {
                $writeSucceeded = false;
                break;
            }

            $offset += $written;
        }

        if (!fflush($handle)) {
            $writeSucceeded = false;
        }

        if (!fclose($handle)) {
            $writeSucceeded = false;
        }

        if (!$writeSucceeded || $offset !== strlen($contents)) {
            unlink($backupPath);
            throw new RuntimeException('The destination backup could not be written completely.');
        }

        self::verifyBackup($backupPath, $destination, $expectedHash);

        return $backupPath;
    }

    public static function verifyBackup(string $backupPath, string $destination, string $existingHash): void
    {
        self::assertNoSymlinkInPath(self::normalizePath($backupPath));
        self::verifyBackupContents(
            self::normalizePath($backupPath),
            self::normalizePath($destination),
            $existingHash
        );
    }

    private static function verifyBackupContents(string $backupPath, string $destination, string $expectedHash): void
    {
        if (is_link($backupPath) || !is_file($backupPath) || !is_readable($backupPath)) {
            throw new RuntimeException('The existing backup is not a readable regular file.');
        }

        $contents = file_get_contents($backupPath);

        if (!is_string($contents) || !str_starts_with($contents, self::BACKUP_HEADER)) {
            throw new RuntimeException('The existing backup has an invalid guard or format.');
        }

        try {
            $record = json_decode(substr($contents, strlen(self::BACKUP_HEADER)), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The existing backup contains invalid JSON.', 0, $exception);
        }

        if (!is_array($record)
            || !isset($record['destination'], $record['sha256'], $record['content'])
            || !is_string($record['destination'])
            || !is_string($record['sha256'])
            || !is_string($record['content'])
            || !hash_equals($destination, $record['destination'])
            || !hash_equals($expectedHash, $record['sha256'])
        ) {
            throw new RuntimeException('The existing backup is not bound to this destination and content hash.');
        }

        $original = base64_decode($record['content'], true);

        if (!is_string($original) || !hash_equals($expectedHash, hash('sha256', $original))) {
            throw new RuntimeException('The existing backup content does not match its recorded hash.');
        }
    }
}
