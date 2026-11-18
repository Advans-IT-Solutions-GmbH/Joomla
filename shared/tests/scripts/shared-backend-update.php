<?php
/**
 * Shared suite: update through Joomla's own update path, as an administrator does it.
 *
 * Every round publishes the package under test with a raised version on a local update server
 * (update.xml with <sha256> plus the ZIP under /advans-test-updates/), points the extension's
 * update site there, signs in to the administrator, runs "Check for Updates" (update.find) and
 * then "Update" (update.update) for the extension, exactly the requests the backend buttons send.
 *
 * Precondition: the container runs the extension (usually the latest published release, built
 * before the installer decided by itself, so the first round also covers the bootstrap without a
 * record). The package under test is at UPDATE_PACKAGE (default /tmp/extension-new.zip) and the
 * extension's updates/update.xml at UPDATE_XML (default /tmp/update.xml).
 *
 * SCENARIOS (comma-separated, in this order):
 *   clean            update without any change on the site: no pause, no report, no backup
 *   modified         an own file (MODIFY_FILE, default the installed script.php) was changed by
 *                    hand: the update succeeds, the backup decodes to exactly the changed bytes and
 *                    the report names the file
 *   backup-failure   the backup directory is not writable: the update stops before any file or the
 *                    version changes, with a plain explanation
 *   foreign-pause    a package variant replaces an unclassified file of another extension: the
 *                    update pauses with a plain explanation and the Approve button; following it
 *                    (Install from Folder of the paused copy) completes the update with a backup
 *   osmap-patch      OSMap's Factory.php still has the bug: patched after a backup and reported
 *   privacy-override an unchanged checkout override of an earlier privacy release (PRIVACY_OVERRIDE)
 *                    is retired by renaming after a backup and reported
 *   mirror-changed   (Product Compare, Joomla 5) a mirror file changed by hand: backed up, replaced
 *   mirror-occupied  (Product Compare, Joomla 5) the mirror folder belongs to another extension: its
 *                    files stay byte-identical and the update explains why the mirror was skipped
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

$package   = getenv('UPDATE_PACKAGE') ?: '/tmp/extension-new.zip';
$template  = getenv('UPDATE_XML') ?: '/tmp/update.xml';
$scenarios = array_filter(array_map('trim', explode(',', (string) (getenv('SCENARIOS') ?: 'clean,modified,backup-failure'))));
$failures  = 0;

function bu_check(string $name, bool $ok, string $detail = ''): bool
{
    global $failures;

    echo ($ok ? 'PASS ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : " - $detail") . "\n";

    if (!$ok) {
        $failures++;
    }

    return $ok;
}

echo "=== Update through the Joomla backend ===\n\n";

$manifest = sh_read_package_manifest($package);

if ($manifest === null || !is_file($template)) {
    bu_check('package under test and update.xml are available', false, "$package, $template");
    exit(1);
}

$zip = new ZipArchive();
$zip->open($package);
$rootXml = simplexml_load_string((string) $zip->getFromName($manifest['xmlfile']));
$zip->close();
$id = strtolower(trim((string) $rootXml->name));
$state = JOOMLA_ROOT . '/administrator/advans-installer-state/' . $id;
$installRoot = match ($manifest['type']) {
    'component' => JOOMLA_ROOT . '/administrator/components/' . $manifest['element'],
    'plugin'    => JOOMLA_ROOT . '/plugins/' . $manifest['folder'] . '/' . $manifest['element'],
    'module'    => JOOMLA_ROOT . '/administrator/modules/' . $manifest['element'],
    'package'   => JOOMLA_ROOT . '/administrator/manifests/packages/' . substr($manifest['element'], 4),
};
$extensionId = sh_find_extension_id($manifest);
$db = sh_db();

if (!bu_check("$id is installed", $extensionId > 0)) {
    exit(1);
}

// A real site is owned by the web server user. The container installed the previous release
// through the CLI as root, which a web update could not overwrite.
exec('chown -R www-data:www-data ' . escapeshellarg(JOOMLA_ROOT));

$row = $db->query('SELECT manifest_cache FROM ' . sh_table('extensions') . " WHERE extension_id = $extensionId")->fetch_row();
$version = (string) (json_decode((string) ($row[0] ?? ''), true)['version'] ?? $manifest['version']);
echo "Installed version: $version; state: $state\n\n";

/** Version stored in #__extensions for the extension under test. */
function bu_installed_version(): string
{
    global $db, $extensionId;

    $row = $db->query('SELECT manifest_cache FROM ' . sh_table('extensions') . " WHERE extension_id = $extensionId")->fetch_row();

    return (string) (json_decode((string) ($row[0] ?? ''), true)['version'] ?? '');
}

/** @return list<string> */
function bu_backups(): array
{
    global $state;

    clearstatcache();

    return glob($state . '/backups/*.php') ?: [];
}

/** Original bytes and destination of a backup container. */
function bu_backup(string $path): array
{
    $content = (string) file_get_contents($path);
    $record = json_decode(substr($content, strlen("<?php http_response_code(404); exit; ?>\n")), true);

    return ['destination' => (string) ($record['destination'] ?? ''), 'bytes' => (string) base64_decode((string) ($record['content'] ?? ''), true)];
}

/** @return array<string, string> SHA-256 of every file below the given directories. */
function bu_snapshot(array $directories): array
{
    $hashes = [];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $hashes[$file->getPathname()] = hash_file('sha256', $file->getPathname());
        }
    }

    ksort($hashes);

    return $hashes;
}

final class BuBrowser
{
    private string $cookies;
    public string $base;

    public function __construct()
    {
        $this->cookies = tempnam(sys_get_temp_dir(), 'bu-cookies-');
        $this->base = rtrim(getenv('BACKEND_URL') ?: 'http://localhost', '/') . '/administrator/';
    }

    /** @param array<string, mixed>|null $post */
    public function request(string $url, ?array $post = null): string
    {
        $curl = curl_init(str_starts_with($url, 'http') ? $url : $this->base . ltrim(html_entity_decode($url), '/'));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_COOKIEJAR      => $this->cookies,
            CURLOPT_COOKIEFILE     => $this->cookies,
            CURLOPT_TIMEOUT        => 300,
        ]);

        if ($post !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($curl);
        curl_close($curl);

        return is_string($body) ? $body : '';
    }

    public static function token(string $html): string
    {
        return preg_match('/"csrf\.token":"([a-f0-9]{32})"/', $html, $match) === 1
            || preg_match('/name="([a-f0-9]{32})" value="1"/', $html, $match) === 1 ? $match[1] : '';
    }

    public function login(): bool
    {
        $page = $this->request('index.php');
        $token = self::token($page);
        $user = getenv('JOOMLA_ADMIN_USERNAME') ?: (getenv('JOOMLA_ADMIN_USER') ?: 'admin');
        $page = $this->request('index.php', [
            'username' => $user,
            'passwd'   => (string) getenv('JOOMLA_ADMIN_PASSWORD'),
            'option'   => 'com_login',
            'task'     => 'login',
            'return'   => base64_encode('index.php'),
            $token     => '1',
        ]);

        return str_contains($page, 'task=logout') || str_contains($page, 'com_cpanel');
    }

    /** @return list<array{type: string, html: string, text: string}> */
    public static function messages(string $html): array
    {
        $messages = [];

        if (preg_match_all('/<script[^>]*class="joomla-script-options[^"]*"[^>]*>(.*?)<\/script>/is', $html, $scripts)) {
            foreach ($scripts[1] as $json) {
                $options = json_decode(html_entity_decode($json), true);

                foreach (($options['joomla.messages'] ?? []) as $group) {
                    foreach ((array) $group as $type => $texts) {
                        foreach ((array) $texts as $text) {
                            $messages[] = [
                                'type' => strtolower((string) $type),
                                'html' => (string) $text,
                                'text' => trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags((string) $text), ENT_QUOTES, 'UTF-8'))),
                            ];
                        }
                    }
                }
            }
        }

        return $messages;
    }
}

/**
 * Publish $source (with $newVersion in its root manifest) on the local update server and run
 * Check for Updates and Update in the backend.
 *
 * @return array{updated: bool, messages: list<array{type: string, html: string, text: string}>, html: string}
 */
function bu_update(BuBrowser $browser, string $source, string $newVersion): array
{
    global $manifest, $template, $id, $extensionId, $db;

    $directory = JOOMLA_ROOT . '/advans-test-updates/' . $id;
    @mkdir($directory, 0755, true);
    $zipName = $id . '-' . $newVersion . '.zip';
    copy($source, $directory . '/' . $zipName);

    if (sh_set_package_version($directory . '/' . $zipName, $manifest['xmlfile'], $newVersion) === null) {
        bu_check("test package $newVersion", false);

        return ['updated' => false, 'messages' => [], 'html' => ''];
    }

    $xml = (string) file_get_contents($template);
    // Every <update> entry (Product Compare has one per plugin group) points to the test package.
    $xml = preg_replace('#<version>[^<]*</version>#', '<version>' . $newVersion . '</version>', $xml);
    $xml = preg_replace('#<downloadurl([^>]*)>[^<]*</downloadurl>#', '<downloadurl$1>http://localhost/advans-test-updates/' . $id . '/' . $zipName . '</downloadurl>', $xml);
    $xml = preg_replace('#\s*<sha256>[^<]*</sha256>#', '', $xml);
    $xml = preg_replace('#(</downloads>)#', "$1\n        <sha256>" . hash_file('sha256', $directory . '/' . $zipName) . '</sha256>', $xml);
    file_put_contents($directory . '/update.xml', $xml);
    exec('chown -R www-data:www-data ' . escapeshellarg(JOOMLA_ROOT . '/advans-test-updates'));

    $location = 'http://localhost/advans-test-updates/' . $id . '/update.xml';
    $sites = sh_table('update_sites');
    $map = sh_table('update_sites_extensions');
    $linked = $db->query("SELECT update_site_id FROM $map WHERE extension_id = $extensionId")->fetch_all();

    if ($linked === []) {
        $statement = $db->prepare("INSERT INTO $sites (name, type, location, enabled) VALUES ('Local test updates', 'extension', ?, 1)");
        $statement->bind_param('s', $location);
        $statement->execute();
        $db->query("INSERT INTO $map (update_site_id, extension_id) VALUES ({$db->insert_id}, $extensionId)");
    } else {
        $statement = $db->prepare("UPDATE $sites SET location = ?, enabled = 1, last_check_timestamp = 0 WHERE update_site_id IN (SELECT update_site_id FROM $map WHERE extension_id = $extensionId)");
        $statement->bind_param('s', $location);
        $statement->execute();
    }

    $db->query('DELETE FROM ' . sh_table('updates') . " WHERE extension_id = $extensionId");
    $page = $browser->request('index.php?option=com_installer&view=update');
    $token = BuBrowser::token($page);
    $browser->request('index.php?option=com_installer&view=update', ['task' => 'update.find', $token => '1']);
    $update = $db->query('SELECT update_id, version FROM ' . sh_table('updates') . " WHERE extension_id = $extensionId")->fetch_assoc();

    if (!bu_check("Check for Updates finds $newVersion", ($update['version'] ?? '') === $newVersion, json_encode($update))) {
        return ['updated' => false, 'messages' => [], 'html' => ''];
    }

    $page = $browser->request('index.php?option=com_installer&view=update');
    $html = $browser->request('index.php?option=com_installer&view=update', [
        'task'   => 'update.update',
        'cid'    => [(int) $update['update_id']],
        BuBrowser::token($page) => '1',
    ]);
    $messages = BuBrowser::messages($html);

    foreach ($messages as $message) {
        echo "  [{$message['type']}] " . mb_substr($message['text'], 0, 500) . "\n";
    }

    return ['updated' => bu_installed_version() === $newVersion, 'messages' => $messages, 'html' => $html];
}

function bu_text(array $result, array $types = []): string
{
    return implode("\n", array_column(array_filter($result['messages'], static fn(array $m): bool => $types === [] || in_array($m['type'], $types, true)), 'text'));
}

$browser = new BuBrowser();

if (!bu_check('administrator login', $browser->login())) {
    exit(1);
}

$modifyFile = getenv('MODIFY_FILE') ? JOOMLA_ROOT . '/' . ltrim((string) getenv('MODIFY_FILE'), '/') : $installRoot . '/script.php';
$relativeModify = substr($modifyFile, strlen(JOOMLA_ROOT) + 1);

foreach ($scenarios as $scenario) {
    $version = sh_bump_version($version);
    echo "\n--- $scenario (update to $version) ---\n";
    $backupsBefore = bu_backups();

    switch ($scenario) {
        case 'clean':
            if (getenv('EXPECT_BOOTSTRAP') === '1') {
                bu_check('bootstrap: the previous release left no record of written files', !is_file($state . '/installed.php'));
            }

            $result = bu_update($browser, $package, $version);
            bu_check('update succeeded without a pause', $result['updated'] && !str_contains(bu_text($result), 'paused'), bu_text($result));
            bu_check('no error message', bu_text($result, ['danger', 'error']) === '', bu_text($result, ['danger', 'error']));
            bu_check('Joomla verified the checksum (no missing-checksum warning)', !preg_match('/checksum/i', bu_text($result)));
            bu_check('no file was reported as changed (all were recognised as released files)', !str_contains(bu_text($result), 'Changed on this site'), bu_text($result));
            bu_check('no backup was needed', bu_backups() === $backupsBefore, implode(', ', array_diff(bu_backups(), $backupsBefore)));
            $record = is_file($state . '/installed.php') ? json_decode(substr((string) file_get_contents($state . '/installed.php'), strlen("<?php exit; ?>\n")), true) : [];
            bu_check('the record of written files exists', count($record['files'] ?? []) > 0 && ($record['version'] ?? '') === $version);
            break;

        case 'modified':
            $changed = (string) file_get_contents($modifyFile) . "\n// Changed on this site " . bin2hex(random_bytes(4)) . "\n";
            file_put_contents($modifyFile, $changed);
            $result = bu_update($browser, $package, $version);
            bu_check('update succeeded without a pause', $result['updated'], bu_text($result));
            $new = array_values(array_diff(bu_backups(), $backupsBefore));
            $backup = count($new) === 1 ? bu_backup($new[0]) : ['destination' => '', 'bytes' => ''];
            bu_check('exactly one new backup', count($new) === 1, implode(', ', $new));
            bu_check('the backup holds exactly the changed bytes', $backup['bytes'] === $changed && $backup['destination'] === $modifyFile);
            bu_check('the changed file was replaced by the update', hash_file('sha256', $modifyFile) !== hash('sha256', $changed));
            bu_check('the report names the changed file and the backup location', str_contains(bu_text($result), $relativeModify)
                && str_contains(bu_text($result), 'administrator/advans-installer-state/' . $id . '/backups'), bu_text($result));
            break;

        case 'backup-failure':
            $changed = (string) file_get_contents($modifyFile) . "\n// Changed again " . bin2hex(random_bytes(4)) . "\n";
            file_put_contents($modifyFile, $changed);
            $before = bu_snapshot([$installRoot, JOOMLA_ROOT . '/components/' . $manifest['element'], JOOMLA_ROOT . '/media/' . $manifest['element']]);
            $previous = bu_installed_version();
            exec('chown root:root ' . escapeshellarg($state . '/backups') . ' && chmod 0555 ' . escapeshellarg($state . '/backups'));
            $result = bu_update($browser, $package, $version);
            exec('chown www-data:www-data ' . escapeshellarg($state . '/backups') . ' && chmod 0700 ' . escapeshellarg($state . '/backups'));
            bu_check('the update was stopped', !$result['updated'] && bu_installed_version() === $previous);
            bu_check('the stop is explained in plain language', str_contains(bu_text($result, ['danger', 'error']), 'could not be written or checked')
                && str_contains(bu_text($result, ['danger', 'error']), $relativeModify), bu_text($result));
            bu_check('no file was changed', bu_snapshot([$installRoot, JOOMLA_ROOT . '/components/' . $manifest['element'], JOOMLA_ROOT . '/media/' . $manifest['element']]) === $before);
            bu_check('the changed file is untouched', file_get_contents($modifyFile) === $changed);
            // The next round starts from a consistent site again.
            $retry = bu_update($browser, $package, $version = sh_bump_version($version));
            bu_check('after the directory is writable again the same update succeeds', $retry['updated'], bu_text($retry));
            break;

        case 'foreign-pause':
            $foreign = JOOMLA_ROOT . '/media/advans-test-foreign/other.txt';
            @mkdir(dirname($foreign), 0755, true);
            file_put_contents($foreign, "belongs to another extension\n");
            exec('chown -R www-data:www-data ' . escapeshellarg(dirname($foreign)));
            $variant = sys_get_temp_dir() . '/bu-foreign-' . bin2hex(random_bytes(4)) . '.zip';
            copy($package, $variant);
            $zip = new ZipArchive();
            $zip->open($variant);
            $script = (string) $zip->getFromName('script.php');
            // The variant declares the operation for the installer (guardedFiles) and performs it
            // after the files are installed, as the extensions' own foreign operations do.
            $hook = "\n    protected function guardedFiles(string \$type, object \$parent): array\n    {\n"
                . "        return [JPATH_ROOT . '/media/advans-test-foreign/other.txt' => \$this->replacementCandidate(\$parent, \"replaced by the test package\\n\")];\n    }\n"
                . "\n    public function postflight(\$type, \$parent)\n    {\n"
                . "        file_put_contents(JPATH_ROOT . '/media/advans-test-foreign/other.txt', \"replaced by the test package\\n\");\n\n"
                . "        return parent::postflight(\$type, \$parent);\n    }\n";
            $script = preg_replace('/(class\s+\w+\s+extends\s+InstallerScript\s*\{)/', '$1' . $hook, $script, 1, $count);
            bu_check('test variant with a foreign operation built', $count === 1 && $zip->addFromString('script.php', $script) && $zip->close());
            $result = bu_update($browser, $variant, $version);
            $paused = bu_text($result, ['danger', 'error']);
            bu_check('the update paused', !$result['updated'] && str_contains($paused, 'The installation was paused. Nothing has been changed.'), bu_text($result));
            bu_check('the pause names the file and explains why', str_contains($paused, 'media/advans-test-foreign/other.txt') && str_contains($paused, 'belongs to another extension'));
            bu_check('the foreign file is untouched', file_get_contents($foreign) === "belongs to another extension\n");
            $link = preg_match('/href="([^"]*install_directory=[^"]*)"[^>]*>\s*Approve\s*</', implode("\n", array_column($result['messages'], 'html')), $match) === 1 ? html_entity_decode($match[1]) : '';
            bu_check('the pause offers the Approve button to the Super User', $link !== '');
            $form = $browser->request($link);
            $pending = $state . '/pending';
            bu_check('the button opens Install from Folder with the paused copy filled in', str_contains($form, 'value="' . htmlspecialchars($pending) . '"') || str_contains($form, 'value="' . $pending . '"'));
            $approved = $browser->request('index.php?option=com_installer&view=install', [
                'task'              => 'install.install',
                'installtype'       => 'folder',
                'install_directory' => $pending,
                BuBrowser::token($form) => '1',
            ]);
            $messages = ['messages' => BuBrowser::messages($approved)];
            echo '  ' . bu_text($messages) . "\n";
            bu_check('the approved installation completed', bu_installed_version() === $version, bu_text($messages));
            bu_check('the foreign file was replaced after a backup', file_get_contents($foreign) === "replaced by the test package\n");
            $backups = array_map('bu_backup', array_diff(bu_backups(), $backupsBefore));
            bu_check('the backup holds the other extension\'s bytes', in_array("belongs to another extension\n", array_column($backups, 'bytes'), true));
            bu_check('the report names the adjusted foreign file', str_contains(bu_text($messages), 'media/advans-test-foreign/other.txt'));
            bu_check('the paused copy is removed after the installation', !is_dir($pending));
            @unlink($variant);
            break;

        case 'osmap-patch':
            $factory = JOOMLA_ROOT . '/administrator/components/com_osmap/library/Alledia/OSMap/Factory.php';
            $fixed = 'return Table::getInstance($tableName, $prefix) ?: null;';
            $buggy = 'return Table::getInstance($tableName, $prefix);';
            $original = (string) file_get_contents($factory);
            $old = str_replace($fixed, $buggy, $original);
            bu_check('OSMap Factory.php carries the bug for this test', str_contains($old, $buggy) && !str_contains($old, $fixed));
            file_put_contents($factory, $old);
            $result = bu_update($browser, $package, $version);
            bu_check('update succeeded without a pause', $result['updated'], bu_text($result));
            bu_check('OSMap was patched', str_contains((string) file_get_contents($factory), $fixed));
            $backups = array_map('bu_backup', array_diff(bu_backups(), $backupsBefore));
            bu_check('the backup holds the unpatched OSMap file', in_array($old, array_column($backups, 'bytes'), true));
            bu_check('the report names the adjusted OSMap file', str_contains(bu_text($result), 'administrator/components/com_osmap/library/Alledia/OSMap/Factory.php'), bu_text($result));
            break;

        case 'privacy-override':
            $siteTemplate = (string) $db->query('SELECT template FROM ' . sh_table('template_styles') . ' WHERE client_id = 0 AND home = 1')->fetch_row()[0];
            $override = JOOMLA_ROOT . '/templates/' . $siteTemplate . '/html/com_j2commerce/checkout/default_shipping_payment.php';
            $bytes = (string) file_get_contents((string) getenv('PRIVACY_OVERRIDE'));
            @mkdir(dirname($override), 0755, true);
            file_put_contents($override, $bytes);
            exec('chown -R www-data:www-data ' . escapeshellarg(JOOMLA_ROOT . '/templates/' . $siteTemplate . '/html'));
            $result = bu_update($browser, $package, $version);
            bu_check('update succeeded without a pause', $result['updated'], bu_text($result));
            bu_check('the unchanged earlier override was retired by renaming it', !is_file($override) && is_file($override . '.plg_privacy_j2commerce-disabled'));
            $backups = array_map('bu_backup', array_diff(bu_backups(), $backupsBefore));
            bu_check('the backup holds the retired override', in_array($bytes, array_column($backups, 'bytes'), true));
            bu_check('the report names the retired override', str_contains(bu_text($result), 'html/com_j2commerce/checkout/default_shipping_payment.php'), bu_text($result));
            break;

        case 'mirror-changed':
            $mirrorFile = JOOMLA_ROOT . '/plugins/j2store/productcompare/script.php';
            $changed = (string) file_get_contents($mirrorFile) . "\n// Changed in the mirror " . bin2hex(random_bytes(4)) . "\n";
            file_put_contents($mirrorFile, $changed);
            $result = bu_update($browser, $package, $version);
            bu_check('update succeeded without a pause', $result['updated'], bu_text($result));
            bu_check('the mirror file was replaced', file_get_contents($mirrorFile) !== $changed);
            $backups = array_map('bu_backup', array_diff(bu_backups(), $backupsBefore));
            bu_check('the backup holds the changed mirror file', in_array($changed, array_column($backups, 'bytes'), true));
            bu_check('the report names the mirror file', str_contains(bu_text($result), 'plugins/j2store/productcompare/script.php'), bu_text($result));
            break;

        case 'mirror-occupied':
            $mirror = JOOMLA_ROOT . '/plugins/j2store/productcompare';
            $other = $mirror . '/other_extension.xml';
            file_put_contents($other, '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<extension type="plugin" group="j2store"><name>plg_j2store_other_compare</name></extension>' . "\n");
            file_put_contents($mirror . '/script.php', (string) file_get_contents($mirror . '/script.php') . "\n// Belongs to the other extension\n");
            exec('chown -R www-data:www-data ' . escapeshellarg($mirror));
            $before = bu_snapshot([$mirror]);
            $result = bu_update($browser, $package, $version);
            bu_check('update succeeded without a pause', $result['updated'], bu_text($result));
            bu_check('the other extension\'s folder is byte-identical', bu_snapshot([$mirror]) === $before);
            bu_check('the update explains why the mirror was skipped', str_contains(bu_text($result), 'plg_j2store_other_compare'), bu_text($result));
            unlink($other);
            break;

        default:
            bu_check("known scenario $scenario", false);
    }
}

echo "\n=== Backend update summary ===\n";
echo $failures === 0 ? "All checks passed\n" : "$failures check(s) failed\n";

exit($failures === 0 ? 0 : 1);
