<?php
/**
 * Shared suite: the help of every plugin of the package is reachable in its
 * edit screen (AGENTS.md, «Jede Extension hat eine Hilfeseite").
 *
 * shared/tests/help-lint.php checks the manifests statically. This suite opens
 * the real plugin edit screen (com_plugins) as the test administrator, in
 * en-GB, de-DE and fr-FR, for the root plugin of the package and for every
 * plugin it bundles under plugins/<group>/<element>/:
 *
 *   - a plugin with its own «Help» tab: the tab is rendered, it is the first
 *     tab of the plugin's own fieldsets, it shows its section headings and the
 *     note at the top of the options in the page language;
 *   - a plugin that links to its component's help: the toolbar Help button
 *     opens option=<component>&view=help, the note at the top of the options
 *     links there, and the help page itself renders with the anchor the
 *     button targets.
 *
 * Every page must be HTTP 200, without a PHP error, a Joomla error page or a
 * raw language key of the package. Components' own help views are rendered and
 * their toolbar links checked by shared-backend-views.php.
 *
 * The request and login helpers follow shared-backend-views.php; that file is
 * a suite, not a library, so they cannot be included from it.
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

$package   = getenv('HELP_PAGES_PACKAGE') ?: '/tmp/extension.zip';
$baseUrl   = getenv('BACKEND_VIEWS_BASE_URL') ?: 'http://localhost';
$adminUser = getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin';
$adminPass = getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#';
$cookieJar = '/tmp/shared-help-pages-cookies.txt';

$failures = 0;

function hp_fail(string $message): void
{
    global $failures;
    $failures++;
    echo "FAIL $message\n";
}

function hp_pass(string $message): void
{
    echo "PASS $message\n";
}

/**
 * @return array{code:int, body:string}
 */
function hp_request(string $url, array $post = [], bool $follow = false): array
{
    global $cookieJar;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIEJAR      => $cookieJar,
        CURLOPT_COOKIEFILE     => $cookieJar,
    ]);

    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }

    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    return ['code' => $code, 'body' => $body === false ? '' : $body];
}

function hp_token(string $html): ?string
{
    foreach (['/name="([a-f0-9]{32})" value="1"/', '/"csrf\.token":"([a-f0-9]{32})"/'] as $pattern) {
        if (preg_match($pattern, $html, $m)) {
            return $m[1];
        }
    }

    return null;
}

function hp_login(string $baseUrl, string $user, string $pass, string $language): bool
{
    global $cookieJar;

    @unlink($cookieJar);
    $token = null;

    for ($i = 0; $i < 5 && $token === null; $i++) {
        $token = hp_token(hp_request($baseUrl . '/administrator/index.php', [], true)['body']);

        if ($token === null) {
            sleep(2);
        }
    }

    if ($token === null) {
        return false;
    }

    $r = hp_request($baseUrl . '/administrator/index.php', [
        'username' => $user,
        'passwd'   => $pass,
        'option'   => 'com_login',
        'task'     => 'login',
        'lang'     => $language,
        'return'   => base64_encode('index.php'),
        $token     => '1',
    ], true);

    return str_contains($r['body'], 'task=logout') || str_contains($r['body'], 'com_cpanel');
}

/**
 * The page without script and style blocks (Joomla injects JavaScript
 * language strings into every page, see shared-backend-views.php).
 */
function hp_without_scripts(string $html): string
{
    return (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
}

/**
 * @return string[]
 */
function hp_error_markers(string $html): array
{
    $patterns = [
        'PHP fatal error'    => '/\bFatal error\b/i',
        'uncaught exception' => '/Uncaught\s+\\\\?[A-Za-z_]/',
        'PHP warning'        => '#<b>Warning</b>\s*:#i',
        'PHP notice'         => '#<b>Notice</b>\s*:#i',
        'PHP deprecation'    => '#<b>Deprecated</b>\s*:#i',
        'Joomla error page'  => '/An error has occurred|Es ist ein Fehler aufgetreten/i',
        'manifest not found' => '/JERROR_LOADFILE_FAILED|Could not load file/i',
    ];

    return array_keys(array_filter($patterns, static fn (string $p): bool => (bool) preg_match($p, $html)));
}

/**
 * The data-url of the toolbar Help button, decoded, or null.
 */
function hp_help_button_url(string $html): ?string
{
    if (preg_match_all('/<[a-z-]+\b[^>]*js-toolbar-help-btn[^>]*>/i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (preg_match('/data-url="([^"]*)"/', $tag, $m)) {
                return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
            }
        }
    }

    return null;
}

/**
 * Plugins of the package with their manifest: the root plugin and every
 * bundled plugin.
 *
 * @return array<int, array{label:string, folder:?string, element:string, xml:SimpleXMLElement, root:bool}>
 */
function hp_plugins(string $package, array $manifest): array
{
    $zip = new ZipArchive();
    $zip->open($package);
    $plugins = [];

    if ($manifest['type'] === 'plugin') {
        $xml = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (!str_contains($name, '/') && str_ends_with($name, '.xml')) {
                $candidate = @simplexml_load_string((string) $zip->getFromIndex($i));

                if ($candidate instanceof SimpleXMLElement && $candidate->getName() === 'extension') {
                    $xml = $candidate;
                }
            }
        }

        if ($xml !== null) {
            $plugins[] = ['label' => $manifest['element'], 'folder' => null, 'element' => $manifest['element'], 'xml' => $xml, 'root' => true];
        }
    }

    // A package bundles its plugins as archives (packages/*.zip); each one is read with its own
    // language files.
    if ($manifest['type'] === 'package') {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (!preg_match('#^packages/[^/]+\.zip$#', $name)) {
                continue;
            }

            $archive = sys_get_temp_dir() . '/help-pages-' . hash('sha256', $package . "\0" . $name) . '.zip';
            file_put_contents($archive, (string) $zip->getFromIndex($i));
            $child = sh_read_package_manifest($archive);
            $childZip = new ZipArchive();

            if ($child === null || $child['type'] !== 'plugin' || $childZip->open($archive) !== true) {
                continue;
            }

            $xml = @simplexml_load_string((string) $childZip->getFromName($child['xmlfile']));
            $childZip->close();

            if ($xml instanceof SimpleXMLElement) {
                $plugins[] = [
                    'label'   => "plg_{$child['folder']}_{$child['element']}",
                    'folder'  => $child['folder'],
                    'element' => $child['element'],
                    'xml'     => $xml,
                    'root'    => false,
                    'archive' => $archive,
                ];
            }
        }
    }

    foreach ($manifest['nested'] as $nested) {
        $content = $zip->getFromName("plugins/{$nested['folder']}/{$nested['element']}/{$nested['element']}.xml");
        $xml     = $content === false ? false : @simplexml_load_string($content);

        if ($xml instanceof SimpleXMLElement) {
            $plugins[] = [
                'label'   => "plg_{$nested['folder']}_{$nested['element']}",
                'folder'  => $nested['folder'],
                'element' => $nested['element'],
                'xml'     => $xml,
                'root'    => false,
            ];
        }
    }

    $zip->close();

    return $plugins;
}

/**
 * The text of a language string as it appears in the page's plain text.
 */
function hp_plain(string $value): string
{
    return trim(sh_plain_text($value));
}

echo "=== Help pages of plugins (authenticated HTTP) ===\n\n";

$manifest = sh_read_package_manifest($package);

if ($manifest === null) {
    hp_fail("cannot read the extension manifest from $package");
    exit(1);
}

$plugins = hp_plugins($package, $manifest);

if (!$plugins) {
    hp_fail("{$manifest['element']} ships no plugin, so this suite has nothing to check");
    exit(1);
}

echo 'Plugins: ' . implode(', ', array_column($plugins, 'label')) . "\n";

$englishStrings = sh_read_package_language($package, 'en-GB');

foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
    $restore = sh_use_cli_language(JOOMLA_ROOT, $tag);

    try {
        // Joomla only copies the language files of tags that exist.
        [$code, $output] = sh_cli_install_checked(JOOMLA_ROOT, $package);

        if ($code !== 0) {
            hp_fail("$tag: cannot install language files: " . sh_strip_ansi($output));
            continue;
        }

        $strings = sh_read_package_language($package, $tag);

        if (!hp_login($baseUrl, $adminUser, $adminPass, $tag)) {
            hp_fail("$tag: cannot authenticate against /administrator");
            continue;
        }

        foreach ($plugins as $plugin) {
            $label = "$tag: {$plugin['label']}";
            // A plugin bundled in a package carries its own language files.
            $strings = sh_read_package_language($plugin['archive'] ?? $package, $tag);
            $englishStrings = sh_read_package_language($plugin['archive'] ?? $package, 'en-GB');
            $id    = $plugin['root'] ? sh_find_extension_id($manifest) : sh_find_extension_id($manifest, $plugin['folder'], $plugin['element']);

            echo "\n--- $label ---\n";

            if ($id === 0) {
                hp_fail("$label: not registered in #__extensions");
                continue;
            }

            $response = hp_request($baseUrl . '/administrator/index.php?option=com_plugins&task=plugin.edit&extension_id=' . $id, [], true);
            $page     = hp_without_scripts($response['body']);
            $text     = sh_plain_text($page);

            if ($response['code'] !== 200 || !str_contains($page, 'extension_id=' . $id)) {
                hp_fail("$label: the edit screen did not open (HTTP {$response['code']})");
                echo '  [diag] ' . mb_substr($text, 0, 400) . "\n";
                continue;
            }

            $markers = hp_error_markers($page);
            $markers ? hp_fail("$label: error markers: " . implode(', ', $markers)) : hp_pass("$label: edit screen renders without errors");

            $rawKeys = array_values(array_intersect(sh_find_raw_language_keys($text), array_keys($englishStrings)));
            $rawKeys
                ? hp_fail("$label: untranslated keys: " . implode(', ', array_slice($rawKeys, 0, 5)))
                : hp_pass("$label: no untranslated key of the package");

            $xml  = $plugin['xml'];
            $note = $xml->xpath('/extension/config/fields[@name="params"]/fieldset[@name="basic"]/field[1][@type="note"]')[0] ?? null;

            if ($note === null || !isset($strings[(string) $note['description']])) {
                hp_fail("$label: the manifest has no note at the top of the options");
            } elseif (!str_contains($text, hp_plain($strings[(string) $note['description']]))) {
                hp_fail("$label: the note at the top of the options is not shown in $tag");
            } else {
                hp_pass("$label: the note at the top of the options is shown");
            }

            $helpFieldset = $xml->xpath('/extension/config/fields[@name="params"]/fieldset[@name="help"]')[0] ?? null;

            if ($helpFieldset !== null) {
                // Own «Help» tab: first of the plugin's tabs, with its sections.
                preg_match_all('/\bid="attrib-([a-z0-9_]+)"/', $page, $tabs);

                if (($tabs[1][0] ?? null) !== 'help') {
                    hp_fail("$label: the Help tab is not the first tab of the plugin's options (" . implode(', ', $tabs[1]) . ')');
                } else {
                    hp_pass("$label: the Help tab is the first tab of the plugin's options");
                }

                $tabLabel = $strings[(string) $helpFieldset['label']] ?? null;

                if ($tabLabel === null || !str_contains($page, '<legend>' . $tabLabel . '</legend>')) {
                    hp_fail("$label: the Help tab is not labelled in $tag");
                } else {
                    hp_pass("$label: the Help tab is labelled \"$tabLabel\"");
                }

                $missing = [];

                foreach ($helpFieldset->xpath('field[@type="helppage"]/section/@title') ?: [] as $title) {
                    $value = $strings[(string) $title] ?? null;

                    if ($value === null || !str_contains($text, hp_plain($value))) {
                        $missing[] = (string) $title;
                    }
                }

                $missing
                    ? hp_fail("$label: help sections not shown in $tag: " . implode(', ', $missing))
                    : hp_pass("$label: every help section is shown");
            } else {
                // Link to the component's help view.
                $url = hp_help_button_url($page);

                if ($url === null || !preg_match('/option=(com_[a-z0-9_]+)&view=help/', $url, $m)) {
                    hp_fail("$label: the toolbar Help button does not open a component help view (" . ($url ?? 'no button') . ')');
                    continue;
                }

                hp_pass("$label: the toolbar Help button opens $url");

                $noteHtml = $note !== null ? ($strings[(string) $note['description']] ?? '') : '';
                str_contains($noteHtml, 'option=' . $m[1] . '&amp;view=help')
                    ? hp_pass("$label: the note links to the help of {$m[1]}")
                    : hp_fail("$label: the note does not link to the help of {$m[1]}");

                $help     = hp_request($baseUrl . '/administrator/' . ltrim(preg_replace('/#.*$/', '', $url), '/'));
                $helpPage = hp_without_scripts($help['body']);
                $anchor   = parse_url($url, PHP_URL_FRAGMENT);

                if ($help['code'] !== 200 || hp_error_markers($helpPage)) {
                    hp_fail("$label: the help page does not render (HTTP {$help['code']})");
                } elseif ($anchor && !str_contains($helpPage, 'id="' . $anchor . '"')) {
                    hp_fail("$label: the help page has no section #$anchor");
                } else {
                    hp_pass("$label: the help page renders" . ($anchor ? " with section #$anchor" : ''));
                }
            }

            // Release the checkout the edit screen took.
            $token = hp_token($response['body']);

            if ($token !== null) {
                hp_request($baseUrl . '/administrator/index.php', [
                    'option'       => 'com_plugins',
                    'task'         => 'plugin.cancel',
                    'extension_id' => $id,
                    $token         => '1',
                ], true);
            }
        }
    } finally {
        $restore();
    }
}

echo "\n=== Help Pages Summary ===\n";
echo $failures === 0 ? "All checks passed\n" : "$failures check(s) failed\n";

exit($failures === 0 ? 0 : 1);
