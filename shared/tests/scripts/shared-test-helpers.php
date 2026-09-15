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
        $sql .= " AND folder = '$folder'";
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
    exec('cd ' . escapeshellarg($root) . ' && php cli/joomla.php ' . $arguments . ' 2>&1', $output, $code);

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
 * @return string[]
 */
function sh_find_raw_language_keys(string $text): array
{
    preg_match_all('/\b(?:PLG|COM|MOD|PKG|TPL|LIB|JLIB|FILES)_[A-Z0-9]+(?:_[A-Z0-9]+){1,}\b/', $text, $m);

    return array_values(array_unique($m[0]));
}

/**
 * True when a test that cannot run must fail instead of being skipped.
 */
function sh_strict_skip(): bool
{
    return getenv('TEST_STRICT_SKIP') === '1';
}
