<?php
/**
 * Test 03: AJAX Endpoint
 * Tests that the AJAX endpoint is accessible
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class AjaxEndpointTest
{
    private $baseUrl = 'http://localhost';

    public function run(): bool
    {
        echo "=== AJAX Endpoint Tests ===\n\n";

        $allPassed = true;
        $allPassed = $this->testPluginEnabled() && $allPassed;
        $allPassed = $this->testEndpointAccessible() && $allPassed;
        $allPassed = $this->testResponseFormat() && $allPassed;
        $allPassed = $this->testTextsTask() && $allPassed;
        $allPassed = $this->testErrorPayloadKeepsEnvelope() && $allPassed;
        $allPassed = $this->testNoSessionRedirectingTokenCheck() && $allPassed;
        $allPassed = $this->testRoutedUrlsAreRaw() && $allPassed;
        $allPassed = $this->testScriptEndpointUrlIsRaw() && $allPassed;

        // Multilingual case of the dev finding (13.10.2026): a GET without any
        // cookie (no language cookie, no session) ran into a 303 loop whose
        // Location carried &amp; instead of &.
        $restore = $this->ensureMultilingual();
        try {
            foreach (['texts', 'reset'] as $task) {
                $allPassed = $this->testCookielessGetDoesNotLoop($task) && $allPassed;
            }
        } finally {
            $restore();
        }

        $this->printSummary();
        return $allPassed;
    }

    private function testPluginEnabled(): bool
    {
        echo "Test: Plugin is enabled... ";
        
        $db = Factory::getDbo();
        $query = $db->getQuery(true)
            ->select('enabled')
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('ajax'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));
        
        $db->setQuery($query);
        $enabled = $db->loadResult();
        
        if ($enabled == 1) {
            echo "PASS\n";
            return true;
        }
        
        echo "FAIL (enabled=$enabled)\n";
        return false;
    }

    private function testEndpointAccessible(): bool
    {
        echo "Test: AJAX endpoint accessible... ";
        
        // com_ajax endpoint
        $url = $this->baseUrl . '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        echo "(HTTP $httpCode) ";

        // A request without a token or session must end in a JSON answer. A
        // redirect at the end (or a loop that runs out of hops) is a failure:
        // it was the 303 of Session::checkToken() that looped up to 1.5.24.
        if ($httpCode === 200 && json_decode((string) $response, true) !== null) {
            echo "PASS\n";
            echo "  Response preview: " . substr($response, 0, 100) . "\n";
            return true;
        }

        echo "FAIL\n";
        echo "  Response: $response\n";
        return false;
    }

    private function testResponseFormat(): bool
    {
        echo "Test: Response contains expected structure... ";
        
        $url = $this->baseUrl . '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json';
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        $httpCode = 0;
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // Only the language filter may redirect here (301/302 to the language
        // URL, see testCookielessGetDoesNotLoop()). A 303 is the redirect of
        // Session::checkToken() that looped up to 1.5.24.
        if (in_array($httpCode, [301, 302])) {
            echo "PASS (language redirect)\n";
            return true;
        }
        
        // com_ajax wraps plugin response, try to decode
        $data = json_decode($response, true);
        
        if ($data !== null) {
            echo "PASS (valid JSON)\n";
            return true;
        }
        
        // HTML response is also acceptable - Joomla rendered the page
        if (strpos($response, '<html') !== false || strpos($response, '<!DOCTYPE') !== false) {
            echo "PASS (HTML response - Joomla processed the request)\n";
            return true;
        }
        
        echo "FAIL (HTTP $httpCode, invalid response format)\n";
        return false;
    }

    /**
     * The script carries no text of its own. When a page loads it without the
     * texts in its script options, it asks the endpoint (task "texts"), which
     * answers without a form token with the translated texts of the plugin.
     */
    private function testTextsTask(): bool
    {
        require_once __DIR__ . '/ajax-test-helpers.php';

        echo "Test: Script texts are delivered by the endpoint without a token... ";

        // Sent like the script sends it: POST to the com_ajax URL, task in the body,
        // no form token, no session.
        $ch = curl_init($this->baseUrl . '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'task=texts');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body     = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $payload = ajaxforms_decode_response($body);
        $texts   = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        // The direct onAfterRoute answer must carry the same envelope com_ajax
        // produces: a top-level success flag and the plugin payload in data[].
        $envelope = json_decode($body, true);

        $en = [];
        $ini = '/var/www/html/plugins/ajax/joomlaajaxforms/language/en-GB/plg_ajax_joomlaajaxforms.ini';
        if (is_file($ini)) {
            $en = parse_ini_file($ini, false, INI_SCANNER_RAW) ?: [];
        }

        $problems = [];

        if ($httpCode !== 200 || ($payload['success'] ?? false) !== true) {
            $problems[] = "HTTP $httpCode, body " . substr($body, 0, 150);
        }

        if (!is_array($envelope)
            || ($envelope['success'] ?? null) !== true
            || !is_array($envelope['data'] ?? null)
        ) {
            $problems[] = 'envelope shape mismatch: ' . substr($body, 0, 150);
        }

        foreach (['ERROR_GENERIC', 'PROFILE_SAVED', 'CLOSE'] as $name) {
            $value = (string) ($texts[$name] ?? '');

            if ($value === '' || preg_match('/^[A-Z][A-Z0-9_]+$/', $value)) {
                $problems[] = "$name missing or untranslated ('$value')";
            }
        }

        if (($texts['ERROR_GENERIC'] ?? null) !== ($en['PLG_AJAX_JOOMLAAJAXFORMS_JS_ERROR_GENERIC'] ?? '')) {
            $problems[] = 'ERROR_GENERIC is not the text of the language file';
        }

        // Same answer carries the state of the "Debug Output" option. On a normal
        // page onBeforeRender() does not run, so this is the only way the option
        // can reach the script. Default is off, and the key must be a boolean so
        // the script does not switch traces on from a missing value.
        if (!array_key_exists('debug', $payload) || $payload['debug'] !== false) {
            $problems[] = 'debug flag missing or not false by default: '
                . var_export($payload['debug'] ?? null, true);
        }

        if ($problems === []) {
            echo "PASS\n";

            return true;
        }

        echo "FAIL (" . implode('; ', $problems) . ")\n";

        return false;
    }

    /**
     * A logical error (here: a state-changing task sent without a form token)
     * must be carried in the very same com_ajax envelope as a success. The
     * outer envelope always reports the dispatch as success:true and puts the
     * plugin's own payload in data[]; the rejection lives in that inner payload
     * (success:false). testTextsTask() only proves the happy path, so this
     * proves the error path keeps the shape onAfterRoute is meant to mirror.
     */
    private function testErrorPayloadKeepsEnvelope(): bool
    {
        require_once __DIR__ . '/ajax-test-helpers.php';

        echo "Test: A rejected task keeps the com_ajax envelope... ";

        $ch = curl_init($this->baseUrl . '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_POST, true);
        // A real task that changes state, but no form token: onAjaxJoomlaajaxforms()
        // rejects it and returns its own success:false payload.
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'task=reset');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        $body     = (string) curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $envelope = json_decode($body, true);

        $problems = [];

        if ($httpCode !== 200) {
            $problems[] = "HTTP $httpCode";
        }

        // The outer envelope must be intact even for a rejection.
        if (!is_array($envelope)
            || ($envelope['success'] ?? null) !== true
            || !is_array($envelope['data'] ?? null)
        ) {
            $problems[] = 'outer envelope shape mismatch: ' . substr($body, 0, 150);
        }

        // The rejection itself must sit in the inner plugin payload.
        if (!ajaxforms_is_json_rejection($body)) {
            $problems[] = 'inner payload is not a success:false rejection: ' . substr($body, 0, 150);
        }

        if ($problems === []) {
            echo "PASS\n";

            return true;
        }

        echo "FAIL (" . implode('; ', $problems) . ")\n";

        return false;
    }

    /**
     * Make sure the site is multilingual for the redirect test: the language
     * filter is enabled and at least one content language is published whose
     * site language is installed. The entrypoint of every lane enables all
     * plugins, so normally nothing changes here. Whatever this method changes
     * is undone by the returned callback.
     *
     * @return callable  Restores the previous state.
     */
    private function ensureMultilingual(): callable
    {
        $db = Factory::getDbo();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['extension_id', 'enabled']))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('languagefilter'));
        $filter = $db->setQuery($query)->loadObject();

        $query = $db->getQuery(true)
            ->select($db->quoteName(['lang_id', 'published']))
            ->from($db->quoteName('#__languages'))
            ->where($db->quoteName('lang_code') . ' = ' . $db->quote('en-GB'));
        $language = $db->setQuery($query)->loadObject();

        $filterWasEnabled   = $filter && (int) $filter->enabled === 1;
        $languageWasPublic  = $language && (int) $language->published === 1;

        if ($filter && !$filterWasEnabled) {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__extensions'))
                    ->set($db->quoteName('enabled') . ' = 1')
                    ->where($db->quoteName('extension_id') . ' = ' . (int) $filter->extension_id)
            )->execute();
        }

        if ($language && !$languageWasPublic) {
            $db->setQuery(
                $db->getQuery(true)
                    ->update($db->quoteName('#__languages'))
                    ->set($db->quoteName('published') . ' = 1')
                    ->where($db->quoteName('lang_id') . ' = ' . (int) $language->lang_id)
            )->execute();
        }

        echo "Multilingual setup: language filter "
            . ($filter ? ($filterWasEnabled ? 'enabled' : 'enabled for this test') : 'NOT INSTALLED')
            . ", content language en-GB "
            . ($language ? ($languageWasPublic ? 'published' : 'published for this test') : 'MISSING')
            . "\n";

        return function () use ($db, $filter, $filterWasEnabled, $language, $languageWasPublic): void {
            if ($filter && !$filterWasEnabled) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName('#__extensions'))
                        ->set($db->quoteName('enabled') . ' = 0')
                        ->where($db->quoteName('extension_id') . ' = ' . (int) $filter->extension_id)
                )->execute();
            }

            if ($language && !$languageWasPublic) {
                $db->setQuery(
                    $db->getQuery(true)
                        ->update($db->quoteName('#__languages'))
                        ->set($db->quoteName('published') . ' = 0')
                        ->where($db->quoteName('lang_id') . ' = ' . (int) $language->lang_id)
                )->execute();
            }
        };
    }

    /**
     * A GET of the endpoint without any cookie, exactly like the checker's curl:
     * no language cookie and no session, redirects followed by hand without
     * sending a cookie back.
     *
     * The language filter may redirect once to the language-prefixed URL (301
     * or 302). That hop must keep the query intact (raw &, every parameter
     * still there), and the next request must be answered with JSON. Version
     * 1.5.24 answered the second request with a 303 to Route::_('index.php')
     * (Session::checkToken() on a new session), whose Location carried &amp;
     * and broke the parameters on every further hop.
     */
    private function testCookielessGetDoesNotLoop(string $task): bool
    {
        require_once __DIR__ . '/ajax-test-helpers.php';

        echo "Test: Cookie-less GET (task=$task) on a multilingual site answers without a redirect loop... ";

        $expected = [
            'option' => 'com_ajax',
            'plugin' => 'joomlaajaxforms',
            'format' => 'json',
            'task'   => $task,
        ];

        $url      = $this->baseUrl . '/index.php?' . http_build_query($expected);
        $hops     = [];
        $problems = [];
        $status   = 0;
        $body     = '';

        for ($i = 0; $i < 5; $i++) {
            [$status, $location, $body] = $this->rawGet($url);

            if ($status < 300 || $status >= 400) {
                break;
            }

            $hops[] = "$status $location";

            if ($location === '') {
                $problems[] = "HTTP $status without Location";
                break;
            }

            if (stripos($location, '&amp;') !== false
                || stripos($location, 'amp;') !== false
                || stripos($location, 'amp%3B') !== false
            ) {
                $problems[] = "Location carries an HTML-escaped ampersand: $location";
            }

            if ($status === 303) {
                $problems[] = "HTTP 303 (the redirect of Session::checkToken()): $location";
            }

            parse_str((string) parse_url($location, PHP_URL_QUERY), $vars);

            foreach ($expected as $name => $value) {
                if (($vars[$name] ?? null) !== $value) {
                    $problems[] = "Location lost $name=$value: $location";
                }
            }

            if ($problems !== []) {
                break;
            }

            $url = $this->absoluteUrl($location);
        }

        // The language filter is the only redirect allowed, and it must be
        // there: without it this test would not prove the multilingual case.
        if (count($hops) !== 1) {
            $problems[] = 'expected exactly one language redirect, got ' . count($hops)
                . ($hops === [] ? ' (is the language filter active?)' : ': ' . implode(' -> ', $hops));
        } elseif (!preg_match('#/en/|[?&]lang=en(&|$)#', $hops[0])) {
            $problems[] = 'the redirect does not lead to the language URL: ' . $hops[0];
        }

        if ($problems === [] && $status !== 200) {
            $problems[] = "final answer HTTP $status, body " . substr($body, 0, 150);
        }

        if ($problems === []) {
            $payload = ajaxforms_decode_response($body);

            if ($task === 'texts') {
                if (($payload['success'] ?? null) !== true || !is_array($payload['data'] ?? null)) {
                    $problems[] = 'texts are not delivered without a token: ' . substr($body, 0, 150);
                }
            } elseif (!ajaxforms_is_json_rejection($body)) {
                $problems[] = 'a task without a token is not rejected with JSON: ' . substr($body, 0, 150);
            }
        }

        if ($problems === []) {
            echo "PASS (" . $hops[0] . ")\n";

            return true;
        }

        echo "FAIL (" . implode('; ', array_unique($problems)) . ")\n";

        return false;
    }

    /**
     * Session::checkToken() must not be called by the plugin: for a request
     * without a session it redirects with 303 to Route::_('index.php'), which
     * carries the current com_ajax query HTML-escaped. That redirect was the
     * loop the dev checker found with version 1.5.24. Comments are ignored.
     *
     * Only a static call on the Session class counts (short, qualified or
     * fully qualified name, or an alias imported with "use ... as"), so an
     * unrelated method of the same name does not trip the check.
     */
    private function testNoSessionRedirectingTokenCheck(): bool
    {
        echo "Test: Plugin never calls Session::checkToken()... ";

        $tokens = $this->pluginTokens();

        if ($tokens === null) {
            echo "FAIL (plugin source not found)\n";

            return false;
        }

        $nameTypes = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
        $names     = ['session' => true];
        $n         = count($tokens);

        // Aliases of the Session class: use Joomla\CMS\Session\Session as Alias;
        for ($i = 0; $i < $n - 3; $i++) {
            if (is_array($tokens[$i]) && in_array($tokens[$i][0], $nameTypes, true)
                && preg_match('/(^|\\\\)Session$/i', $tokens[$i][1])
                && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_AS
                && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING
            ) {
                $names[strtolower($tokens[$i + 2][1])] = true;
            }
        }

        for ($i = 0; $i < $n - 3; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], $nameTypes, true)) {
                continue;
            }

            $short = strtolower(substr((string) strrchr('\\' . $tokens[$i][1], '\\'), 1));

            if (!isset($names[$short])
                || !is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON
                || !is_array($tokens[$i + 2]) || strcasecmp($tokens[$i + 2][1], 'checkToken') !== 0
                || $tokens[$i + 3] !== '('
            ) {
                continue;
            }

            echo "FAIL (Session::checkToken() called on line {$tokens[$i][2]})\n";

            return false;
        }

        echo "PASS\n";

        return true;
    }

    /**
     * Every URL the plugin routes ends up in a JSON answer the script follows
     * (redirect after login and MFA) or in a stored return URL, never in HTML.
     * Each Route::_() call must therefore pass xhtml=false, otherwise the
     * script receives &amp; instead of & and the query breaks.
     */
    private function testRoutedUrlsAreRaw(): bool
    {
        echo "Test: Every Route::_() call of the plugin returns a raw URL (xhtml=false)... ";

        $tokens = $this->pluginTokens();

        if ($tokens === null) {
            echo "FAIL (plugin source not found)\n";

            return false;
        }

        $calls    = 0;
        $problems = [];

        for ($i = 0, $n = count($tokens); $i < $n - 3; $i++) {
            $isRoute = is_array($tokens[$i]) && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(^|\\\\)Route$/', $tokens[$i][1]);

            if (!$isRoute
                || !is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON
                || !is_array($tokens[$i + 2]) || $tokens[$i + 2][1] !== '_'
                || $tokens[$i + 3] !== '('
            ) {
                continue;
            }

            $calls++;
            $line  = $tokens[$i][2];
            $depth = 0;
            $args  = [''];

            for ($j = $i + 3; $j < $n; $j++) {
                $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                if (in_array($text, ['(', '[', '{'], true)) {
                    $depth++;
                    if ($depth === 1) {
                        continue;
                    }
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                } elseif ($text === ',' && $depth === 1) {
                    $args[] = '';
                    continue;
                }

                $args[count($args) - 1] .= $text;
            }

            if (strtolower(trim($args[1] ?? '')) !== 'false') {
                $problems[] = "line $line";
            }
        }

        if ($calls === 0) {
            echo "FAIL (no Route::_() call found, the check reads the wrong file)\n";

            return false;
        }

        if ($problems === []) {
            echo "PASS ($calls calls)\n";

            return true;
        }

        echo "FAIL (xhtml not false on " . implode(', ', $problems) . ")\n";

        return false;
    }

    /**
     * The script builds the endpoint URL itself. It must use a raw & so that a
     * redirect of the language filter keeps every parameter.
     */
    private function testScriptEndpointUrlIsRaw(): bool
    {
        require_once __DIR__ . '/ajax-test-helpers.php';

        echo "Test: The script's endpoint URL uses a raw & (no &amp;)... ";

        $source = @file_get_contents(ajaxforms_script_path());

        if (!is_string($source) || $source === '') {
            echo "FAIL (script not found)\n";

            return false;
        }

        // Only executable code counts: a commented-out URL must neither keep
        // the check green nor fail it.
        preg_match_all('/[\'"`]([^\'"`]*option=com_ajax[^\'"`]*)[\'"`]/', $this->jsWithoutComments($source), $m);
        $urls     = $m[1];
        $problems = [];

        if ($urls === []) {
            $problems[] = 'no endpoint URL found in the script';
        }

        foreach ($urls as $u) {
            if (stripos($u, 'amp;') !== false) {
                $problems[] = "escaped ampersand in '$u'";
            }

            if (strpos($u, 'option=com_ajax&plugin=joomlaajaxforms&format=json') === false) {
                $problems[] = "unexpected endpoint URL '$u'";
            }
        }

        if ($problems === []) {
            echo "PASS (" . count($urls) . " URLs)\n";

            return true;
        }

        echo "FAIL (" . implode('; ', $problems) . ")\n";

        return false;
    }

    /**
     * Tokens of the installed plugin class without comments, or null.
     *
     * @return array<int, mixed>|null
     */
    private function pluginTokens(): ?array
    {
        $file = '/var/www/html/plugins/ajax/joomlaajaxforms/src/Extension/JoomlaAjaxForms.php';
        $code = @file_get_contents($file);

        if (!is_string($code) || $code === '') {
            return null;
        }

        return array_values(array_filter(token_get_all($code), static function ($t): bool {
            return !is_array($t) || !in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true);
        }));
    }

    /**
     * JavaScript source with every comment removed. String and regular
     * expression literals are kept as they are, so a comment marker inside
     * them does not start a comment. Template literals are kept as well, but
     * their ${...} substitutions are code and are stripped the same way.
     */
    private function jsWithoutComments(string $source): string
    {
        $i = 0;

        return $this->jsStripCode($source, $i, false);
    }

    /**
     * Copy code from $i and drop its comments. In a template substitution
     * ($inSubstitution) it stops at the "}" that closes the substitution and
     * leaves $i on it.
     */
    private function jsStripCode(string $source, int &$i, bool $inSubstitution): string
    {
        $out     = '';
        $len     = strlen($source);
        $lastSig = '';
        $word    = '';
        $depth   = 0;

        for (; $i < $len; $i++) {
            $c    = $source[$i];
            $next = $source[$i + 1] ?? '';

            if ($c === '/' && $next === '/') {
                $end = strpos($source, "\n", $i);
                $i   = ($end === false ? $len : $end) - 1;

                continue;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($source, '*/', $i + 2);
                $i   = ($end === false ? $len : $end + 2) - 1;
                $out .= ' ';

                continue;
            }

            if ($inSubstitution && $c === '}' && $depth === 0) {
                return $out;
            }

            if ($c === '`') {
                $out .= $this->jsStripTemplate($source, $i);
                $lastSig = '`';
                $word    = '';

                continue;
            }

            $isRegex = $c === '/'
                && ($lastSig === '' || strpos('(,=:[!&|?{};+-*%<>~^', $lastSig) !== false
                    || in_array($word, ['return', 'typeof', 'case', 'in', 'of'], true));

            if ($c === '"' || $c === "'" || $isRegex) {
                $start   = $i;
                $inClass = false;

                for ($i++; $i < $len; $i++) {
                    if ($source[$i] === '\\') {
                        $i++;
                        continue;
                    }

                    if ($isRegex && $source[$i] === '[') {
                        $inClass = true;
                    } elseif ($isRegex && $source[$i] === ']') {
                        $inClass = false;
                    } elseif ($source[$i] === $c && !$inClass) {
                        break;
                    }
                }

                $out    .= substr($source, $start, $i - $start + 1);
                $lastSig = $c;
                $word    = '';

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
            }

            $isIdent = ctype_alnum($c) || $c === '_' || $c === '$';
            $prevRaw = $out === '' ? '' : substr($out, -1);
            $out    .= $c;

            if ($isIdent) {
                // Continue the word only when it is not separated by whitespace.
                $word = ($prevRaw !== '' && (ctype_alnum($prevRaw) || $prevRaw === '_' || $prevRaw === '$')) ? $word . $c : $c;
            } elseif (!ctype_space($c)) {
                $word = '';
            }

            if (!ctype_space($c)) {
                $lastSig = $c;
            }
        }

        return $out;
    }

    /**
     * Copy the template literal that starts at $i (a backtick) and leave $i on
     * its closing backtick. Each ${...} substitution goes through
     * jsStripCode(), so comments inside it are removed.
     */
    private function jsStripTemplate(string $source, int &$i): string
    {
        $out = '`';
        $len = strlen($source);

        for ($i++; $i < $len; $i++) {
            $c = $source[$i];

            if ($c === '\\') {
                $out .= substr($source, $i, 2);
                $i++;

                continue;
            }

            if ($c === '`') {
                return $out . '`';
            }

            if ($c === '$' && ($source[$i + 1] ?? '') === '{') {
                $i  += 2;
                $out .= '${' . $this->jsStripCode($source, $i, true) . '}';

                continue;
            }

            $out .= $c;
        }

        return $out;
    }

    /**
     * One GET without cookies and without following redirects.
     *
     * @return array{0:int, 1:string, 2:string}  Status, Location header, body.
     */
    private function rawGet(string $url): array
    {
        $location = '';

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$location): int {
            if (stripos($line, 'Location:') === 0) {
                $location = trim(substr($line, 9));
            }

            return strlen($line);
        });
        $body   = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$status, $location, $body];
    }

    private function absoluteUrl(string $location): string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        return $this->baseUrl . '/' . ltrim($location, '/');
    }

    private function printSummary(): void
    {
        echo "\n=== AJAX Endpoint Test Summary ===\n";
        echo "All tests completed.\n";
    }
}

$test = new AjaxEndpointTest();
$result = $test->run();
exit($result ? 0 : 1);
