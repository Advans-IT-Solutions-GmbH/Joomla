<?php
/**
 * Shared suite: every backend view of the component is really rendered.
 *
 * Reflection and file_exists() prove that a class is there, not that it works.
 * A backend view that calls a method it does not have, or that a factory never
 * gave it, only fails when Joomla actually renders it — and until this suite
 * existed nothing in the CI ever opened a component page in the browser. The
 * Import/Export dashboard shipped a `$this->getDatabase()` call on a view that
 * was neither DatabaseAware nor given a database, and every one of the other
 * suites stayed green.
 *
 * The suite therefore logs into /administrator as the test administrator and
 * requests every backend view of the component under test:
 *
 *   - the component's entry point without a view (its default view),
 *   - every View\<Name>\HtmlView class under administrator/components/<option>/src,
 *   - every tmpl/<name> layout folder (covers views without their own class),
 *   - every name in BACKEND_VIEWS_EXTRA (comma separated, for addresses the
 *     component must answer although they are not views of their own).
 *
 * Each response must be HTTP 200, must not carry a PHP error or a Joomla error
 * page, must not show a raw language key, and must contain at least one string
 * from the extension's own language file — otherwise the page did not render.
 *
 * The package is read from /tmp/extension.zip (BACKEND_VIEWS_PACKAGE overrides
 * it). The suite only reads; it changes nothing on the site.
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

$package  = getenv('BACKEND_VIEWS_PACKAGE') ?: '/tmp/extension.zip';
$baseUrl  = getenv('BACKEND_VIEWS_BASE_URL') ?: 'http://localhost';
$adminUser = getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin';
$adminPass = getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#';
$cookieJar = '/tmp/shared-backend-views-cookies.txt';

$failures = 0;

function bv_fail(string $message): void
{
    global $failures;
    $failures++;
    echo "FAIL $message\n";
}

function bv_pass(string $message): void
{
    echo "PASS $message\n";
}

/**
 * One HTTP request. Redirects are not followed unless asked for, so a redirect
 * to the login form is visible instead of being hidden behind a 200.
 *
 * @return array{code:int, body:string}
 */
function bv_request(string $url, array $post = [], bool $follow = false): array
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

function bv_extract_token(string $html): ?string
{
    if (preg_match('/name="([a-f0-9]{32})" value="1"/', $html, $m)) {
        return $m[1];
    }

    if (preg_match('/"csrf\.token":"([a-f0-9]{32})"/', $html, $m)) {
        return $m[1];
    }

    if (preg_match('/[?&;]([a-f0-9]{32})=1/', $html, $m)) {
        return $m[1];
    }

    return null;
}

function bv_login(string $baseUrl, string $user, string $pass): bool
{
    global $cookieJar;

    @unlink($cookieJar);

    $token = null;

    for ($i = 0; $i < 5; $i++) {
        $r     = bv_request($baseUrl . '/administrator/index.php', [], true);
        $token = bv_extract_token($r['body']);

        if ($token !== null) {
            break;
        }

        sleep(2);
    }

    if ($token === null) {
        return false;
    }

    $r = bv_request($baseUrl . '/administrator/index.php', [
        'username' => $user,
        'passwd'   => $pass,
        'option'   => 'com_login',
        'task'     => 'login',
        'return'   => base64_encode('index.php'),
        $token     => '1',
    ], true);

    return str_contains($r['body'], 'task=logout') || str_contains($r['body'], 'com_cpanel');
}

/**
 * Body text without script and style blocks, as plain text.
 */
function bv_page_text(string $html): string
{
    $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);

    return sh_plain_text($html);
}

/**
 * Markers that only ever come from a broken page, never from page content.
 *
 * @return string[]
 */
function bv_error_markers(string $html): array
{
    $patterns = [
        'PHP fatal error'      => '/\bFatal error\b/i',
        'PHP parse error'      => '/\bParse error\b/i',
        'undefined method'     => '/Call to undefined (method|function)/i',
        'uncaught exception'   => '/Uncaught\s+\\\\?[A-Za-z_]/',
        'stack trace'          => '/\bStack trace:/',
        'class not found'      => '/Class ["\'][^"\']+["\'] not found/i',
        'PHP warning'          => '#<b>Warning</b>\s*:#i',
        'PHP notice'           => '#<b>Notice</b>\s*:#i',
        'PHP deprecation'      => '#<b>Deprecated</b>\s*:#i',
        'Joomla "view not found"' => '/View not found/i',
        'Joomla error page'    => '/An error has occurred|Es ist ein Fehler aufgetreten/i',
    ];

    $found = [];

    foreach ($patterns as $label => $pattern) {
        if (preg_match($pattern, $html)) {
            $found[] = $label;
        }
    }

    return $found;
}

/**
 * Every backend view name the component must answer. '' is the entry point
 * without a view parameter (the controller's default view).
 *
 * @return string[]
 */
function bv_discover_views(string $option): array
{
    $views = [''];
    $base  = JOOMLA_ROOT . '/administrator/components/' . $option;

    foreach (glob($base . '/src/View/*/HtmlView.php') ?: [] as $file) {
        $views[] = strtolower(basename(\dirname($file)));
    }

    foreach (glob($base . '/tmpl/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $views[] = strtolower(basename($dir));
    }

    foreach (explode(',', (string) getenv('BACKEND_VIEWS_EXTRA')) as $extra) {
        $extra = strtolower(trim($extra));

        if ($extra !== '') {
            $views[] = $extra;
        }
    }

    return array_values(array_unique($views));
}

echo "=== Backend Views (authenticated HTTP) ===\n\n";

$manifest = sh_read_package_manifest($package);

if ($manifest === null) {
    bv_fail("cannot read the extension manifest from $package");
    exit(1);
}

if ($manifest['type'] !== 'component') {
    bv_fail("this suite renders backend views of components; {$manifest['element']} is a {$manifest['type']}");
    exit(1);
}

$option = $manifest['element'];
echo "Component: $option\n";

if (!is_dir(JOOMLA_ROOT . '/administrator/components/' . $option)) {
    bv_fail("the component is not installed at administrator/components/$option");
    exit(1);
}

$views = bv_discover_views($option);
echo 'Views: ' . implode(', ', array_map(static fn (string $v): string => $v === '' ? '(default)' : $v, $views)) . "\n\n";

// Strings of the extension itself: at least one of them has to appear on a
// page that really rendered. en-GB is the language the test site runs in.
$strings = sh_read_package_language($package, 'en-GB');

if (!$strings) {
    bv_fail('the package has no en-GB language file, so a rendered page cannot be recognised');
    exit(1);
}

echo "--- Admin authentication ---\n";
$loggedIn = bv_login($baseUrl, $adminUser, $adminPass);

if (!$loggedIn) {
    bv_fail('cannot authenticate against /administrator');
    exit(1);
}

bv_pass('admin login succeeds');

foreach ($views as $view) {
    $label = $view === '' ? "$option (default view)" : "$option&view=$view";
    $url   = $baseUrl . '/administrator/index.php?option=' . rawurlencode($option)
        . ($view === '' ? '' : '&view=' . rawurlencode($view));

    echo "\n--- $label ---\n";

    $response = bv_request($url);
    $body     = $response['body'];

    if ($response['code'] !== 200) {
        bv_fail("$label: HTTP {$response['code']} (expected 200)");
        echo '  [diag] ' . mb_substr(bv_page_text($body), 0, 400) . "\n";

        continue;
    }

    bv_pass("$label: HTTP 200");

    $markers = bv_error_markers($body);

    if ($markers) {
        bv_fail("$label: error markers on the page: " . implode(', ', $markers));
        echo '  [diag] ' . mb_substr(bv_page_text($body), 0, 400) . "\n";
    } else {
        bv_pass("$label: no PHP error and no Joomla error page");
    }

    $text    = bv_page_text($body);
    $rawKeys = sh_find_raw_language_keys($text);

    if ($rawKeys) {
        bv_fail("$label: untranslated language keys: " . implode(', ', \array_slice($rawKeys, 0, 5)));
    } else {
        bv_pass("$label: no untranslated language keys");
    }

    $shown = sh_shown_language_keys($text, $strings);

    if ($shown) {
        bv_pass("$label: page rendered (" . implode(', ', \array_slice($shown, 0, 3)) . ')');
    } else {
        bv_fail("$label: no string of the extension on the page, so it did not render");
        echo '  [diag] ' . mb_substr($text, 0, 400) . "\n";
    }
}

echo "\n=== Backend Views Summary ===\n";
echo $failures === 0 ? "All checks passed\n" : "$failures check(s) failed\n";

exit($failures === 0 ? 0 : 1);
