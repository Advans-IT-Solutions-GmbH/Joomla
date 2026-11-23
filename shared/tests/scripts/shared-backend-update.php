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
 *   clean            update without local changes; no installer copies are created
 *   modified         unknown changes pause the update; Appointments additionally exercises the
 *                    download-to-client, explicit apply, and local-upload recovery flow
 *   foreign-pause    an unknown foreign file pauses until the operator resolves it manually
 *   osmap-patch      the OSMap compatibility change pauses until the operator applies it manually
 *   privacy-override an old checkout override pauses without being renamed or deleted
 *   mirror-changed   (Product Compare, Joomla 5) a changed mirror pauses until manually resolved
 *   mirror-occupied  (Product Compare, Joomla 5) another extension's mirror remains untouched
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

$package   = getenv('UPDATE_PACKAGE') ?: '/tmp/extension-new.zip';
$template  = getenv('UPDATE_XML') ?: '/tmp/update.xml';
$scenarios = array_filter(array_map('trim', explode(',', (string) (getenv('SCENARIOS') ?: 'clean,modified'))));
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

    return array_merge(glob($state . '/backups/*.php') ?: [], glob($state . '/recovery/backups/*.php') ?: []);
}

function bu_state(string $path): array
{
    return is_file($path) ? json_decode(substr((string) file_get_contents($path), strlen("<?php exit; ?>\n")), true, 512, JSON_THROW_ON_ERROR) : [];
}

function bu_review(BuBrowser $browser, string $action, array $review, string $file, array $fields, bool $token = true): string
{
    $page = 'index.php?option=com_appointmentsrecovery';
    $post = ['action' => $action, 'plan' => $review['id'], 'file' => $file] + $fields;
    if ($token) {
        $post[BuBrowser::token($browser->request($page))] = '1';
    }

    return $browser->request($page, $post);
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
            $multipart = count(array_filter($post, static fn(mixed $value): bool => $value instanceof CURLFile)) > 0;
            curl_setopt($curl, CURLOPT_POSTFIELDS, $multipart ? $post : http_build_query($post));
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

function bu_update_after_recommendation(BuBrowser $browser, string $package, string $version): array
{
    $result = bu_update($browser, $package, $version);

    // A package stops once for itself and once for each bundled extension: repeat while a fresh
    // recommendation is the only reason (bounded).
    for ($attempt = 0; $attempt < 8 && !$result['updated'] && str_contains(bu_text($result), 'Before you update'); $attempt++) {
        $result = bu_update($browser, $package, $version);
    }

    return $result;
}

$browser = new BuBrowser();

if (!bu_check('administrator login', $browser->login())) {
    exit(1);
}

$modifyFile = getenv('MODIFY_FILE') ? JOOMLA_ROOT . '/' . ltrim((string) getenv('MODIFY_FILE'), '/')
    : $installRoot . ($id === 'com_appointments' ? '/src/View/Help/HtmlView.php' : '/script.php');
$relativeModify = substr($modifyFile, strlen(JOOMLA_ROOT) + 1);

foreach ($scenarios as $scenario) {
    $version = sh_bump_version($version);
    echo "\n--- $scenario (update to $version) ---\n";
    $backupsBefore = bu_backups();

    switch ($scenario) {
        case 'clean':
            if (getenv('EXPECT_BOOTSTRAP') === '1') {
                // Start from the state of an installation made before the record existed: once a
                // published release writes the record itself, it is removed here, so the update must
                // recognise every installed file through the catalog of released files alone.
                if (is_file($state . '/installed.php')) {
                    unlink($state . '/installed.php');
                    echo "Removed the record of the published release to update from a pre-record state\n";
                }

                bu_check('bootstrap: no record of written files before the update', !is_file($state . '/installed.php'));
            }

            $result = bu_update_after_recommendation($browser, $package, $version);
            bu_check('update succeeded without a pause', $result['updated'] && !str_contains(bu_text($result), 'paused'), bu_text($result));
            bu_check('no error message', bu_text($result, ['danger', 'error']) === '', bu_text($result, ['danger', 'error']));
            bu_check('Joomla verified the checksum (no missing-checksum warning)', !preg_match('/checksum/i', bu_text($result)));
            bu_check('no file was reported as changed (all were recognised as released files)', !str_contains(bu_text($result), 'Changed on this site'), bu_text($result));
            bu_check('no backup was needed', bu_backups() === $backupsBefore, implode(', ', array_diff(bu_backups(), $backupsBefore)));
            $record = is_file($state . '/installed.php') ? json_decode(substr((string) file_get_contents($state . '/installed.php'), strlen("<?php exit; ?>\n")), true) : [];
            bu_check('the record of written files exists', count($record['files'] ?? []) > 0 && ($record['version'] ?? '') === $version);
            break;

        case 'modified':
            $original = (string) file_get_contents($modifyFile);
            $changed = $original . "\n// Changed on this site " . bin2hex(random_bytes(4)) . "\n";
            file_put_contents($modifyFile, $changed);
            $result = bu_update_after_recommendation($browser, $package, $version);
            $paused = bu_text($result, ['danger', 'error']);
            bu_check('an unknown local change pauses before update', !$result['updated'] && str_contains($paused, $relativeModify), $paused);
            bu_check('the changed file remains byte-identical and no server copy is created',
                file_get_contents($modifyFile) === $changed && bu_backups() === $backupsBefore);

            if ($id === 'com_appointments') {
                $reviewFile = $state . '/recovery/review.php';
                $review = bu_state($reviewFile);
                $componentId = null;
                foreach ($review['rows'] ?? [] as $rowId => $row) {
                    if (strtolower(str_replace('\\', '/', $row['destination'])) === strtolower(str_replace('\\', '/', $modifyFile))) {
                        $componentId = (string) $rowId;
                        break;
                    }
                }

                bu_check('the changed Appointments file appears in the review', $componentId !== null);
                if ($componentId === null) {
                    break;
                }

                $page = $browser->request('index.php?option=com_appointmentsrecovery');
                bu_check('the administrator can access the recovery review', $page !== '');
                $downloadUrl = 'index.php?option=com_appointmentsrecovery&view=download&plan='
                    . rawurlencode((string) $review['id']) . '&file=' . rawurlencode($componentId);
                $downloaded = $browser->request($downloadUrl);
                $localCopy = sys_get_temp_dir() . '/appointments-local-' . bin2hex(random_bytes(8)) . '.php';

                try {
                    bu_check('the download contains the reviewed bytes', $downloaded === $changed);
                    file_put_contents($localCopy, $downloaded);
                    bu_check('review metadata stores no file bytes',
                        !str_contains((string) file_get_contents($reviewFile), $changed)
                        && !is_dir($state . '/recovery/backups'));
                    bu_review($browser, 'decide', $review, $componentId, ['choice' => 'apply', 'confirm' => '1'], false);
                    bu_check('a request without a CSRF token cannot authorize the operation',
                        !isset(bu_state($reviewFile)['choices'][$componentId]));
                    $retry = bu_update($browser, $package, $version);
                    bu_check('an incomplete review still pauses', !$retry['updated'] && file_get_contents($modifyFile) === $changed);
                    $review = bu_state($reviewFile);
                    bu_review($browser, 'decide', $review, $componentId, ['choice' => 'apply', 'confirm' => '1']);
                    bu_check('the explicit apply decision is recorded',
                        (bu_state($reviewFile)['choices'][$componentId] ?? '') === 'apply');

                    $retry = bu_update($browser, $package, $version);
                    if (!$retry['updated'] && str_contains(bu_text($retry), 'Before you update')) {
                        bu_check('the backup recommendation stops before a reviewed update copies files',
                            file_get_contents($modifyFile) === $changed,
                            bu_text($retry));
                        $retry = bu_update($browser, $package, $version);
                    }
                    $releaseBytes = (string) file_get_contents($modifyFile);
                    $releaseHash = hash('sha256', $releaseBytes);
                    $attemptFile = $state . '/recovery/attempt.php';
                    bu_check('the reviewed package update completed', $retry['updated'] && $releaseHash !== hash('sha256', $changed), bu_text($retry));
                    bu_check('the recovery attempt contains metadata only',
                        is_file($attemptFile) && !str_contains((string) file_get_contents($attemptFile), $changed));
                    bu_review($browser, 'restore', $review, $componentId, [
                        'expected' => $releaseHash,
                        'restore' => new CURLFile($localCopy, 'application/octet-stream', basename($localCopy)),
                    ]);
                    bu_check('the locally downloaded file can be re-uploaded for recovery',
                        file_get_contents($modifyFile) === $changed);
                    file_put_contents($modifyFile, $releaseBytes);
                } finally {
                    if (is_file($localCopy)) {
                        unlink($localCopy);
                    }
                }
            } else {
                file_put_contents($modifyFile, $original);
                $retry = bu_update($browser, $package, $version);
                bu_check('after manually resolving the conflict the update succeeds', $retry['updated'], bu_text($retry));
                bu_check('manual conflict resolution creates no server copy', bu_backups() === $backupsBefore);
            }
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
            $hook = "\n    protected function guardedFiles(string \$type, object \$parent): array\n    {\n"
                . "        return [JPATH_ROOT . '/media/advans-test-foreign/other.txt' => \$this->replacementCandidate(\$parent, \"replaced by the test package\\n\")];\n    }\n"
                . "\n    public function postflight(\$type, \$parent)\n    {\n"
                . "        file_put_contents(JPATH_ROOT . '/media/advans-test-foreign/other.txt', \"replaced by the test package\\n\");\n\n"
                . "        return parent::postflight(\$type, \$parent);\n    }\n";
            $script = preg_replace('/(class\\s+\\w+\\s+extends\\s+InstallerScript\\s*\\{)/', '$1' . $hook, $script, 1, $count);
            bu_check('test variant with a foreign operation built', $count === 1 && $zip->addFromString('script.php', $script) && $zip->close());
            $result = bu_update_after_recommendation($browser, $variant, $version);
            $paused = bu_text($result, ['danger', 'error']);
            bu_check('unknown foreign operation pauses the update', !$result['updated'] && str_contains($paused, 'The installation was paused. Nothing has been changed.'), $paused);
            bu_check('the pause names the file and explains the conflict',
                str_contains($paused, 'media/advans-test-foreign/other.txt')
                && str_contains($paused, 'has local or unknown contents')
                && !str_contains($paused, 'belongs to another extension'));
            bu_check('the foreign file and stored-copy inventory remain unchanged',
                file_get_contents($foreign) === "belongs to another extension\n" && bu_backups() === $backupsBefore);
            $pauseMessages = array_filter(
                $result['messages'],
                static fn(array $message): bool => in_array($message['type'], ['danger', 'error'], true)
            );
            $pauseHtml = implode("\n", array_column($pauseMessages, 'html'));
            bu_check('the pause offers no approval bypass', !preg_match('/<(?:a|button|form)\b/i', $pauseHtml));
            file_put_contents($foreign, "replaced by the test package\n");
            $retry = bu_update($browser, $variant, $version);
            bu_check('after manual conflict resolution the update completes', $retry['updated'], bu_text($retry));
            bu_check('the resolved foreign file remains as intended without a server copy',
                file_get_contents($foreign) === "replaced by the test package\n" && bu_backups() === $backupsBefore);
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
            $result = bu_update_after_recommendation($browser, $package, $version);
            $paused = bu_text($result, ['danger', 'error']);
            bu_check('the foreign OSMap file pauses the update without being changed', !$result['updated']
                && file_get_contents($factory) === $old && str_contains($paused, 'administrator/components/com_osmap/library/Alledia/OSMap/Factory.php'), $paused);
            bu_check('the OSMap conflict creates no server copy', bu_backups() === $backupsBefore);
            file_put_contents($factory, str_replace($buggy, $fixed, $old));
            $retry = bu_update($browser, $package, $version);
            bu_check('after the operator applies the compatibility change the update completes', $retry['updated'], bu_text($retry));
            bu_check('OSMap remains fixed and no server copy was created',
                str_contains((string) file_get_contents($factory), $fixed) && bu_backups() === $backupsBefore);
            break;

        case 'privacy-override':
            $siteTemplate = (string) $db->query('SELECT template FROM ' . sh_table('template_styles') . ' WHERE client_id = 0 AND home = 1')->fetch_row()[0];
            $override = JOOMLA_ROOT . '/templates/' . $siteTemplate . '/html/com_j2commerce/checkout/default_shipping_payment.php';
            $bytes = (string) file_get_contents((string) getenv('PRIVACY_OVERRIDE'));
            @mkdir(dirname($override), 0755, true);
            file_put_contents($override, $bytes);
            exec('chown -R www-data:www-data ' . escapeshellarg(JOOMLA_ROOT . '/templates/' . $siteTemplate . '/html'));
            $result = bu_update_after_recommendation($browser, $package, $version);
            $paused = bu_text($result, ['danger', 'error']);
            bu_check('the existing checkout override pauses the update and remains untouched', !$result['updated']
                && is_file($override) && file_get_contents($override) === $bytes
                && str_contains($paused, 'html/com_j2commerce/checkout/default_shipping_payment.php'), $paused);
            bu_check('the override is neither renamed nor copied on the server',
                !is_file($override . '.plg_privacy_j2commerce-disabled') && bu_backups() === $backupsBefore);
            unlink($override);
            $retry = bu_update($browser, $package, $version);
            bu_check('after manual review and removal of the override the update completes', $retry['updated'], bu_text($retry));
            bu_check('no installer copy was created', bu_backups() === $backupsBefore);
            break;

        case 'mirror-changed':
            $mirrorFile = JOOMLA_ROOT . '/plugins/j2store/productcompare/script.php';
            $canonicalFile = JOOMLA_ROOT . '/plugins/j2commerce/productcompare/script.php';
            $changed = (string) file_get_contents($mirrorFile) . "\n// Changed in the mirror " . bin2hex(random_bytes(4)) . "\n";
            file_put_contents($mirrorFile, $changed);
            $result = bu_update_after_recommendation($browser, $package, $version);
            $paused = bu_text($result, ['danger', 'error']);
            bu_check('a changed Joomla 5 mirror pauses and remains byte-identical', !$result['updated']
                && file_get_contents($mirrorFile) === $changed && str_contains($paused, 'plugins/j2store/productcompare/script.php'), $paused);
            bu_check('the changed mirror creates no server copy', bu_backups() === $backupsBefore);
            file_put_contents($mirrorFile, (string) file_get_contents($canonicalFile));
            $retry = bu_update($browser, $package, $version);
            bu_check('after manually resolving the mirror conflict the update completes', $retry['updated'], bu_text($retry));
            bu_check('the mirror contains the canonical release bytes without a server copy',
                file_get_contents($mirrorFile) === file_get_contents($canonicalFile) && bu_backups() === $backupsBefore);
            break;

        case 'mirror-occupied':
            $mirror = JOOMLA_ROOT . '/plugins/j2store/productcompare';
            $other = $mirror . '/other_extension.xml';
            file_put_contents($other, '<?xml version="1.0" encoding="utf-8"?>' . "\n" . '<extension type="plugin" group="j2store"><name>plg_j2store_other_compare</name></extension>' . "\n");
            file_put_contents($mirror . '/script.php', (string) file_get_contents($mirror . '/script.php') . "\n// Belongs to the other extension\n");
            exec('chown -R www-data:www-data ' . escapeshellarg($mirror));
            $before = bu_snapshot([$mirror]);
            $result = bu_update_after_recommendation($browser, $package, $version);
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
