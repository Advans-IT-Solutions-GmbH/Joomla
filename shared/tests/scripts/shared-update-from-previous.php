<?php
/**
 * Shared suite: update from the previous release.
 *
 * Precondition (prepared by CI): the container was set up with the package of
 * the previous release of this extension, built from the last `release:`
 * commit on main, and the package under test is available at
 * /tmp/extension-new.zip (UPDATE_PACKAGE overrides it).
 *
 * The suite
 *   1. registers an update site that still uses the repository's former
 *      organisation name for the installed extension,
 *   2. installs a copy of the new package whose root manifest declares a
 *      version above the installed one (the PR version often equals the last
 *      release) over the previous version through the CLI,
 *   3. verifies: the installed manifest changed and is the one from that copy,
 *      the installer script is the new one, manifest_cache carries the raised
 *      version, exactly one update site is
 *      linked and it uses the current organisation name, bundled plugins are
 *      installed and enabled, and the installer output contains no untranslated
 *      language key and no error.
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

$newPackage = getenv('UPDATE_PACKAGE') ?: '/tmp/extension-new.zip';
$failures   = 0;

function up_check(string $name, bool $ok, string $detail = ''): void
{
    global $failures;

    if ($ok) {
        echo "PASS $name\n";
        return;
    }

    $failures++;
    echo "FAIL $name" . ($detail !== '' ? " - $detail" : '') . "\n";
}

echo "=== Update From Previous Release ===\n\n";

$versionSource = (string) @file_get_contents(JOOMLA_ROOT . '/libraries/src/Version.php');
preg_match_all('/const (MAJOR|MINOR|PATCH)_VERSION = (\d+);/', $versionSource, $versionParts);
$versionMap = array_combine($versionParts[1] ?: [], $versionParts[2] ?: []);
echo 'Tested versions: Joomla ' . ($versionMap ? $versionMap['MAJOR'] . '.' . $versionMap['MINOR'] . '.' . $versionMap['PATCH'] : 'unknown')
    . ', PHP ' . PHP_VERSION . "\n\n";

if (!is_file($newPackage)) {
    $message = "new package $newPackage not present (this suite needs a container set up with the previous release)";

    if (sh_strict_skip()) {
        up_check('new package available', false, $message);
        exit(1);
    }

    echo "SKIP $message\n";
    exit(0);
}

$manifest = sh_read_package_manifest($newPackage);

if ($manifest === null) {
    up_check('read manifest of new package', false, $newPackage);
    exit(1);
}

$db          = sh_db();
$extensions  = sh_table('extensions');
$sites       = sh_table('update_sites');
$sitesMap    = sh_table('update_sites_extensions');
$extensionId = sh_find_extension_id($manifest);

up_check('previous version is installed', $extensionId > 0, "{$manifest['type']} {$manifest['element']} not found");

if ($extensionId === 0) {
    exit(1);
}

$row             = $db->query("SELECT manifest_cache FROM $extensions WHERE extension_id = $extensionId")->fetch_row();
$previousVersion = (string) (json_decode((string) ($row[0] ?? ''), true)['version'] ?? '');
echo "Installed (previous) version: $previousVersion\n";
echo "Package version: {$manifest['version']}\n";

$installDir = match ($manifest['type']) {
    'plugin'    => JOOMLA_ROOT . '/plugins/' . $manifest['folder'] . '/' . $manifest['element'],
    'component' => JOOMLA_ROOT . '/administrator/components/' . $manifest['element'],
    default     => '',
};
up_check('install directory is known for the extension type', $installDir !== '', "type {$manifest['type']}");

$installedManifestPath = $installDir . '/' . basename($manifest['xmlfile']);
$manifestHashBefore    = is_file($installedManifestPath) ? hash_file('sha256', $installedManifestPath) : '';
up_check('previous manifest is installed', $manifestHashBefore !== '', $installedManifestPath);

// The previous release and the package under test often carry the same
// version (a PR without a release commit). Installing that package would only
// reinstall the same version, so the test installs a copy of it whose root
// manifest declares a higher version. Only this copy is changed.
$updateVersion = sh_bump_version($previousVersion !== '' ? $previousVersion : $manifest['version']);

if (version_compare($manifest['version'], $updateVersion, '>')) {
    $updateVersion = $manifest['version'];
}

$updatePackage = sys_get_temp_dir() . '/shared-update-' . bin2hex(random_bytes(4)) . '.zip';
register_shutdown_function(static function () use ($updatePackage): void {
    @unlink($updatePackage);
});

$packagedManifest = null;

if (copy($newPackage, $updatePackage)) {
    $packagedManifest = sh_set_package_version($updatePackage, $manifest['xmlfile'], $updateVersion);
}

up_check('test package with a higher version built', $packagedManifest !== null, $updatePackage);

if ($packagedManifest === null) {
    exit(1);
}

up_check(
    'update raises the version',
    $previousVersion !== '' && version_compare($updateVersion, $previousVersion, '>'),
    "previous '$previousVersion', update '$updateVersion'"
);
echo "Update version (test package): $updateVersion\n\n";

// 1. Update site with the former organisation name.
if ($manifest['updateserver'] === '') {
    up_check('new manifest declares an update server', false);
    exit(1);
}

$legacyUrl = str_replace('/Advans-IT-Solutions-GmbH/', '/advansit/', $manifest['updateserver']);
up_check('legacy update URL differs from the current one', $legacyUrl !== $manifest['updateserver']);

$stmt = $db->prepare("INSERT INTO $sites (name, type, location, enabled) VALUES ('Legacy update site (test)', 'extension', ?, 1)");
$stmt->bind_param('s', $legacyUrl);
$stmt->execute();
$legacySiteId = (int) $db->insert_id;
$db->query("INSERT INTO $sitesMap (update_site_id, extension_id) VALUES ($legacySiteId, $extensionId)");
echo "Inserted legacy update site #$legacySiteId: $legacyUrl\n\n";

// 2. Install the new package over the previous version.
[$code, $output] = sh_cli_install(JOOMLA_ROOT, $updatePackage);
$clean = sh_strip_ansi($output);
echo $clean . "\n\n";

up_check('CLI update exits with 0', $code === 0, "exit code $code");
$rawKeys = sh_find_raw_language_keys($clean);
up_check('no untranslated language keys in installer output', !$rawKeys, implode(', ', $rawKeys));
up_check('no [ERROR] message in installer output', !preg_match('/\[ERROR\]/', $clean));

// 3. Verification.
$extensionIdAfter = sh_find_extension_id($manifest);
up_check('extension keeps its extension_id', $extensionIdAfter === $extensionId, "before $extensionId, after $extensionIdAfter");

$row          = $db->query("SELECT manifest_cache FROM $extensions WHERE extension_id = $extensionId")->fetch_row();
$cachedVersion = (string) (json_decode((string) ($row[0] ?? ''), true)['version'] ?? '');
up_check('manifest_cache version is the update version', $cachedVersion === $updateVersion, "installed '$cachedVersion', update '$updateVersion'");

$manifestHashAfter = is_file($installedManifestPath) ? hash_file('sha256', $installedManifestPath) : '';
up_check('installed manifest file changed', $manifestHashAfter !== '' && $manifestHashAfter !== $manifestHashBefore, $installedManifestPath);
up_check(
    'installed manifest is the one from the update package',
    $manifestHashAfter === hash('sha256', $packagedManifest),
    $installedManifestPath
);

$zip = new ZipArchive();

if ($zip->open($newPackage) !== true) {
    up_check('open new package', false, $newPackage);
} else {
    $script = $zip->getFromName('script.php');

    if ($script !== false) {
        $installedPath = $installDir . '/script.php';
        up_check(
            'installed script.php is the one from the new package',
            is_file($installedPath) && hash_file('sha256', $installedPath) === hash('sha256', $script),
            $installedPath
        );
    }

    $zip->close();
}

$result = $db->query(
    "SELECT s.update_site_id, s.location FROM $sites s INNER JOIN $sitesMap m ON m.update_site_id = s.update_site_id"
    . " WHERE m.extension_id = $extensionId"
);
$linked = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
echo "Linked update sites after update:\n";
foreach ($linked as $site) {
    echo "  #{$site['update_site_id']} {$site['location']}\n";
}

up_check('exactly one update site is linked', count($linked) === 1, count($linked) . ' linked');
up_check(
    'the linked update site uses the current organisation name',
    count($linked) === 1 && str_contains($linked[0]['location'], '/Advans-IT-Solutions-GmbH/Joomla/')
);

$legacyLeft = (int) $db->query("SELECT COUNT(*) FROM $sites WHERE location LIKE '%/advansit/Joomla/%'")->fetch_row()[0];
up_check('no update site with the former organisation name remains', $legacyLeft === 0, "$legacyLeft remaining");

foreach ($manifest['nested'] as $plugin) {
    $result = $db->query(
        "SELECT enabled FROM $extensions WHERE type = 'plugin' AND folder = '" . $db->real_escape_string($plugin['folder'])
        . "' AND element = '" . $db->real_escape_string($plugin['element']) . "'"
    );
    $nestedRow = $result ? $result->fetch_row() : null;
    up_check("bundled plugin {$plugin['folder']}/{$plugin['element']} is installed", $nestedRow !== null);
    up_check("bundled plugin {$plugin['folder']}/{$plugin['element']} is enabled", $nestedRow !== null && (int) $nestedRow[0] === 1);
}

echo "\n=== Update From Previous Release Summary ===\n";
echo $failures === 0 ? "All checks passed\n" : "$failures check(s) failed\n";

exit($failures === 0 ? 0 : 1);
