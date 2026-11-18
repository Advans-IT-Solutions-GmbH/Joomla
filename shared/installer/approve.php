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

require_once __DIR__ . '/State.php';
require_once __DIR__ . '/FileGuard.php';
require_once __DIR__ . '/Approval.php';

if ($argc !== 4 || $argv[1] !== '--approve' || !preg_match('/^[a-f0-9]{64}$/D', $argv[3])) {
    fwrite(STDERR, "Usage: php approve.php --approve /absolute/path/plan.php SHA256_OF_REVIEWED_PLAN\n");
    exit(1);
}

$path = realpath($argv[2]);

if ($path === false || basename($path) !== 'plan.php' || !hash_equals($argv[3], hash_file('sha256', $path))) {
    fwrite(STDERR, "The review plan is missing or has changed; nothing was approved.\n");
    exit(1);
}

\Advans\Installer\Approval::approve($path);
fwrite(STDOUT, "Approved exact file hashes. Retry the same package in Joomla; changed files or package bytes require a new review.\n");
