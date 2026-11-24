<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\V3;

defined('_JEXEC') or die;

final class InstallerLock
{
    /** @var array<string, array{handle: resource, count: int}> */
    private static array $locks = [];

    /** @return resource|null */
    public static function acquire(string $stateRoot)
    {
        State::directory($stateRoot);
        $directory = realpath($stateRoot);

        if ($directory === false) {
            throw new \RuntimeException('Cannot resolve shared installer lock directory.');
        }

        $key = str_replace('\\', '/', $directory);

        if (DIRECTORY_SEPARATOR === '\\') {
            $key = strtolower($key);
        }

        if (isset(self::$locks[$key]) && is_resource(self::$locks[$key]['handle'])) {
            self::$locks[$key]['count']++;

            return self::$locks[$key]['handle'];
        }

        $path = $stateRoot . '/installer.lock';

        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Invalid shared installer lock.');
        }

        $handle = fopen($path, 'c+b');

        if ($handle === false) {
            throw new \RuntimeException('Cannot open shared installer lock.');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        self::$locks[$key] = ['handle' => $handle, 'count' => 1];

        return $handle;
    }

    public static function release(mixed $handle): void
    {
        if (!is_resource($handle)) {
            return;
        }

        foreach (self::$locks as $key => $lock) {
            if ($lock['handle'] !== $handle) {
                continue;
            }

            if ($lock['count'] > 1) {
                self::$locks[$key]['count']--;

                return;
            }

            unset(self::$locks[$key]);
            flock($handle, LOCK_UN);
            fclose($handle);

            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
