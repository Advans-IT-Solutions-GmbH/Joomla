<?php
/**
 * Test 07: Username Reminder Request
 *
 * Verifies the remind handler through real HTTP requests to com_ajax:
 * - a request without CSRF token is rejected
 * - an invalid e-mail address returns success:false
 * - a well-formed but unknown e-mail address returns the neutral success
 *   response (no account enumeration) and no server error
 * - the plugin's remind language keys are loaded through the Joomla language API
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
require_once __DIR__ . '/ajax-test-helpers.php';

use Joomla\CMS\Factory;

JLoader::registerNamespace(
    'Advans\Plugin\Ajax\JoomlaAjaxForms',
    '/var/www/html/plugins/ajax/joomlaajaxforms/src',
    false, false, 'psr4'
);

class RemindRequestTest
{
    private int $passed = 0;
    private int $failed = 0;
    private string $baseUrl  = 'http://localhost';
    private string $ajaxPath = '/index.php?option=com_ajax&plugin=joomlaajaxforms&group=ajax&format=json';

    private function test(string $name, bool $ok, string $msg = ''): void
    {
        if ($ok) {
            echo "✓ $name\n";
            $this->passed++;
        } else {
            echo "✗ $name" . ($msg ? " — $msg" : '') . "\n";
            $this->failed++;
        }
    }

    /**
     * A plain GET. http() above always posts, and the form-detection check has
     * to fetch the rendered view without submitting anything.
     *
     * @return array{0: int, 1: string}
     */
    private function httpGet(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [$code, $body ?: ''];
    }

    private function http(string $url, array $fields, ?string $cookieJar = null, bool $follow = true): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        if ($cookieJar !== null) {
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body ?: ''];
    }

    /**
     * Starts a guest session in a cookie jar and reads a CSRF token for it.
     *
     * Redirects are followed and cookies kept across them. The token is read
     * from a form field or from Joomla's script options ("csrf.token"). The
     * username reminder view of com_users renders a form for guests; the home
     * page is the fallback.
     *
     * @return array{0: string, 1: string} cookie jar path, token ('' if none)
     */
    private function sessionAndToken(): array
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'remind-cookies-');
        $pages     = [
            '/index.php?option=com_users&view=remind',
            '/',
        ];

        foreach ($pages as $page) {
            $ch = curl_init($this->baseUrl . $page);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
            $response = (string) curl_exec($ch);
            $code     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
            curl_close($ch);

            if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $response, $m)
                || preg_match('/"csrf\.token"\s*:\s*"([a-f0-9]{32})"/i', $response, $m)) {
                return [$cookieJar, $m[1]];
            }

            echo "  DIAG no token on $page: HTTP $code, final URL $finalUrl, " . strlen($response) . " bytes"
                . (preg_match('/<title>(.*?)<\/title>/is', $response, $t) ? ', title "' . trim(strip_tags($t[1])) . '"' : '')
                . "\n";
        }

        return [$cookieJar, ''];
    }

    private function testHandlerExists(): void
    {
        echo "\n--- Handler ---\n";

        $class = \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class;
        $this->test('Plugin class loadable', class_exists($class));

        if (class_exists($class)) {
            $rc = new ReflectionClass($class);
            $this->test('handleRemind() exists', $rc->hasMethod('handleRemind'));
            $this->test('sendRemindEmail() exists', $rc->hasMethod('sendRemindEmail'));
        }
    }

    private function testNoTokenRejected(): void
    {
        echo "\n--- HTTP: no CSRF token → rejected ---\n";

        [$code, $body] = $this->http(
            $this->baseUrl . $this->ajaxPath . '&task=remind',
            ['task' => 'remind', 'email' => 'nobody@example.com'],
            null,
            false
        );
        // Always a JSON error, never a redirect (also for a new session).
        $rejected = $code === 200 && ajaxforms_is_json_rejection($body);

        $this->test('Remind request without token is rejected with JSON success=false (no redirect)', $rejected, "HTTP $code, body: " . substr($body, 0, 200));
    }

    private function testInvalidEmail(): void
    {
        echo "\n--- HTTP: invalid e-mail address ---\n";

        [$cookies, $token] = $this->sessionAndToken();
        $this->test('CSRF token obtained from the site', $token !== '');

        $fields = ['task' => 'remind', 'email' => 'not-an-email'];
        if ($token !== '') {
            $fields[$token] = '1';
        }

        [$code, $body] = $this->http($this->baseUrl . $this->ajaxPath . '&task=remind', $fields, $cookies);
        $data = ajaxforms_decode_response($body);

        $this->test('Invalid e-mail → no server error', $code < 500, "HTTP $code");
        $this->test('Invalid e-mail → success:false', $data !== null && ($data['success'] ?? null) === false, 'body: ' . substr($body, 0, 200));
    }

    private function testUnknownEmail(): void
    {
        echo "\n--- HTTP: unknown e-mail address (no enumeration) ---\n";

        [$cookies, $token] = $this->sessionAndToken();

        $fields = ['task' => 'remind', 'email' => 'nobody_' . time() . '@example.com'];
        if ($token !== '') {
            $fields[$token] = '1';
        }

        [$code, $body] = $this->http($this->baseUrl . $this->ajaxPath . '&task=remind', $fields, $cookies);
        $data = ajaxforms_decode_response($body);

        $this->test('Unknown e-mail → no server error', $code < 500, "HTTP $code");
        $this->test('Unknown e-mail → neutral success response', $data !== null && ($data['success'] ?? null) === true, 'body: ' . substr($body, 0, 200));
    }

    private function testLanguageKeys(): void
    {
        echo "\n--- Language keys ---\n";

        $lang = Factory::getLanguage();
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ROOT . '/plugins/ajax/joomlaajaxforms');

        foreach (['PLG_AJAX_JOOMLAAJAXFORMS_REMIND_SUCCESS', 'PLG_AJAX_JOOMLAAJAXFORMS_REMIND_EMAIL_SUBJECT'] as $key) {
            $this->test("Language key $key loaded", $lang->hasKey($key));
        }
    }

    /**
     * The script has to find the form without the form action.
     *
     * Same reasoning as in 06-reset-request.php: Joomla writes the task only
     * into the action URL and renders no hidden task field, so with SEF turned
     * on `form[action*="remind.remind"]` stops matching and the form is never
     * converted. A wrapper-bound selector is no better, because a template
     * override replaces that wrapper. At least one selector must find the form
     * element on its own: no ancestor, no action.
     */
    private function testFormDetection(): void
    {
        echo "\n--- Form detection (independent of SEF and of the wrapper) ---\n";

        $selectors = ajaxforms_form_selectors('remind', 'remind.remind');
        $this->test('Script exposes its form selectors', $selectors !== [],
            'userFormSelectors() missing or of an unexpected shape in joomlaajaxforms.js');

        if (!$selectors) {
            return;
        }

        [$code, $html] = $this->httpGet($this->baseUrl . '/index.php?option=com_users&view=remind');
        $this->test('Remind page delivered', $code === 200 && str_contains($html, '<form'), "HTTP $code");

        $robust = [];

        foreach ($selectors as $selector) {
            if (!ajaxforms_selector_is_standalone($selector) || ajaxforms_selector_uses_action($selector)) {
                continue;
            }

            $matched = ajaxforms_selector_matches($html, $selector);
            $this->test("Selector '$selector' is understood", $matched !== null, 'unsupported selector shape');

            if ($matched === true) {
                $robust[] = $selector;
            }
        }

        $this->test('A selector finds the form without its wrapper and without the action',
            $robust !== [],
            'only wrapper- or action-dependent selectors match, so SEF or a template override breaks the detection: '
                . implode(' | ', $selectors));
    }

    public function run(): bool
    {
        echo "=== Username Reminder Request Tests ===\n";

        $this->testHandlerExists();
        $this->testNoTokenRejected();
        $this->testInvalidEmail();
        $this->testUnknownEmail();
        $this->testLanguageKeys();
        $this->testFormDetection();

        echo "\n=== Remind Request Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new RemindRequestTest();
exit($test->run() ? 0 : 1);
