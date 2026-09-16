<?php
/**
 * Helpers for the shared test suites (shared-*.php).
 *
 * The helpers only use plain PHP, the Joomla CLI and the database settings
 * from configuration.php, so they work unchanged on Joomla 5 and Joomla 6 and
 * never depend on an application object in the test process.
 */

/**
 * Read type, element, folder, version and update server URL from the root
 * manifest of an installation package.
 *
 * @return array{type:string, element:string, folder:string, version:string, updateserver:string, nested:array<int, array{folder:string, element:string}>, xmlfile:string}|null
 */
function sh_read_package_manifest(string $package): ?array
{
    if (!is_file($package) || !class_exists('ZipArchive')) {
        return null;
    }

    $zip = new ZipArchive();

    if ($zip->open($package) !== true) {
        return null;
    }

    $manifest = null;
    $nested   = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (preg_match('#^plugins/([a-z0-9_]+)/([a-z0-9_]+)/\2\.xml$#i', $name, $m)) {
            $nested[] = ['folder' => $m[1], 'element' => $m[2]];
            continue;
        }

        if (strpos($name, '/') !== false || !str_ends_with(strtolower($name), '.xml')) {
            continue;
        }

        $xml = @simplexml_load_string((string) $zip->getFromIndex($i));

        if ($xml instanceof SimpleXMLElement && $xml->getName() === 'extension') {
            $manifest = ['xml' => $xml, 'file' => $name];
        }
    }

    $zip->close();

    if ($manifest === null) {
        return null;
    }

    /** @var SimpleXMLElement $xml */
    $xml     = $manifest['xml'];
    $type    = (string) $xml['type'];
    $folder  = (string) $xml['group'];
    $element = trim((string) $xml->element);

    if ($element === '' && $type === 'plugin') {
        foreach ($xml->files->children() as $child) {
            if ((string) $child['plugin'] !== '') {
                $element = (string) $child['plugin'];
                break;
            }
        }
    }

    if ($element === '' && $type === 'component') {
        $element = strtolower(preg_replace('/[^A-Za-z0-9_]/', '', (string) $xml->name));
        $element = str_starts_with($element, 'com_') ? $element : 'com_' . $element;
    }

    $updateServer = '';

    if (isset($xml->updateservers->server)) {
        $updateServer = trim((string) $xml->updateservers->server[0]);
    }

    return [
        'type'         => $type,
        'element'      => $element,
        'folder'       => $folder,
        'version'      => trim((string) $xml->version),
        'updateserver' => $updateServer,
        'nested'       => $nested,
        'xmlfile'      => $manifest['file'],
    ];
}

/**
 * The next version above $version for test packages: the last numeric part is
 * raised by one, a pre-release suffix is dropped (1.2.3 -> 1.2.4,
 * 1.2.3-rc1 -> 1.2.4).
 */
function sh_bump_version(string $version): string
{
    if (!preg_match('/^(\d+)(?:\.(\d+))?(?:\.(\d+))?/', trim($version), $m)) {
        return '999.0.0';
    }

    return sprintf('%d.%d.%d', (int) $m[1], (int) ($m[2] ?? 0), (int) ($m[3] ?? 0) + 1);
}

/**
 * Set <version> in the root manifest of a package copy. Returns the new
 * manifest content, or null when the file could not be changed.
 */
function sh_set_package_version(string $package, string $manifestFile, string $version): ?string
{
    $zip = new ZipArchive();

    if ($zip->open($package) !== true) {
        return null;
    }

    $content = $zip->getFromName($manifestFile);
    $updated = is_string($content)
        ? preg_replace('#<version>[^<]*</version>#', '<version>' . htmlspecialchars($version, ENT_XML1) . '</version>', $content, 1, $count)
        : null;

    if (!is_string($updated) || $count !== 1 || !$zip->addFromString($manifestFile, $updated)) {
        $zip->close();

        return null;
    }

    if (!$zip->close()) {
        return null;
    }

    $check = sh_read_package_manifest($package);

    return $check !== null && $check['version'] === $version ? $updated : null;
}

function sh_db(): mysqli
{
    static $db = null;

    if ($db instanceof mysqli) {
        return $db;
    }

    require_once JOOMLA_ROOT . '/configuration.php';
    $config = new JConfig();
    $db     = new mysqli($config->host, $config->user, $config->password, $config->db);
    $db->set_charset('utf8mb4');

    return $db;
}

function sh_table(string $name): string
{
    require_once JOOMLA_ROOT . '/configuration.php';

    return (new JConfig())->dbprefix . $name;
}

function sh_find_extension_id(array $manifest, ?string $folder = null, ?string $element = null): int
{
    $db      = sh_db();
    $type    = $db->real_escape_string($folder === null ? $manifest['type'] : 'plugin');
    $element = $db->real_escape_string($element ?? $manifest['element']);
    $folder  = $db->real_escape_string($folder ?? $manifest['folder']);
    $sql     = 'SELECT extension_id FROM ' . sh_table('extensions')
        . " WHERE type = '$type' AND element = '$element'";

    if ($type === 'plugin') {
        // An installer script may move a plugin to another group on some stacks
        // (product compare registers folder=j2store on Joomla 5 with J2Store 4).
        // INSTALLED_PLUGIN_FOLDERS lists every group the extension may use.
        $folders = [$folder];

        if (func_num_args() < 2 && getenv('INSTALLED_PLUGIN_FOLDERS')) {
            foreach (explode(',', (string) getenv('INSTALLED_PLUGIN_FOLDERS')) as $alias) {
                $alias = trim($alias);

                if ($alias !== '') {
                    $folders[] = $db->real_escape_string($alias);
                }
            }
        }

        $sql .= " AND folder IN ('" . implode("','", array_unique($folders)) . "')";
    }

    $result = $db->query($sql);
    $row    = $result ? $result->fetch_row() : null;

    return $row ? (int) $row[0] : 0;
}

/**
 * Run a Joomla CLI command. Returns [exit code, combined output].
 *
 * @return array{0:int, 1:string}
 */
function sh_cli(string $root, string $arguments): array
{
    $output = [];
    $code   = 0;
    exec('cd ' . escapeshellarg($root) . ' && HTTP_HOST=localhost php cli/joomla.php ' . $arguments . ' 2>&1', $output, $code);

    return [$code, implode("\n", $output)];
}

/**
 * Install a package through the CLI from a private copy (the installer may
 * delete the file it installs from).
 *
 * @return array{0:int, 1:string}
 */
function sh_cli_install(string $root, string $package): array
{
    $copy = sys_get_temp_dir() . '/shared-install-' . bin2hex(random_bytes(4)) . '.zip';
    copy($package, $copy);

    try {
        return sh_cli($root, 'extension:install --path=' . escapeshellarg($copy) . ' --no-interaction');
    } finally {
        @unlink($copy);
    }
}

/**
 * Switch the language the Joomla CLI application uses (configuration.php
 * `$language`). Languages without an installed core pack get a minimal
 * metadata file so Joomla accepts the tag; the extension's own language files
 * are what is being tested. Returns a callable that restores everything.
 */
function sh_use_cli_language(string $root, string $tag): callable
{
    $configFile = $root . '/configuration.php';
    $original   = file_get_contents($configFile);
    $created    = [];

    foreach ([$root . '/administrator/language/' . $tag, $root . '/language/' . $tag] as $dir) {
        $metadata = $dir . '/langmetadata.xml';

        if (is_file($metadata)) {
            continue;
        }

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
            $created[] = $dir;
        }

        $locale = str_replace('-', '_', $tag);
        file_put_contents(
            $metadata,
            '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<metafile client="' . (str_contains($dir, '/administrator/') ? 'administrator' : 'site') . '">'
            . "<name>$tag (test stub)</name><version>1.0.0</version><creationDate>2026-01</creationDate>"
            . "<author>Test</author><metadata><name>$tag</name><tag>$tag</tag><rtl>0</rtl>"
            . "<locale>$locale.utf8, $locale, $tag</locale><firstDay>1</firstDay><weekEnd>0,6</weekEnd>"
            . '<calendar>gregorian</calendar></metadata></metafile>'
        );
        $created[] = $metadata;
    }

    if (preg_match('/public \$language\s*=\s*[^;]*;/', $original)) {
        $updated = preg_replace('/public \$language\s*=\s*[^;]*;/', "public \$language = '$tag';", $original);
    } else {
        $updated = preg_replace('/}\s*$/', "    public \$language = '$tag';\n}\n", $original);
    }

    file_put_contents($configFile, $updated);

    return static function () use ($configFile, $original, $created): void {
        file_put_contents($configFile, $original);

        foreach (array_reverse($created) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }
    };
}

function sh_strip_ansi(string $text): string
{
    return preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);
}

/**
 * Text without HTML tags, entities and line wrapping, for comparing CLI output
 * (which wraps long messages) with language strings.
 */
function sh_plain_text(string $text): string
{
    $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return trim((string) preg_replace('/\s+/u', ' ', $text));
}

/**
 * All language strings of one language in an installation package
 * (every <tag>/*.ini except *.sys.ini).
 *
 * @return array<string, string>
 */
function sh_read_package_language(string $package, string $tag): array
{
    $strings = [];

    if (!is_file($package) || !class_exists('ZipArchive')) {
        return $strings;
    }

    $zip = new ZipArchive();

    if ($zip->open($package) !== true) {
        return $strings;
    }

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = (string) $zip->getNameIndex($i);

        if (!preg_match('#(^|/)' . preg_quote($tag, '#') . '/[^/]+\.ini$#', $name) || str_ends_with($name, '.sys.ini')) {
            continue;
        }

        $parsed = @parse_ini_string((string) $zip->getFromIndex($i), false, INI_SCANNER_RAW);

        foreach (is_array($parsed) ? $parsed : [] as $key => $value) {
            $strings[(string) $key] = str_replace('"_QQ_"', '"', (string) $value);
        }
    }

    $zip->close();

    return $strings;
}

function sh_extension_enabled(int $extensionId): ?int
{
    $result = sh_db()->query('SELECT enabled FROM ' . sh_table('extensions') . ' WHERE extension_id = ' . $extensionId);
    $row    = $result ? $result->fetch_row() : null;

    return $row ? (int) $row[0] : null;
}

function sh_set_extension_enabled(int $extensionId, int $enabled): void
{
    sh_db()->query('UPDATE ' . sh_table('extensions') . ' SET enabled = ' . ($enabled ? 1 : 0) . ' WHERE extension_id = ' . $extensionId);
}

/**
 * Untranslated language keys in a text. Two segments are enough
 * (PLG_EXAMPLE); the part after the prefix must start with a letter or digit.
 * Table and element names in installer output are lower case and do not match.
 *
 * @return string[]
 */
function sh_find_raw_language_keys(string $text): array
{
    preg_match_all('/\b(?:PLG|COM|MOD|PKG|TPL|LIB|JLIB|FILES)_[A-Z0-9][A-Z0-9_]*\b/', $text, $m);

    return array_values(array_unique($m[0]));
}

/**
 * A distinctive piece of a language string for searching it in (plain) CLI
 * output: the longest literal part between sprintf placeholders, at most
 * 60 characters. Null when the string has no literal part of 15 characters.
 */
function sh_language_fragment(string $value): ?string
{
    $parts = preg_split('/%(?:\d+\$)?[sdfu%]/', sh_plain_text($value)) ?: [];
    usort($parts, static fn (string $a, string $b): int => mb_strlen(trim($b)) <=> mb_strlen(trim($a)));
    $best = trim((string) ($parts[0] ?? ''));

    return mb_strlen($best) >= 15 ? mb_substr($best, 0, 60) : null;
}

/**
 * Keys of the language strings of $tag that appear in $plain (see
 * sh_plain_text). With $reference (the en-GB strings of the same package) only
 * strings whose text differs from the reference count, so a match proves that
 * the $tag language file was used and not the English fallback.
 *
 * @param array<string, string>      $strings
 * @param array<string, string>|null $reference
 *
 * @return string[]
 */
function sh_shown_language_keys(string $plain, array $strings, ?array $reference = null): array
{
    $shown = [];

    foreach ($strings as $key => $value) {
        $fragment = sh_language_fragment($value);

        if ($fragment === null || !str_contains($plain, $fragment)) {
            continue;
        }

        if ($reference !== null && isset($reference[$key]) && sh_language_fragment($reference[$key]) === $fragment) {
            continue;
        }

        $shown[] = $key;
    }

    return $shown;
}

/**
 * True when a test that cannot run must fail instead of being skipped.
 */
function sh_strict_skip(): bool
{
    return getenv('TEST_STRICT_SKIP') === '1';
}
