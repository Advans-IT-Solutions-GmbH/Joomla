<?php
/**
 * Help page lint: every extension of this repository has a help page that an
 * administrator reaches where he works with the extension.
 *
 * Usage: php shared/tests/help-lint.php [<extension-directory> ...]
 *        Without arguments every extension of the repository is checked.
 *
 * Rules (AGENTS.md, «Jede Extension hat eine Hilfeseite»):
 *
 *   Component
 *     - ships a help view: <admin>/tmpl/help/default.php
 *     - every administrator file that builds a toolbar links to it
 *       (option=<component>&view=help), except the help view itself
 *     - a declared administrator submenu has an entry for view=help
 *
 *   Plugin or module (also the plugins a component or plugin bundles under
 *   plugins/<group>/<element>/) has one of
 *     a) its own «Help» tab: the first parameter fieldset after "basic" and
 *        "description" is named "help" and holds a field of type "helppage"
 *        with at least three sections, the field class ships under
 *        <namespace path>/Field/HelppageField.php and is registered with
 *        addfieldprefix, and the first field of "basic" is a note that points
 *        to the tab;
 *     b) a link to the help view of the component it is delivered with
 *        (bundled below the component's plugins/ folder, or listed in the
 *        same package manifest as the component): the
 *        manifest's <help url="KEY"> names a language key whose en-GB value
 *        opens option=<component>&view=help, and the first field of "basic"
 *        is a note whose en-GB text links to the same help view.
 *
 *   Package: no screen of its own; its extensions are checked themselves.
 *
 * Every copy of HelppageField.php is identical apart from its namespace and
 * @package line, so a fix reaches all of them.
 *
 * Exits with 1 when any problem is found.
 */

$root = dirname(__DIR__, 2);

// Extensions whose help is still being built in a separate pull request. Each
// entry must name that pull request's branch, and that pull request removes the
// entry, so the allowlist can only shrink.
const HELP_ALLOWLIST = [];

const MIN_HELP_SECTIONS = 3;

$errors = [];
$notes  = [];

$fail = static function (string $where, string $message) use (&$errors): void {
    $errors[] = "$where: $message";
};

/**
 * The XML file in $dir whose root element is <extension>.
 */
function hl_manifest(string $dir): ?string
{
    foreach (glob($dir . '/*.xml') ?: [] as $file) {
        $xml = @simplexml_load_file($file);

        if ($xml !== false && $xml->getName() === 'extension') {
            return $file;
        }
    }

    return null;
}

/**
 * Extension directories of the repository: top-level folders and the folders
 * below j2commerce/ that carry a root manifest.
 *
 * @return string[]
 */
function hl_all_extensions(string $root): array
{
    $dirs = [];

    foreach (array_merge(glob($root . '/*', GLOB_ONLYDIR) ?: [], glob($root . '/j2commerce/*', GLOB_ONLYDIR) ?: []) as $dir) {
        if (hl_manifest($dir) !== null) {
            $dirs[] = $dir;
        }
    }

    sort($dirs);

    return $dirs;
}

/**
 * en-GB strings of the language files below $dir/language/en-GB (and the
 * administrator language folder of a component).
 *
 * @return array<string, string>
 */
function hl_english(string $dir): array
{
    $strings = [];
    $files   = array_merge(
        glob($dir . '/language/en-GB/*.ini') ?: [],
        glob($dir . '/administrator/language/en-GB/*.ini') ?: []
    );

    foreach ($files as $file) {
        $parsed = @parse_ini_file($file, false, INI_SCANNER_RAW);

        if (is_array($parsed)) {
            $strings += $parsed;
        }
    }

    return $strings;
}

/**
 * The help view address of a component, in the plain and in the HTML form.
 *
 * @return string[]
 */
function hl_help_addresses(string $component): array
{
    return ["option=$component&view=help", "option=$component&amp;view=help"];
}

function hl_contains_any(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) {
            return true;
        }
    }

    return false;
}

/**
 * Check one component. Returns true when its help view exists.
 */
function hl_check_component(string $dir, SimpleXMLElement $xml, string $name, callable $fail): bool
{
    $element = strtolower(trim((string) $xml->element)) ?: $name;
    $folder  = (string) ($xml->administration->files['folder'] ?? '');
    $admin   = $folder !== '' ? $dir . '/' . $folder : $dir;
    $ok      = true;

    if (!is_file($admin . '/tmpl/help/default.php')) {
        $fail($name, "component without help view: $folder/tmpl/help/default.php is missing");
        $ok = false;
    }

    if (isset($xml->administration->submenu)) {
        $linked = false;

        foreach ($xml->administration->submenu->menu as $menu) {
            if (str_contains((string) $menu['link'], 'view=help')) {
                $linked = true;
            }
        }

        if (!$linked) {
            $fail($name, 'the administrator submenu has no entry for view=help');
        }
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($admin, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path     = str_replace('\\', '/', $file->getPathname());
        $relative = substr($path, strlen(str_replace('\\', '/', $dir)) + 1);

        if (substr($path, -4) !== '.php'
            || preg_match('#/(lib|vendor|tmpl/help|src/View/Help)/#', $path)) {
            continue;
        }

        $code = (string) file_get_contents($path);

        if (!preg_match('/ToolbarHelper::|Toolbar::getInstance\(|->getToolbar\(/', $code)) {
            continue;
        }

        if (!hl_contains_any($code, hl_help_addresses($element))) {
            $fail($name, "$relative builds a toolbar without a link to option=$element&view=help");
        }
    }

    return $ok;
}

/**
 * The parameter fieldsets of a plugin or module manifest, in order.
 *
 * @return SimpleXMLElement[]
 */
function hl_fieldsets(SimpleXMLElement $xml): array
{
    $sets = [];

    foreach ($xml->xpath('/extension/config/fields[@name="params"]/fieldset') ?: [] as $set) {
        $sets[] = $set;
    }

    return $sets;
}

/**
 * The first field of the "basic" fieldset when it is a note, else null.
 */
function hl_basic_note(array $fieldsets): ?SimpleXMLElement
{
    foreach ($fieldsets as $set) {
        if ((string) $set['name'] === 'basic') {
            $first = $set->field[0] ?? null;

            return $first !== null && strtolower((string) $first['type']) === 'note' ? $first : null;
        }
    }

    return null;
}

/**
 * Check one plugin or module. $components maps the components delivered with
 * it (element => has help view).
 *
 * @param array<string, bool> $components
 */
function hl_check_addon(string $dir, SimpleXMLElement $xml, string $name, array $components, callable $fail, array &$copies): void
{
    $fieldsets = hl_fieldsets($xml);
    $english   = hl_english($dir);
    $note      = hl_basic_note($fieldsets);

    // a) Own «Help» tab.
    $tab = null;

    foreach ($fieldsets as $set) {
        if (!in_array((string) $set['name'], ['basic', 'description'], true)) {
            $tab = $set;
            break;
        }
    }

    if ($tab !== null && (string) $tab['name'] === 'help') {
        $page = $tab->xpath('field[@type="helppage"]')[0] ?? null;

        if ($page === null) {
            $fail($name, 'the «help» fieldset has no field of type "helppage"');

            return;
        }

        $sections = count($page->section);

        if ($sections < MIN_HELP_SECTIONS) {
            $fail($name, "the help page has $sections section(s); at least " . MIN_HELP_SECTIONS
                . ' are required (what it does, settings, problems and what to do)');
        }

        foreach ($page->section as $section) {
            if (trim((string) $section['title']) === '' || count($section->children()) === 0) {
                $fail($name, 'every help section needs a title and at least one paragraph, list or note');
            }
        }

        $namespace = trim((string) $xml->namespace);
        $path      = trim((string) ($xml->namespace['path'] ?? 'src'));
        $prefix    = $namespace . '\\Field';
        $declared  = (string) ($xml->config->fields['addfieldprefix'] ?? '') . '|' . (string) ($tab['addfieldprefix'] ?? '');

        if ($namespace === '' || !str_contains($declared, $prefix)) {
            $fail($name, "the help field needs addfieldprefix=\"$prefix\" on <fields> or the «help» fieldset");
        }

        $class = $dir . '/' . $path . '/Field/HelppageField.php';

        if (!is_file($class)) {
            $fail($name, "$path/Field/HelppageField.php is missing");
        } else {
            $copies[$name] = $class;
        }

        if ($note === null) {
            $fail($name, 'the first field of the "basic" fieldset must be a note that points to the «Help» tab');
        }

        return;
    }

    // b) Link to the help view of the component delivered with it.
    $helpKey = trim((string) ($xml->help['url'] ?? ''));

    if ($helpKey !== '') {
        $url    = $english[$helpKey] ?? null;
        $target = null;

        foreach ($components as $component => $hasHelp) {
            if ($url !== null && hl_contains_any($url, hl_help_addresses($component))) {
                $target = $component;
            }
        }

        if ($url === null) {
            $fail($name, "<help url=\"$helpKey\"> names a key that the en-GB language file does not define");
        } elseif ($target === null) {
            $fail($name, "<help url=\"$helpKey\"> does not open the help view of a component delivered with it");
        } elseif (!$components[$target]) {
            $fail($name, "<help url> points to $target, which has no help view");
        }

        $text = $note !== null ? ($english[(string) $note['description']] ?? '') : '';

        if ($target !== null && !hl_contains_any($text, hl_help_addresses($target))) {
            $fail($name, "the first field of the \"basic\" fieldset must be a note whose en-GB text links to option=$target&view=help");
        }

        return;
    }

    $fail($name, 'no reachable help: neither a «help» tab (first fieldset after "basic") nor <help url> to its component\'s help view');
}

/**
 * Components that a package of the repository delivers together with a module
 * or plugin of its own folder: extension folder => (component element => has
 * help view). A module or plugin shipped in such a package may link to the
 * help view of that component like a plugin a component bundles.
 *
 * @return array<string, array<string, bool>>
 */
function hl_package_components(string $root): array
{
    $components = [];
    $packages   = [];

    foreach (hl_all_extensions($root) as $dir) {
        $xml = simplexml_load_file(hl_manifest($dir));

        if ((string) $xml['type'] === 'component') {
            $element = strtolower(trim((string) $xml->element)) ?: basename($dir);
            $folder  = (string) ($xml->administration->files['folder'] ?? '');
            $admin   = $folder !== '' ? $dir . '/' . $folder : $dir;

            $components[$element] = is_file($admin . '/tmpl/help/default.php');
        } elseif ((string) $xml['type'] === 'package') {
            $packages[] = $xml;
        }
    }

    $delivered = [];

    foreach ($packages as $xml) {
        $shipped = [];
        $members = [];

        foreach ($xml->files->file ?? [] as $file) {
            $type = (string) $file['type'];
            $id   = strtolower(trim((string) $file['id']));

            if ($type === 'component' && isset($components[$id])) {
                $shipped[$id] = $components[$id];
            } elseif ($type === 'module') {
                $members[] = $id;
            } elseif ($type === 'plugin') {
                $members[] = 'plg_' . strtolower((string) $file['group']) . '_' . $id;
            }
        }

        foreach ($members as $member) {
            $delivered[$member] = ($delivered[$member] ?? []) + $shipped;
        }
    }

    return $delivered;
}

$targets   = array_slice($argv, 1);
$targets   = $targets ? array_map(static fn (string $t): string => rtrim($root . '/' . trim($t, '/\\'), '/'), $targets) : hl_all_extensions($root);
$copies    = [];
$delivered = hl_package_components($root);

foreach ($targets as $dir) {
    $name     = basename($dir);
    $manifest = hl_manifest($dir);

    if ($manifest === null) {
        $fail($name, 'no extension manifest found');
        continue;
    }

    if (isset(HELP_ALLOWLIST[$name])) {
        $notes[] = "$name: allowed until " . HELP_ALLOWLIST[$name] . ' is merged';
        continue;
    }

    $xml  = simplexml_load_file($manifest);
    $type = (string) $xml['type'];

    // Components shipped by this extension (for the plugins it bundles).
    $components = [];

    if ($type === 'component') {
        $element              = strtolower(trim((string) $xml->element)) ?: $name;
        $components[$element] = hl_check_component($dir, $xml, $name, $fail);
    } elseif ($type === 'plugin' || $type === 'module') {
        hl_check_addon($dir, $xml, $name, $delivered[$name] ?? [], $fail, $copies);
    } elseif ($type === 'package') {
        $notes[] = "$name: package, its extensions are checked themselves";
    } else {
        $fail($name, "unknown extension type \"$type\"");
    }

    foreach (glob($dir . '/plugins/*/*', GLOB_ONLYDIR) ?: [] as $bundled) {
        $bundledManifest = hl_manifest($bundled);

        if ($bundledManifest === null) {
            continue;
        }

        $label = $name . '/plugins/' . basename(dirname($bundled)) . '/' . basename($bundled);
        hl_check_addon($bundled, simplexml_load_file($bundledManifest), $label, $components, $fail, $copies);
    }
}

// All copies of the help field class are the same class.
$normalise = static fn (string $code): string => preg_replace(
    ['/^namespace [^;]+;$/m', '/^ \* @package .*$/m'],
    ['namespace X;', ' * @package X'],
    str_replace("\r\n", "\n", $code)
);
$reference = null;

foreach ($copies as $name => $file) {
    $code = $normalise((string) file_get_contents($file));

    if ($reference === null) {
        $reference = [$name, $code];
    } elseif ($code !== $reference[1]) {
        $fail($name, "HelppageField.php differs from the copy in {$reference[0]} (only namespace and @package may differ)");
    }
}

foreach ($notes as $note) {
    echo "NOTE $note\n";
}

if ($errors) {
    foreach ($errors as $error) {
        echo "FAIL $error\n";
    }

    echo count($errors) . " problem(s) found.\n";
    exit(1);
}

echo 'Help pages: ' . count($targets) . " extension(s) checked, every one has reachable help.\n";
exit(0);
