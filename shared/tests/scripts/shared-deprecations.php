<?php
/**
 * Shared suite: PHP deprecations of the extension (production-like lane).
 *
 * The production-like images enable `error_reporting = E_ALL`, `log_errors = On`
 * and `error_log = /tmp/php-errors.log` for both CLI and Apache. After the
 * regular suites have run, this script
 *
 *   1. lints every PHP file of the package with the container's PHP version,
 *      which reports compile-time deprecations (for example implicitly
 *      nullable parameter types on PHP 8.4), and
 *   2. reads the PHP error log written during all previous suites.
 *
 * Any deprecation, warning or error that originates in a file of this
 * extension fails the suite. Entries from Joomla core or third-party
 * extensions are listed for information only.
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

$package  = getenv('INSTALL_MESSAGES_PACKAGE') ?: '/tmp/extension.zip';
$logFile  = getenv('PHP_ERROR_LOG') ?: '/tmp/php-errors.log';
$failures = 0;

echo "=== PHP Deprecations (PHP " . PHP_VERSION . ") ===\n\n";

$manifest = sh_read_package_manifest($package);

if ($manifest === null) {
    echo "FAIL cannot read the manifest of $package\n";
    exit(1);
}

if (!is_file($logFile)) {
    $message = "$logFile does not exist (the image was not built with PHP_STRICT_DEPRECATIONS=1)";

    if (sh_strict_skip()) {
        echo "FAIL $message\n";
        exit(1);
    }

    echo "SKIP $message\n";
    exit(0);
}

// Paths that belong to this extension once installed.
$ownPaths = [];

if ($manifest['type'] === 'plugin') {
    $ownPaths[] = '/plugins/' . $manifest['folder'] . '/' . $manifest['element'] . '/';
} elseif ($manifest['type'] === 'component') {
    $ownPaths[] = '/administrator/components/' . $manifest['element'] . '/';
    $ownPaths[] = '/components/' . $manifest['element'] . '/';
}

foreach ($manifest['nested'] as $plugin) {
    $ownPaths[] = '/plugins/' . $plugin['folder'] . '/' . $plugin['element'] . '/';
}

$ownPaths[] = '/templates/';  // template overrides shipped by the extension
$ownPaths[] = '/tmp/shared-lint-';

// 1. Compile-time deprecations of every PHP file in the package.
$lintDir = sys_get_temp_dir() . '/shared-lint-' . bin2hex(random_bytes(4));
mkdir($lintDir, 0755, true);
$zip = new ZipArchive();
$zip->open($package);
$zip->extractTo($lintDir);
$zip->close();

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lintDir, FilesystemIterator::SKIP_DOTS));
$linted   = 0;

foreach ($iterator as $file) {
    if (strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $linted++;
    $out  = [];
    $code = 0;
    exec('php -d error_reporting=E_ALL -d display_errors=stderr -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
    $text = implode("\n", $out);

    if ($code !== 0 || preg_match('/Deprecated|Warning|Fatal|Parse error/i', $text)) {
        $failures++;
        echo "FAIL php -l " . substr($file->getPathname(), strlen($lintDir) + 1) . ":\n  " . str_replace("\n", "\n  ", $text) . "\n";
    }
}

echo "Linted $linted PHP file(s) of the package\n";

// 2. Runtime entries recorded while the suites ran.
$own   = [];
$other = [];

foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (!preg_match('/PHP (Deprecated|Warning|Notice|Fatal error|Parse error):\s+(.*) in (\S+) on line (\d+)/', $line, $m)) {
        continue;
    }

    $entry = "{$m[1]}: {$m[2]} ({$m[3]}:{$m[4]})";
    $isOwn = false;

    foreach ($ownPaths as $path) {
        if (str_contains($m[3], $path)) {
            $isOwn = true;
            break;
        }
    }

    if ($isOwn) {
        $own[$entry] = true;
    } else {
        $other[$entry] = true;
    }
}

if ($own) {
    $failures += count($own);
    echo "\nFAIL entries caused by this extension:\n  " . implode("\n  ", array_keys($own)) . "\n";
} else {
    echo "PASS no runtime deprecation, warning or error from this extension\n";
}

if ($other) {
    echo "\nINFO " . count($other) . " entr" . (count($other) === 1 ? 'y' : 'ies') . " from Joomla core or other extensions (not evaluated):\n  "
        . implode("\n  ", array_slice(array_keys($other), 0, 50)) . "\n";
}

echo "\n=== PHP Deprecations Summary ===\n";
echo $failures === 0 ? "All checks passed\n" : "$failures problem(s) found\n";

exit($failures === 0 ? 0 : 1);
