<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\AppointmentsConflict\V2;

defined('_JEXEC') or die;

use Advans\Installer\V3\Destinations;
use Advans\Installer\V3\FileGuard;
use Advans\Installer\V3\Knowledge;
use Advans\Installer\V3\State;

final class Guard
{
    public const REVIEW = 'recovery/review.php';
    private const MAX_TRANSFER_BYTES = 16777216;

    /**
     * @param array<string, string> $files Destination to staged source.
     * @param array{prefixes: list<string>, files: list<string>, languages: list<string>} $owned
     * @return list<array{destination: string, existing: string, incoming: string}>
     */
    public static function inspect(array $files, array $owned, Knowledge $knowledge): array
    {
        $comparison = FileGuard::compare($files);
        $conflicts = [];

        foreach ($comparison['differing'] as $destination => $hashes) {
            if (!Destinations::isOwned($destination, $owned) || $knowledge->isKnown($destination, $hashes['existing'])) {
                continue;
            }

            $conflicts[] = [
                'destination' => $destination,
                'existing' => $hashes['existing'],
                'incoming' => $hashes['incoming'],
            ];
        }

        return $conflicts;
    }

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
            $candidate = $sources[$row['destination']] ?? '';
            if ($candidate === '') {
                throw new \RuntimeException('A reviewed conflict is not part of this package.');
            }
            $row['keep'] = self::canKeep($row['destination'], $candidate, $files);
            $row['remove'] = str_contains(str_replace('\\', '/', $candidate), '/.advans-operations/');
            $row['downloaded'] = false;
            $rows[hash('sha256', $row['destination'] . "\0" . $row['existing'] . "\0" . $row['incoming'])] = $row;
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

    public static function download(string $state, string $plan, string $id): array
    {
        $review = self::current($state);
        $row = $review['rows'][$id] ?? null;
        if (($review['id'] ?? null) !== $plan || !is_array($row) || !self::validRow($id, $row)
            || self::liveHash($row) !== $row['existing']) {
            throw new \RuntimeException('The file or review has changed; request the update again.');
        }
        $size = filesize($row['destination']);
        $bytes = file_get_contents($row['destination']);
        if (!is_int($size) || $size > self::MAX_TRANSFER_BYTES || !is_string($bytes)
            || strlen($bytes) !== $size || !hash_equals($row['existing'], hash('sha256', $bytes))) {
            throw new \RuntimeException('The local file cannot be safely downloaded.');
        }

        $review['rows'][$id]['downloaded'] = true;
        State::write($state . '/' . self::REVIEW, $review);

        return ['bytes' => $bytes, 'filename' => basename(str_replace('\\', '/', $row['destination']))];
    }

    public static function decide(string $state, string $plan, string $id, string $choice, bool $confirmed): void
    {
        $current = self::current($state);
        $row = $current['rows'][$id] ?? null;
        if (($current['id'] ?? null) !== $plan || !is_array($row) || !self::validRow($id, $row)
            || $choice !== 'apply' || !$confirmed || empty($row['downloaded'])
            || self::liveHash($row) !== $row['existing']) {
            throw new \RuntimeException('Download and verify the local file before approving this update.');
        }

        $current['choices'][$id] = $choice;
        State::write($state . '/' . self::REVIEW, $current);
    }

    public static function validChoice(array $row, mixed $choice): bool
    {
        return $choice === 'apply' && !empty($row['downloaded']);
    }

    public static function bundledFiles(string $root, array $files): array
    {
        $prefix = str_replace('\\', '/', FileGuard::normalize($root)) . '/';

        return array_filter($files, static fn(string $source): bool =>
            str_starts_with(str_replace('\\', '/', FileGuard::normalize($source)), $prefix));
    }

    public static function journal(string $state, array $review): void
    {
        $review['installed'] = false;
        State::write($state . '/recovery/attempt.php', $review);
        State::write($state . '/' . self::REVIEW, []);
    }

    public static function verifyInstalled(array $review, string $state): void
    {
        if (empty($review['rows'])) {
            return;
        }

        foreach ($review['rows'] ?? [] as $id => $row) {
            $current = self::liveHash($row);
            if ($row['remove'] ? $current !== null : $current !== $row['incoming']) {
                throw new \RuntimeException('Joomla did not install the reviewed bytes at the expected destination.');
            }
        }
        $review['installed'] = true;
        State::write($state . '/recovery/attempt.php', $review);
    }

    public static function liveHash(array $row): ?string
    {
        $destination = FileGuard::normalize($row['destination']);
        if (!self::safeDestination($destination)) {
            throw new \RuntimeException('The reviewed destination is outside Appointments.');
        }
        clearstatcache(true, $destination);
        if (!file_exists($destination) && !is_link($destination)) {
            return null;
        }

        return FileGuard::compare([$destination => $destination])['incoming'][$destination];
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

    public static function attempt(string $state): array
    {
        $file = $state . '/recovery/attempt.php';

        return file_exists($file) || is_link($file) ? State::read($file) : [];
    }

    public static function restoreUpload(string $state, string $plan, string $id, string $expected, string $upload): void
    {
        $attempt = self::attempt($state);
        $row = $attempt['rows'][$id] ?? null;
        if (($attempt['id'] ?? null) !== $plan || !is_array($row)
            || !self::validRow($id, $row) || empty($row['keep'])
            || ($expected !== '' && !hash_equals($row['incoming'], $expected))
            || self::liveHash(['destination' => $row['destination']]) !== ($expected === '' ? null : $expected)
            || is_link($upload) || !is_file($upload) || !is_readable($upload)) {
            throw new \RuntimeException('The destination or uploaded local copy is not eligible for recovery.');
        }

        $size = filesize($upload);
        if (!is_int($size) || $size > self::MAX_TRANSFER_BYTES
            || hash_file('sha256', $upload) !== $row['existing']) {
            throw new \RuntimeException('The uploaded file does not match the reviewed local file.');
        }

        $destination = FileGuard::normalize($row['destination']);
        $directory = dirname($destination);

        $permissions = $expected === '' ? 0644 : fileperms($destination);
        if (!is_int($permissions)) {
            throw new \RuntimeException('Cannot read the existing file permissions.');
        }
        $mode = $permissions & 0777;
        if (hash_file('sha256', $upload) !== $row['existing']
            || self::liveHash(['destination' => $destination]) !== ($expected === '' ? null : $expected)) {
            throw new \RuntimeException('The upload or destination changed before recovery.');
        }

        $temporaryDirectory = $directory . DIRECTORY_SEPARATOR . '.appointments-recovery-' . bin2hex(random_bytes(16));

        if (!mkdir($temporaryDirectory, 0700)) {
            throw new \RuntimeException('Cannot create private recovery staging.');
        }

        $temporary = $temporaryDirectory . DIRECTORY_SEPARATOR . 'restore.tmp';
        $input = fopen($upload, 'rb');
        $output = fopen($temporary, 'xb');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (file_exists($temporary) && !unlink($temporary)) {
                throw new \RuntimeException('Cannot remove an incomplete recovery upload.');
            }
            if (!rmdir($temporaryDirectory)) {
                throw new \RuntimeException('Cannot remove private recovery staging.');
            }

            throw new \RuntimeException('Cannot stage the uploaded local copy for recovery.');
        }

        try {
            $copied = stream_copy_to_stream($input, $output);

            if ($copied !== $size || !fflush($output)) {
                throw new \RuntimeException('Cannot stage the complete uploaded local copy.');
            }
            fclose($input);
            fclose($output);
            $temporaryHash = hash_file('sha256', $temporary);

            if (!chmod($temporary, $mode) || !is_string($temporaryHash) || !hash_equals($row['existing'], $temporaryHash)
                || self::liveHash(['destination' => $destination]) !== ($expected === '' ? null : $expected)) {
                throw new \RuntimeException('The staged upload or destination changed before recovery.');
            }

            if ($expected === '' ? !link($temporary, $destination) : !rename($temporary, $destination)) {
                throw new \RuntimeException('Cannot publish the uploaded local copy.');
            }
            if (self::liveHash(['destination' => $destination]) !== $row['existing']) {
                throw new \RuntimeException('The recovered file did not verify after publication.');
            }
        } finally {
            if (is_resource($input)) {
                fclose($input);
            }
            if (is_resource($output)) {
                fclose($output);
            }
            if (file_exists($temporary) && !unlink($temporary)) {
                throw new \RuntimeException('Cannot remove the temporary recovery upload.');
            }
            if (is_dir($temporaryDirectory) && !rmdir($temporaryDirectory)) {
                throw new \RuntimeException('Cannot remove private recovery staging.');
            }
        }
    }

    private static function validRow(string $id, array $row): bool
    {
        return is_string($row['destination'] ?? null)
            && is_string($row['existing'] ?? null)
            && is_string($row['incoming'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $row['existing']) === 1
            && preg_match('/^[a-f0-9]{64}$/D', $row['incoming']) === 1
            && hash_equals($id, hash('sha256', $row['destination'] . "\0" . $row['existing'] . "\0" . $row['incoming']))
            && self::safeDestination(FileGuard::normalize($row['destination']));
    }

    private static function safeDestination(string $destination): bool
    {
        $relative = Knowledge::relative($destination);

        return preg_match(
            // The component, its module and plugins, and the package that installs them.
            '#^(?:administrator/components/com_appointments|components/com_appointments|media/com_appointments|administrator/modules/mod_appointments|plugins/[^/]+/appointments[^/]*|administrator/manifests/packages/appointments)(?:/|$)|^administrator/manifests/packages/pkg_appointments\.xml$|^(?:administrator/)?language/[a-z]{2}-[A-Z]{2}/(?:com_appointments|mod_appointments|pkg_appointments|plg_(?:task_appointments|privacy_appointments|content_appointmentsprivacy|system_appointments(?:dav|attribution)|user_appointments))(?:\.sys)?\.ini$#D',
            $relative
        ) === 1;
    }
}
