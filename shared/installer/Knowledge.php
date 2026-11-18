<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer;

defined('_JEXEC') or die;

/**
 * What the installer knows about the files of an extension: the SHA-256 of every file the
 * installer wrote during the last successful installation on this site (the record) and the
 * SHA-256 of every file of every earlier release (the catalog shipped in the package).
 *
 * A destination whose current content matches either is "untouched": it holds bytes this
 * extension shipped, so replacing it loses nothing that a public release cannot restore.
 */
final class Knowledge
{
    /** Catalog of released file hashes, staged into advansinstaller/ of the package at build time. */
    public const CATALOG = 'known-hashes.php';

    /** Per-site record of the files written by the last successful installation. */
    public const RECORD = 'installed.php';

    /**
     * @param array<string, string> $record Path relative to the site root => SHA-256.
     * @param array<string, true>   $hashes SHA-256 of every released file.
     */
    private function __construct(private array $record, private array $hashes, private bool $complete)
    {
    }

    public static function load(string $catalogFile, string $recordFile): self
    {
        $record = [];

        if (is_file($recordFile)) {
            $data = State::read($recordFile);

            foreach ((array) ($data['files'] ?? []) as $path => $hash) {
                if (is_string($path) && is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) {
                    $record[$path] = $hash;
                }
            }
        }

        $hashes = [];
        $complete = false;

        if (is_file($catalogFile)) {
            $data = State::read($catalogFile);
            $complete = ($data['complete'] ?? false) === true;

            foreach ((array) ($data['hashes'] ?? []) as $hash) {
                if (is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1) {
                    $hashes[$hash] = true;
                }
            }
        }

        return new self($record, $hashes, $complete);
    }

    /** Whether the catalog covers every reproducible earlier release. */
    public function isComplete(): bool
    {
        return $this->complete;
    }

    public function hasRecord(): bool
    {
        return $this->record !== [];
    }

    /**
     * Whether $hash is content this extension wrote to $destination or shipped in any release.
     */
    public function isKnown(string $destination, string $hash): bool
    {
        $relative = self::relative($destination);

        if (isset($this->record[$relative]) && hash_equals($this->record[$relative], $hash)) {
            return true;
        }

        return isset($this->hashes[$hash]);
    }

    /** Destination path relative to the Joomla root, with forward slashes. */
    public static function relative(string $destination): string
    {
        $root = rtrim(str_replace('\\', '/', FileGuard::normalize(JPATH_ROOT)), '/') . '/';
        $path = str_replace('\\', '/', FileGuard::normalize($destination));

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Record every destination that now holds exactly the bytes this installation wrote.
     *
     * @param array<string, string> $incoming Normalized destination => incoming SHA-256.
     */
    public static function record(string $recordFile, string $version, array $incoming): int
    {
        $files = [];

        foreach ($incoming as $destination => $hash) {
            if (!is_link($destination) && is_file($destination) && hash_file('sha256', $destination) === $hash) {
                $files[self::relative($destination)] = $hash;
            }
        }

        ksort($files, SORT_STRING);
        State::write($recordFile, ['version' => $version, 'files' => $files]);

        return count($files);
    }
}
