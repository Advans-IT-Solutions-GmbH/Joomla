<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\AppointmentsConflict\V1;

defined('_JEXEC') or die;

use Advans\Installer\V2\Destinations;
use Advans\Installer\V2\FileGuard;
use Advans\Installer\V2\Knowledge;
use Advans\Installer\V2\State;

/**
 * Bind Appointments review and recovery to the exact package and destination bytes.
 */
final class Guard
{
    public const REVIEW = 'recovery/review.php';

    /**
     * @param array<string, string> $files Destination to staged source.
     * @param array{prefixes: list<string>, files: list<string>, languages: list<string>} $owned
     * @return list<array{destination: string, existing: string, incoming: string, backup: string}>
     */
    public static function inspect(array $files, array $owned, Knowledge $knowledge, string $state): array
    {
        $comparison = FileGuard::compare($files);
        $conflicts = [];

        foreach ($comparison['differing'] as $destination => $hashes) {
            if (!Destinations::isOwned($destination, $owned) || $knowledge->isKnown($destination, $hashes['existing'])) {
                continue;
            }

            State::directory($state . '/recovery/backups');
            $conflicts[] = [
                'destination' => $destination,
                'existing' => $hashes['existing'],
                'incoming' => $hashes['incoming'],
                'backup' => FileGuard::backup($destination, $hashes['existing'], $state . '/recovery/backups'),
            ];
        }

        return $conflicts;
    }

    /**
     * Only copy inputs used by one destination and not read as installer metadata may be
     * substituted. Joomla still gets the original manifest and owns the installed path.
     */
    public static function canKeep(string $destination, string $candidate, array $files): bool
    {
        $relative = Knowledge::relative($destination);
        $leaf = basename(str_replace('\\', '/', $candidate));
        if (str_contains(str_replace('\\', '/', $candidate), '/.advans-operations/')
            || !preg_match('#^(?:(?:administrator/)?components/com_appointments|administrator/modules/mod_appointments|media/com_appointments|plugins/[^/]+/[^/]+)/(?:src|tmpl|forms|css|js)/#D', $relative)
            || preg_match('/\.(?:php|xml|css|js)$/iD', $leaf) !== 1) {
            return false;
        }
        if (in_array(strtolower($relative), [
            'administrator/components/com_appointments/src/helper/defaulttemplates.php',
            'administrator/components/com_appointments/src/service/close/queue.php',
        ], true)) {
            return false;
        }

        $candidate = FileGuard::normalize($candidate);
        $uses = 0;
        foreach ($files as $source) {
            if (FileGuard::normalize($source) === $candidate && ++$uses > 1) {
                return false;
            }
        }

        return $uses === 1;
    }

    public static function review(string $state, string $version, array $conflicts, array $files, string $manifestHash = ''): array
    {
        $sources = array_combine(array_map([FileGuard::class, 'normalize'], array_keys($files)), array_values($files));
        $rows = [];
        foreach ($conflicts as $row) {
            $candidate = $sources[$row['destination']];
            $row['keep'] = self::canKeep($row['destination'], $candidate, $files);
            $row['remove'] = str_contains(str_replace('\\', '/', $candidate), '/.advans-operations/');
            $id = hash('sha256', $row['destination'] . "\0" . $row['existing'] . "\0" . $row['incoming']);
            $rows[$id] = $row;
        }
        ksort($rows);
        $id = hash('sha256', json_encode([$version, $manifestHash, $rows], JSON_THROW_ON_ERROR));
        $current = self::current($state);
        if (($current['id'] ?? null) === $id) {
            return $current;
        }

        $review = ['id' => $id, 'version' => $version, 'rows' => $rows, 'choices' => []];
        State::write($state . '/' . self::REVIEW, $review);

        return $review;
    }

    public static function current(string $state): array
    {
        $file = $state . '/' . self::REVIEW;

        return file_exists($file) || is_link($file) ? State::read($file) : [];
    }

    public static function decide(string $state, string $plan, string $id, string $choice): void
    {
        $current = self::current($state);
        $row = $current['rows'][$id] ?? null;
        if (($current['id'] ?? null) !== $plan || !is_array($row)
            || !self::validChoice($row, $choice) || self::liveHash($row) !== $row['existing']) {
            throw new \RuntimeException('The file or review has changed, or the choice is not supported.');
        }
        self::backupBytes($row);
        $current['choices'][$id] = $choice;
        State::write($state . '/' . self::REVIEW, $current);
    }

    public static function validChoice(array $row, mixed $choice): bool
    {
        return $choice === 'replace' || ($choice === 'keep' && $row['keep']);
    }

    /** Stage only package inputs. Keep the unmodified hashes in the caller's installer ledger. */
    public static function apply(array $review, array $files): void
    {
        $sources = array_combine(array_map([FileGuard::class, 'normalize'], array_keys($files)), array_values($files));
        foreach ($review['rows'] as $id => $row) {
            $source = $sources[$row['destination']] ?? '';
            $choice = $review['choices'][$id] ?? null;
            if ($source === '' || !self::validChoice($row, $choice)
                || self::liveHash($row) !== $row['existing']
                || hash_file('sha256', $source) !== $row['incoming']) {
                throw new \RuntimeException('A reviewed file changed before installation.');
            }
            $bytes = self::backupBytes($row);
            if ($choice === 'keep') {
                if (!self::canKeep($row['destination'], $source, $files) || is_link($source)
                    || file_put_contents($source, $bytes, LOCK_EX) !== strlen($bytes)
                    || hash_file('sha256', $source) !== $row['existing']) {
                    throw new \RuntimeException('Cannot stage the selected local bytes.');
                }
            }
        }
    }

    public static function bundledFiles(string $root, array $files): array
    {
        $prefix = str_replace('\\', '/', FileGuard::normalize($root)) . '/';

        return array_filter($files, static fn(string $source): bool =>
            str_starts_with(str_replace('\\', '/', FileGuard::normalize($source)), $prefix));
    }

    public static function verifyInstalled(array $review): void
    {
        foreach ($review['rows'] ?? [] as $id => $row) {
            if ($row['remove']) {
                continue;
            }
            $expected = $review['choices'][$id] === 'keep' ? $row['existing'] : $row['incoming'];
            if (self::liveHash($row) !== $expected) {
                throw new \RuntimeException('Joomla did not install the reviewed bytes at the expected destination.');
            }
        }
    }

    public static function liveHash(array $row): ?string
    {
        $destination = $row['destination'];
        clearstatcache(true, $destination);
        $checked = FileGuard::compare([$destination => $row['backup']]);
        if (!file_exists($destination)) {
            return null;
        }

        $hash = $checked['differing'][$destination]['existing'] ?? $checked['incoming'][$destination];

        return $hash;
    }

    public static function backupBytes(array $row): string
    {
        $bytes = FileGuard::restoreBytes($row['backup'], $row['destination'], $row['existing']);
        if (!hash_equals($row['existing'], hash('sha256', $bytes))) {
            throw new \RuntimeException('The backup changed while being read.');
        }

        return $bytes;
    }

    /** @return resource */
    public static function lock(string $state)
    {
        $directory = $state . '/recovery';
        State::directory($directory);
        $file = $directory . '/lock.php';
        if (is_link($file) || (file_exists($file) && !is_file($file))) {
            throw new \RuntimeException('Invalid Appointments recovery lock.');
        }
        $handle = fopen($file, 'c+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open Appointments recovery lock.');
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another Appointments installation or recovery operation is running.');
        }

        return $handle;
    }

    public static function journal(string $state, array $review): void
    {
        // Consume before copying; a crashed attempt never supplies consent for another update.
        State::write($state . '/recovery/attempt.php', $review);
        State::write($state . '/' . self::REVIEW, []);
    }

    public static function attempt(string $state): array
    {
        $file = $state . '/recovery/attempt.php';

        return file_exists($file) || is_link($file) ? State::read($file) : [];
    }

    public static function restore(string $state, string $plan, string $id, string $expected): void
    {
        $attempt = self::attempt($state);
        $row = $attempt['rows'][$id] ?? null;
        if (($attempt['id'] ?? null) !== $plan || !is_array($row) || !$row['keep']
            || ($expected !== '' && !hash_equals($row['incoming'], $expected))
            || self::liveHash($row) !== ($expected === '' ? null : $expected)) {
            throw new \RuntimeException('The destination has changed or cannot be restored in place.');
        }
        $bytes = self::backupBytes($row);
        $destination = $row['destination'];
        $temporaryDirectory = realpath(sys_get_temp_dir());
        $siteRoot = realpath(JPATH_ROOT);
        $directory = realpath(dirname($destination));
        if ($temporaryDirectory === false || $siteRoot === false || $directory === false
            || $temporaryDirectory === $siteRoot || str_starts_with($temporaryDirectory, $siteRoot . DIRECTORY_SEPARATOR)
            || stat($temporaryDirectory)['dev'] !== stat($directory)['dev']) {
            throw new \RuntimeException('Recovery requires a private temporary directory on the same filesystem.');
        }
        $temporary = $temporaryDirectory . DIRECTORY_SEPARATOR . 'appointments-' . bin2hex(random_bytes(16));
        $mask = umask(0077);
        try {
            $handle = fopen($temporary, 'xb');
        } finally {
            umask($mask);
        }
        if ($handle === false) {
            throw new \RuntimeException('Cannot stage the restoration.');
        }
        try {
            try {
                if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                    throw new \RuntimeException('Cannot write the complete restoration.');
                }
            } finally {
                fclose($handle);
            }
            $mode = $expected === '' ? 0644 : fileperms($destination);
            if ($mode === false || !chmod($temporary, $mode & 0777)) {
                throw new \RuntimeException('Cannot set the restored file permissions.');
            }
            if (hash_file('sha256', $temporary) !== $row['existing']
                || self::liveHash($row) !== ($expected === '' ? null : $expected)) {
                throw new \RuntimeException('The staged file or destination changed before recovery.');
            }
            // link() publishes a missing file atomically without replacing a newly created file.
            if (!($expected === '' ? link($temporary, $destination) : rename($temporary, $destination))) {
                throw new \RuntimeException('Cannot publish the restoration.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
