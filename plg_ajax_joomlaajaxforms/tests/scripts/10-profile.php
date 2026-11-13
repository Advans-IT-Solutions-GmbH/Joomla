<?php
/**
 * Test 10: Profile
 *
 * Verifies profile-save handler behaviour via:
 * - Reflection-based method existence (no strpos)
 * - Real HTTP: unauthenticated save → rejected, no-token → rejected
 * - Real HTTP: a signed-in save goes through the profile form of com_users
 *   (password rules, name filter, password taken unchanged)
 * - Real HTTP: under a forced password reset the AJAX save is redirected and
 *   stores nothing, the com_users profile save the script falls back to works
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

    /**
     * Custom fields: the fields plugin marks every editable custom field missing
     * from the request with false, which the save turns into a cleared value.
     * Only fields the submitted form rendered may be cleared that way; a partial
     * override must keep the values it never displayed.
     */
    private function testOnlyRenderedCustomFieldsAreCleared(): void
    {
        echo "\n--- Custom fields of partial and complete profile forms ---\n";

        $class = \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class;

        // Partial override: shows one field, another stored field is not rendered.
        $partial = $class::keepRenderedClears(
            ['name' => 'A', 'com_fields' => ['shown' => 'value', 'stored' => false]],
            ['shown']
        );
        $this->test(
            'A field the partial form did not render keeps its stored value',
            !array_key_exists('stored', $partial['com_fields'] ?? []) && ($partial['com_fields']['shown'] ?? null) === 'value'
        );

        // Complete form: a rendered checkbox field with every box unticked is cleared.
        $complete = $class::keepRenderedClears(['com_fields' => ['boxes' => false]], ['boxes']);
        $this->test('A rendered field missing from the request is saved as cleared', ($complete['com_fields']['boxes'] ?? null) === false);

        // Legacy request: no rendered fields, nothing is cleared.
        $legacy = $class::keepRenderedClears(['name' => 'B', 'com_fields' => ['one' => false, 'two' => false]], []);
        $this->test('A legacy request clears no custom field', !array_key_exists('com_fields', $legacy) && $legacy['name'] === 'B');
    }

    /** A custom field the request neither carries nor clears is omitted from the validation. */
    private function testOmittedFieldsAreLeftOutOfTheValidation(): void
    {
        echo "\n--- Omitted custom fields ---\n";

        $class = \Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class;

        $omitted = $class::omittedFieldNames(
            ['name' => 'A', 'com_fields' => ['shown' => 'new', 'cleared' => false]],
            ['shown', 'cleared', 'hidden', 'date']
        );
        $this->test(
            'Only the fields the request neither carries nor clears are omitted',
            $omitted === ['hidden', 'date'],
            json_encode($omitted)
        );

        $bare = $class::omittedFieldNames(['name' => 'B'], ['hidden']);
        $this->test('A request without custom fields omits them all', $bare === ['hidden']);
        $none = $class::omittedFieldNames(['name' => 'C'], []);
        $this->test('No custom fields omit nothing', $none === []);
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

        $this->lastCode = $code;

        // Kept for the failure message: a body that is not JSON (a PHP notice,
        // an error page or a redirect) is otherwise only reported as NULL.
        $this->lastAnswer = "HTTP $code, body: " . substr(trim(strip_tags($body)), 0, 600);

        return ajaxforms_decode_response($body);
    }

    /** Raw status and start of the body of the last endpoint answer. */
    private string $lastAnswer = '';

    /** HTTP status of the last endpoint answer. */
    private int $lastCode = 0;

    /**
     * A signed-in account with a forced password reset (requireReset = 1):
     * Joomla lets only the profile save of com_users through, so the AJAX
     * save is redirected and stores nothing (the security gate holds). The
     * script hands such a redirected save to com_users (task profile.save),
     * and that save stores the new password and clears the reset flag.
     */
    private function testForcedResetProfileGoesToComUsers(): void
    {
        echo "\n--- HTTP: forced password reset → profile form goes to com_users ---\n";

        // The script side: a redirected answer is handed to com_users.
        $source = (string) @file_get_contents(ajaxforms_script_path());
        $body   = preg_match('/\n    saveProfile:\s*function\s*\(form\)\s*\{(.*?)\n    \}\n/s', $source, $m) ? $m[1] : '';
        $this->test(
            'saveProfile() hands a redirected answer to com_users',
            str_contains($body, 'response.redirected')
                && str_contains($body, 'JoomlaAjaxForms.submitNatively(form)')
                && str_contains($body, "'profile.save'")
        );

        $user    = ajaxforms_create_test_user('forcedreset');
        $oldPass = 'Old-Passw0rd-2026!';
        $newPass = 'New-Passw0rd-2026!';
        $db      = Factory::getContainer()->get(DatabaseInterface::class);
        $jar     = (string) tempnam(sys_get_temp_dir(), 'reset-cookies-');

        $row = function () use ($db, $user): object {
            $db->setQuery('SELECT password, requireReset FROM #__users WHERE id = ' . (int) $user->id);

            return $db->loadObject() ?: (object) ['password' => '', 'requireReset' => null];
        };

        try {
            $db->setQuery(
                'UPDATE #__users SET requireReset = 1, password = '
                . $db->quote(\Joomla\CMS\User\UserHelper::hashPassword($oldPass))
                . ' WHERE id = ' . (int) $user->id
            )->execute();

            $consent = (object) [
                'user_id' => (int) $user->id,
                'state'   => 1,
                'created' => Factory::getDate()->toSql(),
                'subject' => 'PLG_SYSTEM_PRIVACYCONSENT_SUBJECT',
                'body'    => 'Test consent',
            ];
            $db->insertObject('#__privacy_consents', $consent);

            $token = $this->tokenFrom('/index.php?option=com_users&view=login', $jar);
            $login = $this->ajax('login', ['username' => $user->username, 'password' => $oldPass, $token => '1'], $jar);
            $this->test('Account with a forced reset signs in', ($login['success'] ?? null) === true, var_export($login, true));

            $token = $this->tokenFrom('/index.php?option=com_users&view=profile&layout=edit', $jar);
            $this->test('Profile edit form delivers a token under the forced reset', $token !== '');

            $form = [
                'name'      => $user->name,
                'username'  => $user->username,
                'email1'    => $user->email,
                'email2'    => $user->email,
                'password1' => $newPass,
                'password2' => $newPass,
            ];

            $ajax = $this->ajax('saveProfile', ['jform' => $form, $token => '1'], $jar);
            $this->test(
                'The AJAX save under the forced reset is redirected by Joomla, not answered',
                $ajax === null && $this->lastCode >= 300 && $this->lastCode < 400,
                $this->lastAnswer
            );
            $this->test(
                'That AJAX save stores nothing',
                \Joomla\CMS\User\UserHelper::verifyPassword($oldPass, $row()->password) && (int) $row()->requireReset === 1
            );

            // The form as the script submits it after the redirect: to com_users,
            // task profile.save from the submit button (or the added field).
            $token = $this->tokenFrom('/index.php?option=com_users&view=profile&layout=edit', $jar);
            $ch    = curl_init($this->baseUrl . '/index.php?option=com_users&task=profile.save');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'option' => 'com_users', 'task' => 'profile.save', 'jform' => $form, $token => '1',
            ]));
            curl_setopt($ch, CURLOPT_COOKIEFILE, $jar);
            curl_setopt($ch, CURLOPT_COOKIEJAR, $jar);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            $after = $row();
            $this->test(
                'The com_users profile save stores the new password',
                \Joomla\CMS\User\UserHelper::verifyPassword($newPass, $after->password),
                "HTTP $code"
            );
            $this->test('That save clears the forced reset', (int) $after->requireReset === 0, "HTTP $code");
        } finally {
            $db->setQuery('DELETE FROM #__privacy_consents WHERE user_id = ' . (int) $user->id)->execute();
            ajaxforms_delete_test_user('forcedreset');
            @unlink($jar);
        }
    }

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

            // The account has given the privacy consent, as a real account on a
            // site with the privacy consent plugin would: without it that plugin
            // redirects every request of the signed-in user to the profile form.
            $consent = (object) [
                'user_id' => (int) $user->id,
                'state'   => 1,
                'created' => \Joomla\CMS\Factory::getDate()->toSql(),
                'subject' => 'PLG_SYSTEM_PRIVACYCONSENT_SUBJECT',
                'body'    => 'Test consent',
            ];
            $db->insertObject('#__privacy_consents', $consent);

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

            // A legacy request with plain field names and no password fields:
            // accepted, no PHP warning in the answer, password unchanged.
            $hashBefore = $hashOf();
            $legacy     = $this->ajax('saveProfile', ['name' => 'Legacy User', 'email' => $user->email, $token => '1'], $jar);
            $this->test('A legacy save with plain name and email is accepted as JSON', ($legacy['success'] ?? null) === true, $this->lastAnswer);
            $this->test('That save stores the name and keeps the password', $nameOf() === 'Legacy User' && $hashOf() === $hashBefore, 'stored: ' . $nameOf());

            // A required custom field with a stored value that a name/email-only
            // override does not render must not block the save.
            $fieldId    = 0;
            $calendarId = 0;
            try {
                $now   = Factory::getDate()->toSql();
                $field = (object) [
                    'asset_id' => 0, 'context' => 'com_users.user', 'group_id' => 0,
                    'title' => 'Ajaxforms required', 'name' => 'ajaxforms-required', 'label' => 'Ajaxforms required',
                    'default_value' => '', 'type' => 'text', 'note' => '', 'description' => '',
                    'state' => 1, 'required' => 1, 'only_use_in_subform' => 0, 'ordering' => 1,
                    'params' => '{}', 'fieldparams' => '{}', 'language' => '*',
                    'created_time' => $now, 'created_user_id' => 0, 'modified_time' => $now, 'modified_by' => 0,
                    'access' => 1,
                ];
                try {
                    $db->insertObject('#__fields', $field, 'id');
                    $fieldId = (int) $field->id;
                } catch (\Throwable $e) {
                    $this->lastAnswer = $e->getMessage();
                }
                $this->test('A required custom field for users exists', $fieldId > 0, (string) $this->lastAnswer);

                // A stored calendar value is a UTC timestamp. It is not user
                // input of this save and must pass through unchanged.
                if ($fieldId > 0) {
                    $calendar               = clone $field;
                    $calendar->name         = 'ajaxforms-date';
                    $calendar->title        = 'Ajaxforms date';
                    $calendar->label        = 'Ajaxforms date';
                    $calendar->type         = 'calendar';
                    $calendar->required     = 0;
                    $calendar->ordering     = 2;
                    unset($calendar->id);
                    try {
                        $db->insertObject('#__fields', $calendar, 'id');
                        $calendarId = (int) $calendar->id;
                    } catch (\Throwable $e) {
                        $this->lastAnswer = $e->getMessage();
                    }
                    $this->test('A calendar custom field for users exists', $calendarId > 0, (string) $this->lastAnswer);

                    if ($calendarId > 0) {
                        $dateRow = (object) ['field_id' => $calendarId, 'item_id' => (string) $user->id, 'value' => '2026-01-15 09:30:00'];
                        $db->insertObject('#__fields_values', $dateRow);
                    }
                }

                if ($fieldId > 0) {
                    $valueRow = (object) ['field_id' => $fieldId, 'item_id' => (string) $user->id, 'value' => 'stored value'];
                    $db->insertObject('#__fields_values', $valueRow);
                    $required = $this->ajax('saveProfile', ['jform' => ['name' => 'Required User', 'username' => $user->username, 'email' => $user->email], $token => '1'], $jar);
                    $this->test('A name/email-only save is accepted although a required custom field is not rendered', ($required['success'] ?? null) === true, $this->lastAnswer);
                    $this->test('That save stores the name', $nameOf() === 'Required User', 'stored: ' . $nameOf());
                    $db->setQuery('SELECT value FROM #__fields_values WHERE field_id = ' . $fieldId . ' AND item_id = ' . $db->quote((string) $user->id));
                    $this->test('The stored value of the required field is kept', (string) $db->loadResult() === 'stored value');

                    if ($calendarId > 0) {
                        $db->setQuery('SELECT value FROM #__fields_values WHERE field_id = ' . $calendarId . ' AND item_id = ' . $db->quote((string) $user->id));
                        $this->test('An omitted calendar field is not revalidated and keeps its stored value', (string) $db->loadResult() === '2026-01-15 09:30:00');
                    }
                }
            } finally {
                foreach ([$fieldId, $calendarId] as $createdId) {
                    if ($createdId > 0) {
                        $db->setQuery('DELETE FROM #__fields_values WHERE field_id = ' . $createdId)->execute();
                        $db->setQuery('DELETE FROM #__fields WHERE id = ' . $createdId)->execute();
                    }
                }
            }        } finally {
            $db->setQuery('DELETE FROM #__privacy_consents WHERE user_id = ' . (int) $user->id)->execute();
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
        $this->testOnlyRenderedCustomFieldsAreCleared();
        $this->testOmittedFieldsAreLeftOutOfTheValidation();
        $this->testUnauthenticatedRejected();
        $this->testNoTokenRejected();
        $this->testSignedInSaveUsesTheCoreProfileForm();
        $this->testForcedResetProfileGoesToComUsers();
        $this->testLanguageKeys();

        echo "\n=== Profile Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new ProfileTest();
exit($test->run() ? 0 : 1);
