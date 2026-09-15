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

    private function http(string $url, array $fields, array $cookies = [], bool $follow = true): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $follow);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
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

    /**
     * @return array{0: array<string,string>, 1: string}
     */
    private function sessionAndToken(): array
    {
        // The username reminder view of com_users always renders a form with a
        // CSRF token for guests; the home page only has one when a form module
        // is published (not the case on a plain Joomla site).
        $pages = [
            '/index.php?option=com_users&view=remind',
            '/',
        ];

        foreach ($pages as $page) {
            $ch = curl_init($this->baseUrl . $page);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_HEADER, true);
            $response = (string) curl_exec($ch);
            curl_close($ch);

            $cookies = [];
            if (preg_match('/Set-Cookie:\s*([^=;\s]+)=([^;\r\n]+)/i', $response, $m)) {
                $cookies[$m[1]] = $m[2];
            }

            if (preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $response, $m)
                || preg_match('/"csrf\.token"\s*:\s*"([a-f0-9]{32})"/i', $response, $m)) {
                return [$cookies, $m[1]];
            }
        }

        return [[], ''];
    }

    /**
     * The plugin answers with a JSON envelope; the handler result may be nested
     * as a JSON string in data[0] (com_ajax format=json).
     *
     * @return array<string,mixed>|null
     */
    private function decode(string $body): ?array
    {
        $outer = json_decode($body, true);

        if (!is_array($outer)) {
            return null;
        }

        if (isset($outer['data'][0]) && is_string($outer['data'][0])) {
            $inner = json_decode($outer['data'][0], true);

            if (is_array($inner) && array_key_exists('success', $inner)) {
                return $inner;
            }
        }

        return $outer;
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
            [],
            false
        );
        $data     = $this->decode($body);
        $rejected = ($code >= 300 && $code < 400)
            || ($data !== null && ($data['success'] ?? null) === false)
            || $code === 403;

        $this->test('Remind request without token is rejected', $rejected, "HTTP $code, body: " . substr($body, 0, 200));
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
        $data = $this->decode($body);

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
        $data = $this->decode($body);

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

    public function run(): bool
    {
        echo "=== Username Reminder Request Tests ===\n";

        $this->testHandlerExists();
        $this->testNoTokenRejected();
        $this->testInvalidEmail();
        $this->testUnknownEmail();
        $this->testLanguageKeys();

        echo "\n=== Remind Request Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new RemindRequestTest();
exit($test->run() ? 0 : 1);
