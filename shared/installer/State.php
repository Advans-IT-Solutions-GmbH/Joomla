<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer;

final class State
{
    private const PREFIX = "<?php exit; ?>\n";

    public static function read(string $path): array
    {
        if (is_link($path) || !is_file($path)) {
            throw new \RuntimeException('Invalid installer state file.');
        }

        $data = file_get_contents($path);

        if ($data === false || !str_starts_with($data, self::PREFIX)) {
            throw new \RuntimeException('Invalid installer state envelope.');
        }

        $decoded = json_decode(substr($data, strlen(self::PREFIX)), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid installer state payload.');
        }

        return $decoded;
    }

    public static function directory(string $directory): void
    {
        for ($ancestor = $directory; dirname($ancestor) !== $ancestor; $ancestor = dirname($ancestor)) {
            if (is_link($ancestor)) {
                throw new \RuntimeException('Installer state directory is a symbolic link.');
            }
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot create installer state directory.');
        }
    }

    public static function write(string $path, array $data): void
    {
        $directory = dirname($path);
        self::directory($directory);

        if (is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new \RuntimeException('Invalid installer state destination.');
        }

        $bytes = self::PREFIX . json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $temporary = $directory . '/state-' . bin2hex(random_bytes(16)) . '.php';
        $handle = fopen($temporary, 'xb');

        if ($handle === false) {
            throw new \RuntimeException('Cannot create installer state.');
        }

        try {
            if (fwrite($handle, $bytes) !== strlen($bytes) || !fflush($handle)) {
                throw new \RuntimeException('Cannot write installer state.');
            }
        } finally {
            fclose($handle);
        }

        if (!rename($temporary, $path)) {
            throw new \RuntimeException('Cannot publish installer state.');
        }
    }
}
