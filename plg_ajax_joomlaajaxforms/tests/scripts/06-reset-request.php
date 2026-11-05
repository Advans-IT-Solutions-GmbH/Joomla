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

        // Installed outside the try, cleaned up inside the finally: a partly
        // installed capture must never survive this method either.
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
     * The mail follows a supported account language or the configured default
     * site language; the active request language is never a selector.
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

        $plugin    = ajaxforms_plugin_instance();
        $configuredSiteTag = (string) \Joomla\CMS\Component\ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        $defaultTag = in_array($configuredSiteTag, ['de-DE', 'en-GB', 'fr-FR'], true)
            ? $configuredSiteTag
            : 'en-GB';

        foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
            $account         = new stdClass();
            $account->params = json_encode(['language' => $tag]);

            $this->test(
                "Supported account language $tag is selected even without its site language pack",
                $method->invoke($plugin, $account) === $tag,
                'got ' . var_export($method->invoke($plugin, $account), true)
            );
        }

        $unsupported         = new stdClass();
        $unsupported->params = json_encode(['language' => 'zz-ZZ']);

        $this->test(
            'An unsupported account language falls back to a supported site language',
            $method->invoke($plugin, $unsupported) === $defaultTag,
            'expected ' . $defaultTag . ', got ' . var_export($method->invoke($plugin, $unsupported), true)
        );

        $none         = new stdClass();
        $none->params = '{}';

        $this->test(
            'A guest or account without a language uses the configured site language, not the request language',
            $method->invoke($plugin, $none) === $defaultTag,
            'expected ' . $defaultTag . ', got ' . var_export($method->invoke($plugin, $none), true)
        );

        $siteParams = \Joomla\CMS\Component\ComponentHelper::getParams('com_languages');
        $originalSiteLanguage = (string) $siteParams->get('site', 'en-GB');

        try {
            $siteParams->set('site', 'de-CH');

            $this->test(
                'An unsupported configured site language falls back to en-GB',
                $method->invoke($plugin, $none) === 'en-GB',
                'got ' . var_export($method->invoke($plugin, $none), true)
            );
        } finally {
            $siteParams->set('site', $originalSiteLanguage);
        }
    }

    /**
     * The plugin supplies translated com_users defaults when only the site
     * language pack is installed. MailTemplate must still send the core
     * template, plain-text alternative and configured HTML layout in each
     * supported recipient language.
     */
    private function testCoreTemplateMailsSupportAllLocales(): void
    {
        echo "\n--- Core mail templates render in every shipped language ---\n";

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
            $this->test(
                'CLI bootstrap registers the core mail helper namespace',
                class_exists(\Joomla\Component\Mails\Administrator\Helper\MailsHelper::class)
            );

            $plugin = ajaxforms_plugin_instance();
            $send   = new ReflectionMethod($plugin, 'sendResetEmail');
            $send->setAccessible(true);
            $expected = [
                'de-DE' => 'Guten Tag',
                'en-GB' => 'A request has been made',
                'fr-FR' => 'Une demande de réinitialisation',
            ];

            foreach ($expected as $tag => $phrase) {
                $suffix = 'mail_' . strtolower(substr($tag, 0, 2));
                $user   = ajaxforms_create_test_user($suffix, $tag);

                try {
                    ajaxforms_mailcatch_clear();
                    $requestLanguage = Factory::getApplication()->getLanguage();
                    $send->invoke($plugin, $user, str_repeat('a1b2c3d4', 4));
                    $this->test("$tag core mail preserves the request language object", Factory::getApplication()->getLanguage() === $requestLanguage);

                    $messages = ajaxforms_mailcatch_messages();
                    $this->test("$tag reset template sends exactly one mail", count($messages) === 1, count($messages) . ' captured');

                    if (count($messages) !== 1) {
                        continue;
                    }

                    $raw  = $messages[0];
                    $text = ajaxforms_mail_text($raw);
                    $this->test("$tag template keys are translated", stripos($text, 'COM_USERS_EMAIL_') === false);
                    $installed = array_keys(\Joomla\CMS\Language\LanguageHelper::getInstalledLanguages(0));
                    $this->test(
                        "$tag rendered mail contains translated core wording",
                        in_array($tag, $installed, true) || stripos($text, $phrase) !== false,
                        'expected shipped wording was not found for a language without a site language pack'
                    );
                    $this->test(
                        "$tag mail keeps the HTML layout and plain-text alternative",
                        stripos($text, '<html') !== false
                            && stripos($text, '<table') !== false
                            && stripos($raw, 'text/plain') !== false
                    );
                } finally {
                    ajaxforms_delete_test_user($suffix);
                }
            }
        } finally {
            ajaxforms_mailcatch_remove();
        }
    }

    private function testIncompleteCoreLanguageDefaults(): void
    {
        echo "\n--- Administrator-only and incomplete exact-locale packs ---\n";
        $plugin = ajaxforms_plugin_instance();
        $load = new ReflectionMethod($plugin, 'loadCoreMailFallbackStrings');
        $load->setAccessible(true);
        $fixture = __DIR__ . '/ajaxforms-language-fixture-' . getmypid();
        $admin = $fixture . '/admin/language/fr-FR';
        $site = $fixture . '/site/language/fr-FR';
        $english = $fixture . '/site/language/en-GB';
        mkdir($admin, 0755, true);
        mkdir($site, 0755, true);
        mkdir($english, 0755, true);

        try {
            file_put_contents($admin . '/com_users.ini', "COM_USERS=\"Administration des utilisateurs\"\n");
            file_put_contents($english . '/com_users.ini', "COM_USERS_EMAIL_PASSWORD_RESET_BODY=\"English fallback {TOKEN}\"\n");
            $language = new class ('fr-FR') extends \Joomla\CMS\Language\Language {
                public string $fixture;

                public function load($extension = 'joomla', $basePath = JPATH_BASE, $lang = null, $reload = false, $default = true)
                {
                    // Language::load() recursively calls this override for its
                    // English fallback using the already-remapped base path.
                    if ($extension === 'com_users' && !str_starts_with($basePath, $this->fixture . '/')) {
                        $basePath = $this->fixture . ($basePath === JPATH_SITE ? '/site' : ($basePath === JPATH_ADMINISTRATOR ? '/admin' : '/empty'));
                    }

                    return parent::load($extension, $basePath, $lang, $reload, $default);
                }

                public function setFixtureOverride(string $key, string $value): void
                {
                    $this->override[$key] = $value;
                }
            };
            $language->fixture = $fixture;
            $language->load('com_users', JPATH_SITE, 'fr-FR', true);
            $this->test(
                'Fixture begins with an implicit English fallback, not an exact-locale translation',
                $language->_('COM_USERS_EMAIL_PASSWORD_RESET_BODY') === 'English fallback {TOKEN}'
            );
            $load->invoke($plugin, $language, 'fr-FR');
            $this->test(
                'Administrator-only com_users does not suppress French reset defaults',
                str_contains($language->_('COM_USERS_EMAIL_PASSWORD_RESET_BODY'), 'Une demande de réinitialisation')
            );
            $this->test(
                'Administrator-only com_users retains its exact-locale translation',
                $language->_('COM_USERS') === 'Administration des utilisateurs'
            );

            file_put_contents($site . '/com_users.ini', "COM_USERS_EMAIL_PASSWORD_RESET_SUBJECT=\"Sujet local {SITENAME}\"\n");
            $load->invoke($plugin, $language, 'fr-FR');
            $this->test(
                'Incomplete site pack preserves its translated subject',
                $language->_('COM_USERS_EMAIL_PASSWORD_RESET_SUBJECT') === 'Sujet local {SITENAME}'
            );
            $this->test(
                'Incomplete site pack fills missing reminder and activation keys in French',
                str_contains($language->_('COM_USERS_EMAIL_USERNAME_REMINDER_BODY'), 'Bonjour')
                    && str_contains($language->_('COM_USERS_EMAIL_REGISTERED_WITH_ACTIVATION_BODY_NOPW'), 'Bonjour')
            );
            $language->setFixtureOverride('COM_USERS_EMAIL_PASSWORD_RESET_BODY', 'Texte personnalisé {TOKEN}');
            $load->invoke($plugin, $language, 'fr-FR');
            $this->test(
                'Exact-locale language override keeps precedence over shipped defaults',
                $language->_('COM_USERS_EMAIL_PASSWORD_RESET_BODY') === 'Texte personnalisé {TOKEN}'
            );
        } finally {
            unlink($english . '/com_users.ini');
            rmdir($english);
            foreach ([$admin, $site] as $path) {
                if (is_file($path . '/com_users.ini')) {
                    unlink($path . '/com_users.ini');
                }
                rmdir($path);
                rmdir(dirname($path));
                rmdir(dirname($path, 2));
            }
            rmdir($fixture);
        }
    }

    private function testLegacyLocalizedRendering(): void
    {
        echo "\n--- Legacy MailTemplate rendering hook ---\n";
        $plugin = ajaxforms_plugin_instance();
        $factoryMethod = new ReflectionMethod($plugin, 'mailTemplateLanguageFactory');
        $factoryMethod->setAccessible(true);
        $factory = $factoryMethod->invoke($plugin);
        $language = $factory->createLanguage('fr-FR');
        $template = (object) [
            'subject' => 'Operator subject {NAME}',
            'body' => 'COM_USERS_EMAIL_PASSWORD_RESET_BODY',
            'htmlbody' => '<p>Operator HTML {NAME}</p>',
        ];
        $original = clone $template;
        $container = Factory::getContainer();
        $create = new ReflectionMethod($plugin, 'createLocalizedMailTemplate');
        $create->setAccessible(true);
        $arguments = [
            'com_users.password_reset',
            'fr-FR',
            $container->get(\Joomla\CMS\Mail\MailerFactoryInterface::class)->createMailer(),
            $factory,
            $container->get(DatabaseInterface::class),
        ];
        $mailer = $create->invoke($plugin, $arguments, $template);
        // Exercise the compatibility branch even on factory-injection cores.
        $mailer->localizeLegacy($language, $template);
        $mailer->addUnsafeTags(['NAME']);
        $replace = new ReflectionMethod($mailer, 'replaceTags');
        $replace->setAccessible(true);
        $data = ['NAME' => '<img src=x onerror=alert(1)>', 'TOKEN' => 'test-code'];
        $requestLanguage = Factory::getApplication()->getLanguage();

        $subject = $replace->invoke($mailer, 'wrong request-language subject', $data);
        $plain = $replace->invoke($mailer, 'wrong request-language body', $data);
        $html = $replace->invoke($mailer, '<html><table>' . $template->htmlbody . '</table></html>', $data);
        $this->test('Legacy adapter preserves the operator subject', $subject === 'Operator subject ' . $data['NAME']);
        $this->test('Legacy adapter renders recipient-locale plain text', $plain === str_replace('{TOKEN}', 'test-code', $language->_($template->body)));
        $this->test(
            'Legacy adapter preserves operator HTML and layout while escaping unsafe tags',
            str_contains($html, '<html><table><p>Operator HTML &lt;img')
                && !str_contains($html, '<img')
        );
        $this->test('Legacy adapter leaves stored template data untouched', $template == $original);
        $this->test('Legacy adapter does not replace the request language object', Factory::getApplication()->getLanguage() === $requestLanguage);

        $htmlOnly = (object) [
            'subject' => 'Operator subject',
            'body' => '',
            'htmlbody' => '<p>Bonjour {NAME}<br>Suite</p>',
        ];
        $mailer = $create->invoke($plugin, $arguments, $htmlOnly);
        $mailer->localizeLegacy($language, $htmlOnly);
        $replace = new ReflectionMethod($mailer, 'replaceTags');
        $replace->setAccessible(true);
        $replace->invoke($mailer, $htmlOnly->subject, ['NAME' => 'Recipient']);
        $plain = $replace->invoke($mailer, '', ['NAME' => 'Recipient']);
        $this->test(
            'Legacy HTML-only operator template supplies a translated plain alternative with replaced tags',
            $plain === "Bonjour Recipient\nSuite"
        );
    }

    private function testLegacyAttachmentNamesThroughSend(): void
    {
        echo "\n--- Legacy attachment names through the real send path ---\n";
        $reason = ajaxforms_mailcatch_install();
        $this->test('Attachment regression mail capture in place', $reason === null, (string) $reason);

        if ($reason !== null) {
            return;
        }

        $config = \Joomla\CMS\Component\ComponentHelper::getParams('com_mails');
        $originalStyle = $config->get('mail_style', 'plaintext');
        $file = __DIR__ . '/ajaxforms-attachment-fixture-' . getmypid() . '.pdf';

        try {
            file_put_contents($file, "%PDF-1.4\nAJAX Forms attachment fixture\n");
            $plugin = ajaxforms_plugin_instance();
            $getTemplate = new ReflectionMethod($plugin, 'getMailTemplate');
            $getTemplate->setAccessible(true);
            $template = $getTemplate->invoke($plugin, 'com_users.password_reset', 'fr-FR');
            $this->test('Core reset template exists for attachment send regression', $template !== null);

            if ($template === null) {
                return;
            }

            $factoryMethod = new ReflectionMethod($plugin, 'mailTemplateLanguageFactory');
            $factoryMethod->setAccessible(true);
            $factory = $factoryMethod->invoke($plugin);
            $create = new ReflectionMethod($plugin, 'createLocalizedMailTemplate');
            $create->setAccessible(true);
            $container = Factory::getContainer();

            foreach (['plaintext', 'both'] as $style) {
                $config->set('mail_style', $style);
                ajaxforms_mailcatch_clear();
                $mailer = $create->invoke($plugin, [
                    'com_users.password_reset',
                    'fr-FR',
                    $container->get(\Joomla\CMS\Mail\MailerFactoryInterface::class)->createMailer(),
                    $factory,
                    $container->get(DatabaseInterface::class),
                ], $template);
                // Force the legacy adapter even when CI uses an injection-capable core.
                $mailer->localizeLegacy($factory->createLanguage('fr-FR'), $template);
                $mailer->addTemplateData([
                    'NAME' => 'A&B',
                    'SITENAME' => 'Test site',
                    'TOKEN' => 'test-code',
                    'LINK_TEXT' => 'index.php',
                ]);
                $mailer->addUnsafeTags(['NAME']);
                $mailer->addRecipient('attachment@example.test');
                $mailer->addAttachment($file, '{NAME}.pdf');
                $this->test("$style attachment mail sends through MailTemplate", $mailer->send() === true);
                $messages = ajaxforms_mailcatch_messages();
                $this->test("$style attachment mail captured exactly once", count($messages) === 1);
                $raw = $messages[0] ?? '';
                $this->test(
                    "$style attachment filename keeps an unescaped ampersand",
                    str_contains($raw, 'filename="A&B.pdf"') && !str_contains($raw, 'A&amp;B.pdf')
                );
            }
        } finally {
            $config->set('mail_style', $originalStyle);
            if (is_file($file)) {
                unlink($file);
            }
            ajaxforms_mailcatch_remove();
        }
    }

    /**
     * The plain-text fallback must render in the recipient's language, and it
     * must not change the language of the running request. Text::sprintf() would
     * do both wrong: it renders in the request language and, when a second mail
     * to another recipient follows, the first recipient's language would reach
     * it. mailText() uses a separate Language instance, so it stays isolated.
     */
    private function testFallbackMailLanguageIsIsolated(): void
    {
        echo "\n--- Fallback mail language is isolated ---\n";

        $plugin = ajaxforms_plugin_instance();
        $rc     = new ReflectionClass(\Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class);

        $this->test('Method mailText exists', $rc->hasMethod('mailText'));

        if (!$rc->hasMethod('mailText')) {
            return;
        }

        $method = $rc->getMethod('mailText');
        $method->setAccessible(true);

        $requestTagBefore = Factory::getApplication()->getLanguage()->getTag();

        $rendered = (string) $method->invoke(
            $plugin,
            'en-GB',
            'PLG_AJAX_JOOMLAAJAXFORMS_RESET_EMAIL_SUBJECT',
            'Isolation Site'
        );

        $this->test(
            'Fallback text is translated, not the raw key',
            $rendered !== '' && !str_contains($rendered, 'PLG_AJAX_JOOMLAAJAXFORMS_RESET_EMAIL_SUBJECT'),
            'got ' . var_export($rendered, true)
        );

        $this->test(
            'The sprintf argument reached the fallback text',
            str_contains($rendered, 'Isolation Site'),
            'got ' . var_export($rendered, true)
        );

        $requestTagAfter = Factory::getApplication()->getLanguage()->getTag();

        $this->test(
            'Rendering a mail does not change the request language',
            $requestTagBefore === $requestTagAfter,
            "before $requestTagBefore, after $requestTagAfter"
        );
    }

    /**
     * Every account mail sender must route its plain-text fallback through the
     * isolated mailText() helper. The moderate finding this closes was that the
     * fallbacks used Text::sprintf(), which renders in the running request's
     * language and lets the language of one recipient reach the next. A single
     * sender slipping back to Text::sprintf() would silently reopen the bug for
     * that mail alone, so each of the four is checked on its own, read straight
     * from the source so the guard cannot drift from the code.
     */
    private function testEverySenderFallbackStaysIsolated(): void
    {
        echo "\n--- Every mail sender keeps its fallback language isolated ---\n";

        $rc = new ReflectionClass(\Advans\Plugin\Ajax\JoomlaAjaxForms\Extension\JoomlaAjaxForms::class);

        foreach (['sendResetEmail', 'sendRemindEmail', 'sendActivationEmail', 'sendAdminNotification'] as $name) {
            if (!$rc->hasMethod($name)) {
                $this->test("Sender $name exists", false);
                continue;
            }

            $method = $rc->getMethod($name);
            $lines  = file($method->getFileName());
            $source = implode('', array_slice(
                $lines,
                $method->getStartLine() - 1,
                $method->getEndLine() - $method->getStartLine() + 1
            ));

            $this->test(
                "$name renders its fallback through mailText()",
                str_contains($source, '$this->mailText('),
                'mailText() call not found'
            );

            $this->test(
                "$name does not fall back to Text::sprintf()",
                !str_contains($source, 'Text::sprintf('),
                'Text::sprintf() would render in the request language'
            );

            // The plain-text fallback is the last attempt to reach the recipient.
            // Joomla's mailer does not only throw, it also returns false (site
            // mail switched off, recipient rejected), so a discarded return value
            // loses the mail without a trace. Read from the source for the same
            // reason as above: a sender that drops the check again is caught here.
            $this->test(
                "$name reports a refused fallback mail",
                str_contains($source, '$this->logFailedMail($mailer->Send(),'),
                'the return value of Send() is not evaluated'
            );

            $this->test(
                "$name does not discard the result of Send()",
                !preg_match('/\$mailer->Send\(\)\s*;/i', $source),
                'a bare $mailer->Send(); throws away the failure'
            );
        }
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

    /**
     * The reset state of an account: token hash, counter and start of the window.
     *
     * @return  array{0: string, 1: int, 2: string}
     */
    private function resetState(int $userId): array
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName(['activation', 'resetCount', 'lastResetTime']))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = ' . $userId);

        $db->setQuery($query);
        $row = $db->loadObject();

        return [(string) ($row->activation ?? ''), (int) ($row->resetCount ?? 0), (string) ($row->lastResetTime ?? '')];
    }

    private function writeResetState(int $userId, string $activation, int $count, ?string $lastResetTime): void
    {
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->update($db->quoteName('#__users'))
            ->set($db->quoteName('activation') . ' = ' . $db->quote($activation))
            ->set($db->quoteName('resetCount') . ' = ' . $count)
            ->set($db->quoteName('lastResetTime') . ' = ' . ($lastResetTime === null ? 'NULL' : $db->quote($lastResetTime)))
            ->where($db->quoteName('id') . ' = ' . $userId);

        $db->setQuery($query)->execute();
    }

    /**
     * The reset requests of an account are limited like in com_users: at most
     * "Maximum Reset Count" requests within "Time in Hours". A refused request
     * gets the same answer as an accepted one, so the form still reveals
     * nothing, and leaves the stored token alone.
     */
    private function testResetLimitOfTheUsersOptions(): void
    {
        echo "\n--- HTTP: reset requests follow the limit of the Users options ---\n";

        $original = ajaxforms_set_component_params('com_users', ['reset_count' => 2, 'reset_time' => 1]);
        $user     = ajaxforms_create_test_user('resetlimit');
        $userId   = (int) $user->id;

        try {
            $first = $this->resetAnswer((string) $user->email);
            [$tokenOne, $countOne] = $this->resetState($userId);
            $this->test('First request is answered as accepted', ($first['success'] ?? null) === true);
            $this->test('First request stores a reset token', $tokenOne !== '');
            $this->test('First request starts the counter at 1', $countOne === 1, "resetCount $countOne");

            $this->resetAnswer((string) $user->email);
            [$tokenTwo, $countTwo] = $this->resetState($userId);
            $this->test('Second request within the window is sent', $tokenTwo !== '' && $tokenTwo !== $tokenOne);
            $this->test('Second request raises the counter to 2', $countTwo === 2, "resetCount $countTwo");

            $third = $this->resetAnswer((string) $user->email);
            [$tokenThree, $countThree] = $this->resetState($userId);
            $this->test('Third request beyond the limit gets the same answer', $third === $first,
                'first: ' . var_export($first, true) . ', third: ' . var_export($third, true));
            $this->test('Third request beyond the limit sends nothing (token unchanged)', $tokenThree === $tokenTwo);
            $this->test('Third request beyond the limit keeps the counter', $countThree === 2, "resetCount $countThree");

            // Once the window has passed, a new one starts.
            $twoHoursAgo = gmdate('Y-m-d H:i:s', time() - 7200);
            $this->writeResetState($userId, $tokenThree, 2, $twoHoursAgo);
            $this->resetAnswer((string) $user->email);
            [$tokenFour, $countFour, $windowFour] = $this->resetState($userId);
            $this->test('After the window a request is sent again', $tokenFour !== $tokenThree);
            $this->test('After the window the counter starts at 1 again', $countFour === 1, "resetCount $countFour");
            $this->test('After the window a new window starts', $windowFour !== $twoHoursAgo, "lastResetTime $windowFour");
        } finally {
            ajaxforms_restore_component_params('com_users', $original);
            ajaxforms_delete_test_user('resetlimit');
        }
    }

    /**
     * com_users never resets the password of a Super User from the site. The
     * AJAX request answers the same way as for any address and sends nothing.
     */
    private function testNoResetForSuperUsers(): void
    {
        echo "\n--- HTTP: no reset for a Super User ---\n";

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select($db->quoteName(['u.id', 'u.email', 'u.activation', 'u.resetCount', 'u.lastResetTime']))
            ->from($db->quoteName('#__users', 'u'))
            ->join('INNER', $db->quoteName('#__user_usergroup_map', 'm'), $db->quoteName('m.user_id') . ' = ' . $db->quoteName('u.id'))
            ->where($db->quoteName('m.group_id') . ' = 8')
            ->where($db->quoteName('u.block') . ' = 0')
            ->setLimit(1);

        $db->setQuery($query);
        $admin = $db->loadObject();

        $this->test('A Super User exists', $admin !== null);

        if ($admin === null) {
            return;
        }

        try {
            $answer = $this->resetAnswer((string) $admin->email);
            [$activation] = $this->resetState((int) $admin->id);

            $this->test('Super User request gets the neutral answer', ($answer['success'] ?? null) === true,
                'answer: ' . var_export($answer, true));
            $this->test('Super User request stores no reset token', $activation === (string) $admin->activation);
        } finally {
            $this->writeResetState((int) $admin->id, (string) $admin->activation, (int) $admin->resetCount,
                $admin->lastResetTime === null ? null : (string) $admin->lastResetTime);
        }
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
        $this->testCoreTemplateMailsSupportAllLocales();
        $this->testIncompleteCoreLanguageDefaults();
        $this->testLegacyLocalizedRendering();
        $this->testLegacyAttachmentNamesThroughSend();
        $this->testFallbackMailLanguageIsIsolated();
        $this->testEverySenderFallbackStaysIsolated();
        $this->testAnswerDoesNotRevealAccounts();
        $this->testResetLimitOfTheUsersOptions();
        $this->testNoResetForSuperUsers();

        echo "\n=== Reset Request Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$test = new ResetRequestTest();
exit($test->run() ? 0 : 1);
