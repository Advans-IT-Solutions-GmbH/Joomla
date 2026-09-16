<?php
/**
 * Shared suite: PHP and Joomla deprecations of the extension (production-like lane).
 *
 * The production-like images enable `error_reporting = E_ALL`, `log_errors = On`
 * and `error_log = /tmp/php-errors.log` for both CLI and Apache.
 *
 * Two modes:
 *
 *   php shared-deprecations.php --arm
 *       Run once after the container is up and before the suites. Installs
 *       shared-deprecation-tracer.php (same folder) as auto_prepend_file for CLI and
 *       Apache, hooks it into Joomla's framework files (Joomla replaces the error
 *       handler during bootstrap), enables Joomla's `log_deprecated` setting,
 *       reloads Apache and proves with a canary that CLI and HTTP deprecations
 *       reach the logs.
 *
 *   php shared-deprecations.php
 *       Run after the suites. Repeats the canary (fails if logging is not
 *       active), then
 *         1. lints every PHP file of the package with the container's PHP
 *            (compile-time deprecations, e.g. implicitly nullable types),
 *         2. reads the PHP error log of all previous suites,
 *         3. reads the tracer log: every deprecation, including @-suppressed ones
 *            and Joomla's Log::add(..., 'deprecated') entries, is attributed to
 *            the first caller outside Joomla's libraries,
 *         4. reads Joomla's deprecated.php log.
 *       Anything attributed to a file of this extension fails the suite. Entries
 *       from Joomla core or other extensions are listed for information only.
 */

define('JOOMLA_ROOT', rtrim(getenv('JOOMLA_ROOT') ?: '/var/www/html', '/'));
const TRACER_TARGET  = '/usr/local/lib/advans-deprecation-tracer.php';
const TRACER_INI     = 'zz-deprecation-tracer.ini';
const CANARY_PREFIX  = 'ADVANS-DEPRECATION-CANARY-';
const FRAMEWORK_MARK = 'advans-deprecation-tracer';

require_once __DIR__ . '/shared-test-helpers.php';

$package   = getenv('INSTALL_MESSAGES_PACKAGE') ?: '/tmp/extension.zip';
$logFile   = getenv('PHP_ERROR_LOG') ?: '/tmp/php-errors.log';
$traceFile = getenv('ADVANS_DEPRECATION_TRACE') ?: '/tmp/php-deprecation-trace.log';

if (in_array('--arm', $argv, true)) {
    exit(dep_arm($logFile, $traceFile));
}

$failures = 0;

echo "=== PHP Deprecations (PHP " . PHP_VERSION . ") ===\n\n";

$manifest = sh_read_package_manifest($package);

if ($manifest === null) {
    echo "FAIL cannot read the manifest of $package\n";
    exit(1);
}

if (!is_file($logFile)) {
    $message = "$logFile does not exist (the image was not built with PHP_STRICT_DEPRECATIONS=1)";

    if (sh_strict_skip()) {
        echo "FAIL $message\n";
        exit(1);
    }

    echo "SKIP $message\n";
    exit(0);
}

if (!is_file($traceFile)) {
    $message = "$traceFile does not exist (the deprecation tracer was not armed with --arm before the suites)";

    if (sh_strict_skip()) {
        echo "FAIL $message\n";
        exit(1);
    }

    echo "SKIP $message\n";
    exit(0);
}

// 0. Canary: logging must still work in CLI and HTTP.
$canaryFailures = dep_canary($logFile, $traceFile);
$failures      += count($canaryFailures);

foreach ($canaryFailures as $line) {
    echo "FAIL canary: $line\n";
}

$isOwn     = dep_own_matcher($manifest, $package);
$isLibrary = static fn (string $file): bool => str_starts_with($file, JOOMLA_ROOT . '/libraries/') || $file === TRACER_TARGET;

// 1. Compile-time deprecations of every PHP file in the package.
$lintDir = sys_get_temp_dir() . '/shared-lint-' . bin2hex(random_bytes(4));
mkdir($lintDir, 0755, true);
$zip = new ZipArchive();
$zip->open($package);
$zip->extractTo($lintDir);
$zip->close();

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($lintDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
$linted   = 0;

foreach ($iterator as $file) {
    if ($file->isDir() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }

    $linted++;
    $out  = [];
    $code = 0;
    // log_errors=0: lint messages belong to this report, not to the runtime log.
    exec('php -d error_reporting=E_ALL -d display_errors=stderr -d log_errors=0 -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $out, $code);
    $text = implode("\n", $out);

    // Match PHP's message prefixes only: a file path containing "Warning" or
    // "Deprecated" must not count, and "No syntax errors detected" is success.
    if ($code !== 0 || preg_match('/^(?:PHP )?(?:Deprecated|Warning|Notice|Fatal error|Parse error):/m', $text)) {
        $failures++;
        $relative = substr($file->getPathname(), strlen($lintDir) + 1);
        echo "FAIL php -l $relative:\n  " . str_replace([$lintDir . '/', "\n"], ['', "\n  "], $text) . "\n";
    }
}

foreach ($iterator as $file) {
    $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
}

@rmdir($lintDir);

echo "Linted $linted PHP file(s) of the package\n";

// 2. PHP error log (deprecations, warnings, notices and errors in files of the extension).
$own   = [];
$other = [];

foreach (file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    if (str_contains($line, CANARY_PREFIX)) {
        continue;
    }

    if (!preg_match('/PHP (Deprecated|Warning|Notice|Fatal error|Parse error):\s+(.*) in ((?:[A-Za-z]:)?\/\S+?)(?: on line |:)(\d+)/', $line, $m)) {
        continue;
    }

    $entry = "{$m[1]}: {$m[2]} ({$m[3]}:{$m[4]})";

    if ($isOwn(['file' => $m[3]])) {
        $own[$entry] = true;
    } else {
        $other[$entry] = true;
    }
}

dep_report('PHP error log', $own, $other, $failures);

// 3. Tracer log: every deprecation with its call stack.
$own     = [];
$other   = [];
$records = 0;

foreach (dep_read_trace($traceFile) as $record) {
    if (str_starts_with((string) $record['message'], CANARY_PREFIX)) {
        continue;
    }

    $records++;
    $frames = $record['frames'] ?? [];

    if (($record['file'] ?? '') !== '') {
        array_unshift($frames, ['file' => $record['file'], 'line' => $record['line'] ?? 0]);
    }

    // First caller outside Joomla's libraries (libraries/src, libraries/vendor).
    $culprit = null;

    foreach ($frames as $frame) {
        if (!$isLibrary((string) $frame['file'])) {
            $culprit = $frame;
            break;
        }
    }

    $origin = ($record['file'] ?? '') !== '' ? " [raised in {$record['file']}:{$record['line']}]" : '';
    $label  = "{$record['source']}: {$record['message']}";

    if ($culprit !== null && $isOwn($culprit)) {
        $own["$label (caller {$culprit['file']}:{$culprit['line']})$origin"] = true;
        continue;
    }

    $inStack = null;

    foreach ($frames as $frame) {
        if ($isOwn($frame)) {
            $inStack = $frame;
            break;
        }
    }

    $where = $culprit !== null ? " (caller {$culprit['file']}:{$culprit['line']})" : '';
    $note  = $inStack !== null ? " -- extension further up the stack at {$inStack['file']}:{$inStack['line']}" : '';
    $other["$label$where$note"] = true;
}

echo "\nTracer log: $records recorded deprecation(s)\n";
dep_report('Tracer log (call stack)', $own, $other, $failures);

// 4. Joomla's deprecated.php (log_deprecated). Entries forwarded from
// trigger_error() carry the raising file; direct Log::add() calls do not and are
// attributed through the tracer log above.
$own         = [];
$other       = [];
$deprecated  = dep_joomla_log_dir() . '/deprecated.php';

if (is_file($deprecated)) {
    foreach (file($deprecated, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if ($line[0] === '#' || str_contains($line, CANARY_PREFIX)) {
            continue;
        }

        $message = implode("\t", array_slice(explode("\t", $line), 4)) ?: $line;

        if (preg_match('/ - ((?:[A-Za-z]:)?\/\S+) - Line (\d+)$/', $message, $m) && $isOwn(['file' => $m[1]])) {
            $own[$message] = true;
        } else {
            $other[$message] = true;
        }
    }

    dep_report('Joomla deprecated.php', $own, $other, $failures);
} else {
    echo "\nINFO $deprecated does not exist (no Joomla deprecation was logged by a web request)\n";
}

echo "\n=== PHP Deprecations Summary ===\n";
echo $failures === 0 ? "All checks passed\n" : "$failures problem(s) found\n";

exit($failures === 0 ? 0 : 1);

// ---------------------------------------------------------------------------

function dep_report(string $title, array $own, array $other, int &$failures): void
{
    if ($own) {
        $failures += count($own);
        echo "\nFAIL $title: entries caused by this extension:\n  " . implode("\n  ", array_keys($own)) . "\n";
    } else {
        echo "PASS $title: nothing caused by this extension\n";
    }

    if ($other) {
        echo "INFO $title: " . count($other) . " entr" . (count($other) === 1 ? 'y' : 'ies') . " from Joomla core or other extensions (not evaluated):\n  "
            . implode("\n  ", array_slice(array_keys($other), 0, 50)) . "\n";
    }
}

/**
 * @return iterable<array>
 */
function dep_read_trace(string $traceFile): iterable
{
    foreach (file($traceFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $record = json_decode($line, true);

        if (is_array($record) && isset($record['message'], $record['source'])) {
            yield $record;
        }
    }
}

/**
 * Build a predicate that tells whether a stack frame belongs to the extension:
 * its installed folders (from the manifest), template overrides shipped in the
 * package, or a copy of a package file in Joomla's temporary install folder.
 */
function dep_own_matcher(array $manifest, string $package): Closure
{
    $root     = JOOMLA_ROOT;
    $prefixes = [];
    $patterns = [];
    $hashes   = [];
    $element  = $manifest['element'];
    $media    = [];

    if ($manifest['type'] === 'plugin') {
        $groups = array_filter(array_merge([$manifest['folder']], explode(',', (string) getenv('INSTALLED_PLUGIN_FOLDERS'))));

        foreach (array_unique(array_map('trim', $groups)) as $group) {
            $prefixes[] = "$root/plugins/$group/$element/";
            $media[]    = "plg_{$group}_{$element}";
        }
    } elseif ($manifest['type'] === 'component') {
        $prefixes[] = "$root/administrator/components/$element/";
        $prefixes[] = "$root/components/$element/";
        $prefixes[] = "$root/api/components/$element/";
        $media[]    = $element;
    } elseif ($manifest['type'] === 'module') {
        $prefixes[] = "$root/modules/$element/";
        $prefixes[] = "$root/administrator/modules/$element/";
        $media[]    = $element;
    }

    foreach ($manifest['nested'] as $plugin) {
        $prefixes[] = "$root/plugins/{$plugin['folder']}/{$plugin['element']}/";
    }

    $zip = new ZipArchive();

    if ($zip->open($package) === true) {
        $xml = @simplexml_load_string((string) $zip->getFromName($manifest['xmlfile']));

        if ($xml instanceof SimpleXMLElement && isset($xml->media['destination'])) {
            $media[] = (string) $xml->media['destination'];
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);

            if (!str_ends_with(strtolower($name), '.php')) {
                continue;
            }

            $hashes[sha1((string) $zip->getFromIndex($i))] = true;

            // overrides/<component>/<view>/<file> is copied to templates/<template>/html/...
            if (str_starts_with($name, 'overrides/')) {
                $patterns[] = '#^' . preg_quote("$root/templates/", '#') . '[^/]+/html/' . preg_quote(substr($name, strlen('overrides/')), '#') . '$#';
            }
        }

        $zip->close();
    }

    foreach (array_unique(array_filter($media)) as $folder) {
        $prefixes[] = "$root/media/$folder/";
    }

    return static function (array $frame) use ($prefixes, $patterns, $hashes): bool {
        $file = (string) ($frame['file'] ?? '');

        foreach ($prefixes as $prefix) {
            if (str_starts_with($file, $prefix)) {
                return true;
            }
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $file)) {
                return true;
            }
        }

        return isset($frame['sha1']) && isset($hashes[$frame['sha1']]);
    };
}

function dep_joomla_config(): string
{
    return JOOMLA_ROOT . '/configuration.php';
}

function dep_joomla_log_dir(): string
{
    $config = (string) @file_get_contents(dep_joomla_config());

    if (preg_match('/public\s+\$log_path\s*=\s*\'([^\']+)\'/', $config, $m)) {
        return rtrim($m[1], '/');
    }

    return JOOMLA_ROOT . '/administrator/logs';
}

/**
 * Prepare the container: tracer as auto_prepend_file, framework hooks,
 * log_deprecated, Apache reload, canary.
 */
function dep_arm(string $logFile, string $traceFile): int
{
    echo "=== Arm deprecation tracer ===\n";

    $source = __DIR__ . '/shared-deprecation-tracer.php';
    $iniDir = PHP_CONFIG_FILE_SCAN_DIR;

    if (!is_file($source)) {
        echo "FAIL $source is missing (copy it next to this script)\n";
        return 1;
    }

    if ($iniDir === '' || !is_dir($iniDir)) {
        echo "FAIL PHP has no configuration scan directory\n";
        return 1;
    }

    @mkdir(dirname(TRACER_TARGET), 0755, true);

    if (!copy($source, TRACER_TARGET)) {
        echo "FAIL cannot copy the tracer to " . TRACER_TARGET . "\n";
        return 1;
    }

    chmod(TRACER_TARGET, 0644);
    file_put_contents("$iniDir/" . TRACER_INI, "; Test container only: record deprecations with call stack\nauto_prepend_file = " . TRACER_TARGET . "\n");
    echo "OK   auto_prepend_file set in $iniDir/" . TRACER_INI . "\n";

    foreach ([$traceFile, $logFile] as $file) {
        if (!is_file($file)) {
            touch($file);
        }

        chmod($file, 0666);
    }

    // Joomla: log deprecations to administrator/logs/deprecated.php.
    $configFile = dep_joomla_config();
    $config     = (string) @file_get_contents($configFile);

    if ($config === '') {
        echo "FAIL cannot read $configFile\n";
        return 1;
    }

    if (preg_match('/public\s+\$log_deprecated\s*=/', $config)) {
        $config = preg_replace('/(public\s+\$log_deprecated\s*=\s*)[^;]*;/', '${1}1;', $config, 1);
    } else {
        $config = preg_replace('/}\s*$/', "\tpublic \$log_deprecated = 1;\n}\n", $config, 1);
    }

    file_put_contents($configFile, $config);
    echo "OK   log_deprecated = 1 in $configFile\n";

    // Joomla writes the log file with the web server user; a file created by a
    // root CLI process first would block Apache, so create it up front.
    $logDir     = dep_joomla_log_dir();
    $deprecated = "$logDir/deprecated.php";
    @mkdir($logDir, 0755, true);

    if (!is_file($deprecated)) {
        file_put_contents($deprecated, "#<?php die('Forbidden.'); ?>\n#Date: " . gmdate('Y-m-d H:i:s') . " UTC\n\n#Fields: datetime\tpriority clientip\tcategory\tmessage\n");
    }

    @chown($deprecated, 'www-data');
    @chgrp($deprecated, 'www-data');
    chmod($deprecated, 0666);

    // Joomla replaces the error handler during bootstrap; re-register the tracer afterwards.
    foreach (['includes', 'administrator/includes', 'api/includes'] as $folder) {
        $framework = JOOMLA_ROOT . "/$folder/framework.php";

        if (!is_file($framework)) {
            continue;
        }

        $code = (string) file_get_contents($framework);

        if (!str_contains($code, FRAMEWORK_MARK)) {
            $hook = "\n// " . FRAMEWORK_MARK . " (test container only)\n\\function_exists('advans_deprecation_tracer_rearm') && advans_deprecation_tracer_rearm();\n";

            if (str_ends_with(rtrim($code), '?>')) {
                $hook = "<?php\n" . $hook;
            }

            file_put_contents($framework, $code . $hook);
        }

        echo "OK   tracer hook in $folder/framework.php\n";
    }

    // Apache reads php.ini at start-up; a graceful restart applies the new ini file.
    $out  = [];
    $code = 0;
    exec('apache2ctl -k graceful 2>&1', $out, $code);

    if ($code !== 0) {
        $pidFile = getenv('APACHE_PID_FILE') ?: '/var/run/apache2/apache2.pid';
        $pid     = is_file($pidFile) ? (int) trim((string) file_get_contents($pidFile)) : 0;
        $out[]   = $pid > 0 && function_exists('posix_kill') && posix_kill($pid, 10) ? "sent SIGUSR1 to $pid" : 'no Apache PID found';
    }

    echo "OK   Apache graceful restart: " . trim(implode(' ', $out)) . "\n";

    // The restart is asynchronous: retry the canary for up to 60 seconds.
    $failures = [];

    for ($attempt = 1; $attempt <= 12; $attempt++) {
        sleep(5);
        $failures = dep_canary($logFile, $traceFile);

        if ($failures === []) {
            break;
        }

        echo "     canary attempt $attempt: " . count($failures) . " check(s) open\n";
    }

    foreach ($failures as $line) {
        echo "FAIL canary: $line\n";
    }

    echo $failures === [] ? "Deprecation tracer armed\n" : "Deprecation tracer NOT working\n";

    return $failures === [] ? 0 : 1;
}

function dep_canary_code(string $token, string $channel, bool $withApplication): string
{
    $root = var_export(JOOMLA_ROOT, true);
    $app  = '';

    if ($withApplication) {
        // Same bootstrap as includes/app.php, then Joomla's own logging set-up
        // (CMSApplication::setupLogging() runs in execute(), which the canary skips).
        $app = <<<PHP
try {
    \$container = \\Joomla\\CMS\\Factory::getContainer();
    \$container->alias('session.web', 'session.web.site')
        ->alias('session', 'session.web.site')
        ->alias('JSession', 'session.web.site')
        ->alias(\\Joomla\\CMS\\Session\\Session::class, 'session.web.site')
        ->alias(\\Joomla\\Session\\Session::class, 'session.web.site')
        ->alias(\\Joomla\\Session\\SessionInterface::class, 'session.web.site');
    \$app = \$container->get(\\Joomla\\CMS\\Application\\SiteApplication::class);
    \\Joomla\\CMS\\Factory::\$application = \$app;
    (new \\ReflectionMethod(\$app, 'setupLogging'))->invoke(\$app);
    @trigger_error('{$token}-{$channel}-apptrigger', E_USER_DEPRECATED);
    \\Joomla\\CMS\\Log\\Log::add('{$token}-{$channel}-applog', \\Joomla\\CMS\\Log\\Log::WARNING, 'deprecated');
    echo "CANARY-APP-OK\\n";
} catch (\\Throwable \$e) {
    echo 'CANARY-APP-ERROR: ' . get_class(\$e) . ': ' . \$e->getMessage() . "\\n";
}
PHP;
    }

    return <<<PHP
<?php
trigger_error('{$token}-{$channel}-plain', E_USER_DEPRECATED);
@trigger_error('{$token}-{$channel}-silenced', E_USER_DEPRECATED);
define('_JEXEC', 1);
define('JPATH_BASE', {$root});
require_once JPATH_BASE . '/includes/defines.php';
\$_SERVER['HTTP_HOST']   = \$_SERVER['HTTP_HOST'] ?? 'localhost';
\$_SERVER['SCRIPT_NAME'] = \$_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
@trigger_error('{$token}-{$channel}-joomla', E_USER_DEPRECATED);
\\Joomla\\CMS\\Log\\Log::add('{$token}-{$channel}-log', \\Joomla\\CMS\\Log\\Log::WARNING, 'deprecated');
{$app}
echo "CANARY-DONE\\n";

PHP;
}

/**
 * Trigger known deprecations in a CLI process and in a web request and check
 * that they reach the PHP error log, the tracer log (with the canary file in the
 * call stack, also when @-suppressed or raised after Joomla's bootstrap) and,
 * for the web request, Joomla's deprecated.php.
 *
 * @return string[] failed checks
 */
function dep_canary(string $logFile, string $traceFile): array
{
    $token    = CANARY_PREFIX . bin2hex(random_bytes(6));
    $failures = [];

    // CLI
    $cliFile = sys_get_temp_dir() . "/advans-canary-" . bin2hex(random_bytes(6)) . '.php';
    file_put_contents($cliFile, dep_canary_code($token, 'cli', false));
    $out = [];
    exec('php ' . escapeshellarg($cliFile) . ' 2>&1', $out);
    @unlink($cliFile);

    if (!str_contains(implode("\n", $out), 'CANARY-DONE')) {
        $failures[] = 'CLI canary did not run: ' . trim(implode(' ', $out));
    }

    // HTTP
    $webName = 'advans-canary-' . bin2hex(random_bytes(12)) . '.php';
    $webFile = JOOMLA_ROOT . '/' . $webName;
    $body    = '';

    try {
        file_put_contents($webFile, dep_canary_code($token, 'http', true));
        chmod($webFile, 0644);
        $body = dep_http_get(rtrim(getenv('ADVANS_CANARY_BASE_URL') ?: 'http://localhost', '/') . '/' . $webName);
    } finally {
        @unlink($webFile);
    }

    if (!str_contains($body, 'CANARY-DONE')) {
        $failures[] = 'HTTP canary did not run: ' . substr(trim(strip_tags($body)), 0, 300);
    }

    $appBooted = str_contains($body, 'CANARY-APP-OK');

    if (!$appBooted && str_contains($body, 'CANARY-APP-ERROR')) {
        echo "WARN canary: Joomla site application could not be booted in the web canary ("
            . trim((string) strstr($body, 'CANARY-APP-ERROR')) . "); deprecated.php is not verified\n";
    }

    clearstatcache();
    $phpLog = (string) @file_get_contents($logFile);
    $trace  = [];

    foreach (dep_read_trace($traceFile) as $record) {
        if (str_starts_with((string) $record['message'], $token)) {
            $trace[substr((string) $record['message'], strlen($token) + 1)] = $record;
        }
    }

    foreach (['cli', 'http'] as $channel) {
        $channelFile = $channel === 'cli' ? $cliFile : $webFile;

        if (!str_contains($phpLog, "$token-$channel-plain")) {
            $failures[] = "$channel: E_USER_DEPRECATED is missing in $logFile";
        }

        $checks = ['plain', 'silenced', 'joomla', 'log'];

        if ($channel === 'http' && $appBooted) {
            $checks[] = 'apptrigger';
            $checks[] = 'applog';
        }

        foreach ($checks as $check) {
            $record = $trace["$channel-$check"] ?? null;

            if ($record === null) {
                $failures[] = "$channel: '$check' deprecation is missing in the tracer log $traceFile";
                continue;
            }

            if (!in_array($channelFile, array_column($record['frames'] ?? [], 'file'), true) && ($record['file'] ?? '') !== $channelFile) {
                $failures[] = "$channel: '$check' has no call stack pointing to the canary file";
            }

            if ($channel === 'http' && ($record['sapi'] ?? '') === 'cli') {
                $failures[] = "http: '$check' was recorded by the CLI SAPI";
            }
        }
    }

    if ($appBooted) {
        $deprecated = (string) @file_get_contents(dep_joomla_log_dir() . '/deprecated.php');

        foreach (['apptrigger', 'applog'] as $check) {
            if (!str_contains($deprecated, "$token-http-$check")) {
                $failures[] = "http: '$check' is missing in Joomla's deprecated.php (log_deprecated not active)";
            }
        }
    }

    if ($failures === []) {
        echo "PASS canary: CLI and HTTP deprecations reach the PHP log, the tracer log (incl. @-suppressed, with call stack)"
            . ($appBooted ? " and Joomla's deprecated.php" : '') . "\n";
    }

    return $failures;
}

function dep_http_get(string $url): string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_FOLLOWLOCATION => false]);
        $body = curl_exec($ch);
        curl_close($ch);

        return is_string($body) ? $body : '';
    }

    $context = stream_context_create(['http' => ['timeout' => 60, 'ignore_errors' => true]]);

    return (string) @file_get_contents($url, false, $context);
}
