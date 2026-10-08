<?php
/**
 * Shared suite: installer messages in en-GB, de-DE and fr-FR.
 *
 * For every language the extension is removed and installed again through the
 * real Joomla CLI (`extension:remove`, `extension:install`) with that language
 * set as the application language. Installations over the installed extension
 * then exercise the update path in every language: an update must show the
 * short confirmation, not the first-installation guide, and a plugin may ask to
 * be enabled only while it is disabled. The CLI prints every message queued by
 * the installer script, so the output is checked for:
 *
 *   - untranslated language keys (PLG_/COM_/MOD_/PKG_/TPL_/LIB_/JLIB_ ...)
 *   - at least one installer message from the language file of the active
 *     language (for de-DE/fr-FR a text that differs from en-GB)
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

// The language switch must show: every installation prints at least one
// installer message taken from the language file of the active language. For
// de-DE and fr-FR only strings whose text differs from en-GB count, so the
// English fallback cannot pass the check.
$englishStrings = sh_read_package_language($package, 'en-GB');

$checkTranslated = static function (string $tag, string $label, string $output) use ($package, $englishStrings): void {
    $strings = sh_read_package_language($package, $tag);

    if (!$strings) {
        im_fail("$label: the package has no $tag language file");

        return;
    }

    $shown = sh_shown_language_keys(
        sh_plain_text(sh_strip_ansi($output)),
        $strings,
        $tag === 'en-GB' ? null : $englishStrings
    );

    if ($shown) {
        im_pass("$label: installer message in $tag shown (" . implode(', ', array_slice($shown, 0, 3)) . ')');
    } else {
        im_fail("$label: no installer message from the $tag language file in the output");
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
        $checkTranslated($tag, "$tag install", $out);

        if (sh_find_extension_id($manifest) > 0) {
            im_pass("$tag install: extension registered");
        } else {
            im_fail("$tag install: extension not registered after installation");
        }
    } finally {
        $restore();
    }
}

// Updates over the installed extension. An update shows the short confirmation
// (*_POSTINSTALL_UPDATED) instead of the first-installation guide (any other
// *_POSTINSTALL_* string). A plugin mentions the one step that can still be open,
// enabling it (*_ENABLE_HINT), only while it is disabled in #__extensions.
//
// Each update installs a copy of the package whose root manifest declares a
// strictly higher version than the one currently installed, so every update is
// a genuine upgrade over a lower version and never a same-version reinstall.
$updatePackage = sys_get_temp_dir() . '/shared-install-messages-' . bin2hex(random_bytes(4)) . '.zip';
register_shutdown_function(static function () use ($updatePackage): void {
    @unlink($updatePackage);
});

if (!copy($package, $updatePackage)) {
    im_fail('cannot copy the package for the update checks');
    exit(1);
}

$updateVersion = $manifest['version'];

$runUpdate = static function (string $tag, string $label) use ($updatePackage, $manifest, &$updateVersion, $checkOutput): string {
    $updateVersion = sh_bump_version($updateVersion);

    if (sh_set_package_version($updatePackage, $manifest['xmlfile'], $updateVersion) === null) {
        im_fail("$label: cannot build an update package with version $updateVersion");

        return '';
    }

    echo "\n--- $label (version $updateVersion) ---\n";
    $restore = sh_use_cli_language(JOOMLA_ROOT, $tag);

    try {
        [$code, $out] = sh_cli_install(JOOMLA_ROOT, $updatePackage);
    } finally {
        $restore();
    }

    echo $out . "\n";
    $checkOutput($label, $code, $out);

    return sh_plain_text(sh_strip_ansi($out));
};

$checkUpdate = static function (string $tag, string $label, string $plain, bool $expectHint) use ($package, $manifest, &$updateVersion): void {
    $updated = null;
    $hint    = null;
    $shown   = [];

    foreach (sh_read_package_language($package, $tag) as $key => $value) {
        if (str_ends_with($key, '_POSTINSTALL_UPDATED')) {
            $updated = sh_plain_text(str_replace('%s', $updateVersion, $value));
        } elseif (str_ends_with($key, '_ENABLE_HINT')) {
            $hint = mb_substr(sh_plain_text($value), 0, 60);
        } elseif (str_contains($key, '_POSTINSTALL_')) {
            $fragment = mb_substr(sh_plain_text(explode('%', $value)[0]), 0, 60);

            if (mb_strlen($fragment) >= 20 && str_contains($plain, $fragment)) {
                $shown[] = $key;
            }
        }
    }

    if ($updated === null) {
        im_pass("$label: the extension defines no update confirmation");
    } elseif (str_contains($plain, $updated)) {
        im_pass("$label: update confirmation shown");
    } else {
        im_fail("$label: update confirmation missing (expected \"$updated\")");
    }

    if ($shown) {
        im_fail("$label: first-installation guide shown on update: " . implode(', ', $shown));
    } else {
        im_pass("$label: first-installation guide not shown");
    }

    if ($manifest['type'] !== 'plugin') {
        return;
    }

    if ($hint === null) {
        im_fail("$label: no *_ENABLE_HINT language string for a disabled plugin");

        return;
    }

    if (str_contains($plain, $hint) === $expectHint) {
        im_pass("$label: enable hint " . ($expectHint ? 'shown while the plugin is disabled' : 'not shown while the plugin is enabled'));
    } else {
        im_fail("$label: enable hint " . ($expectHint ? 'missing although the plugin is disabled' : 'shown although the plugin is enabled'));
    }
};

// After an update the extension keeps its row: same ID, no second row, and no
// update-site link or update site left pointing at a row that no longer exists.
$checkRegistration = static function (string $label, int $expectedId) use ($manifest): void {
    $db    = sh_db();
    $table = sh_table('extensions');
    $type  = $db->real_escape_string($manifest['type']);
    $elem  = $db->real_escape_string($manifest['element']);
    $sql   = "SELECT extension_id FROM $table WHERE type = '$type' AND element = '$elem'";

    if ($manifest['type'] === 'plugin') {
        $folders = [$manifest['folder']];

        foreach (explode(',', (string) getenv('INSTALLED_PLUGIN_FOLDERS')) as $alias) {
            if (trim($alias) !== '') {
                $folders[] = trim($alias);
            }
        }

        $folders = array_map([$db, 'real_escape_string'], array_unique($folders));
        $sql    .= " AND folder IN ('" . implode("','", $folders) . "')";
    }

    $ids = [];
    $res = $db->query($sql);

    while ($res && ($row = $res->fetch_row())) {
        $ids[] = (int) $row[0];
    }

    if ($ids === [$expectedId]) {
        im_pass("$label: extension keeps its row (ID $expectedId, no duplicate)");
    } else {
        im_fail("$label: expected exactly the row $expectedId, found: " . ($ids ? implode(', ', $ids) : 'none'));
    }

    $links = sh_table('update_sites_extensions');
    $res   = $db->query(
        "SELECT l.update_site_id, l.extension_id FROM $links l"
        . " LEFT JOIN $table e ON e.extension_id = l.extension_id WHERE e.extension_id IS NULL"
    );
    $orphans = [];

    while ($res && ($row = $res->fetch_row())) {
        $orphans[] = "site {$row[0]} → extension {$row[1]}";
    }

    if ($orphans) {
        im_fail("$label: update-site links to missing extensions: " . implode('; ', $orphans));
    } else {
        im_pass("$label: no update-site link to a missing extension");
    }

    if ($manifest['updateserver'] === '') {
        return;
    }

    $sites    = sh_table('update_sites');
    $location = $db->real_escape_string($manifest['updateserver']);
    $res      = $db->query(
        "SELECT s.update_site_id FROM $sites s LEFT JOIN $links l ON l.update_site_id = s.update_site_id"
        . " WHERE s.location = '$location' AND l.update_site_id IS NULL"
    );
    $unused = [];

    while ($res && ($row = $res->fetch_row())) {
        $unused[] = (int) $row[0];
    }

    if ($unused) {
        im_fail("$label: update site of the extension without any link: " . implode(', ', $unused));
    } else {
        im_pass("$label: no unused update site for the extension's update server");
    }
};

$extensionId = sh_find_extension_id($manifest);
$isPlugin    = $manifest['type'] === 'plugin';

if ($extensionId <= 0) {
    im_fail('update: extension not registered before the update checks');
} else {
    $enabledBefore = sh_extension_enabled($extensionId);

    try {
        if ($isPlugin) {
            sh_set_extension_enabled($extensionId, 1);
        }

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $label = "$tag update" . ($isPlugin ? ' (plugin enabled)' : '');
            $checkUpdate($tag, $label, $runUpdate($tag, $label), false);
            $checkRegistration($label, $extensionId);
        }

        if ($isPlugin) {
            sh_set_extension_enabled($extensionId, 0);
            $label = 'en-GB update (plugin disabled)';
            $checkUpdate('en-GB', $label, $runUpdate('en-GB', $label), true);
            $checkRegistration($label, $extensionId);
        }
    } finally {
        if ($enabledBefore !== null) {
            sh_set_extension_enabled($extensionId, $enabledBefore);
        }
    }
}

echo "\n=== Installer Messages Summary ===\n";
echo $failures === 0 ? "All checks passed\n" : "$failures check(s) failed\n";

exit($failures === 0 ? 0 : 1);
