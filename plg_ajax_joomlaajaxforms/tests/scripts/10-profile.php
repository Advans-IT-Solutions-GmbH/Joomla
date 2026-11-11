<?php
/**
 * Test 10: Profile
 *
 * Verifies profile-save handler behaviour via:
 * - Reflection-based method existence (no strpos)
 * - Real HTTP: unauthenticated save → rejected, no-token → rejected
 * - Real HTTP: a signed-in save goes through the profile form of com_users
 *   (password rules, name filter, password taken unchanged)
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

        // Always a JSON error, never a redirect (also for a new session).
        $rejected = $code === 200 && ajaxforms_is_json_rejection($body);

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

        // Always a JSON error, never a redirect (also for a new session).
        $rejected = $code === 200 && ajaxforms_is_json_rejection($body);

        $this->test('No-token profileSave POST rejected', $rejected, "HTTP $code, body: " . substr($body, 0, 200));
    }

    /** GET a page in the given cookie jar and return its CSRF token ('' if none). */
    private function tokenFrom(string $path, string $jar): string
    {
        $ch = curl_init($this->baseUrl . $path);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        $html = (string) curl_exec($ch);
        curl_close($ch);

        return preg_match('/<input[^>]+name="([a-f0-9]{32})"[^>]+value="1"/i', $html, $m) ? $m[1] : '';
    }

    /** POST to the endpoint in the given cookie jar and return the plugin answer. */
    private function ajax(string $task, array $fields, string $jar): ?array
    {
        $ch = curl_init($this->baseUrl . $this->ajaxPath . '&task=' . $task);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(['task' => $task] + $fields));
        curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
        curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Kept for the failure message: a body that is not JSON (a PHP notice,
        // an error page or a redirect) is otherwise only reported as NULL.
        $this->lastAnswer = "HTTP $code, body: " . substr(trim(strip_tags($body)), 0, 600);

        return ajaxforms_decode_response($body);
    }

    /** Raw status and start of the body of the last endpoint answer. */
    private string $lastAnswer = '';

    /**
     * A signed-in profile save applies the profile form of com_users: the
     * password rules of the Users options, the string filter on the name, and
     * the password exactly as typed (no tag stripping or entity decoding).
     */
    private function testSignedInSaveUsesTheCoreProfileForm(): void
    {
        echo "\n--- HTTP: signed-in profile save follows com_users ---\n";

        $user     = ajaxforms_create_test_user('profile');
        $oldPass  = 'Old-Passw0rd-2026!';
        $newPass  = 'New<b>Pass</b>&amp;9x';
        $db       = Factory::getContainer()->get(DatabaseInterface::class);
        $original = ajaxforms_set_component_params('com_users', [
            'minimum_length'    => 12,
            'minimum_integers'  => 1,
            'minimum_symbols'   => 1,
            'minimum_uppercase' => 1,
            'minimum_lowercase' => 1,
        ]);
        $jar = (string) tempnam(sys_get_temp_dir(), 'profile-cookies-');

        $hashOf = function () use ($db, $user): string {
            $db->setQuery('SELECT password FROM #__users WHERE id = ' . (int) $user->id);

            return (string) $db->loadResult();
        };
        $nameOf = function () use ($db, $user): string {
            $db->setQuery('SELECT name FROM #__users WHERE id = ' . (int) $user->id);

            return (string) $db->loadResult();
        };

        try {
            $db->setQuery(
                'UPDATE #__users SET password = ' . $db->quote(\Joomla\CMS\User\UserHelper::hashPassword($oldPass))
                . ' WHERE id = ' . (int) $user->id
            )->execute();

            $token = $this->tokenFrom('/index.php?option=com_users&view=login', $jar);
            $login = $this->ajax('login', ['username' => $user->username, 'password' => $oldPass, $token => '1'], $jar);
            $this->test('Test account signs in', ($login['success'] ?? null) === true, var_export($login, true));

            $token = $this->tokenFrom('/index.php?option=com_users&view=profile&layout=edit', $jar);
            $this->test('Profile edit form delivers a token', $token !== '');

            $form = [
                'name'     => 'Renamed <b>User</b>',
                'username' => $user->username,
                'email1'   => $user->email,
                'email2'   => $user->email,
            ];

            $weak = $this->ajax('saveProfile', ['jform' => $form + ['password1' => 'short1!A', 'password2' => 'short1!A'], $token => '1'], $jar);
            $this->test('A password below the rules of the Users options is refused', ($weak['success'] ?? null) === false, $this->lastAnswer);
            $this->test('The refused password is not stored', \Joomla\CMS\User\UserHelper::verifyPassword($oldPass, $hashOf()));

            $saved = $this->ajax('saveProfile', ['jform' => $form + ['password1' => $newPass, 'password2' => $newPass], $token => '1'], $jar);
            $this->test('A valid profile save is accepted', ($saved['success'] ?? null) === true, $this->lastAnswer);
            $this->test('The password is stored exactly as typed', \Joomla\CMS\User\UserHelper::verifyPassword($newPass, $hashOf()));
            $this->test('The name is filtered like com_users (no markup)', $nameOf() === 'Renamed User', 'stored: ' . $nameOf());

            // Overrides that post jform[email] instead of jform[email1] keep working.
            $alias = $this->ajax('saveProfile', ['jform' => ['name' => 'Alias User', 'username' => $user->username, 'email' => $user->email], $token => '1'], $jar);
            $this->test('A save with jform[email] instead of jform[email1] is accepted', ($alias['success'] ?? null) === true, $this->lastAnswer);
            $this->test('That save stores the name', $nameOf() === 'Alias User', 'stored: ' . $nameOf());
        } finally {
            ajaxforms_restore_component_params('com_users', $original);
            ajaxforms_delete_test_user('profile');
            @unlink($jar);
        }
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
        $this->testSignedInSaveUsesTheCoreProfileForm();
        $this->testLanguageKeys();

        echo "\n=== Profile Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new ProfileTest();
exit($test->run() ? 0 : 1);
