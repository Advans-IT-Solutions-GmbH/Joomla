<?php
/**
 * Test 06: Password Reset Request
 *
 * Verifies reset-request handler behaviour via:
 * - Reflection-based method existence (no strpos)
 * - Real HTTP: unknown email → success:false or neutral, no-token → rejected
 * - Language key presence via Joomla language API
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';
require_once __DIR__ . '/ajax-test-helpers.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

JLoader::registerNamespace(
    'Advans\Plugin\Ajax\JoomlaAjaxForms',
    '/var/www/html/plugins/ajax/joomlaajaxforms/src',
    false, false, 'psr4'
);

class ResetRequestTest
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

    private function http(string $method, string $url, array $fields = [], array $cookies = [], bool $follow = true): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        }
        if ($cookies) {
            curl_setopt($ch, CURLOPT_COOKIE, implode('; ', array_map(
                fn($k, $v) => "$k=$v", array_keys($cookies), array_values($cookies)
            )));
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $body ?: ''];
    }

    private function getSessionAndToken(): array
    {
        $ch = curl_init($this->baseUrl . '/');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HEADER, true);
        $response = (string) curl_exec($ch);
        curl_close($ch);

        $cookie = '';
        if (preg_match('/Set-Cookie:\s*([^;\r\n]+)/i', $response, $m)) {
            $cookie = trim($m[1]);
        }
        $token = '';
        if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $response, $m)) {
            $token = $m[1];
        }
        return [$cookie, $token];
    }

    private function testMethodsViaReflection(): void
    {
        echo "\n--- Method existence (Reflection) ---\n";

        $class = \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class;
        $this->test('Class loadable', class_exists($class));
        if (!class_exists($class)) {
            return;
        }

        $rc = new ReflectionClass($class);
        foreach (['handleReset', 'handleRemind'] as $m) {
            $this->test("Method $m exists", $rc->hasMethod($m));
        }
    }

    private function testNoTokenRejected(): void
    {
        echo "\n--- HTTP: no CSRF token → rejected ---\n";

        $url = $this->baseUrl . $this->ajaxPath . '&task=reset';
        [$code, $body] = $this->http('POST', $url, [
            'task' => 'reset', 'email' => 'nobody@example.com',
        ], [], false);

        // Always a JSON error, never a redirect (also for a new session).
        $rejected = $code === 200 && ajaxforms_is_json_rejection($body);

        $this->test('No-token resetRequest POST rejected', $rejected, "HTTP $code, body: " . substr($body, 0, 200));
    }

    private function testUnknownEmailHandled(): void
    {
        echo "\n--- HTTP: unknown email → handled (no 500) ---\n";

        [$cookie, $token] = $this->getSessionAndToken();

        $cookies = [];
        if ($cookie && str_contains($cookie, '=')) {
            [$cn, $cv] = explode('=', $cookie, 2);
            $cookies[$cn] = $cv;
        }

        $fields = ['task' => 'reset', 'email' => 'nobody_xyz_' . time() . '@example.com'];
        if ($token) {
            $fields[$token] = '1';
        }

        $url = $this->baseUrl . $this->ajaxPath . '&task=reset';
        [$code, $body] = $this->http('POST', $url, $fields, $cookies);

        // Must not 500; either success:false (user not found) or success:true (silent for security)
        $this->test(
            'Unknown email → no 500 error',
            $code !== 500,
            "HTTP $code, body: " . substr($body, 0, 200)
        );

        $outer = json_decode($body, true);
        $this->test(
            'Unknown email → valid JSON response',
            $outer !== null && isset($outer['success']),
            "body: " . substr($body, 0, 200)
        );
    }

    private function testLanguageKeys(): void
    {
        echo "\n--- Language keys ---\n";

        $lang = Factory::getLanguage();
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ADMINISTRATOR);
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ROOT . '/plugins/ajax/joomlaajaxforms');

        foreach ([
            'PLG_AJAX_JOOMLAAJAXFORMS_RESET_SUCCESS',
            'PLG_AJAX_JOOMLAAJAXFORMS_RESET_EMAIL_SUBJECT',
            'PLG_AJAX_JOOMLAAJAXFORMS_RESET_EMAIL_BODY',
        ] as $key) {
            $this->test("Language key $key present", $lang->hasKey($key) !== false);
        }
    }

    /**
     * The script has to find the form without the form action.
     *
     * Joomla writes the task only into the action URL and renders no hidden task
     * field in this form. With SEF turned on the routed address no longer
     * carries the task, `form[action*="reset.request"]` stops matching, the form
     * is never converted to AJAX, and a visitor who enters a valid address gets
     * no answer at all. A selector that depends on the wrapper of the core view
     * is no better, because a template override replaces that wrapper.
     *
     * So at least one selector must find the form element on its own: no
     * ancestor, no action.
     */
    private function testFormDetection(): void
    {
        echo "\n--- Form detection (independent of SEF and of the wrapper) ---\n";

        $selectors = ajaxforms_form_selectors('reset', 'reset.request');
        $this->test('Script exposes its form selectors', $selectors !== [],
            'userFormSelectors() missing or of an unexpected shape in joomlaajaxforms.js');

        if (!$selectors) {
            return;
        }

        [$code, $html] = $this->http('GET', $this->baseUrl . '/index.php?option=com_users&view=reset');
        $this->test('Reset page delivered', $code === 200 && str_contains($html, '<form'), "HTTP $code");

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

        $script = (string) @file_get_contents(ajaxforms_script_path());
        $this->test('A hidden task field is accepted as a further hook',
            str_contains($script, 'input[name="task"][value='));
    }

    /**
     * The reset mail has to carry the design of the site.
     *
     * The plugin used to compose subject and body itself and to send them with
     * setBody(), so this mail left the site as Content-Type: text/plain while
     * every other mail of the site was rendered from the template in
     * #__mail_templates, with frame, logo and tables. The setting under System,
     * Mail Templates had no effect on it at all.
     *
     * The check is made on the message the site actually hands to the mail
     * transport, which is where that difference showed.
     */
    private function testResetMailCarriesTheSiteDesign(): void
    {
        echo "\n--- Reset mail goes through the site's mail template ---\n";

        $reason = ajaxforms_mailcatch_install();
        $this->test('Mail capture in place', $reason === null, (string) $reason);

        if ($reason !== null) {
            return;
        }

        try {
            $selfTest = ajaxforms_mailcatch_selftest();
            $this->test('Mail capture works', $selfTest === null, (string) $selfTest);

            if ($selfTest !== null) {
                return;
            }

            // The site has to be allowed to build HTML mails at all; without
            // that setting even a template mail stays plain text.
            ajaxforms_enable_html_mail();

            $user  = ajaxforms_create_test_user('reset', 'en-GB');
            $token = str_repeat('a1b2c3d4', 4);

            ajaxforms_mailcatch_clear();

            $plugin = ajaxforms_plugin_instance();
            $send   = new ReflectionMethod($plugin, 'sendResetEmail');
            $send->setAccessible(true);
            $send->invoke($plugin, $user, $token);

            $messages = ajaxforms_mailcatch_messages();
            $this->test('Exactly one reset mail sent', count($messages) === 1, count($messages) . ' captured');

            if (count($messages) !== 1) {
                return;
            }

            $raw  = $messages[0];
            $text = ajaxforms_mail_text($raw);

            $this->test(
                'Mail addressed to the account',
                stripos($raw, (string) $user->email) !== false,
                'recipient not found in the message'
            );

            $this->test(
                'Mail carries an HTML part (Content-Type is not text/plain)',
                (bool) preg_match('#^Content-Type:\s*multipart/alternative#mi', $raw),
                'headers: ' . substr($raw, 0, 400)
            );

            $this->test(
                'HTML part is rendered through the mail layout',
                stripos($text, '<html') !== false && stripos($text, '<table') !== false,
                'no frame markup in the message'
            );

            $this->test(
                'A plain-text alternative is still sent',
                stripos($raw, 'text/plain') !== false
            );

            $this->test(
                'Mail carries the reset link with the token',
                strpos($text, 'com_users') !== false && strpos($text, $token) !== false,
                'reset link or token missing'
            );

            // The plugin answers inside com_ajax, where the strings of
            // com_users are not loaded; without loading them the template would
            // arrive as its raw language keys.
            $this->test(
                'Template strings are translated, not raw language keys',
                stripos($text, 'COM_USERS_EMAIL_') === false,
                'a raw language key is in the message'
            );
        } finally {
            ajaxforms_delete_test_user('reset');
            ajaxforms_mailcatch_remove();
        }
    }

    /**
     * The mail has to arrive in the language of the customer, so the language
     * of the account decides, not the language the request happened to run in.
     */
    private function testMailLanguageFollowsTheAccount(): void
    {
        echo "\n--- Mail language follows the account ---\n";

        $class = \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class;
        $rc    = new ReflectionClass($class);

        $this->test('Method accountMailLanguage exists', $rc->hasMethod('accountMailLanguage'));

        if (!$rc->hasMethod('accountMailLanguage')) {
            return;
        }

        $method = $rc->getMethod('accountMailLanguage');
        $method->setAccessible(true);
        $plugin = $rc->newInstanceWithoutConstructor();

        $chosen         = new stdClass();
        $chosen->params = json_encode(['language' => 'fr-FR']);

        $this->test(
            'Language of the account wins',
            $method->invoke($plugin, $chosen) === 'fr-FR',
            'got ' . var_export($method->invoke($plugin, $chosen), true)
        );

        $none         = new stdClass();
        $none->params = '{}';

        $this->test(
            'Without a choice a valid tag is still used',
            (bool) preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', (string) $method->invoke($plugin, $none)),
            'got ' . var_export($method->invoke($plugin, $none), true)
        );
    }

    /**
     * The answer must stay the same for a known and an unknown address,
     * otherwise the form tells an attacker which addresses have an account.
     */
    private function testAnswerDoesNotRevealAccounts(): void
    {
        echo "\n--- Answer does not reveal whether an account exists ---\n";

        $user = ajaxforms_create_test_user('resetreveal');

        try {
            $known   = $this->resetAnswer((string) $user->email);
            $unknown = $this->resetAnswer('nobody_xyz_' . time() . '@example.test');

            $this->test(
                'Request with a token is accepted',
                $known !== null && ($known['success'] ?? null) === true,
                'answer: ' . var_export($known, true)
            );

            $this->test(
                'Known and unknown address get the same answer',
                $known !== null && $known === $unknown,
                'known: ' . var_export($known, true) . ', unknown: ' . var_export($unknown, true)
            );
        } finally {
            ajaxforms_delete_test_user('resetreveal');
        }
    }

    /**
     * Starts a guest session in a cookie jar and reads a CSRF token for it.
     *
     * Redirects are followed and the cookies are kept across them, because the
     * token only counts together with the session it was issued for. The reset
     * view of com_users renders a form for guests, the home page is the
     * fallback.
     *
     * @return  array{0: string, 1: string}  cookie jar path, token ('' if none)
     */
    private function sessionAndToken(): array
    {
        $cookieJar = tempnam(sys_get_temp_dir(), 'reset-cookies-');

        foreach (['/index.php?option=com_users&view=reset', '/'] as $page) {
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

            echo "  DIAG no token on $page: HTTP $code, final URL $finalUrl, " . strlen($response) . " bytes\n";
        }

        return [$cookieJar, ''];
    }

    /**
     * @return  array<string, mixed>|null  The decoded answer of a reset request
     */
    private function resetAnswer(string $email): ?array
    {
        [$cookieJar, $token] = $this->sessionAndToken();

        $fields = ['task' => 'reset', 'email' => $email];

        if ($token !== '') {
            $fields[$token] = '1';
        }

        $ch = curl_init($this->baseUrl . $this->ajaxPath . '&task=reset');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        $body = (string) curl_exec($ch);
        curl_close($ch);

        @unlink($cookieJar);

        return ajaxforms_decode_response($body);
    }

    public function run(): bool
    {
        echo "=== Password Reset Request Tests ===\n";

        $this->testMethodsViaReflection();
        $this->testNoTokenRejected();
        $this->testUnknownEmailHandled();
        $this->testLanguageKeys();
        $this->testFormDetection();
        $this->testResetMailCarriesTheSiteDesign();
        $this->testMailLanguageFollowsTheAccount();
        $this->testAnswerDoesNotRevealAccounts();

        echo "\n=== Reset Request Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new ResetRequestTest();
exit($test->run() ? 0 : 1);
