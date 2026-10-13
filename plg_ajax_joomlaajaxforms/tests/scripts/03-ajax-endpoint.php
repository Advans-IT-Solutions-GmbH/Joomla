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

        // Any other status (303 or another 3xx above all) fails before the body
        // is looked at: a redirect body can carry JSON or HTML as well.
        if ($httpCode !== 200) {
            echo "FAIL (HTTP $httpCode)\n";
            return false;
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

            // Only the language filter may redirect, and it answers with 301 or
            // 302. A 303 is the redirect of Session::checkToken(); any other
            // status (307, 308, ...) is no language redirect either.
            if ($status === 303) {
                $problems[] = "HTTP 303 (the redirect of Session::checkToken()): $location";
            } elseif ($status !== 301 && $status !== 302) {
                $problems[] = "HTTP $status is not the language redirect (301/302): $location";
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

        $names = $this->classNames($tokens, 'Session');
        $n     = count($tokens);

        for ($i = 0; $i < $n - 3; $i++) {
            if (!$this->namesClass($tokens[$i], $names)
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
     *
     * Route::link() is not part of this check: the plugin uses it only for the
     * links in its mails (routedLink()), where xhtml follows the mail part, on
     * for the HTML body and off for the plain text body.
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
        $names    = $this->classNames($tokens, 'Route');

        // Position of the xhtml argument: Route::_($url, $xhtml, ...).
        $xhtmlArg = ['_' => 1];

        for ($i = 0, $n = count($tokens); $i < $n - 3; $i++) {
            if (!$this->namesClass($tokens[$i], $names)
                || !is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON
                || !is_array($tokens[$i + 2]) || !isset($xhtmlArg[strtolower($tokens[$i + 2][1])])
                || $tokens[$i + 3] !== '('
            ) {
                continue;
            }

            $position = $xhtmlArg[strtolower($tokens[$i + 2][1])];

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

            // Named argument (xhtml: false) anywhere, otherwise the positional one.
            $args  = array_map(static fn ($a) => strtolower(trim($a)), $args);
            $named = array_values(array_filter($args, static fn ($a) => str_starts_with($a, 'xhtml:')));
            $xhtml = $named !== [] ? substr($named[0], 6) : ($args[$position] ?? '');

            if ($xhtml !== 'false') {
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
     * The script builds the endpoint URL itself, in config.baseUrl. It must use
     * a raw & so that a redirect of the language filter keeps every parameter.
     *
     * Only the return values of that function are inspected, not the whole
     * script, so a commented-out URL elsewhere plays no part. The function is
     * located outside JavaScript comments only (a commented-out copy never
     * counts), and a self-check on variants of the script proves that. It is
     * plain ES5 (identifiers, string literals, comments, no template or regex
     * literal), where the PHP tokenizer splits strings and comments exactly as
     * JavaScript does. A template or regex literal in it fails the check, so
     * this assumption cannot silently stop holding.
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

        $urls     = [];
        $problems = $this->baseUrlProblems($source, $urls);

        // Self-check of the reader on variants of the shipped script: a
        // commented-out baseUrl (block or line comment) must never count as
        // the endpoint, and an escaped URL must fail. Otherwise the guard
        // could pass while the real assignment is gone.
        if ($problems === []) {
            $problems = $this->baseUrlSelfCheck($source);
        }

        if ($problems === []) {
            echo "PASS (" . count($urls) . " URLs)\n";

            return true;
        }

        echo "FAIL (" . implode('; ', $problems) . ")\n";

        return false;
    }

    /**
     * Problems of the endpoint URL that config.baseUrl returns, or [] when it
     * is the raw com_ajax URL. $urls receives the URLs found.
     *
     * The function is located only outside JavaScript comments. Exactly one
     * such definition must exist.
     *
     * @param  string[]  $urls
     *
     * @return string[]
     */
    private function baseUrlProblems(string $source, array &$urls): array
    {
        $urls   = [];
        $blocks = $this->baseUrlDefinitions($source);

        if (count($blocks) !== 1) {
            return ['expected one config.baseUrl function outside comments, found ' . count($blocks)];
        }

        $problems = [];
        $tokens   = token_get_all('<?php ' . $blocks[0][1][0]);

        foreach ($tokens as $i => $token) {
            $text = is_array($token) ? $token[1] : $token;

            // Where the two lexers could disagree, fail instead of guessing: a
            // template literal (backtick), a bare slash (regex literal or
            // division, neither belongs in a URL builder), a "#" (a comment
            // in PHP, a private field in JavaScript) and a closing PHP tag
            // (ends PHP mode, the rest would be read as inline HTML).
            if ($text === '`' || $text === '/'
                || (is_array($token) && in_array($token[0], [T_CLOSE_TAG, T_INLINE_HTML], true))
                || (is_array($token) && $token[0] === T_COMMENT && $text[0] === '#')
            ) {
                $problems[] = 'config.baseUrl contains code this check cannot read reliably: ' . trim($text);
                break;
            }

            if (!is_array($token) || $token[0] !== T_RETURN) {
                continue;
            }

            // The string literals of this return statement (comments are
            // separate tokens and never count).
            for ($j = $i + 1, $n = count($tokens); $j < $n && $tokens[$j] !== ';'; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING
                    && strpos($tokens[$j][1], 'option=com_ajax') !== false
                ) {
                    $urls[] = substr($tokens[$j][1], 1, -1);
                }
            }
        }

        if ($problems === [] && $urls === []) {
            $problems[] = 'config.baseUrl returns no endpoint URL';
        }

        foreach ($urls as $u) {
            if (stripos($u, 'amp;') !== false) {
                $problems[] = "escaped ampersand in '$u'";
            }

            if (strpos($u, 'option=com_ajax&plugin=joomlaajaxforms&format=json') === false) {
                $problems[] = "unexpected endpoint URL '$u'";
            }
        }

        return $problems;
    }

    /**
     * The config.baseUrl function definitions outside JavaScript comments, as
     * preg matches with offsets: [0] the whole definition, [1] its body.
     *
     * @return array<int, array<int, array{0:string, 1:int}>>
     */
    private function baseUrlDefinitions(string $source): array
    {
        preg_match_all('/baseUrl\s*:\s*\(function\s*\(\)\s*\{(.*?)\}\)\(\)/s', $source, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        return array_values(array_filter($all, function (array $match) use ($source): bool {
            return !$this->jsOffsetInComment($source, $match[0][1]);
        }));
    }

    /**
     * Whether a position of the script lies inside a JavaScript comment.
     *
     * Block comment: the last "/*" before the position comes after the last
     * closing marker. A block comment cannot contain its closing marker, so a
     * real block comment is never missed. Line comment: the line up to the
     * position contains "//". A comment marker inside a string can only make
     * code look commented, never the other way round, so the error is always
     * on the safe side: the check fails instead of passing.
     */
    private function jsOffsetInComment(string $source, int $offset): bool
    {
        $before = substr($source, 0, $offset);
        $open   = strrpos($before, '/*');
        $close  = strrpos($before, '*/');

        if ($open !== false && ($close === false || $close < $open)) {
            return true;
        }

        $lineStart = strrpos($before, "\n");
        $line      = $lineStart === false ? $before : substr($before, $lineStart + 1);

        return strpos($line, '//') !== false;
    }

    /**
     * Run baseUrlProblems() on variants of the shipped script and return what
     * it misjudged. The real config.baseUrl is replaced by a commented-out
     * copy (multi-line block comment, single-line block comment, line
     * comment), which must fail; an escaped URL must fail; an additional
     * commented-out copy next to the real function must still pass.
     *
     * @return string[]
     */
    private function baseUrlSelfCheck(string $source): array
    {
        $definitions = $this->baseUrlDefinitions($source);

        if (count($definitions) !== 1) {
            return ['self-check: config.baseUrl not found'];
        }

        [$real, $offset] = $definitions[0][0];
        $url     = '/index.php?option=com_ajax&plugin=joomlaajaxforms&format=json';
        $replace = static fn (string $with): string => substr_replace($source, $with, $offset, strlen($real));

        $mustFail = [
            'multi-line block comment' => $replace("/*\nbaseUrl: (function() { return '" . $url . "'; })()\n*/"),
            'single-line block comment' => $replace("/* baseUrl: (function(){ return '" . $url . "'; })() */"),
            'line comment'              => $replace("// baseUrl: (function(){ return '" . $url . "'; })(),"),
            'escaped ampersand'         => $replace(str_replace('&plugin', '&amp;plugin', $real)),
        ];
        $mustPass = [
            'extra commented-out copy' => $replace("/* baseUrl: (function(){ return '/x'; })() */\n" . $real),
        ];

        $problems = [];

        foreach ($mustFail as $name => $variant) {
            $urls = [];

            if ($this->baseUrlProblems($variant, $urls) === []) {
                $problems[] = "self-check: $name was accepted";
            }
        }

        foreach ($mustPass as $name => $variant) {
            $urls = [];
            $got  = $this->baseUrlProblems($variant, $urls);

            if ($got !== []) {
                $problems[] = "self-check: $name was rejected (" . implode('; ', $got) . ')';
            }
        }

        return $problems;
    }

    /**
     * Lower-case short names under which the plugin can refer to a class: its
     * own short name and every alias imported with "use ...\Class as Alias".
     *
     * @return array<string, true>
     */
    private function classNames(array $tokens, string $class): array
    {
        $names = [strtolower($class) => true];

        for ($i = 0, $n = count($tokens); $i < $n - 2; $i++) {
            if (is_array($tokens[$i])
                && in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && preg_match('/(^|\\\\)' . preg_quote($class, '/') . '$/i', $tokens[$i][1])
                && is_array($tokens[$i + 1]) && $tokens[$i + 1][0] === T_AS
                && is_array($tokens[$i + 2]) && $tokens[$i + 2][0] === T_STRING
            ) {
                $names[strtolower($tokens[$i + 2][1])] = true;
            }
        }

        return $names;
    }

    /**
     * Whether a token is a class name (short, qualified or fully qualified)
     * whose last part is one of $names.
     *
     * @param  array<string, true>  $names
     */
    private function namesClass($token, array $names): bool
    {
        if (!is_array($token) || !in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return false;
        }

        return isset($names[strtolower(substr((string) strrchr('\\' . $token[1], '\\'), 1))]);
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
