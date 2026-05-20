<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Ajax.JoomlaAjaxForms
 *
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary License
 */

namespace Advans\Plugin\Ajax\JoomlaAjaxForms\Extension;

defined('_JEXEC') or die;

use Joomla\CMS\Authentication\Authentication;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Mail\MailerFactoryInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Event\DispatcherInterface;
use Joomla\Event\SubscriberInterface;

class JoomlaAjaxForms extends CMSPlugin implements SubscriberInterface
{
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
     * Intercept AJAX requests before Joomla's SEF redirect.
     *
     * Joomla 5.x encodes & as &amp; in redirect Location headers,
     * causing infinite 303 loops for com_ajax URLs with multiple
     * query parameters. This handler detects our AJAX requests
     * early and responds directly.
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
        echo json_encode(['data' => [$result]], JSON_UNESCAPED_UNICODE);

        $app->close();
    }

    /**
     * Pass language strings to JavaScript via Joomla script options
     */
    public function onBeforeRender(): void
    {
        $doc = $this->getApplication()->getDocument();
        if (method_exists($doc, 'addScriptOptions')) {
            $doc->addScriptOptions('plg_ajax_joomlaajaxforms', [
                'ERROR_GENERIC'          => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_ERROR_GENERIC'),
                'MFA_SELECT_METHOD'      => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_MFA_SELECT_METHOD'),
                'MFA_ENTER_CODE'         => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_MFA_ENTER_CODE'),
                'MFA_METHOD'             => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_MFA_METHOD'),
                'MFA_CODE_LABEL'         => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_MFA_CODE_LABEL'),
                'MFA_CANCEL'             => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_MFA_CANCEL'),
                'MFA_VERIFY'             => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_MFA_VERIFY'),
                'MFA_CODE_INVALID_LENGTH' => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_JS_MFA_CODE_INVALID_LENGTH'),
                'PROFILE_SAVED'          => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVED'),
            ]);
        }
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
        // Validate CSRF token
        if (!Session::checkToken('get') && !Session::checkToken('post') && !Session::checkToken()) {
            $result = $this->jsonError(Text::_('JINVALID_TOKEN'));

            if ($event) {
                $event->addResult($result);
            }

            return $result;
        }

        $input = $this->getApplication()->getInput();
        $task = $input->getCmd('task', '');

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

        $result = $this->getApplication()->login($credentials, $options);

        if ($result === true) {
            $user = $this->getApplication()->getIdentity();

            // Check if MFA is required — redirect to Joomla's captive page
            $mfaMethods = $this->getMfaMethods($user->id);
            if (!empty($mfaMethods)) {
                // Clear any system messages queued during login
                $this->getApplication()->getMessageQueue(true);
                $session = $this->getApplication()->getSession();
                $session->set('application.queue', []);

                // Find the profile page URL via the menu system.
                // We look up the Itemid and let Route::_() build the
                // SEF URL with the correct language prefix.
                $profileUrl = Uri::base();
                $menu = $this->getApplication()->getMenu();
                if ($menu) {
                    $items = $menu->getItems(['component', 'link'], ['com_j2store', 'index.php?option=com_j2store&view=myprofile']);
                    if (!empty($items)) {
                        $sefPath = Route::_('index.php?Itemid=' . $items[0]->id, false);
                        $profileUrl = rtrim(Uri::base(), '/') . '/' . ltrim($sefPath, '/');
                    }
                }
                $session->set('com_users.return_url', $profileUrl);

                // Pass return URL as query parameter so the captive template
                // can restore it (the session value may be overwritten by
                // Joomla's MultiFactorAuthenticationHandler before the
                // template renders).
                $captiveUrl = Route::_(
                    'index.php?option=com_users&view=captive&return=' . base64_encode($profileUrl),
                    false
                );

                return $this->jsonSuccess([
                    'redirect' => $captiveUrl,
                    'message'  => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_MFA_REQUIRED'),
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
                $redirect = Route::_('index.php?option=com_j2store&view=myprofile', false);
            }

            return $this->jsonSuccess([
                'redirect' => $redirect,
                'message'  => Text::sprintf('PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_SUCCESS', $user->name),
            ]);
        }

        // Clear Joomla's message queue so com_ajax doesn't expose the
        // English authentication error from plg_authentication_joomla.
        $this->getApplication()->getMessageQueue(true);
        $this->getApplication()->getSession()->set('application.queue', []);

        return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_LOGIN_FAILED'));
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

            return $this->jsonSuccess([
                'redirect' => $redirect,
                'message'  => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_LOGOUT_SUCCESS'),
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
        $db = Factory::getContainer()->get(DatabaseInterface::class);

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

        try {
            $user = new \Joomla\CMS\User\User();
            $user->name = $name;
            $user->username = $username;
            $user->email = $email;
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
            $db = Factory::getContainer()->get(DatabaseInterface::class);
            $query = $this->createDbQuery($db)
                ->select('*')
                ->from($db->quoteName('#__users'))
                ->where($db->quoteName('email') . ' = :email')
                ->where($db->quoteName('block') . ' = 0')
                ->bind(':email', $email);
            $db->setQuery($query);
            $user = $db->loadObject();

            if ($user) {
                $token = UserHelper::genRandomPassword(32);
                $hashedToken = UserHelper::hashPassword($token);
                $tokenCreated = Factory::getDate()->toSql();

                $query = $this->createDbQuery($db)
                    ->update($db->quoteName('#__users'))
                    ->set($db->quoteName('activation') . ' = :activation')
                    ->set($db->quoteName('lastResetTime') . ' = :resetTime')
                    ->where($db->quoteName('id') . ' = :userId')
                    ->bind(':activation', $hashedToken)
                    ->bind(':resetTime', $tokenCreated)
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
            $db = Factory::getContainer()->get(DatabaseInterface::class);
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
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $userId = (int) $user->id;

            if (!$this->isJ2CommerceInstalled($db)) {
                return $this->jsonError(Text::_('PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND'));
            }

            if ($this->isJ2Commerce4($db)) {
                // J2Commerce 4.x — tables: #__j2store_carts / #__j2store_cartitems
                // FK in #__j2store_cartitems to #__j2store_carts is `cart_id` (not j2store_cart_id)
                $subQuery = $this->createDbQuery($db)
                    ->select($db->quoteName('j2store_cart_id'))
                    ->from($db->quoteName('#__j2store_carts'))
                    ->where($db->quoteName('user_id') . ' = :userId')
                    ->bind(':userId', $userId, ParameterType::INTEGER);

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
                    ->where($db->quoteName('user_id') . ' = :userId')
                    ->bind(':userId', $userId, ParameterType::INTEGER);

                $query = $this->createDbQuery($db)
                    ->delete($db->quoteName('#__j2commerce_cartitems'))
                    ->where($db->quoteName('j2commerce_cartitem_id') . ' = :cartitemId')
                    ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')')
                    ->bind(':cartitemId', $cartitemId, ParameterType::INTEGER);
            }

            $db->setQuery($query);
            $db->execute();

            $cartCount = $this->getCartCountForUser($db, $userId);
            $cartTotal = $this->getCartTotalForUser($db, $userId);

            return $this->jsonSuccess([
                'cartCount' => $cartCount,
                'cartTotal' => $cartTotal,
                'message'   => Text::_('PLG_AJAX_JOOMLAAJAXFORMS_CART_ITEM_REMOVED'),
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
        $user = $this->getApplication()->getIdentity();

        if (!$user || $user->guest) {
            return $this->jsonSuccess(['cartCount' => 0]);
        }

        try {
            $db     = Factory::getContainer()->get(DatabaseInterface::class);
            $userId = (int) $user->id;

            if (!$this->isJ2CommerceInstalled($db)) {
                return $this->jsonSuccess(['cartCount' => 0]);
            }

            $cartCount = $this->getCartCountForUser($db, $userId);

            return $this->jsonSuccess(['cartCount' => $cartCount]);
        } catch (\Exception $e) {
            Log::add('Cart count error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
            return $this->jsonSuccess(['cartCount' => 0]);
        }
    }

    /**
     * Returns true if any supported version of J2Commerce is installed.
     */
    private function isJ2CommerceInstalled(DatabaseInterface $db): bool
    {
        static $installed = null;
        if ($installed === null) {
            $tables   = $db->getTableList();
            $prefix   = $db->getPrefix();
            $installed = in_array($prefix . 'j2store_carts', $tables, true)
                      || in_array($prefix . 'j2commerce_carts', $tables, true);
        }
        return $installed;
    }

    /**
     * Returns true if J2Commerce 4.x is installed (uses #__j2store_* tables).
     * Returns false for J2Commerce 6.x (uses #__j2commerce_* tables).
     */
    private function isJ2Commerce4(DatabaseInterface $db): bool
    {
        static $result = null;
        if ($result === null) {
            $tables  = $db->getTableList();
            $prefix  = $db->getPrefix();
            $result  = in_array($prefix . 'j2store_carts', $tables, true);
        }
        return $result;
    }

    /**
     * Get total cart item quantity for a user.
     */
    private function getCartCountForUser(DatabaseInterface $db, int $userId): int
    {
        if ($this->isJ2Commerce4($db)) {
            // #__j2store_cartitems FK to #__j2store_carts is `cart_id`; quantity column is `product_qty`
            // (verified against production DB dump: advansj5_j2store_cartitems has product_qty decimal(12,4))
            $subQuery = $this->createDbQuery($db)
                ->select($db->quoteName('j2store_cart_id'))
                ->from($db->quoteName('#__j2store_carts'))
                ->where($db->quoteName('user_id') . ' = :userId')
                ->bind(':userId', $userId, ParameterType::INTEGER);

            $query = $this->createDbQuery($db)
                ->select('COALESCE(SUM(' . $db->quoteName('product_qty') . '), 0)')
                ->from($db->quoteName('#__j2store_cartitems'))
                ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')');
        } else {
            $subQuery = $this->createDbQuery($db)
                ->select($db->quoteName('j2commerce_cart_id'))
                ->from($db->quoteName('#__j2commerce_carts'))
                ->where($db->quoteName('user_id') . ' = :userId')
                ->bind(':userId', $userId, ParameterType::INTEGER);

            $query = $this->createDbQuery($db)
                ->select('COALESCE(SUM(' . $db->quoteName('product_qty') . '), 0)')
                ->from($db->quoteName('#__j2commerce_cartitems'))
                ->where($db->quoteName('cart_id') . ' IN (' . $subQuery . ')');
        }

        $db->setQuery($query);

        return (int) $db->loadResult();
    }

    /**
     * Get formatted cart total for a user.
     */
    private function getCartTotalForUser(DatabaseInterface $db, int $userId): string
    {
        if ($this->isJ2Commerce4($db)) {
            // #__j2store_cartitems has no price column; prices are in #__j2store_orderitems
            // joined via cart_id → #__j2store_carts.j2store_cart_id
            $query = $this->createDbQuery($db)
                ->select('COALESCE(SUM(' . $db->quoteName('oi.orderitem_finalprice') . ' * ' . $db->quoteName('oi.orderitem_quantity') . '), 0)')
                ->from($db->quoteName('#__j2store_orderitems', 'oi'))
                ->join('INNER', $db->quoteName('#__j2store_carts', 'c') . ' ON ' . $db->quoteName('c.j2store_cart_id') . ' = ' . $db->quoteName('oi.cart_id'))
                ->where($db->quoteName('c.user_id') . ' = :userId')
                ->bind(':userId', $userId, ParameterType::INTEGER);
        } else {
            // J2Commerce 6: #__j2commerce_cartitems has no price column and #__j2commerce_carts
            // has no cart_total column. Prices are in #__j2commerce_product_prices (variant-based,
            // with quantity breaks and customer group rules) — replicating that logic here is not
            // feasible. Return 0.00; the cart total display should be handled via the J2Commerce
            // cart view, not via a direct DB query.
            return '0.00';
        }

        $db->setQuery($query);

        return number_format((float) $db->loadResult(), 2);
    }

    /**
     * Handle profile save
     */
    protected function handleSaveProfile(): string
    {
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
                $errors = $user->getErrors();
                $errorMsg = !empty($errors) ? implode(' ', array_map('strval', $errors)) : Text::_('PLG_AJAX_JOOMLAAJAXFORMS_PROFILE_SAVE_FAILED');
                Log::add('Profile save failed: ' . $errorMsg, Log::ERROR, 'plg_ajax_joomlaajaxforms');
                return $this->jsonError($errorMsg);
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
            $db = Factory::getContainer()->get(DatabaseInterface::class);
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
     * Send password reset email
     *
     * @param   object  $user   User object
     * @param   string  $token  Reset token
     *
     * @return  void
     */
    protected function sendResetEmail(object $user, string $token): void
    {
        try {
            $siteName = $this->getApplication()->get('sitename');
            $resetLink = Uri::root() . 'index.php?option=com_users&view=reset&layout=confirm&token=' . $token;

            $subject = Text::sprintf('PLG_AJAX_JOOMLAAJAXFORMS_RESET_EMAIL_SUBJECT', $siteName);
            $body = Text::sprintf(
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
            $mailer->Send();
        } catch (\Exception $e) {
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
        try {
            $siteName = $this->getApplication()->get('sitename');
            $loginLink = Uri::root() . 'index.php?option=com_users&view=login';

            $subject = Text::sprintf('PLG_AJAX_JOOMLAAJAXFORMS_REMIND_EMAIL_SUBJECT', $siteName);
            $body = Text::sprintf(
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
            $mailer->Send();
        } catch (\Exception $e) {
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
        try {
            $siteName = $this->getApplication()->get('sitename');
            $activationLink = Uri::root() . 'index.php?option=com_users&task=registration.activate&token=' . $user->activation;

            $subject = Text::sprintf('PLG_AJAX_JOOMLAAJAXFORMS_ACTIVATION_EMAIL_SUBJECT', $siteName);
            $body = Text::sprintf(
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
            $mailer->Send();
        } catch (\Exception $e) {
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
        try {
            $siteName = $this->getApplication()->get('sitename');
            $adminEmail = $this->getApplication()->get('mailfrom');

            $subject = Text::sprintf('PLG_AJAX_JOOMLAAJAXFORMS_ADMIN_ACTIVATION_EMAIL_SUBJECT', $siteName);
            $body = Text::sprintf(
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
            $mailer->Send();
        } catch (\Exception $e) {
            Log::add('Admin notification error: ' . $e->getMessage(), Log::ERROR, 'plg_ajax_joomlaajaxforms');
        }
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
