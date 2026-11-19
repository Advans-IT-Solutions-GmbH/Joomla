<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/FileGuard.php';

use Advans\Installer\V2\FileGuard;

if ($argc !== 6 || $argv[1] !== '--export' || !preg_match('/^[a-f0-9]{64}$/D', $argv[4])) {
    fwrite(STDERR, "Usage: php export-backup.php --export BACKUP ABSOLUTE_DESTINATION EXISTING_SHA256 NEW_OUTPUT\n");
    exit(1);
}

$output = null;
$created = false;

try {
    $bytes = FileGuard::restoreBytes($argv[2], $argv[3], $argv[4]);
    $output = FileGuard::normalize($argv[5]);
    FileGuard::compare([$output => $argv[2]]);
    $previousMask = umask(0077);
    try {
        $handle = @fopen($output, 'xb');
    } finally {
        umask($previousMask);
    }

    if ($handle === false) {
        throw new RuntimeException('The output could not be created exclusively.');
    }

    $created = true;
    try {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written <= 0) {
                throw new RuntimeException('The output could not be written completely.');
            }
            $offset += $written;
        }
        if (!fflush($handle)) {
            throw new RuntimeException('The output could not be flushed.');
        }
    } finally {
        fclose($handle);
    }

    if (!hash_equals($argv[4], (string) hash_file('sha256', $output))) {
        throw new RuntimeException('The exported bytes did not pass verification.');
    }
} catch (RuntimeException | JsonException $error) {
    if ($created && $output !== null) {
        @unlink($output);
    }
    fwrite(STDERR, "Backup export failed: " . $error->getMessage() . "\n");
    exit(1);
}

fwrite(STDOUT, "Verified backup exported to a new file. The installed destination was not changed.\n");
