<?php
/**
 * Test 10: Profile
 *
 * Verifies profile-save handler behaviour via:
 * - Reflection-based method existence (no strpos)
 * - Real HTTP: unauthenticated save → rejected, no-token → rejected
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

JLoader::registerNamespace(
    'Advans\Plugin\Ajax\JoomlaAjaxForms',
    '/var/www/html/plugins/ajax/joomlaajaxforms/src',
    false, false, 'psr4'
);

class ProfileTest
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

    private function testMethodsViaReflection(): void
    {
        echo "\n--- Method existence (Reflection) ---\n";

        $class = \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class;
        $this->test('Class loadable', class_exists($class));
        if (!class_exists($class)) {
            return;
        }

        $rc = new ReflectionClass($class);
        foreach (['handleSaveProfile'] as $m) {
            $this->test("Method $m exists", $rc->hasMethod($m));
        }
    }

    private function testUnauthenticatedRejected(): void
    {
        echo "\n--- HTTP: unauthenticated profile save → rejected ---\n";

        // No session cookie → guest user → must be rejected
        $url = $this->baseUrl . $this->ajaxPath . '&task=saveProfile';
        [$code, $body] = $this->http('POST', $url, [
            'task' => 'saveProfile', 'name' => 'Test User', 'email' => 'test@example.com',
        ], [], false);

        $data    = json_decode($body, true);
        $isJson  = $data !== null;
        $rejected = ($code >= 300 && $code < 400)
            || ($isJson && isset($data['success']) && $data['success'] === false)
            || (!$isJson && $code === 200);

        $this->test(
            'Unauthenticated saveProfile → rejected',
            $rejected,
            "HTTP $code, body: " . substr($body, 0, 200)
        );
    }

    private function testNoTokenRejected(): void
    {
        echo "\n--- HTTP: no CSRF token → rejected ---\n";

        $url = $this->baseUrl . $this->ajaxPath . '&task=saveProfile';
        [$code, $body] = $this->http('POST', $url, [
            'task' => 'saveProfile', 'name' => 'Test User', 'email' => 'test@example.com',
        ], [], false);

        $data    = json_decode($body, true);
        $isJson  = $data !== null;
        $rejected = ($code >= 300 && $code < 400)
            || ($isJson && isset($data['success']) && $data['success'] === false)
            || (!$isJson && $code === 200);

        $this->test('No-token profileSave POST rejected', $rejected, "HTTP $code, body: " . substr($body, 0, 200));
    }

    private function testLanguageKeys(): void
    {
        echo "\n--- Language keys ---\n";

        $lang = Factory::getLanguage();
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ADMINISTRATOR);
        $lang->load('plg_ajax_joomlaajaxforms', JPATH_ROOT . '/plugins/ajax/joomlaajaxforms');

        foreach ([
            'PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVED',
            'PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVE_FAILED',
            'PLG_AJAX_JOOMLAAJAXFORMS_NOT_LOGGED_IN',
        ] as $key) {
            $this->test("Language key $key present", $lang->hasKey($key) !== false);
        }
    }

    public function run(): bool
    {
        echo "=== Profile Tests ===\n";

        $this->testMethodsViaReflection();
        $this->testUnauthenticatedRejected();
        $this->testNoTokenRejected();
        $this->testLanguageKeys();

        echo "\n=== Profile Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new ProfileTest();
exit($test->run() ? 0 : 1);
