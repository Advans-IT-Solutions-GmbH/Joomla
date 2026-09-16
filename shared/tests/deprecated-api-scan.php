<?php
/**
 * Static scan for deprecated Joomla APIs in the production code of the extensions.
 *
 * Usage (repository root, no Joomla needed):
 *   php shared/tests/deprecated-api-scan.php [extension-dir ...]
 *
 * Without arguments all extensions of the repository are scanned. Test folders
 * (tests, tests-j2c4, tests-j2c6), vendor and node_modules are skipped.
 *
 * The scan works on the PHP tokens (token_get_all), so strings and comments
 * never match. Every finding is printed as path:line and fails the scan unless
 * an entry in ALLOWLIST below covers it.
 *
 * Checked APIs (deprecated in Joomla 5.4 and/or removed in Joomla 6/7):
 *   - Factory::getDbo/getUser/getSession/getDocument/getLanguage/getConfig/
 *     getMailer/getCache and the static Factory::$database/... properties
 *   - legacy J-prefixed class aliases (JFactory, JText, ...), jimport(),
 *     JLoader::register/import/discover/loadByPsr4
 *   - $db->getQuery(true) unless the same function checks
 *     method_exists($db, 'createQuery') first (joomla/database 3.x in Joomla 5.4
 *     already provides DatabaseDriver::createQuery(), so that branch is dead
 *     code on every supported Joomla version)
 *   - setQuery() with offset/limit arguments
 *   - $app->input (use getInput())
 *   - Table::getInstance(), BaseDatabaseModel/BaseController::getInstance(),
 *     Session::getInstance(), ->getDbo(), JPATH_COMPONENT*,
 *     HTMLHelper::script()/stylesheet(), CMSObject, Joomla\CMS\Filesystem\*
 *   - plugins (CMSPlugin subclasses): $this->app / $this->db, declared $app/$db
 *     properties, $allowLegacyListeners, a dispatcher passed to the plugin
 *     constructor (new Plugin($dispatcher, ...) or parent::__construct($subject, ...))
 */

const ALLOWLIST = [
    // Each entry: file (path relative to the repository root, regex), rule, reason.
    [
        'file'   => '#^j2commerce/plg_privacy_j2commerce/src/Extension/J2Commerce\.php$#',
        'rule'   => 'app-input',
        'reason' => 'File is being reworked in a parallel change; replace $app->input with $app->getInput() there.',
    ],
];

const LEGACY_CLASS_EXCEPTIONS = ['JLoader', 'JConfig', 'JVERSION', 'JPATH_BASE', 'JDEBUG'];

const FACTORY_METHODS = ['getdbo', 'getuser', 'getsession', 'getdocument', 'getlanguage', 'getconfig', 'getmailer', 'getcache'];
const FACTORY_PROPERTIES = ['$config', '$session', '$language', '$document', '$database', '$mailer'];

$repoRoot = dirname(__DIR__, 2);
$targets  = array_slice($argv, 1);

if ($targets === []) {
    $targets = [
        'plg_ajax_joomlaajaxforms',
        'j2commerce/com_j2commerce_importexport',
        'j2commerce/com_j2store_cleanup',
        'j2commerce/plg_j2commerce_productcompare',
        'j2commerce/plg_osmap_j2commerce',
        'j2commerce/plg_privacy_j2commerce',
    ];
}

$files = [];

foreach ($targets as $target) {
    $dir = is_dir($target) ? $target : $repoRoot . '/' . $target;

    if (!is_dir($dir)) {
        fwrite(STDERR, "ERROR: $target is not a directory\n");
        exit(2);
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $f) => !($f->isDir() && in_array($f->getFilename(), ['tests', 'tests-j2c4', 'tests-j2c6', 'vendor', 'node_modules', '.git'], true))
        )
    );

    foreach ($iterator as $file) {
        if (strtolower($file->getExtension()) === 'php') {
            $files[] = $file->getPathname();
        }
    }
}

sort($files);

/**
 * Tokenise a file and drop whitespace/comments; every token becomes [id, text, line].
 */
function scan_tokens(string $path): array
{
    $result = [];
    $line   = 1;

    foreach (token_get_all((string) file_get_contents($path)) as $token) {
        if (is_array($token)) {
            $line = $token[2];

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML, T_OPEN_TAG, T_CLOSE_TAG], true)) {
                continue;
            }

            $result[] = [$token[0], $token[1], $token[2]];
        } else {
            $result[] = [0, $token, $line];
        }
    }

    return $result;
}

function is_name_token(array $t): bool
{
    return in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true);
}

/**
 * Collect namespace, use imports and class declarations of a token list.
 */
function scan_structure(array $tokens): array
{
    $namespace = '';
    $uses      = [];
    $classes   = [];
    $depth     = 0;
    $n         = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        [$id, $text] = $tokens[$i];

        if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif ($text === '}') {
            $depth--;
        }

        if ($id === T_NAMESPACE && isset($tokens[$i + 1]) && is_name_token($tokens[$i + 1])) {
            $namespace = ltrim($tokens[$i + 1][1], '\\');
        }

        if ($id === T_USE && $depth === 0) {
            // use A\B [as C], D\E;  (group uses are not used in this repository)
            $j = $i + 1;

            while ($j < $n && $tokens[$j][1] !== ';') {
                if (is_name_token($tokens[$j])) {
                    $fq    = ltrim($tokens[$j][1], '\\');
                    $alias = substr($fq, (int) strrpos('\\' . $fq, '\\'));

                    if (isset($tokens[$j + 1]) && $tokens[$j + 1][0] === T_AS) {
                        $alias = $tokens[$j + 2][1];
                        $j += 2;
                    }

                    $uses[strtolower($alias)] = $fq;
                }

                $j++;
            }
        }

        if ($id === T_CLASS && !(isset($tokens[$i - 1]) && $tokens[$i - 1][0] === T_DOUBLE_COLON)) {
            $name   = (isset($tokens[$i + 1]) && $tokens[$i + 1][0] === T_STRING) ? $tokens[$i + 1][1] : null;
            $parent = null;

            for ($j = $i + 1; $j < $n && $tokens[$j][1] !== '{'; $j++) {
                if ($tokens[$j][0] === T_EXTENDS && isset($tokens[$j + 1])) {
                    $parent = $tokens[$j + 1][1];
                }
            }

            $bodyStart = $j;
            $bodyEnd   = find_matching($tokens, $bodyStart);

            $classes[] = [
                'name'   => $name === null ? null : ltrim(($namespace !== '' ? $namespace . '\\' : '') . $name, '\\'),
                'parent' => $parent,
                'start'  => $bodyStart,
                'end'    => $bodyEnd,
            ];
        }
    }

    return ['namespace' => $namespace, 'uses' => $uses, 'classes' => $classes];
}

function find_matching(array $tokens, int $open): int
{
    $depth = 0;
    $n     = count($tokens);

    for ($i = $open; $i < $n; $i++) {
        $text = $tokens[$i][1];

        if ($text === '{' || $text === '(' || $text === '[' || $tokens[$i][0] === T_CURLY_OPEN || $tokens[$i][0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif ($text === '}' || $text === ')' || $text === ']') {
            $depth--;

            if ($depth === 0) {
                return $i;
            }
        }
    }

    return $n - 1;
}

function resolve_name(string $name, array $structure): string
{
    if ($name[0] === '\\') {
        return ltrim($name, '\\');
    }

    $first = strtolower(explode('\\', $name)[0]);

    if (isset($structure['uses'][$first])) {
        $rest = substr($name, strlen($first));

        return $structure['uses'][$first] . $rest;
    }

    return ltrim(($structure['namespace'] !== '' ? $structure['namespace'] . '\\' : '') . $name, '\\');
}

/**
 * Split the arguments of the call whose "(" is at $open into token lists.
 */
function call_arguments(array $tokens, int $open): array
{
    $close = find_matching($tokens, $open);
    $args  = [];
    $cur   = [];
    $depth = 0;

    for ($i = $open + 1; $i < $close; $i++) {
        $text = $tokens[$i][1];

        if (in_array($text, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($text, [')', ']', '}'], true)) {
            $depth--;
        }

        if ($text === ',' && $depth === 0) {
            $args[] = $cur;
            $cur    = [];
            continue;
        }

        $cur[] = $tokens[$i];
    }

    if ($cur !== []) {
        $args[] = $cur;
    }

    return $args;
}

// Pass 1: structure of every file, and the class hierarchy across the scanned code.
$parsed  = [];
$parents = [];

foreach ($files as $path) {
    $tokens    = scan_tokens($path);
    $structure = scan_structure($tokens);

    foreach ($structure['classes'] as $class) {
        if ($class['name'] !== null && $class['parent'] !== null) {
            $parents[strtolower($class['name'])] = strtolower(resolve_name($class['parent'], $structure));
        }
    }

    $parsed[$path] = [$tokens, $structure];
}

function is_plugin_class(?string $fqcn, array $parents): bool
{
    $seen = [];

    while ($fqcn !== null && !isset($seen[$fqcn])) {
        if ($fqcn === 'joomla\\cms\\plugin\\cmsplugin') {
            return true;
        }

        $seen[$fqcn] = true;
        $fqcn        = $parents[$fqcn] ?? null;
    }

    return false;
}

// Pass 2: rules.
$findings = [];

foreach ($parsed as $path => [$tokens, $structure]) {
    $relative = str_replace('\\', '/', substr(realpath($path) ?: $path, strlen(realpath($repoRoot)) + 1));
    $n        = count($tokens);
    $add      = static function (string $rule, int $line, string $message) use (&$findings, $relative): void {
        $findings[] = ['file' => $relative, 'line' => $line, 'rule' => $rule, 'message' => $message];
    };

    // Function bodies, for the createQuery() guard.
    $functions = [];

    for ($i = 0; $i < $n; $i++) {
        if ($tokens[$i][0] === T_FUNCTION || $tokens[$i][0] === T_FN) {
            for ($j = $i + 1; $j < $n && !in_array($tokens[$j][1], ['{', ';'], true); $j++) {
                if ($tokens[$i][0] === T_FN && $tokens[$j][0] === T_DOUBLE_ARROW) {
                    break;
                }
            }

            if ($j < $n && $tokens[$j][1] === '{') {
                $functions[] = [$j, find_matching($tokens, $j)];
            } elseif ($j < $n && $tokens[$j][0] === T_DOUBLE_ARROW) {
                $end = $j;

                while ($end < $n && !in_array($tokens[$end][1], [';', ',', ')', ']'], true)) {
                    $end++;
                }

                $functions[] = [$j, $end];
            }
        }
    }

    $enclosingFunction = static function (int $pos) use ($functions): array {
        $best = [0, PHP_INT_MAX];

        foreach ($functions as [$start, $end]) {
            if ($start <= $pos && $pos <= $end && ($end - $start) < ($best[1] - $best[0])) {
                $best = [$start, $end];
            }
        }

        return $best;
    };

    $classAt = static function (int $pos) use ($structure): ?array {
        $best = null;

        foreach ($structure['classes'] as $class) {
            if ($class['start'] <= $pos && $pos <= $class['end'] && ($best === null || $class['start'] > $best['start'])) {
                $best = $class;
            }
        }

        return $best;
    };

    for ($i = 0; $i < $n; $i++) {
        [$id, $text, $line] = $tokens[$i];
        $prev = $tokens[$i - 1] ?? [0, '', 0];
        $next = $tokens[$i + 1] ?? [0, '', 0];

        // Static member access: Class::member
        if (is_name_token($tokens[$i]) && $next[0] === T_DOUBLE_COLON && isset($tokens[$i + 2])) {
            $short    = substr($text, (int) strrpos('\\' . $text, '\\'));
            $resolved = strtolower(resolve_name($text, $structure));
            $member   = $tokens[$i + 2];
            $call     = ($tokens[$i + 3][1] ?? '') === '(';
            $method   = strtolower($member[1]);

            if (preg_match('/^J[A-Z][A-Za-z0-9]+$/', $short) && !in_array($short, LEGACY_CLASS_EXCEPTIONS, true)
                && !isset($structure['uses'][strtolower($short)]) && $text === $short) {
                $add('legacy-class', $line, "$short:: (legacy class alias, use the namespaced class)");
            }

            if (in_array($resolved, ['joomla\\cms\\factory', 'jfactory'], true) || ($short === 'JFactory')) {
                if ($call && in_array($method, FACTORY_METHODS, true)) {
                    $add('factory', $line, "Factory::{$member[1]}() is deprecated (use the application, the DI container or an injected service)");
                } elseif ($member[0] === T_VARIABLE && in_array(strtolower($member[1]), FACTORY_PROPERTIES, true)) {
                    $add('factory', $line, "Factory::{$member[1]} is deprecated");
                }
            }

            if ($call && $method === 'getinstance' && in_array($resolved, [
                'joomla\\cms\\table\\table',
                'joomla\\cms\\mvc\\model\\basedatabasemodel',
                'joomla\\cms\\mvc\\controller\\basecontroller',
                'joomla\\cms\\session\\session',
            ], true)) {
                $add('get-instance', $line, "$short::getInstance() is deprecated (use the MVC factory or instantiate the class)");
            }

            if ($call && $resolved === 'joomla\\cms\\html\\htmlhelper' && in_array($method, ['script', 'stylesheet'], true)) {
                $add('htmlhelper-asset', $line, "HTMLHelper::{$member[1]}() is deprecated (use the WebAssetManager)");
            }

            if ($call && ($short === 'JLoader') && in_array($method, ['register', 'import', 'discover', 'loadbypsr4'], true)) {
                $add('jloader', $line, "JLoader::{$member[1]}() is deprecated (use namespaces / registerNamespace)");
            }

            if ($short === 'parent' && $method === '__construct' && $call) {
                $class = $classAt($i);

                if ($class !== null && is_plugin_class($class['name'] === null ? null : strtolower($class['name']), $parents)) {
                    $args = call_arguments($tokens, $i + 3);

                    if (count($args) >= 2 || (isset($args[0][0]) && in_array(strtolower($args[0][0][1]), ['$subject', '$dispatcher'], true))) {
                        $add('plugin-dispatcher', $line, 'parent::__construct() receives a dispatcher (pass only the config array; the dispatcher is set via setDispatcher())');
                    }
                }
            }
        }

        // new X(...), extends X, instanceof X, X as type in catch etc. for legacy aliases
        if (is_name_token($tokens[$i]) && in_array($prev[0], [T_NEW, T_EXTENDS, T_INSTANCEOF, T_IMPLEMENTS], true)) {
            $short = substr($text, (int) strrpos('\\' . $text, '\\'));

            if (preg_match('/^J[A-Z][A-Za-z0-9]+$/', $short) && !in_array($short, LEGACY_CLASS_EXCEPTIONS, true)
                && !isset($structure['uses'][strtolower($short)]) && $text === $short) {
                $add('legacy-class', $line, "$short (legacy class alias, use the namespaced class)");
            }

            if ($prev[0] === T_NEW && $next[1] === '(') {
                $resolved = strtolower(resolve_name($text, $structure));

                if (is_plugin_class($resolved, $parents)) {
                    $args  = call_arguments($tokens, $i + 1);
                    $first = $args[0] ?? [];

                    foreach ($first as $argToken) {
                        if (($argToken[0] === T_STRING && $argToken[1] === 'DispatcherInterface')
                            || ($argToken[0] === T_VARIABLE && in_array(strtolower($argToken[1]), ['$dispatcher', '$subject'], true))) {
                            $add('plugin-dispatcher', $line, "new $text(\$dispatcher, ...) is deprecated since Joomla 5.4 (pass the config array only, then call setDispatcher())");
                            break;
                        }
                    }
                }
            }
        }

        // Namespaced references to deprecated classes (use statements and fully qualified names).
        if (in_array($id, [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $fq = strtolower(ltrim($text, '\\'));

            if ($fq === 'joomla\\cms\\object\\cmsobject') {
                $add('cmsobject', $line, 'Joomla\\CMS\\Object\\CMSObject is deprecated');
            } elseif (str_starts_with($fq, 'joomla\\cms\\filesystem\\')) {
                $add('cms-filesystem', $line, "$text is deprecated (use Joomla\\Filesystem\\...)");
            }
        }

        // Functions and constants
        if ($id === T_STRING && strtolower($text) === 'jimport' && $next[1] === '(' && !in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            $add('jloader', $line, 'jimport() is deprecated');
        }

        if ($id === T_STRING && in_array($text, ['JPATH_COMPONENT', 'JPATH_COMPONENT_SITE', 'JPATH_COMPONENT_ADMINISTRATOR'], true)) {
            $add('jpath-component', $line, "$text is deprecated (use JPATH_SITE/JPATH_ADMINISTRATOR . '/components/com_...')");
        }

        // Object member access
        if (in_array($id, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true) && $next[0] === T_STRING) {
            $member = strtolower($next[1]);
            $after  = $tokens[$i + 2] ?? [0, '', 0];

            if ($member === 'getquery' && $after[1] === '(') {
                $args = call_arguments($tokens, $i + 2);

                if (count($args) === 1 && count($args[0]) === 1 && strtolower($args[0][0][1]) === 'true') {
                    [$fStart, $fEnd] = $enclosingFunction($i);
                    $guarded         = false;

                    for ($k = max($fStart, 0); $k < $i; $k++) {
                        if ($tokens[$k][0] === T_STRING && strtolower($tokens[$k][1]) === 'method_exists') {
                            $guardArgs = ($tokens[$k + 1][1] ?? '') === '(' ? call_arguments($tokens, $k + 1) : [];

                            if (isset($guardArgs[1][0]) && trim($guardArgs[1][0][1], '\'"') === 'createQuery') {
                                $guarded = true;
                                break;
                            }
                        }
                    }

                    if (!$guarded) {
                        $add('get-query-true', $line, 'getQuery(true) is deprecated (use createQuery())');
                    }
                }
            }

            if ($member === 'setquery' && $after[1] === '(' && count(call_arguments($tokens, $i + 2)) > 1) {
                $add('set-query-limit', $line, 'setQuery() with offset/limit arguments is deprecated (use $query->setLimit())');
            }

            if ($member === 'input' && $after[1] !== '(' && !($prev[0] === T_VARIABLE && $prev[1] === '$this')) {
                $add('app-input', $line, '->input is deprecated (use getInput())');
            }

            if ($member === 'getdbo' && $after[1] === '(') {
                $add('get-dbo', $line, '->getDbo() is deprecated (use getDatabase())');
            }

            if ($prev[0] === T_VARIABLE && $prev[1] === '$this' && in_array($member, ['app', 'db'], true) && $after[1] !== '(') {
                $class = $classAt($i);

                if ($class !== null && is_plugin_class($class['name'] === null ? null : strtolower($class['name']), $parents)) {
                    $add('plugin-app-db', $line, "\$this->{$next[1]} in a plugin is deprecated (use getApplication() / getDatabase())");
                }
            }
        }

        // Property declarations inside plugin classes
        if ($id === T_VARIABLE && in_array(strtolower($text), ['$app', '$db', '$allowlegacylisteners'], true)
            && in_array($prev[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR, T_STATIC, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_READONLY, 0], true)) {
            $class = $classAt($i);

            if ($class !== null && is_plugin_class($class['name'] === null ? null : strtolower($class['name']), $parents)) {
                // Only declarations directly in the class body (not in methods).
                [$fStart] = $enclosingFunction($i);

                if ($fStart === 0 || $fStart < $class['start']) {
                    $isDeclaration = false;

                    for ($k = $i - 1; $k > $class['start']; $k--) {
                        if (in_array($tokens[$k][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_VAR], true)) {
                            $isDeclaration = true;
                            break;
                        }

                        // "(" ends the search: a parameter list, not a property declaration.
                        if (in_array($tokens[$k][1], [';', '{', '}', '('], true)) {
                            break;
                        }
                    }

                    if ($isDeclaration) {
                        $add('plugin-app-db', $line, "Plugin property $text is deprecated (use getApplication() / DatabaseAwareTrait / SubscriberInterface)");
                    }
                }
            }
        }
    }
}

// Allowlist
$failures = [];
$allowed  = [];
$used     = [];

foreach ($findings as $finding) {
    $match = null;

    foreach (ALLOWLIST as $index => $entry) {
        if ($entry['rule'] === $finding['rule'] && preg_match($entry['file'], $finding['file'])) {
            $match = $index;
            break;
        }
    }

    if ($match === null) {
        $failures[] = $finding;
    } else {
        $allowed[]     = $finding + ['reason' => ALLOWLIST[$match]['reason']];
        $used[$match] = true;
    }
}

echo "=== Deprecated Joomla API scan ===\n";
echo 'Scanned ' . count($files) . " PHP file(s)\n\n";

foreach ($failures as $f) {
    echo "FAIL {$f['file']}:{$f['line']} [{$f['rule']}] {$f['message']}\n";
}

foreach ($allowed as $f) {
    echo "ALLOW {$f['file']}:{$f['line']} [{$f['rule']}] {$f['message']} -- {$f['reason']}\n";
}

foreach (ALLOWLIST as $index => $entry) {
    // Stale entries are only meaningful when the whole repository was scanned.
    if (!isset($used[$index]) && array_slice($argv, 1) === []) {
        echo "WARN allowlist entry {$entry['file']} [{$entry['rule']}] matches nothing any more; remove it\n";
    }
}

echo "\n" . count($failures) . ' finding(s), ' . count($allowed) . " allowlisted\n";

exit($failures === [] ? 0 : 1);
