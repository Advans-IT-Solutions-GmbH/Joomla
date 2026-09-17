<?php
/**
 * Compatibility requirements check for one extension.
 *
 * Usage: php shared/tests/requirements-check.php <extension-directory>
 *
 * The supported platform is Joomla 5.4 or later (5.4.x and 6.x) and PHP 8.1 or
 * later for every extension of this repository. This check keeps all places
 * that express it consistent:
 *
 *   - script.php: $minimumJoomla = '5.4' and $minimumPhp = '8.1' (Joomla's
 *     InstallerScript::preflight() rejects older versions with the translated
 *     core message JLIB_INSTALLER_MINIMUM_JOOMLA / JLIB_INSTALLER_MINIMUM_PHP)
 *   - every extension manifest and updates/update.xml: targetplatform
 *     (5\.[4-9]|6\.[0-9]) and php_minimum 8.1 where present
 *   - the release workflow that writes update.xml: the same targetplatform
 *
 * The targetplatform expression is also evaluated against sample versions.
 * Exits with 1 when any place differs.
 */

const MIN_JOOMLA   = '5.4';
const MIN_PHP      = '8.1';
const TARGET_REGEX = '(5\.[4-9]|6\.[0-9])';

if ($argc !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php requirements-check.php <extension-directory>\n");
    exit(2);
}

$dir      = rtrim(str_replace('\\', '/', $argv[1]), '/');
$root     = dirname(__DIR__, 2);
$failures = [];

$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

// script.php
$script = "$dir/script.php";

if (!is_file($script)) {
    $fail("$script is missing");
} else {
    $src = (string) file_get_contents($script);

    if (!preg_match("/\\\$minimumJoomla\s*=\s*'([0-9.]+)'/", $src, $m) || $m[1] !== MIN_JOOMLA) {
        $fail("$script: \$minimumJoomla must be '" . MIN_JOOMLA . "' (found " . ($m[1] ?? 'none') . ')');
    }

    if (!preg_match("/\\\$minimumPhp\s*=\s*'([0-9.]+)'/", $src, $m) || $m[1] !== MIN_PHP) {
        $fail("$script: \$minimumPhp must be '" . MIN_PHP . "' (found " . ($m[1] ?? 'none') . ')');
    }

    if (preg_match('/function\s+preflight\s*\(/', $src) && !preg_match('/parent::preflight\s*\(/', $src)) {
        $fail("$script: preflight() must call parent::preflight() so the minimum versions are enforced");
    }
}

// Manifests and update.xml
$xmlFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));

foreach ($iterator as $file) {
    $path = str_replace('\\', '/', $file->getPathname());

    if (!str_ends_with($path, '.xml') || preg_match('#/(tests[^/]*|vendor|node_modules|language|forms|sql|layouts|tmpl)/#', $path)) {
        continue;
    }

    $content = (string) file_get_contents($path);

    if (str_contains($content, '<extension') || str_contains($content, '<updates>')) {
        $xmlFiles[] = $path;
    }
}

$checkedTarget = 0;

foreach ($xmlFiles as $path) {
    $content = (string) file_get_contents($path);
    $isUpdate = str_contains($content, '<updates>');

    preg_match_all('/<targetplatform\s+name="joomla"\s+version="([^"]*)"/', $content, $targets);

    if ($isUpdate && !$targets[1]) {
        $fail("$path: <targetplatform> is missing");
    }

    foreach ($targets[1] as $value) {
        $checkedTarget++;

        if ($value !== TARGET_REGEX) {
            $fail("$path: targetplatform must be " . TARGET_REGEX . " (found $value)");
        }
    }

    preg_match_all('/<php_minimum>([^<]*)<\/php_minimum>/', $content, $phps);

    foreach ($phps[1] as $value) {
        if (trim($value) !== MIN_PHP) {
            $fail("$path: php_minimum must be " . MIN_PHP . " (found $value)");
        }
    }
}

if ($checkedTarget === 0) {
    $fail("$dir: no targetplatform found in any manifest or update.xml");
}

// Release workflow writing update.xml for this extension
// The CI passes the extension path relative to the repository root, e.g.
// j2commerce/plg_osmap_j2commerce; release workflows use it as EXT_PATH.
$relative  = preg_replace('#^\./#', '', $dir);
$workflows = glob("$root/.github/workflows/release-*.yml") ?: [];
$matched   = false;

foreach ($workflows as $workflow) {
    $content = (string) file_get_contents($workflow);

    if (!str_contains($content, 'EXT_PATH="' . $relative . '/"')) {
        continue;
    }

    $matched = true;
    preg_match_all('/<targetplatform\s+name="joomla"\s+version="([^"]*)"/', $content, $targets);

    if (!$targets[1]) {
        $fail(basename($workflow) . ': <targetplatform> is missing');
    }

    foreach ($targets[1] as $value) {
        if ($value !== TARGET_REGEX) {
            $fail(basename($workflow) . ': targetplatform must be ' . TARGET_REGEX . " (found $value)");
        }
    }
}

if (!$matched) {
    $fail("no release workflow with EXT_PATH=\"$relative/\" found");
}

// The expression must accept every supported version and reject older ones.
$pattern = '/^' . TARGET_REGEX . '/';

foreach (['5.4.0' => true, '5.4.8' => true, '6.0.0' => true, '6.1.3' => true, '6.9.1' => true,
          '4.4.14' => false, '5.0.0' => false, '5.3.4' => false] as $version => $expected) {
    if ((bool) preg_match($pattern, $version) !== $expected) {
        $fail('targetplatform ' . TARGET_REGEX . ($expected ? " does not accept $version" : " accepts unsupported $version"));
    }
}

if ($failures) {
    echo "Requirements check failed:\n  - " . implode("\n  - ", $failures) . "\n";
    exit(1);
}

echo "Requirements OK: Joomla " . MIN_JOOMLA . "+ (" . TARGET_REGEX . "), PHP " . MIN_PHP . "+ in script.php, "
    . count($xmlFiles) . " manifest/update file(s) and the release workflow\n";
exit(0);
