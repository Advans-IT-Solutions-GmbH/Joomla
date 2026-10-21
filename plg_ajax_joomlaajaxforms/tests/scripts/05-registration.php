<?php
/**
 * Test 05: Registration
 *
 * Verifies registration handler behaviour via:
 * - Reflection-based method existence (no strpos)
 * - Real HTTP: duplicate username → success:false, no-token → rejected
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

class RegistrationTest
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
        foreach (['handleRegistration'] as $m) {
            $this->test("Method $m exists", $rc->hasMethod($m));
        }
    }

    private function testRegistrationFeatureConfig(): void
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
        $enabled = (int) ($params['enable_registration'] ?? 1);
        $this->test('enable_registration is 0 or 1', in_array($enabled, [0, 1], true), "Got: $enabled");
    }

    private function testNoTokenRejected(): void
    {
        echo "\n--- HTTP: no CSRF token → rejected ---\n";

        $url = $this->baseUrl . $this->ajaxPath . '&task=register';
        [$code, $body] = $this->http('POST', $url, [
            'task' => 'register', 'username' => 'testuser', 'email' => 'test@example.com', 'password' => 'Test123!',
        ], [], false);

        // Always a JSON error, never a redirect (also for a new session).
        $rejected = $code === 200 && ajaxforms_is_json_rejection($body);

        $this->test('No-token register POST rejected', $rejected, "HTTP $code, body: " . substr($body, 0, 200));
    }

    private function testDuplicateUsernameRejected(): void
    {
        echo "\n--- HTTP: duplicate username → rejected ---\n";

        // 'admin' always exists in a fresh Joomla install
        [$cookie, $token] = $this->getSessionAndToken();

        $cookies = [];
        if ($cookie && str_contains($cookie, '=')) {
            [$cn, $cv] = explode('=', $cookie, 2);
            $cookies[$cn] = $cv;
        }

        $fields = [
            'task'     => 'register',
            'username' => 'admin',
            'email'    => 'duplicate@example.com',
            'password' => 'Test123!',
            'password2' => 'Test123!',
        ];
        if ($token) {
            $fields[$token] = '1';
        }

        $url = $this->baseUrl . $this->ajaxPath . '&task=register';
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
            'Duplicate username → success:false or redirect',
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
            'PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_SUCCESS',
            'PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_FAILED',
            'PLG_AJAX_JOOMLAAJAXFORMS_USERNAME_EXISTS',
            'PLG_AJAX_JOOMLAAJAXFORMS_EMAIL_EXISTS',
        ] as $key) {
            $this->test("Language key $key present", $lang->hasKey($key) !== false);
        }
    }

    /**
     * The activation mail and the notice about a new registration have to carry
     * the design of the site, just like the reset and the reminder mail.
     *
     * Both used to be composed in the plugin and sent with setBody(), so they
     * left the site as Content-Type: text/plain. The check is made on the
     * message the site actually hands to the mail transport.
     */
    private function testRegistrationMailsCarryTheSiteDesign(): void
    {
        echo "\n--- Registration mails go through the site's mail templates ---\n";

        $reason = ajaxforms_mailcatch_install();

        try {
            $this->test('Mail capture in place', $reason === null, (string) $reason);

            if ($reason !== null) {
                return;
            }

            $selfTest = ajaxforms_mailcatch_selftest();
            $this->test('Mail capture works', $selfTest === null, (string) $selfTest);

            if ($selfTest !== null) {
                return;
            }

            ajaxforms_enable_html_mail();

            $user = ajaxforms_create_test_user('activation');
            // handleRegistration() stores the plain token in activation and puts
            // it into the link of the mail.
            $user->activation = str_repeat('9f8e7d6c', 4);

            $plugin = ajaxforms_plugin_instance();

            $this->checkActivationMail($plugin, $user);
            $this->checkAdminNotification($plugin, $user);
        } finally {
            ajaxforms_delete_test_user('activation');
            ajaxforms_mailcatch_remove();
        }
    }

    private function checkActivationMail(object $plugin, object $user): void
    {
        // Self-activation (useractivation = 1) and admin activation (2) have
        // their own core template; this is the self-activation case.
        $config = new \Joomla\Registry\Registry(['useractivation' => 1]);

        ajaxforms_mailcatch_clear();

        $send = new ReflectionMethod($plugin, 'sendActivationEmail');
        $send->setAccessible(true);
        $send->invoke($plugin, $user, $config);

        $messages = ajaxforms_mailcatch_messages();
        $this->test('Exactly one activation mail sent', count($messages) === 1, count($messages) . ' captured');

        if (count($messages) !== 1) {
            return;
        }

        $raw  = $messages[0];
        $text = ajaxforms_mail_text($raw);

        $this->test('Activation mail addressed to the account',
            stripos($raw, (string) $user->email) !== false);

        $this->test('Activation mail carries an HTML part (Content-Type is not text/plain)',
            (bool) preg_match('#^Content-Type:\s*multipart/alternative#mi', $raw),
            'headers: ' . substr($raw, 0, 400));

        $this->test('Activation mail is rendered through the mail layout',
            stripos($text, '<html') !== false && stripos($text, '<table') !== false);

        $this->test('Activation mail carries the activation link',
            strpos($text, 'registration.activate') !== false
                && strpos($text, (string) $user->activation) !== false,
            'activation link or token missing');

        $this->test('Activation mail strings are translated, not raw language keys',
            stripos($text, 'COM_USERS_EMAIL_') === false);

        // The stored password hash must never reach a mail, whatever a site put
        // into its template.
        $this->test('Activation mail carries no stored credential',
            (string) $user->password !== '' && strpos($raw, (string) $user->password) === false);
    }

    private function checkAdminNotification(object $plugin, object $user): void
    {
        ajaxforms_mailcatch_clear();

        $send = new ReflectionMethod($plugin, 'sendAdminNotification');
        $send->setAccessible(true);
        $send->invoke($plugin, $user);

        $messages = ajaxforms_mailcatch_messages();
        $this->test('Exactly one notice about the registration sent',
            count($messages) === 1, count($messages) . ' captured');

        if (count($messages) !== 1) {
            return;
        }

        $raw  = $messages[0];
        $text = ajaxforms_mail_text($raw);

        $this->test('Notice goes to the address of the site',
            stripos($raw, (string) Factory::getApplication()->get('mailfrom')) !== false);

        $this->test('Notice carries an HTML part (Content-Type is not text/plain)',
            (bool) preg_match('#^Content-Type:\s*multipart/alternative#mi', $raw),
            'headers: ' . substr($raw, 0, 400));

        $this->test('Notice is rendered through the mail layout',
            stripos($text, '<html') !== false && stripos($text, '<table') !== false);

        $this->test('Notice names the new account',
            strpos($text, (string) $user->username) !== false);

        $this->test('Notice strings are translated, not raw language keys',
            stripos($text, 'COM_USERS_EMAIL_') === false);
    }

    /**
     * One registration through the endpoint of the site.
     *
     * @return  array{0: string, 1: array<string, mixed>|null}  token, decoded answer
     */
    private function register(string $suffix, string $password, array $extra = []): array
    {
        $username = 'ajaxforms_' . $suffix;

        // A site may add consent fields to the registration form (privacy consent,
        // terms). They are required exactly as in com_users, so the visitor ticks
        // them. Fields the form does not have are dropped by its filter.
        $extra['jform'] = ($extra['jform'] ?? []) + [
            'privacyconsent' => ['privacy' => '1'],
            'terms'          => ['terms' => '1'],
        ];

        return ajaxforms_post_task($this->baseUrl, 'register', $extra + [
            'name'      => 'AJAX Forms ' . $suffix,
            'username'  => $username,
            'email'     => $username . '@example.test',
            'email2'    => $username . '@example.test',
            'password'  => $password,
            'password2' => $password,
        ], [
            '/index.php?option=com_users&view=registration',
            '/index.php?option=com_users&view=login',
            '/',
        ]);
    }

    /**
     * The registration must apply the rules of the core registration: the
     * switch "Allow User Registration", the password rules of the Users options
     * and the captcha chosen there. Every case runs through the real endpoint,
     * so the options are read the way a visitor's request reads them.
     */
    private function testCoreRegistrationRules(): void
    {
        echo "\n--- HTTP: registration follows the rules of com_users ---\n";

        $original = ajaxforms_set_component_params('com_users', [
            'allowUserRegistration' => 1,
            'useractivation'        => 0,
            'captcha'               => '0',
            'minimum_length'        => 12,
            'minimum_integers'      => 1,
            'minimum_symbols'       => 1,
            'minimum_uppercase'     => 1,
            'minimum_lowercase'     => 1,
        ]);

        $suffixes = ['regshort', 'regdigit', 'regsymbol', 'regupper', 'reglower', 'regok', 'regconsent', 'regclosed', 'regcaptcha'];

        try {
            foreach ($suffixes as $suffix) {
                ajaxforms_delete_test_user($suffix);
            }

            $refused = [
                'regshort'  => ['Ab1!', 'shorter than the minimum length'],
                'regdigit'  => ['Abcdefghijk!', 'without a digit'],
                'regsymbol' => ['Abcdefghijk1', 'without a symbol'],
                'regupper'  => ['abcdefghij1!', 'without an upper case letter'],
                'reglower'  => ['ABCDEFGHIJ1!', 'without a lower case letter'],
            ];

            foreach ($refused as $suffix => [$password, $what]) {
                [$token, $answer] = $this->register($suffix, $password);

                $this->test("Token found for the case $what", $token !== '');
                $this->test(
                    "Password $what is refused",
                    $answer !== null && ($answer['success'] ?? null) === false,
                    'answer: ' . var_export($answer, true)
                );
                $this->test("No account created for a password $what", ajaxforms_user_record('ajaxforms_' . $suffix) === null);
            }

            [, $answer] = $this->register('regok', 'Abcdefghij1!');
            $this->test(
                'Password that meets every rule is accepted',
                $answer !== null && ($answer['success'] ?? null) === true,
                'answer: ' . var_export($answer, true)
            );
            $this->test('Account created for the valid password', ajaxforms_user_record('ajaxforms_regok') !== null);

            // Where the site adds the privacy consent to the registration form, it
            // is required on the AJAX path exactly as in com_users.
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $db->setQuery(
                (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
                    ->select('COUNT(*)')
                    ->from($db->quoteName('#__extensions'))
                    ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
                    ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
                    ->where($db->quoteName('element') . ' = ' . $db->quote('privacyconsent'))
                    ->where($db->quoteName('enabled') . ' = 1')
            );

            if ((int) $db->loadResult() > 0) {
                [, $answer] = $this->register('regconsent', 'Abcdefghij1!', ['jform' => ['privacyconsent' => ['privacy' => '']]]);
                $this->test(
                    'Registration without the privacy consent of the form is refused',
                    $answer !== null && ($answer['success'] ?? null) === false,
                    'answer: ' . var_export($answer, true)
                );
                $this->test('No account created without the privacy consent', ajaxforms_user_record('ajaxforms_regconsent') === null);
            }

            // The captcha of the Users options: a test provider that accepts
            // exactly "ajaxforms-ok" (see ajaxforms_install_test_captcha()).
            ajaxforms_install_test_captcha();
            ajaxforms_set_component_params('com_users', ['captcha' => 'ajaxformstest']);

            foreach (['no answer' => null, 'a wrong answer' => 'wrong'] as $what => $captcha) {
                [, $answer] = $this->register('regcaptcha', 'Abcdefghij1!', $captcha === null ? [] : ['jform' => ['captcha' => $captcha]]);
                $this->test(
                    "Registration with $what to the captcha of the Users options is refused",
                    $answer !== null && ($answer['success'] ?? null) === false,
                    'answer: ' . var_export($answer, true)
                );
                $this->test("No account created with $what to the captcha", ajaxforms_user_record('ajaxforms_regcaptcha') === null);
            }

            [, $answer] = $this->register('regcaptcha', 'Abcdefghij1!', ['jform' => ['captcha' => 'ajaxforms-ok']]);
            $this->test(
                'Registration with the right answer to the captcha is accepted',
                $answer !== null && ($answer['success'] ?? null) === true,
                'answer: ' . var_export($answer, true)
            );
            $this->test('Account created with the right answer to the captcha', ajaxforms_user_record('ajaxforms_regcaptcha') !== null);
            ajaxforms_delete_test_user('regcaptcha');

            ajaxforms_set_component_params('com_users', ['captcha' => '0', 'allowUserRegistration' => 0]);
            [, $answer] = $this->register('regclosed', 'Abcdefghij1!');
            $this->test(
                'Registration is refused while "Allow User Registration" is off',
                $answer !== null && ($answer['success'] ?? null) === false,
                'answer: ' . var_export($answer, true)
            );
            $this->test('No account created while registration is off', ajaxforms_user_record('ajaxforms_regclosed') === null);
        } finally {
            ajaxforms_restore_component_params('com_users', $original);
            ajaxforms_remove_test_captcha();

            foreach ($suffixes as $suffix) {
                ajaxforms_delete_test_user($suffix);
            }
        }
    }

    public function run(): bool
    {
        echo "=== Registration Tests ===\n";

        $this->testMethodsViaReflection();
        $this->testRegistrationFeatureConfig();
        $this->testNoTokenRejected();
        $this->testDuplicateUsernameRejected();
        $this->testLanguageKeys();
        $this->testCoreRegistrationRules();
        $this->testRegistrationMailsCarryTheSiteDesign();

        echo "\n=== Registration Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new RegistrationTest();
exit($test->run() ? 0 : 1);
