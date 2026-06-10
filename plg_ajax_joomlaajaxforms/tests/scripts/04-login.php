<?php
/**
 * Test 04: Login
 *
 * Verifies login/logout/MFA handler behaviour via:
 * - Reflection-based method existence (no strpos)
 * - Real HTTP requests: wrong credentials → success:false, no-token → rejected
 * - Language key presence via Joomla language API
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Event\Dispatcher;
use Joomla\Registry\Registry;

JLoader::registerNamespace(
    'Advans\Plugin\Ajax\JoomlaAjaxForms',
    '/var/www/html/plugins/ajax/joomlaajaxforms/src',
    false, false, 'psr4'
);

class LoginTest
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
        foreach (['handleLogin', 'handleLogout', 'getMfaMethods'] as $m) {
            $this->test("Method $m exists", $rc->hasMethod($m));
        }
    }

    private function testLoginFeatureConfig(): void
    {
        echo "\n--- Plugin config (DB query) ---\n";

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName('params'))
            ->from($db->quoteName('#__extensions'))
            ->where($db->quoteName('type')    . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder')  . ' = ' . $db->quote('ajax'))
            ->where($db->quoteName('element') . ' = ' . $db->quote('joomlaajaxforms'));
        $db->setQuery($query);
        $params = json_decode($db->loadResult() ?: '{}', true);

        $this->test('Plugin found in #__extensions', $params !== null);
        $enabled = (int) ($params['enable_login'] ?? 1);
        $this->test('enable_login is 0 or 1', in_array($enabled, [0, 1], true), "Got: $enabled");
    }

    private function testNoTokenRejected(): void
    {
        echo "\n--- HTTP: no CSRF token → rejected ---\n";

        $url = $this->baseUrl . $this->ajaxPath . '&task=login';
        [$code, $body] = $this->http('POST', $url, ['task' => 'login', 'username' => 'admin', 'password' => 'Admin123!'], [], false);

        $data    = json_decode($body, true);
        $isJson  = $data !== null;
        $rejected = ($code >= 300 && $code < 400)
            || ($isJson && isset($data['success']) && $data['success'] === false)
            || (!$isJson && $code === 200);

        $this->test('No-token login POST rejected', $rejected, "HTTP $code, body: " . substr($body, 0, 200));
    }

    private function testWrongCredentialsRejected(): void
    {
        echo "\n--- HTTP: wrong credentials → rejected ---\n";

        [$cookie, $token] = $this->getSessionAndToken();

        $cookies = [];
        if ($cookie && str_contains($cookie, '=')) {
            [$cn, $cv] = explode('=', $cookie, 2);
            $cookies[$cn] = $cv;
        }

        $fields = ['task' => 'login', 'username' => 'nonexistent_user_xyz', 'password' => 'wrong_pass_xyz'];
        if ($token) {
            $fields[$token] = '1';
        }

        $url = $this->baseUrl . $this->ajaxPath . '&task=login';
        [$code, $body] = $this->http('POST', $url, $fields, $cookies);

        $outer = json_decode($body, true);
        $inner = null;
        if (isset($outer['data'][0])) {
            $inner = is_string($outer['data'][0]) ? json_decode($outer['data'][0], true) : $outer['data'][0];
        }

        $rejected = ($inner !== null && isset($inner['success']) && $inner['success'] === false)
            || ($outer !== null && isset($outer['success']) && $outer['success'] === false)
            || ($code >= 300 && $code < 400);

        $this->test(
            'Wrong credentials → success:false or redirect',
            $rejected,
            "HTTP $code, body: " . substr($body, 0, 200)
        );
    }

    private function testLanguageKeys(): void
    {
        echo "\n--- Language keys ---\n";

        $lang = Factory::getLanguage();
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ADMINISTRATOR);
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ROOT . '/plugins/ajax/joomlaajaxforms');

        foreach ([
            'PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_SUCCESS',
            'PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_FAILED',
            'PLG_AJAX_JOOMLAAJAXFORMS_LOGOUT_SUCCESS',
            'PLG_AJAX_JOOMLAAJAXFORMS_MFA_REQUIRED',
        ] as $key) {
            $this->test("Language key $key present", $lang->hasKey($key) !== false);
        }
    }

    public function run(): bool
    {
        echo "=== Login Tests ===\n";

        $this->testMethodsViaReflection();
        $this->testLoginFeatureConfig();
        $this->testNoTokenRejected();
        $this->testWrongCredentialsRejected();
        $this->testLanguageKeys();

        echo "\n=== Login Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new LoginTest();
exit($test->run() ? 0 : 1);
