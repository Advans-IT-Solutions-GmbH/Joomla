<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Ajax.JoomlaAjaxForms
 *
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Ajax\JoomlaAjaxForms\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\Form;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Language\LanguageHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Mail\MailTemplate;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Registry\Registry;
use Joomla\Utilities\ArrayHelper;

class JoomlaAjaxForms extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute'          => 'onAfterRoute',
            'onAjaxJoomlaajaxforms' => 'onAjaxJoomlaajaxforms',
            'onBeforeRender'        => 'onBeforeRender',
        ];
    }

    /**
     * Create a database query object (Joomla 4/5/6 compatible).
     * Joomla 6 deprecates getQuery(true) in favour of createQuery().
     *
     * @param   DatabaseInterface  $db
     * @return  \Joomla\Database\QueryInterface
     */
    private function createDbQuery(DatabaseInterface $db): \Joomla\Database\QueryInterface
    {
        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }

    /**
     * Answer our own com_ajax JSON requests directly, when this event reaches
     * the plugin at all (Joomla delivers onAfterRoute only to plugins that are
     * already loaded at that point).
     *
     * The 303 redirect loop seen on multilingual sites (Location with &amp;
     * instead of &) did not come from the router: it came from
     * Session::checkToken(), which for a request without a session redirects
     * to Route::_('index.php'). That route carries the current com_ajax query
     * and is HTML-escaped by default, so every hop broke the parameters again.
     * The plugin therefore never calls Session::checkToken() (see
     * hasValidToken()), and tests/scripts/03-ajax-endpoint.php guards both.
     *
     * The direct answer mirrors the envelope com_ajax's JsonResponse would
     * have produced: a top-level success flag with message and messages, and
     * the plugin's own JSON payload as the single entry of data[]. Answering
     * in that exact shape keeps this shortcut and the regular com_ajax path
     * interchangeable for the client, which reads the payload from data[0].
     */
    public function onAfterRoute(): void
    {
        $app = $this->getApplication();
        $input = $app->getInput();

        if ($input->getCmd('option') !== 'com_ajax'
            || $input->getCmd('plugin') !== 'joomlaajaxforms'
            || $input->getCmd('format', 'html') !== 'json'
        ) {
            return;
        }

        // This is our AJAX request — handle it directly
        $result = $this->onAjaxJoomlaajaxforms();

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        // Mirror the envelope com_ajax's JsonResponse would have produced, so
        // this shortcut and the regular com_ajax path answer in the exact same
        // shape: the dispatch succeeded (success true), and the plugin's own
        // JSON payload is the single entry of data[]. Without the success key
        // the shape only worked by accident, because the client reads data[0].
        echo json_encode(
            ['success' => true, 'message' => null, 'messages' => null, 'data' => [$result]],
            JSON_UNESCAPED_UNICODE
        );

        $app->close();
    }

    /**
     * Pass language strings to JavaScript via Joomla script options.
     *
     * `debug` gates the developer traces in media/js/joomlaajaxforms.js. It is
     * off by default, so a production site prints no traces during normal use.
     * A failed request is still reported with console.error.
     *
     * This handler only runs when something imported the ajax plugin group, so
     * on a normal page the script finds no options here. The "texts" task of
     * the endpoint carries the same `debug` value for exactly that case.
     */
    public function onBeforeRender(): void
    {
        $doc = $this->getApplication()->getDocument();
        if (method_exists($doc, 'addScriptOptions')) {
            $doc->addScriptOptions(
                'plg_ajax_joomlaajaxforms',
                array_merge(['debug' => (bool) $this->params->get('debug', 0)], $this->scriptTexts())
            );
        }
    }

    /**
     * Texts the script shows itself, in the language of the current request.
     *
     * The script never carries a text of its own. Plugins of the ajax group are
     * imported by com_ajax, so on a normal page onBeforeRender() runs only when
     * something imported the plugin; the script then asks the endpoint for these
     * texts (task "texts") instead of falling back to a fixed language.
     *
     * @return  array<string, string>
     */
    private function scriptTexts(): array
    {
        return [
            'ERROR_GENERIC' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_ERROR_GENERIC'),
            'PROFILE_SAVED' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVED'),
            'CLOSE'         => Text::_('JCLOSE'),
        ];
    }


    /**
     * Main AJAX handler.
     *
     * Called by com_ajax via AjaxEvent (Joomla 5) or directly from onAfterRoute.
     * When called via AjaxEvent, the result must be added to the event object
     * because Joomla 5 ignores return values from SubscriberInterface handlers.
     *
     * @param   \Joomla\CMS\Event\Plugin\AjaxEvent|null  $event  The event object (when dispatched by com_ajax)
     *
     * @return  string  JSON response
     */
    public function onAjaxJoomlaajaxforms($event = null): string
    {
        $input = $this->getApplication()->getInput();
        $task = $input->getCmd('task', '');

        // "texts" only returns the public texts the script shows (no user data, no
        // state change), so it is answered without a form token: a page without
        // any form still needs them. The text map is wrapped under "data" so the
        // response shape stays {success:true, data:{...texts}} like every other
        // task; the script reads it as data.data after unwrapping the com_ajax
        // envelope (see media/js/joomlaajaxforms.js unwrapResponse()).
        //
        // The answer carries the "debug" option as well. On a normal page the
        // ajax plugin group is imported by com_ajax only, so onBeforeRender()
        // does not run and the script finds no script options: without this the
        // option could never take effect on a live storefront. The flag says
        // nothing about the site beyond the state of a developer switch.
        if ($task === 'texts') {
            $result = $this->jsonSuccess([
                'data'  => $this->scriptTexts(),
                'debug' => (bool) $this->params->get('debug', 0),
            ]);

            if ($event) {
                $event->addResult($result);
            }

            return $result;
        }

        // Validate CSRF token
        if (!$this->hasValidToken()) {
            $result = $this->jsonError(Text::_('JINVALID_TOKEN'));

            if ($event) {
                $event->addResult($result);
            }

            return $result;
        }

        switch ($task) {
            case 'login':
                $result = $this->handleLogin();
                break;
            case 'logout':
                $result = $this->handleLogout();
                break;
            case 'register':
                $result = $this->handleRegistration();
                break;
            case 'reset':
                $result = $this->handleReset();
                break;
            case 'remind':
                $result = $this->handleRemind();
                break;
            case 'mfa_validate':
                // MFA validation is now handled by Joomla's captive page
                $result = $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_INVALID_TASK'));
                break;
            case 'removeCartItem':
                $result = $this->handleRemoveCartItem();
                break;
            case 'getCartCount':
                $result = $this->handleGetCartCount();
                break;
            case 'saveProfile':
                $result = $this->handleSaveProfile();
                break;
            default:
                $result = $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_INVALID_TASK'));
                break;
        }

        // When dispatched by com_ajax, add result to the event object
        if ($event) {
            $event->addResult($result);
        }

        return $result;
    }

    /**
     * Handle login request
     *
     * @return  string  JSON response
     */
    protected function handleLogin(): string
    {
        if (!$this->params->get('enable_login', 1)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_TASK_DISABLED'));
        }

        $user = $this->getApplication()->getIdentity();
        if ($user && !$user->guest) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_ALREADY_LOGGED_IN'));
        }

        $input = $this->getApplication()->getInput();
        $username = $input->post->getString('username', '');
        $password = $input->post->getString('password', '');
        $remember = $input->post->getInt('remember', 0);
        $returnUrl = $input->post->getBase64('return', '');

        if (empty($username) || empty($password)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_FAILED'));
        }

        $credentials = [
            'username' => $username,
            'password' => $password,
        ];

        $options = [
            'remember' => (bool) $remember,
            'action'   => 'core.login.site',
        ];

        // Did the credentials pass authentication even though the login failed? CMSApplication::login()
        // hands the authentication response to onUserLoginFailure; its status is STATUS_SUCCESS only
        // when the password was correct and a user plugin refused afterwards (plg_user_joomla for a
        // blocked account). A wrong password never sets this, so the precise sentence below cannot be
        // used to find out whether an account exists. Listened to for this one login only.
        $accepted   = null;
        $dispatcher = $this->getApplication()->getDispatcher();
        $listener   = static function ($event) use (&$accepted): void {
            $response = method_exists($event, 'getAuthenticationResponse')
                ? (array) $event->getAuthenticationResponse()
                : (array) ($event->getArgument('subject') ?? []);

            if ((int) ($response['status'] ?? 0) === Authentication::STATUS_SUCCESS) {
                $accepted = (string) ($response['username'] ?? '');
            }
        };
        $dispatcher->addListener('onUserLoginFailure', $listener);

        try {
            $result = $this->getApplication()->login($credentials, $options);
        } finally {
            $dispatcher->removeListener('onUserLoginFailure', $listener);
        }

        if ($result === true) {
            $user = $this->getApplication()->getIdentity();

            // Check if MFA is required — redirect to Joomla's captive page
            $mfaMethods = $this->getMfaMethods($user->id);
            if (!empty($mfaMethods)) {
                // Clear any system messages queued during login
                $this->getApplication()->getMessageQueue(true);
                $session = $this->getApplication()->getSession();
                $session->set('application.queue', []);

                // Same target as without MFA (see getProfileRedirect()), as an
                // absolute URL because it is stored as the return URL of the
                // captive page.
                $profileUrl = $this->getProfileRedirect(true);
                $session->set('com_users.return_url', $profileUrl);

                // Pass return URL as query parameter so the captive template
                // can restore it (the session value may be overwritten by
                // Joomla's MultiFactorAuthenticationHandler before the
                // template renders).
                $captiveUrl = Route::_(
                    'index.php?option=com_users&view=captive&return=' . base64_encode($profileUrl),
                    false
                );

                // JS reads redirect from data.data.redirect (login handler line 276, logout line 613)
                return $this->jsonSuccess([
                    'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_MFA_REQUIRED'),
                    'data'    => ['redirect' => $captiveUrl],
                ]);
            }

            // Clear any system messages from the login process
            $this->getApplication()->getMessageQueue(true);
            $session = $this->getApplication()->getSession();
            $session->set('application.queue', []);

            // Determine redirect: POST return param > J2Store profile page
            $redirect = '';
            if ($returnUrl) {
                $decoded = base64_decode($returnUrl);
                if (!empty($decoded) && Uri::isInternal($decoded)) {
                    $redirect = $decoded;
                }
            }
            // Clear the return URL to prevent Joomla's login redirect
            // (often Home) from taking precedence later.
            $session->set('com_users.return_url', '');

            if (empty($redirect)) {
                $redirect = $this->getProfileRedirect();
            }

            // JS reads redirect from data.data.redirect (login handler line 276, logout line 613)
            return $this->jsonSuccess([
                'message' => Text::sprintf('PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_SUCCESS', $user->name),
                'data'    => ['redirect' => $redirect],
            ]);
        }

        // Clear Joomla's message queue so com_ajax doesn't expose the
        // English authentication error from plg_authentication_joomla.
        $this->getApplication()->getMessageQueue(true);
        $this->getApplication()->getSession()->set('application.queue', []);

        // Correct password, account still awaiting its activation: say what is missing instead of
        // asking to check username and password, with the site's sender address as contact.
        $state = $accepted !== null ? $this->activationState($accepted !== '' ? $accepted : $username) : '';

        if ($state === 'confirm' || $state === 'approval') {
            return $this->jsonError(Text::sprintf(
                $state === 'confirm' ? 'PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_NOT_ACTIVATED' : 'PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_AWAITING_APPROVAL',
                (string) $this->getApplication()->get('mailfrom')
            ));
        }

        return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_FAILED'));
    }

    /**
     * Why a blocked account cannot log in, as far as the customer can act on it:
     * - 'confirm': the confirmation link in the registration e-mail is still pending. The code is
     *   an activation token of 32 letters and digits (ApplicationHelper::getHash() in Joomla,
     *   UserHelper::genRandomPassword(32) in handleRegistration()), and the account has not
     *   confirmed yet. com_users accepts the same form (getAlnum(), 32 characters).
     * - 'approval': the e-mail is confirmed and an administrator still has to approve the account
     *   (administrator activation: RegistrationModel::activate() sets the user parameter `activate`
     *   and stores a new token for the administrator).
     * - '': anything else, so the generic sentence stays. That covers an account blocked by an
     *   administrator (no code) and a password-reset hash in the same column (handleReset() and
     *   Joomla store a password hash there, which starts with a dollar sign).
     */
    protected function activationState(string $username): string
    {
        if ($username === '') {
            return '';
        }

        try {
            $db    = $this->getDatabase();
            $query = $this->createDbQuery($db)
                ->select([$db->quoteName('activation'), $db->quoteName('params')])
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('username') . ' = :username')
                ->where($db->quoteName('block') . ' = 1')
                ->bind(':username', $username);
            $row = $db->setQuery($query)->loadObject();
        } catch (\Throwable $e) {
            return '';
        }

        if (!$row || !preg_match('/^[A-Za-z0-9]{32}$/', (string) $row->activation)) {
            return '';
        }

        $params = json_decode((string) $row->params, true);

        return \is_array($params) && !empty($params['activate']) ? 'approval' : 'confirm';
    }

    /**
     * Handle logout request
     *
     * @return  string  JSON response
     */
    protected function handleLogout(): string
    {
        $user = $this->getApplication()->getIdentity();
        if (!$user || $user->guest) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_NOT_LOGGED_IN'));
        }

        $input = $this->getApplication()->getInput();
        $returnUrl = $input->post->getBase64('return', '');

        $result = $this->getApplication()->logout();

        if ($result === true) {
            $redirect = '';
            if ($returnUrl) {
                $decoded = base64_decode($returnUrl);
                if (!empty($decoded) && Uri::isInternal($decoded)) {
                    $redirect = $decoded;
                }
            }
            if (empty($redirect)) {
                $redirect = Uri::base();
            }

            // JS reads redirect from data.data.redirect (logout handler line 613)
            return $this->jsonSuccess([
                'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_LOGOUT_SUCCESS'),
                'data'    => ['redirect' => $redirect],
            ]);
        }

        return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_LOGOUT_FAILED'));
    }

    /**
     * Handle registration request
     *
     * @return  string  JSON response
     */
    protected function handleRegistration(): string
    {
        if (!$this->params->get('enable_registration', 1)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_TASK_DISABLED'));
        }

        $usersConfig = ComponentHelper::getParams('com_users');
        if (!$usersConfig->get('allowUserRegistration')) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_DISABLED'));
        }

        $input = $this->getApplication()->getInput();
        $name = trim($input->post->getString('name', ''));
        $username = trim($input->post->getString('username', ''));
        $email = trim($input->post->getString('email', ''));
        $email2 = trim($input->post->getString('email2', ''));
        $password = $input->post->getString('password', '');
        $password2 = $input->post->getString('password2', '');

        // Validation
        if (empty($name)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_NAME_REQUIRED'));
        }
        if (empty($username) || strlen($username) < 2) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_USERNAME_TOO_SHORT'));
        }
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_EMAIL_INVALID'));
        }
        if ($email !== $email2) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_EMAIL_MISMATCH'));
        }
        if (empty($password)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PASSWORD_REQUIRED'));
        }
        if ($password !== $password2) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PASSWORD_MISMATCH'));
        }

        // Check if username/email already exists
        $db = $this->getDatabase();

        $query = $this->createDbQuery($db)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('username') . ' = :username')
            ->bind(':username', $username);
        $db->setQuery($query);
        if ($db->loadResult() > 0) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_USERNAME_EXISTS'));
        }

        $query = $this->createDbQuery($db)
            ->select('COUNT(*)')
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('email') . ' = :email')
            ->bind(':email', $email);
        $db->setQuery($query);
        if ($db->loadResult() > 0) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_EMAIL_EXISTS'));
        }

        // The rules of the core registration apply unchanged: password rules,
        // captcha, allowed e-mail domains and every field a plugin adds to the
        // registration form (for example the privacy consent).
        $validated = [];
        $coreError = $this->coreRegistrationError($name, $username, $email, $password, $password2, $validated);

        if ($coreError !== null) {
            return $this->jsonError($coreError);
        }

        // Save what the form filtered and validated, not the raw input.
        $name     = (string) ($validated['name'] ?? $name);
        $username = (string) ($validated['username'] ?? $username);
        $email    = (string) ($validated['email1'] ?? $email);

        try {
            // new User() is removed in Joomla 6 — use UserFactoryInterface instead.
            $user = Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById(0);
            $user->name     = $name;
            $user->username = $username;
            $user->email    = $email;
            $user->password = UserHelper::hashPassword($password);

            $userActivation = $usersConfig->get('useractivation');
            $defaultGroup = $usersConfig->get('new_usertype', 2);

            $user->groups = [$defaultGroup];

            if ($userActivation == 1) {
                // Self-activation
                $user->activation = UserHelper::genRandomPassword(32);
                $user->block = 1;
            } elseif ($userActivation == 2) {
                // Admin activation
                $user->activation = UserHelper::genRandomPassword(32);
                $user->block = 1;
            } else {
                $user->block = 0;
            }

            $user->registerDate = Factory::getDate()->toSql();

            if (!$user->save()) {
                return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_FAILED'));
            }

            // Send activation email if needed
            if ($userActivation == 1) {
                $this->sendActivationEmail($user, $usersConfig);
                return $this->jsonSuccess([
                    'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_ACTIVATE_SELF'),
                ]);
            } elseif ($userActivation == 2) {
                $this->sendActivationEmail($user, $usersConfig);
                $this->sendAdminNotification($user);
                return $this->jsonSuccess([
                    'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_ACTIVATE_ADMIN'),
                ]);
            }

            return $this->jsonSuccess([
                'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_SUCCESS'),
            ]);
        } catch (\Exception $e) {
            Log::add('Registration error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_FAILED'));
        }
    }

    /**
     * Validate a registration with the registration form of com_users.
     *
     * The form is the one the core registration validates against, so the same
     * rules apply: the password rules of the Users options (minimum length,
     * digits, symbols, upper and lower case), the captcha chosen there, the
     * allowed e-mail domains and every field a user or system plugin adds in
     * onContentPrepareForm. The script forwards those further fields together
     * with the answer of the captcha.
     *
     * @param   string  $name       The name of the account
     * @param   string  $username   The username
     * @param   string  $email      The e-mail address
     * @param   string  $password   The password
     * @param   string  $password2  The repeated password
     * @param   array   $validated  Receives the filtered and validated data
     *
     * @return  string|null  The first validation message, null when the data is valid
     */
    protected function coreRegistrationError(
        string $name,
        string $username,
        string $email,
        string $password,
        string $password2,
        array &$validated = []
    ): ?string {
        $app = $this->getApplication();

        $data = $app->getInput()->post->get('jform', [], 'array');
        $data = \is_array($data) ? $data : [];

        $data['name']      = $name;
        $data['username']  = $username;
        $data['email1']    = $email;
        $data['password1'] = $password;
        $data['password2'] = $password2;

        try {
            $app->getLanguage()->load('com_users', JPATH_SITE);

            // A form of com_users is only found on the paths of the component
            // that dispatches the request, here com_ajax.
            Form::addFormPath(JPATH_SITE . '/components/com_users/forms');

            $model = $app->bootComponent('com_users')->getMVCFactory()
                ->createModel('Registration', 'Site', ['ignore_request' => true]);
            $form = $model ? $model->getForm([], false) : false;

            if (!$form) {
                return Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_FAILED');
            }

            // The captcha follows the Users options, as in com_users. Outside of
            // com_users the captcha field would read the options of com_ajax.
            $form->setFieldAttribute('captcha', 'plugin', $this->registrationCaptchaPlugin());

            $result = $model->validate($form, $data);

            if ($result !== false) {
                $validated = (array) $result;

                return null;
            }

            foreach ((method_exists($model, 'getErrors') ? $model->getErrors() : []) as $error) {
                $message = $error instanceof \Throwable ? $error->getMessage() : (string) $error;

                if ($message !== '') {
                    return $message;
                }
            }
        } catch (\Throwable $e) {
            Log::add('Registration validation error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }

        return Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REGISTRATION_FAILED');
    }

    /**
     * The captcha plugin of the registration, as the Users options define it.
     *
     * An empty value means "Use Default", the captcha of the Global
     * Configuration. "0" switches the captcha off.
     *
     * @return  string  The plugin element, "0" for none
     */
    protected function registrationCaptchaPlugin(): string
    {
        $plugin = (string) ComponentHelper::getParams('com_users')->get('captcha', '');

        if ($plugin === '') {
            $plugin = (string) $this->getApplication()->get('captcha', '0');
        }

        return $plugin === '' ? '0' : $plugin;
    }

    /**
     * Whether another reset request is allowed for this account.
     *
     * The same rule as com_users (ResetModel::checkResetLimit()): at most
     * reset_count requests within reset_time hours, counted from the first
     * request of the window. The Users options are read directly, because the
     * request runs in com_ajax, whose options do not carry these values.
     * The counter and the start of the window are set on $account.
     *
     * @param   object  $account  The user, with lastResetTime and resetCount
     *
     * @return  boolean  True when the request may be sent
     */
    protected function withinResetLimit(object $account): bool
    {
        $usersConfig = ComponentHelper::getParams('com_users');
        $maxCount    = (int) $usersConfig->get('reset_count', 10);
        $resetHours  = (int) $usersConfig->get('reset_time', 1);

        $lastReset  = empty($account->lastResetTime) ? 0 : (int) strtotime((string) $account->lastResetTime);
        $hoursSince = (strtotime(Factory::getDate()->toSql()) - $lastReset) / 3600;

        if ($hoursSince > $resetHours) {
            $account->lastResetTime = Factory::getDate()->toSql();
            $account->resetCount    = 1;

            return true;
        }

        if ((int) $account->resetCount < $maxCount) {
            $account->resetCount = (int) $account->resetCount + 1;

            return true;
        }

        return false;
    }

    /**
     * Handle password reset request
     *
     * @return  string  JSON response
     */
    protected function handleReset(): string
    {
        if (!$this->params->get('enable_reset', 1)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_TASK_DISABLED'));
        }

        $input = $this->getApplication()->getInput();
        $email = trim($input->post->getString('email', ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_EMAIL_INVALID'));
        }

        // Always return success to prevent email enumeration
        try {
            $db = $this->getDatabase();
            $query = $this->createDbQuery($db)
                ->select('*')
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('email') . ' = :email')
                ->where($db->quoteName('block') . ' = 0')
                ->bind(':email', $email);
            $db->setQuery($query);
            $user = $db->loadObject();

            // The same refusals as com_users, but with the same neutral answer, so
            // the form still reveals nothing about the account: no reset of a
            // Super User from the site, and at most reset_count requests within
            // reset_time hours.
            $account = $user
                ? Factory::getContainer()->get(UserFactoryInterface::class)->loadUserById((int) $user->id)
                : null;

            if ($user && $account && !$account->authorise('core.admin') && $this->withinResetLimit($user)) {
                $token = UserHelper::genRandomPassword(32);
                $hashedToken = UserHelper::hashPassword($token);
                $resetTime  = (string) $user->lastResetTime;
                $resetCount = (int) $user->resetCount;

                $query = $this->createDbQuery($db)
                    ->update($db->quoteName('#__users'))
                    ->set($db->quoteName('activation') . ' = :activation')
                    ->set($db->quoteName('lastResetTime') . ' = :resetTime')
                    ->set($db->quoteName('resetCount') . ' = :resetCount')
                    ->where($db->quoteName('id') . ' = :userId')
                    ->bind(':activation', $hashedToken)
                    ->bind(':resetTime', $resetTime)
                    ->bind(':resetCount', $resetCount, ParameterType::INTEGER)
                    ->bind(':userId', $user->id, ParameterType::INTEGER);
                $db->setQuery($query);
                $db->execute();

                $this->sendResetEmail($user, $token);
            }
        } catch (\Exception $e) {
            Log::add('Password reset error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }

        return $this->jsonSuccess([
            'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_RESET_SUCCESS'),
        ]);
    }

    /**
     * Handle username reminder request
     *
     * @return  string  JSON response
     */
    protected function handleRemind(): string
    {
        if (!$this->params->get('enable_remind', 1)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_TASK_DISABLED'));
        }

        $input = $this->getApplication()->getInput();
        $email = trim($input->post->getString('email', ''));

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_EMAIL_INVALID'));
        }

        // Always return success to prevent email enumeration
        try {
            $db = $this->getDatabase();
            $query = $this->createDbQuery($db)
                ->select('*')
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('email') . ' = :email')
                ->where($db->quoteName('block') . ' = 0')
                ->bind(':email', $email);
            $db->setQuery($query);
            $user = $db->loadObject();

            if ($user) {
                $this->sendRemindEmail($user);
            }
        } catch (\Exception $e) {
            Log::add('Username remind error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }

        return $this->jsonSuccess([
            'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_REMIND_SUCCESS'),
        ]);
    }

    /**
     * Handle cart item removal via J2Store API
     */
    protected function handleRemoveCartItem(): string
    {
        if (!$this->params->get('enable_j2store_cart', 1)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_TASK_DISABLED'));
        }

        $user = $this->getApplication()->getIdentity();
        if (!$user || $user->guest) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_NOT_LOGGED_IN'));
        }

        $input      = $this->getApplication()->getInput();
        $cartitemId = $input->getInt('cartitem_id', 0);

        if (!$cartitemId) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_INVALID_CART_ITEM'));
        }

        try {
            $db = $this->getDatabase();
            $userId = (int) $user->id;

            if (!$this->isJ2CommerceInstalled($db)) {
                return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND'));
            }

            if ($this->isJ2Commerce4($db)) {
                // J2Store / J2Commerce 4.x — tables: #__j2store_carts / #__j2store_cartitems
                // FK in #__j2store_cartitems to #__j2store_carts is `cart_id` (not j2store_cart_id)
                // $userId is (int) — safe to inline in subquery; bind() on subquery objects is lost
                // when the subquery is cast to string and embedded in the outer query's WHERE clause.
                $subQuery = $this->createDbQuery($db)
                    ->select($db->quoteName('j2store_cart_id'))
                    ->from($db->quoteName('#__j2store_carts'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId);

                $query = $this->createDbQuery($db)
                    ->delete($db->quoteName('#__j2store_cartitems'))
                    ->where($db->quoteName('j2store_cartitem_id') . ' = :cartitemId')
                    ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')')
                    ->bind(':cartitemId', $cartitemId, ParameterType::INTEGER);
            } else {
                // J2Commerce 6.x — tables: #__j2commerce_carts / #__j2commerce_cartitems
                $subQuery = $this->createDbQuery($db)
                    ->select($db->quoteName('j2commerce_cart_id'))
                    ->from($db->quoteName('#__j2commerce_carts'))
                    ->where($db->quoteName('user_id') . ' = ' . $userId);

                $query = $this->createDbQuery($db)
                    ->delete($db->quoteName('#__j2commerce_cartitems'))
                    ->where($db->quoteName('j2commerce_cartitem_id') . ' = :cartitemId')
                    ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')')
                    ->bind(':cartitemId', $cartitemId, ParameterType::INTEGER);
            }

            $db->setQuery($query);
            $db->execute();

            // If no row was deleted the item either doesn't exist or belongs to a
            // different user. The query is already user-scoped so nothing was deleted,
            // but we must not return success — that would silently confirm the item ID.
            if ($db->getAffectedRows() === 0) {
                return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_CART_ITEM_NOT_FOUND'));
            }

            $cartCount = $this->getCartCountForUser($db, $userId);
            $cartTotal = $this->getCartTotalForUser($db, $userId);

            // JS reads cartCount/cartTotal from data.data.* (see joomlaajaxforms.js removeCartItem handler)
            return $this->jsonSuccess([
                'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_CART_ITEM_REMOVED'),
                'data'    => [
                    'cartCount' => $cartCount,
                    'cartTotal' => $cartTotal,
                ],
            ]);
        } catch (\Exception $e) {
            Log::add('Cart remove error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_CART_REMOVE_FAILED'));
        }
    }

    /**
     * Return the current cart item count for the logged-in user.
     */
    protected function handleGetCartCount(): string
    {
        if (!$this->params->get('enable_j2store_cart', 1)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_TASK_DISABLED'));
        }

        $user = $this->getApplication()->getIdentity();

        if (!$user || $user->guest) {
            return $this->jsonSuccess(['data' => ['cartCount' => 0]]);
        }

        try {
            $db = $this->getDatabase();
            $userId = (int) $user->id;

            if (!$this->isJ2CommerceInstalled($db)) {
                return $this->jsonSuccess(['data' => ['cartCount' => 0]]);
            }

            $cartCount = $this->getCartCountForUser($db, $userId);

            // JS reads cartCount from data.data.* (see joomlaajaxforms.js getCartCount handler)
            return $this->jsonSuccess(['data' => ['cartCount' => $cartCount]]);
        } catch (\Exception $e) {
            Log::add('Cart count error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
            return $this->jsonSuccess(['data' => ['cartCount' => 0]]);
        }
    }

    /**
     * Whether the request carries the form token of the current session (POST
     * field, query parameter or X-CSRF-Token header).
     *
     * Session::checkToken() is not used: for a new session it redirects to the
     * home page instead of returning false, so the script would get an empty
     * redirect instead of the JSON error it shows to the user.
     */
    private function hasValidToken(): bool
    {
        $input = $this->getApplication()->getInput();
        $token = Session::getFormToken();

        return hash_equals($token, (string) $input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum'))
            || $input->post->get($token, '', 'alnum') !== ''
            || $input->get->get($token, '', 'alnum') !== '';
    }

    /**
     * Profile page to open after a login (with and without MFA):
     * the "myprofile" menu item of the active shop, otherwise the
     * "myprofile" view of the active shop, otherwise (no active shop)
     * the Joomla user profile.
     *
     * @param   bool  $absolute  Return an absolute URL instead of a routed path.
     */
    private function getProfileRedirect(bool $absolute = false): string
    {
        $profileItemId = $this->getMyProfileMenuItemId();
        $shop          = $this->getActiveShop($this->getDatabase());

        if ($profileItemId) {
            $url = 'index.php?Itemid=' . $profileItemId;
        } elseif ($shop !== null) {
            $url = 'index.php?option=com_' . $shop . '&view=myprofile';
        } else {
            // No active shop: the Joomla user profile is the only profile page.
            $url = 'index.php?option=com_users&view=profile';
        }

        return Route::_($url, false, Route::TLS_IGNORE, $absolute);
    }

    /**
     * Returns the menu item ID for the "myprofile" view of the active shop
     * (see getActiveShop()), or null if no shop is active or no such menu
     * item exists.
     *
     * Uses a direct DB query instead of Menu::getItems() to avoid loading
     * the full menu tree. Works on J4/J5/J6.
     */
    private function getMyProfileMenuItemId(): ?int
    {
        $db   = $this->getDatabase();
        $shop = $this->getActiveShop($db);

        if ($shop === null) {
            return null;
        }

        $link = 'index.php?option=com_' . $shop . '&view=myprofile';

        $q = $this->createDbQuery($db)
            ->select($db->quoteName('id'))
            ->from($db->quoteName('#__menu'))
            ->where($db->quoteName('link') . ' = :link')
            ->where($db->quoteName('client_id') . ' = 0')
            ->where($db->quoteName('published') . ' = 1')
            ->bind(':link', $link)
            ->setLimit(1);

        $db->setQuery($q);
        $id = $db->loadResult();

        return $id ? (int) $id : null;
    }

    /**
     * Result of getActiveShop() for this request.
     *
     * @var  string|null
     */
    private ?string $activeShop = null;

    /**
     * Whether getActiveShop() has already been resolved for this request.
     *
     * @var  bool
     */
    private bool $activeShopResolved = false;

    /**
     * Active shop: 'j2commerce' when com_j2commerce is enabled and #__j2commerce_carts
     * exists, otherwise 'j2store' when com_j2store is enabled and #__j2store_carts exists,
     * otherwise null.
     *
     * Tables alone do not decide: after a migration the #__j2store_* tables remain.
     * A component alone does not decide either: an enabled component without its
     * cart table is skipped and the next candidate is checked.
     * Without an active shop AJAX Forms does not use any shop: the cart and the
     * profile of a disabled shop are not reachable for the user anyway.
     */
    private function getActiveShop(DatabaseInterface $db): ?string
    {
        if ($this->activeShopResolved) {
            return $this->activeShop;
        }

        $this->activeShopResolved = true;
        $this->activeShop         = null;

        try {
            $query = $this->createDbQuery($db)
                ->select($db->quoteName('element'))
                ->from($db->quoteName('#__extensions'))
                ->where($db->quoteName('type') . ' = ' . $db->quote('component'))
                ->where($db->quoteName('enabled') . ' = 1')
                ->whereIn($db->quoteName('element'), ['com_j2commerce', 'com_j2store'], ParameterType::STRING);
            $enabled = $db->setQuery($query)->loadColumn() ?: [];

            // Priority order: J2Commerce 6 first, then J2Store / J2Commerce 4.
            foreach (['j2commerce', 'j2store'] as $shop) {
                if (!in_array('com_' . $shop, $enabled, true)) {
                    continue;
                }

                // SHOW TABLES LIKE avoids the stale in-memory cache of getTableList().
                // escape(..., true) escapes the LIKE wildcards ('_' and '%') so the
                // pattern matches the exact table name and not a similarly named table.
                $like = $db->quote($db->escape($db->getPrefix() . $shop . '_carts', true), false);
                $db->setQuery('SHOW TABLES LIKE ' . $like);

                if ($db->loadResult() !== null) {
                    $this->activeShop = $shop;
                    break;
                }

                // Enabled but without its cart table: try the next candidate.
            }
        } catch (\Throwable $e) {
            Log::add('Shop detection error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }

        return $this->activeShop;
    }

    /**
     * Returns true if a supported shop is active (see getActiveShop()).
     */
    private function isJ2CommerceInstalled(DatabaseInterface $db): bool
    {
        return $this->getActiveShop($db) !== null;
    }

    /**
     * Returns true if J2Store / J2Commerce 4.x is the active shop (#__j2store_* tables),
     * false for J2Commerce 6.x (#__j2commerce_* tables) or when no shop is active.
     * Decided by the enabled component, not by the tables (see getActiveShop()).
     */
    private function isJ2Commerce4(DatabaseInterface $db): bool
    {
        return $this->getActiveShop($db) === 'j2store';
    }

    /**
     * Get total cart item quantity for a user.
     */
    private function getCartCountForUser(DatabaseInterface $db, int $userId): int
    {
        if ($this->isJ2Commerce4($db)) {
            // #__j2store_cartitems FK to #__j2store_carts is `cart_id`; quantity column is `product_qty`
            // (verified against production DB dump: advansj5_j2store_cartitems has product_qty decimal(12,4))
            // $userId is (int) — safe to inline; bind() on subquery is lost when cast to string.
            $subQuery = $this->createDbQuery($db)
                ->select($db->quoteName('j2store_cart_id'))
                ->from($db->quoteName('#__j2store_carts'))
                ->where($db->quoteName('user_id') . ' = ' . $userId);

            $query = $this->createDbQuery($db)
                ->select('COALESCE(SUM(' . $db->quoteName('product_qty') . '), 0)')
                ->from($db->quoteName('#__j2store_cartitems'))
                ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')');
        } else {
            $subQuery = $this->createDbQuery($db)
                ->select($db->quoteName('j2commerce_cart_id'))
                ->from($db->quoteName('#__j2commerce_carts'))
                ->where($db->quoteName('user_id') . ' = ' . $userId);

            $query = $this->createDbQuery($db)
                ->select('COALESCE(SUM(' . $db->quoteName('product_qty') . '), 0)')
                ->from($db->quoteName('#__j2commerce_cartitems'))
                ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')');
        }

        try {
            return (int) $db->setQuery($query)->loadResult();
        } catch (\Throwable $e) {
            // Cart table does not exist (component not installed) — return 0.
            return 0;
        }
    }

    /**
     * Get formatted cart total for a user.
     *
     * Both J2Commerce 4 and 6 store cart line items without a price column
     * (#__j2store_cartitems / #__j2commerce_cartitems contain only product_qty
     * and variant_id). Computing a correct total requires joining to the pricing
     * engine (tier prices, customer group rules, coupons, taxes) — not feasible
     * in a lightweight plugin query. Returns '0.00' for both versions; the
     * frontend should hide the total display when cartTotal === '0.00'.
     */
    private function getCartTotalForUser(DatabaseInterface $db, int $userId): string
    {
        return '0.00';
    }

    /**
     * Handle profile save
     */
    protected function handleSaveProfile(): string
    {
        if (!$this->params->get('enable_profile', 1)) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_TASK_DISABLED'));
        }

        $user = $this->getApplication()->getIdentity();
        if (!$user || $user->guest) {
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_NOT_LOGGED_IN'));
        }

        $input = $this->getApplication()->getInput();

        try {
            // Joomla profile form sends jform[name], jform[email1], jform[password1], jform[password2]
            $jform = $input->post->get('jform', [], 'array');



            $name  = trim($jform['name'] ?? $input->post->getString('name', ''));
            $email = trim($jform['email1'] ?? $jform['email'] ?? $input->post->getString('email', ''));

            if (empty($name)) {
                return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_NAME_REQUIRED'));
            }
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_EMAIL_INVALID'));
            }

            // Only update fields that changed
            $changed = false;
            if ($user->name !== $name) {
                $user->name = $name;
                $changed = true;
            }
            if ($user->email !== $email) {
                $user->email = $email;
                $changed = true;
            }

            // Update password if provided
            $password  = $jform['password1'] ?? $input->post->getString('password1', '');
            $password2 = $jform['password2'] ?? $input->post->getString('password2', '');
            if (!empty($password)) {
                if ($password !== $password2) {
                    return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PASSWORD_MISMATCH'));
                }
                $minLength = (int) ComponentHelper::getParams('com_users')->get('minimum_length', 12);
                if (strlen($password) < $minLength) {
                    return $this->jsonError(Text::sprintf('PLG_AJAX_JOOMLAAJAXFORMS_PASSWORD_TOO_SHORT', $minLength));
                }
                $user->password = UserHelper::hashPassword($password);
                $changed = true;
            }

            if (!$changed) {
                return $this->jsonSuccess([
                    'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVED'),
                ]);
            }

            if (!$user->save(true)) {
                // User::getErrors() can carry untranslated database or driver text.
                // It goes to the log for the administrator, never to the client.
                $errors = $user->getErrors();
                Log::add(
                    'Profile save failed: ' . ($errors ? implode(' ', array_map('strval', $errors)) : 'no error message'),
                    Log::ERROR,
                    'plg_ajax_joomlaajaxforms'
                );

                return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVE_FAILED'));
            }

            return $this->jsonSuccess([
                'message' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVED'),
            ]);
        } catch (\Exception $e) {
            Log::add('Profile save exception: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
            return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVE_FAILED'));
        }
    }

    /**
     * Get MFA methods for a user
     *
     * @param   int  $userId  User ID
     *
     * @return  array  MFA methods
     */
    protected function getMfaMethods(int $userId): array
    {
        try {
            $db = $this->getDatabase();
            $query = $this->createDbQuery($db)
                ->select([$db->quoteName('id'), $db->quoteName('title'), $db->quoteName('method')])
                ->from($db->quoteName('#__user_mfa'))
                ->where($db->quoteName('user_id') . ' = :userId')
                ->bind(':userId', $userId, ParameterType::INTEGER);
            $db->setQuery($query);
            $records = $db->loadObjectList();

            if (empty($records)) {
                return [];
            }

            $methods = [];
            foreach ($records as $record) {
                $methods[] = [
                    'id'     => $record->id,
                    'title'  => $record->title,
                    'method' => $record->method,
                ];
            }

            return $methods;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * Send one of Joomla's own account mail templates.
     *
     * Everything the site configured under System, Mail Templates then applies:
     * the stored subject and body, the HTML layout with frame and logo, and the
     * language of the recipient.
     *
     * A missing template and a failed send both leave the recipient without a
     * styled mail, so both return false and let the caller fall back to its
     * plain-text mail: an unstyled mail is better than no mail. Only a template
     * that MailTemplate::send() actually delivered returns true.
     *
     * @param   string    $templateId      Mail template id, for example com_users.password_reset
     * @param   string    $languageTag     Language the mail is rendered in
     * @param   string    $recipientEmail  Address the mail goes to
     * @param   string    $recipientName   Name of the recipient (may be empty)
     * MailTemplate escapes a replacement in the HTML body only when the tag was registered through
     * addUnsafeTags(); everything else reaches the body as markup (libraries/src/Mail/MailTemplate.php
     * line 490 and following, gated on $isHtml, so the plain-text body is never touched). Listing the
     * user-controlled tags by hand is therefore the wrong way round: one forgotten field, or one new
     * field in the template data, and a user-supplied value lands unescaped in an HTML mail.
     *
     * This method inverts that. Every key in $data is marked unsafe except the ones the caller
     * explicitly declares as generated markup, and those are named one by one.
     *
     * @param   array     $data            Replacement data for the template tags
     * @param   string    $context         Short label for the log entry
     * @param   string[]  $trustedTags     The ONLY tags whose value may reach the HTML body as markup,
     *                                     for generated HTML such as link_html. Every other key of
     *                                     $data is escaped.
     *
     * @return  bool  True only when the template mail was sent, false when there
     *                is no such template or the send failed and the caller has
     *                to fall back
     */
    protected function sendTemplateMail(
        string $templateId,
        string $languageTag,
        string $recipientEmail,
        string $recipientName,
        array $data,
        string $context,
        array $trustedTags = []
    ): bool {
        try {
            if (MailTemplate::getTemplate($templateId, $languageTag) === null) {
                Log::add(
                    $context . ': no mail template ' . $templateId . ', composing the mail in the plugin',
                    Log::WARNING,
                    'plg_ajax_joomlaajaxforms'
                );

                return false;
            }

            $this->loadMailTemplateStrings($templateId, $languageTag);

            $mailer = new MailTemplate($templateId, $languageTag);
            $mailer->addTemplateData($data);
            $mailer->addRecipient($recipientEmail, $recipientName !== '' ? $recipientName : null);

            // Escape everything the caller did not declare as generated markup. A flat array value is
            // covered by its own top-level key, because MailTemplate checks the unsafe list against
            // that key, not against the sub-keys (MailTemplate.php lines 490 and 499). The data this
            // plugin passes holds scalars and flat arrays only, so one level is all there is.
            $unsafeTags = array_values(array_diff(array_keys($data), $trustedTags));

            if ($unsafeTags) {
                $mailer->addUnsafeTags($unsafeTags);
            }

            if ($mailer->send() !== true) {
                Log::add(
                    $context . ' could not be sent through ' . $templateId,
                    Log::ERROR,
                    'plg_ajax_joomlaajaxforms'
                );

                return false;
            }
        } catch (\Throwable $e) {
            // A \Throwable must never leave this plugin: the caller answers an
            // AJAX request, and an uncaught error would turn that answer into a
            // server error instead of JSON.
            Log::add(
                $context . ' could not be sent through ' . $templateId . ': ' . $e->getMessage(),
                Log::ERROR,
                'plg_ajax_joomlaajaxforms'
            );

            return false;
        }

        return true;
    }

    /**
     * Make the strings of a mail template readable.
     *
     * A mail template stores subject and body as language keys of the extension
     * it belongs to. MailTemplate loads that extension's language files only
     * when the mail goes out in a language other than the one of the running
     * request. This plugin answers inside com_ajax, where the strings of
     * com_users are not loaded, so without this the mail would carry the raw
     * language keys.
     *
     * @param   string  $templateId   Mail template id, the part before the dot names the extension
     * @param   string  $languageTag  Language the mail is rendered in
     *
     * @return  void
     */
    protected function loadMailTemplateStrings(string $templateId, string $languageTag): void
    {
        try {
            $language = $this->getApplication()->getLanguage();
        } catch (\Throwable $e) {
            return;
        }

        if ($languageTag !== $language->getTag()) {
            // MailTemplate builds its own language object and loads the files itself.
            return;
        }

        $extension = strstr($templateId, '.', true);

        if ($extension === false || $extension === '') {
            return;
        }

        // The same order com_mails uses: the site files carry the mail texts,
        // the administrator files and the .sys file carry the rest, and a
        // template of another extension may well keep its strings there.
        $language->load($extension, JPATH_SITE, $languageTag, true)
            || $language->load($extension, JPATH_SITE . '/components/' . $extension, $languageTag, true);

        $language->load($extension, JPATH_ADMINISTRATOR, $languageTag, true)
            || $language->load($extension, JPATH_ADMINISTRATOR . '/components/' . $extension, $languageTag, true);

        if (!$language->hasKey(strtoupper($extension))) {
            $language->load($extension . '.sys', JPATH_ADMINISTRATOR, $languageTag, true)
                || $language->load($extension . '.sys', JPATH_ADMINISTRATOR . '/components/' . $extension, $languageTag, true);
        }
    }

    /**
     * Replacement data built from a user record.
     *
     * The same set com_users passes, minus the credential fields: a mail
     * template is editable in the backend, so no secret may be reachable
     * through a tag. The link the mail needs is built separately, so dropping
     * the stored token costs nothing. Values that are not scalar are dropped as
     * well, because a template tag can only carry text.
     *
     * Named fields are removed rather than named fields kept, because the
     * columns of #__users that must never leave the site are a short, closed
     * list, while a site may well put any other column of its own record into
     * its own template. An allowed-list would break exactly those templates
     * without protecting anything more.
     *
     * @param   object  $user  User object or record from #__users
     *
     * @return  array<string, mixed>
     */
    protected function mailTemplateData(object $user): array
    {
        $data = ArrayHelper::fromObject($user, false);

        unset($data['password'], $data['password_clear'], $data['otpKey'], $data['otep'], $data['activation']);

        foreach ($data as $key => $value) {
            if ($value !== null && !is_scalar($value) && !is_array($value)) {
                unset($data[$key]);
            }
        }

        return $data;
    }

    /**
     * Language tag for a mail to a user.
     *
     * The mail has to arrive in the language of the customer, so the language
     * chosen in the user's own account wins, then the language of the running
     * request, then the default site language.
     *
     * A language the site does not have installed is skipped: a mail template
     * stores its subject and body as language keys, and without the matching
     * language pack those keys would be what the recipient reads.
     *
     * @param   object  $user  User object or record from #__users
     *
     * @return  string
     */
    protected function accountMailLanguage(object $user): string
    {
        $chosen = '';
        $params = $user->params ?? null;

        if ($params instanceof Registry) {
            $chosen = (string) $params->get('language', '');
        } else {
            if (is_string($params) && $params !== '') {
                $params = json_decode($params, true);
            }

            if (is_object($params)) {
                $params = (array) $params;
            }

            if (is_array($params)) {
                $chosen = (string) ($params['language'] ?? '');
            }
        }

        if ($chosen !== '' && $this->isInstalledSiteLanguage($chosen)) {
            return $chosen;
        }

        try {
            $tag = (string) $this->getApplication()->getLanguage()->getTag();

            // The language of the running request is loaded by definition.
            if (preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag)) {
                return $tag;
            }
        } catch (\Throwable $e) {
            // No application language available.
        }

        return $this->defaultSiteLanguage();
    }

    /**
     * Is this language tag installed for the site?
     *
     * Answers false when the list cannot be read, so an unverified tag is never
     * used for a mail.
     *
     * @param   string  $tag  Language tag such as de-CH
     *
     * @return  bool
     */
    protected function isInstalledSiteLanguage(string $tag): bool
    {
        if (!preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag)) {
            return false;
        }

        try {
            return \array_key_exists($tag, LanguageHelper::getInstalledLanguages(0));
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * The default language of the site. Always a well-formed tag, so it can end
     * every language decision.
     *
     * @return  string
     */
    protected function defaultSiteLanguage(): string
    {
        try {
            $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
        } catch (\Throwable $e) {
            $tag = '';
        }

        return preg_match('/^[a-z]{2,3}-[A-Z]{2}$/', $tag) ? $tag : 'en-GB';
    }

    /**
     * A plugin string rendered in a specific language.
     *
     * Text::sprintf() always renders in the language of the running request, so
     * the plain-text fallback of an account mail would leave in that language
     * even when the recipient's account, and the template mail the fallback
     * replaces, use another one. Sending several mails in one request (the
     * registrant and the site owner) would then let the language of the first
     * reach the second. A separate Language instance keeps every mail in its
     * own language and never touches the language of the request.
     *
     * @param   string  $languageTag  Language the text is rendered in
     * @param   string  $key          Language key of the plugin string
     * @param   mixed   ...$args       sprintf arguments for the string
     *
     * @return  string
     */
    protected function mailText(string $languageTag, string $key, ...$args): string
    {
        try {
            $language = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($languageTag);
            $language->load('plg_ajax_joomlaajaxforms', JPATH_ADMINISTRATOR, $languageTag, true)
                || $language->load('plg_ajax_joomlaajaxforms', JPATH_PLUGINS . '/ajax/joomlaajaxforms', $languageTag, true);

            $text = $language->_($key);
        } catch (\Throwable $e) {
            // Without a usable Language instance the request language is still
            // better than a raw key.
            $text = Text::_($key);
        }

        return $args ? vsprintf($text, $args) : $text;
    }

    /**
     * An absolute, routed address for a mail.
     *
     * Route::link() gives the address the site itself would show, instead of
     * the raw component URL. Without a site router, for example in a CLI
     * context, the unrouted address is still a working link.
     *
     * @param   string   $query  Internal Joomla URL, starting with index.php
     * @param   bool     $xhtml  Escape & as &amp; for the HTML body
     * @param   int      $tls    Route::TLS_* constant
     *
     * @return  string
     */
    protected function routedLink(string $query, bool $xhtml, int $tls): string
    {
        try {
            $link = Route::link('site', $query, $xhtml, $tls, true);

            if (is_string($link) && $link !== '') {
                return $link;
            }
        } catch (\Throwable $e) {
            // No site router available.
        }

        $link = Uri::root() . $query;

        return $xhtml ? htmlspecialchars($link, ENT_COMPAT, 'UTF-8') : $link;
    }

    /**
     * Send password reset email
     *
     * @param   object  $user   User object
     * @param   string  $token  Reset token
     *
     * @return  void
     */
    protected function sendResetEmail(object $user, string $token): void
    {
        $app   = $this->getApplication();
        $query = 'index.php?option=com_users&view=reset&layout=confirm&token=' . $token;
        $mode  = $app->get('force_ssl', 0) == 2 ? Route::TLS_FORCE : Route::TLS_IGNORE;

        // Joomla's own mail template, with the same id and the same data keys
        // com_users uses, so the stored template, the HTML layout and the logo
        // apply to this mail exactly as they do to every other account mail.
        $data              = $this->mailTemplateData($user);
        $data['sitename']  = $app->get('sitename');
        $data['link_text'] = $this->routedLink($query, false, $mode);
        $data['link_html'] = $this->routedLink($query, true, $mode);
        $data['token']     = $token;

        $language = $this->accountMailLanguage($user);

        if ($this->sendTemplateMail(
            'com_users.password_reset',
            $language,
            (string) $user->email,
            (string) $user->name,
            $data,
            'Reset email',
            // link_html is markup this plugin builds itself. Everything else, the account holder's
            // name, username and email included, is escaped by sendTemplateMail().
            ['link_html']
        )) {
            return;
        }

        // Fallback for a site without that mail template: the former plain-text
        // mail, in the recipient's language. An unstyled mail is better than no
        // mail.
        try {
            $siteName = $app->get('sitename');
            $resetLink = Uri::root() . $query;

            $subject = $this->mailText($language, 'PLG_AJAX_JOOMLAAJAXFORMS_RESET_EMAIL_SUBJECT', $siteName);
            $body = $this->mailText(
                $language,
                'PLG_AJAX_JOOMLAAJAXFORMS_RESET_EMAIL_BODY',
                $user->name,
                $siteName,
                $resetLink,
                $token,
                $siteName
            );

            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($user->email, $user->name);
            $mailer->setSubject($subject);
            $mailer->setBody($body);
            $this->logFailedMail($mailer->Send(), 'Reset email');
        } catch (\Throwable $e) {
            Log::add('Reset email error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }
    }

    /**
     * Send username reminder email
     *
     * @param   object  $user  User object
     *
     * @return  void
     */
    protected function sendRemindEmail(object $user): void
    {
        $app   = $this->getApplication();
        $query = 'index.php?option=com_users&view=login';
        $mode  = $app->get('force_ssl', 0) == 2 ? Route::TLS_FORCE : Route::TLS_IGNORE;

        // Same reasoning as sendResetEmail().
        $data              = $this->mailTemplateData($user);
        $data['sitename']  = $app->get('sitename');
        $data['link_text'] = $this->routedLink($query, false, $mode);
        $data['link_html'] = $this->routedLink($query, true, $mode);

        $language = $this->accountMailLanguage($user);

        if ($this->sendTemplateMail(
            'com_users.reminder',
            $language,
            (string) $user->email,
            (string) $user->name,
            $data,
            'Remind email',
            ['link_html']
        )) {
            return;
        }

        try {
            $siteName = $app->get('sitename');
            $loginLink = Uri::root() . $query;

            $subject = $this->mailText($language, 'PLG_AJAX_JOOMLAAJAXFORMS_REMIND_EMAIL_SUBJECT', $siteName);
            $body = $this->mailText(
                $language,
                'PLG_AJAX_JOOMLAAJAXFORMS_REMIND_EMAIL_BODY',
                $user->name,
                $siteName,
                $user->username,
                $loginLink,
                $siteName
            );

            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($user->email, $user->name);
            $mailer->setSubject($subject);
            $mailer->setBody($body);
            $this->logFailedMail($mailer->Send(), 'Remind email');
        } catch (\Throwable $e) {
            Log::add('Remind email error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }
    }

    /**
     * Send activation email
     *
     * @param   \Joomla\CMS\User\User  $user    User object
     * @param   \Joomla\Registry\Registry  $config  Users config
     *
     * @return  void
     */
    protected function sendActivationEmail($user, $config): void
    {
        $app   = $this->getApplication();
        $query = 'index.php?option=com_users&task=registration.activate&token=' . $user->activation;
        $mode  = $app->get('force_ssl', 0) == 2 ? Route::TLS_FORCE : Route::TLS_IGNORE;

        // Self-activation and admin activation have their own core template,
        // the same split com_users makes.
        $templateId = ((int) $config->get('useractivation') === 2)
            ? 'com_users.registration.user.admin_activation'
            : 'com_users.registration.user.self_activation';

        $data              = $this->mailTemplateData($user);
        $data['fromname']  = $app->get('fromname');
        $data['mailfrom']  = $app->get('mailfrom');
        $data['sitename']  = $app->get('sitename');
        $data['siteurl']   = Uri::root();
        $data['activate']  = $this->routedLink($query, false, $mode);

        $language = $this->accountMailLanguage($user);

        if ($this->sendTemplateMail(
            $templateId,
            $language,
            (string) $user->email,
            (string) $user->name,
            $data,
            'Activation email',
            // This mail carries no generated markup: "activate" is a plain URL, so escaping it is
            // correct. The list is kept uniform on purpose and simply matches nothing here.
            ['link_html']
        )) {
            return;
        }

        try {
            $siteName = $app->get('sitename');
            $activationLink = Uri::root() . $query;

            $subject = $this->mailText($language, 'PLG_AJAX_JOOMLAAJAXFORMS_ACTIVATION_EMAIL_SUBJECT', $siteName);
            $body = $this->mailText(
                $language,
                'PLG_AJAX_JOOMLAAJAXFORMS_ACTIVATION_EMAIL_BODY',
                $user->name,
                $siteName,
                $activationLink,
                $user->activation,
                $siteName
            );

            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($user->email, $user->name);
            $mailer->setSubject($subject);
            $mailer->setBody($body);
            $this->logFailedMail($mailer->Send(), 'Activation email');
        } catch (\Throwable $e) {
            Log::add('Activation email error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }
    }

    /**
     * Send admin notification about new registration
     *
     * @param   \Joomla\CMS\User\User  $user  User object
     *
     * @return  void
     */
    protected function sendAdminNotification($user): void
    {
        $app        = $this->getApplication();
        $adminEmail = trim((string) $app->get('mailfrom'));

        // Without a usable site address there is nobody to notify. Saying so in
        // the log is better than handing an empty recipient to the mailer and
        // reading its failure instead.
        if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            Log::add(
                'Admin notification skipped: the site has no valid "mailfrom" address',
                Log::WARNING,
                'plg_ajax_joomlaajaxforms'
            );

            return;
        }

        $data             = $this->mailTemplateData($user);
        $data['fromname'] = $app->get('fromname');
        $data['mailfrom'] = $app->get('mailfrom');
        $data['sitename'] = $app->get('sitename');
        $data['siteurl']  = Uri::root();

        // The notice goes to the site's own address, so it is addressed in the
        // default site language rather than in the language of the registrant.
        $language = $this->defaultSiteLanguage();

        if ($this->sendTemplateMail(
            'com_users.registration.admin.new_notification',
            $language,
            $adminEmail,
            '',
            $data,
            'Admin notification',
            // No link at all in this one, so nothing is trusted. Same uniform list.
            ['link_html']
        )) {
            return;
        }

        try {
            $siteName = $app->get('sitename');

            $subject = $this->mailText($language, 'PLG_AJAX_JOOMLAAJAXFORMS_ADMIN_ACTIVATION_EMAIL_SUBJECT', $siteName);
            $body = $this->mailText(
                $language,
                'PLG_AJAX_JOOMLAAJAXFORMS_ADMIN_ACTIVATION_EMAIL_BODY',
                $siteName,
                $user->name,
                $user->username,
                $user->email
            );

            $mailer = Factory::getContainer()->get(MailerFactoryInterface::class)->createMailer();
            $mailer->addRecipient($adminEmail);
            $mailer->setSubject($subject);
            $mailer->setBody($body);
            $this->logFailedMail($mailer->Send(), 'Admin notification');
        } catch (\Throwable $e) {
            Log::add('Admin notification error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }
    }

    /**
     * Log a plain-text mail that the mailer refused to send.
     *
     * Joomla's mailer does not only throw: it returns false when the site has
     * mail switched off, and PHPMailer returns false for a rejected recipient.
     * Every plain-text fallback here is the last attempt to reach the recipient,
     * so a false return must leave a trace. Without it the request answers
     * success and nobody learns that the account mail, or the merchant's
     * registration notice, never went out.
     *
     * @param   mixed   $sent     Whatever the mailer's Send() returned
     * @param   string  $context  Short label for the log entry
     *
     * @return  void
     */
    protected function logFailedMail($sent, string $context): void
    {
        if ($sent === true) {
            return;
        }

        Log::add(
            $context . ' was not sent: the mailer did not confirm the send',
            Log::ERROR,
            'plg_ajax_joomlaajaxforms'
        );
    }

    /**
     * Return a JSON success response
     *
     * @param   array  $data  Additional data
     *
     * @return  string
     */
    protected function jsonSuccess(array $data = []): string
    {
        return json_encode(array_merge(['success' => true], $data));
    }

    /**
     * Return a JSON error response
     *
     * @param   string  $message  Error message
     *
     * @return  string
     */
    protected function jsonError(string $message): string
    {
        return json_encode(['success' => false, 'message' => $message]);
    }
}
