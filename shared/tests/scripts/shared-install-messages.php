<?php
/**
 * Shared suite: installer messages in en-GB, de-DE and fr-FR.
 *
 * For every language the extension is removed and installed again through the
 * real Joomla CLI (`extension:remove`, `extension:install`) with that language
 * set as the application language. A final installation over the installed
 * extension exercises the update path. The CLI prints every message queued by
 * the installer script, so the output is checked for:
 *
 *   - untranslated language keys (PLG_/COM_/MOD_/PKG_/TPL_/LIB_/JLIB_ ...)
 *   - [ERROR], [WARNING] or [CAUTION] messages
 *   - PHP warnings, notices or fatal errors
 *   - a non-zero exit code
 *
 * The package is read from /tmp/extension.zip (INSTALL_MESSAGES_PACKAGE
 * overrides it). The suite leaves the extension installed.
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

$package = getenv('INSTALL_MESSAGES_PACKAGE') ?: '/tmp/extension.zip';
$failures = 0;

function im_fail(string $message): void
{
    global $failures;
    $failures++;
    echo "FAIL $message\n";
}

function im_pass(string $message): void
{
    echo "PASS $message\n";
}

echo "=== Installer Messages (CLI, en-GB / de-DE / fr-FR) ===\n\n";

$manifest = sh_read_package_manifest($package);

if ($manifest === null) {
    im_fail("cannot read the extension manifest from $package");
    exit(1);
}

echo "Package: $package ({$manifest['type']} {$manifest['element']}"
    . ($manifest['folder'] !== '' ? " in {$manifest['folder']}" : '') . ")\n\n";

$checkOutput = static function (string $label, int $exitCode, string $output): void {
    $clean = sh_strip_ansi($output);

    if ($exitCode !== 0) {
        im_fail("$label: exit code $exitCode");
    } else {
        im_pass("$label: exit code 0");
    }

    $rawKeys = sh_find_raw_language_keys($clean);

    if ($rawKeys) {
        im_fail("$label: untranslated language keys: " . implode(', ', $rawKeys));
    } else {
        im_pass("$label: no untranslated language keys");
    }

    if (preg_match_all('/\[(ERROR|WARNING|CAUTION)\][^\n]*(?:\n(?!\s*$)[^\n]*)*/', $clean, $blocks)) {
        im_fail("$label: unexpected installer messages:\n  " . implode("\n  ", array_map('trim', $blocks[0])));
    } else {
        im_pass("$label: no error, warning or caution messages");
    }

    if (preg_match_all('/^(?:PHP )?(Warning|Notice|Fatal error|Parse error):.*$/m', $clean, $phpErrors)) {
        im_fail("$label: PHP errors in output:\n  " . implode("\n  ", $phpErrors[0]));
    } else {
        im_pass("$label: no PHP warnings, notices or fatal errors");
    }
};

foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
    echo "\n--- $tag ---\n";

    $restore = sh_use_cli_language(JOOMLA_ROOT, $tag);

    try {
        $extensionId = sh_find_extension_id($manifest);

        if ($extensionId > 0) {
            [$code, $out] = sh_cli(JOOMLA_ROOT, 'extension:remove ' . $extensionId . ' --no-interaction');
            echo $out . "\n";
            $checkOutput("$tag uninstall", $code, $out);
        }

        [$code, $out] = sh_cli_install(JOOMLA_ROOT, $package);
        echo $out . "\n";
        $checkOutput("$tag install", $code, $out);

        if (sh_find_extension_id($manifest) > 0) {
            im_pass("$tag install: extension registered");
        } else {
            im_fail("$tag install: extension not registered after installation");
        }
    } finally {
        $restore();
    }
}

echo "\n--- en-GB update over the installed extension ---\n";
$restore = sh_use_cli_language(JOOMLA_ROOT, 'en-GB');

try {
    [$code, $out] = sh_cli_install(JOOMLA_ROOT, $package);
    echo $out . "\n";
    $checkOutput('en-GB update', $code, $out);
} finally {
    $restore();
}

echo "\n=== Installer Messages Summary ===\n";
echo $failures === 0 ? "All checks passed\n" : "$failures check(s) failed\n";

exit($failures === 0 ? 0 : 1);
