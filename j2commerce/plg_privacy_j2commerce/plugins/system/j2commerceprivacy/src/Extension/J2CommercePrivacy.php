<?php
/**
 * @package     J2Commerce Privacy Consent System Plugin
 * @subpackage  Extension
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

namespace Advans\Plugin\System\J2CommercePrivacy\Extension;

defined('_JEXEC') or die;

use Advans\Plugin\Privacy\J2Commerce\Consent\ConsentRepository;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Language;
use Joomla\CMS\Language\LanguageFactoryInterface;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
use Joomla\Input\Input;
use Joomla\Registry\Registry;
use Joomla\Utilities\IpHelper;

/**
 * Server-side checkout consent for J2Commerce 6.
 *
 * WHY A SYSTEM PLUGIN
 * The privacy plugin (group "privacy") is only imported by com_privacy. J2Commerce 6 runs its
 * AJAX checkout without importing that group, so the privacy plugin cannot see the checkout.
 * System plugins are imported on every request, and J2Commerce 6 dispatches its
 * onJ2Commerce* events on the application dispatcher, so this plugin receives them.
 *
 * CHECKBOX
 * J2Commerce 6 fires AfterDisplayShippingPayment in its own shipping & payment templates
 * (bootstrap5 and uikit) directly before the Continue button. This plugin renders the checkbox
 * through that event (J2Commerce 6.3.4 and later), so it appears with the core templates and every
 * override that keeps the J2Commerce event call.
 *
 * ENFORCEMENT
 * Decided only by the privacy plugin options "Show Consent Checkbox" and "Consent Required".
 *
 * FLOW
 * 1. AfterDisplayShippingPayment: checkbox HTML added to the step; an earlier consent is discarded
 *    (every render needs a fresh tick).
 * 2. checkout.shippingPaymentMethodValidate: ticked -> consent stored in the session for the
 *    current cart; not ticked while required -> JSON field error.
 * 3. checkout.confirm and checkout.confirmPayment (POST): required but no consent for the current
 *    cart -> refused. Covers requests that skip step 4, zero-total orders and cart changes.
 * 4. checkout.confirmPayment (POST with form token, or GET gateway return): order, consent cart and
 *    request data are captured; after the controller the order must exist, belong to that cart and
 *    no longer be incomplete -> one #__privacy_consents record (capturePlacement(),
 *    recordAcceptedOrderConsent()). Not on onJ2CommerceAfterSaveOrder: J2Commerce saves an
 *    incomplete order already when the confirmation step is rendered.
 * 5. onJ2CommerceCheckoutCleanup: the consent is removed from the session.
 */
class J2CommercePrivacy extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    public const CONSENT_FIELD = 'j2commerce_privacy_consent';

    public const DECISION_RECORD = 'record';
    public const DECISION_REJECT = 'reject';
    public const DECISION_IGNORE = 'ignore';

    public const RESPONSE_STEP_ERROR    = 'step_error';
    public const RESPONSE_CONFIRM_ERROR = 'confirm_error';
    public const RESPONSE_PAYMENT_ERROR = 'payment_error';
    public const RESPONSE_REDIRECT      = 'redirect';

    public const SESSION_CONSENT = 'plg_system_j2commerceprivacy_consent';

    public const TASK_VALIDATE        = 'checkout.shippingpaymentmethodvalidate';
    public const TASK_CONFIRM         = 'checkout.confirm';
    public const TASK_CONFIRM_PAYMENT = 'checkout.confirmpayment';

    /** J2Commerce order state of an order saved by the confirmation step but not placed. */
    public const ORDER_STATE_INCOMPLETE = 5;

    private const CART_HELPER = 'J2Commerce\\Component\\J2commerce\\Administrator\\Helper\\CartHelper';

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute'                            => 'onAfterRoute',
            'onJ2CommerceAfterDisplayShippingPayment' => 'onJ2CommerceAfterDisplayShippingPayment',
            'onJ2CommerceCheckoutCleanup'             => 'onJ2CommerceCheckoutCleanup',
        ];
    }

    /**
     * Decide what to do with a submitted shipping & payment step.
     *
     * @param   Registry  $privacyParams  Params of the privacy plugin (plg_privacy_j2commerce).
     * @param   bool      $consentTicked  The consent checkbox was posted with value 1.
     */
    public static function decide(Registry $privacyParams, bool $consentTicked): string
    {
        if (!(int) $privacyParams->get('show_consent_checkbox', 1)) {
            return self::DECISION_IGNORE;
        }

        if ($consentTicked) {
            return self::DECISION_RECORD;
        }

        return (int) $privacyParams->get('consent_required', 1) ? self::DECISION_REJECT : self::DECISION_IGNORE;
    }

    /**
     * Resolve "controller.task" the way Joomla's ComponentDispatcher does.
     */
    public static function resolveTask(Input $input): string
    {
        $task = strtolower((string) $input->getCmd('task', ''));

        if ($task !== '' && !str_contains($task, '.')) {
            $controller = strtolower((string) $input->getCmd('controller', ''));

            if ($controller !== '') {
                $task = $controller . '.' . $task;
            }
        }

        return $task;
    }

    /**
     * HTML of the consent checkbox (input id/name j2commerce_privacy_consent). Contains no script,
     * so it survives J2Commerce's AJAX step loading.
     */
    public static function renderConsentCheckbox(Registry $privacyParams): string
    {
        $language = self::currentLanguage();
        $language->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR)
            || $language->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');

        $required  = (bool) (int) $privacyParams->get('consent_required', 1);
        $text      = (string) $privacyParams->get('consent_text', '');
        $text      = trim($text) !== '' ? $text : $language->_('PLG_PRIVACY_J2COMMERCE_CONSENT_CHECKBOX_DEFAULT');
        $articleId = (int) $privacyParams->get('privacy_article', 0);
        $linkText  = htmlspecialchars($language->_('PLG_PRIVACY_J2COMMERCE_POLICY_LINK'), ENT_QUOTES, 'UTF-8');

        // The consent text is configured by the site administrator and may contain HTML.
        // Route::_() without xhtml returns the raw URL; it is escaped exactly once here.
        $policy = $articleId > 0
            ? '<a href="' . htmlspecialchars(Route::_('index.php?option=com_content&view=article&id=' . $articleId, false), ENT_QUOTES, 'UTF-8')
                . '" target="_blank" rel="noopener noreferrer">' . $linkText . '</a>'
            : $linkText;
        $text = str_replace('{privacy_policy}', $policy, $text);

        return '<div class="j2commerce-privacy-consent mb-3 uk-margin">'
            . '<div class="form-check">'
            . '<input type="checkbox" class="form-check-input uk-checkbox" id="' . self::CONSENT_FIELD . '" name="' . self::CONSENT_FIELD . '" value="1"'
            . ($required ? ' required' : '') . '> '
            . '<label class="form-check-label" for="' . self::CONSENT_FIELD . '">' . $text
            . ($required ? ' <span class="text-danger" aria-hidden="true">*</span>' : '')
            . '</label>'
            . '</div>'
            . '</div>';
    }

    /**
     * Discard a consent from an earlier render, another tab or another cart: the rendered,
     * unticked checkbox has to be ticked again. Also callable from a custom checkout override
     * that renders its own checkbox instead of the J2Commerce event.
     */
    public static function markCheckboxRendered(bool $required = true): void
    {
        try {
            Factory::getApplication()->getSession()->remove(self::SESSION_CONSENT);
        } catch (\Throwable $e) {
            Log::add('Consent checkbox state could not be reset: ' . $e->getMessage(), Log::WARNING, 'plg_system_j2commerceprivacy');
        }
    }

    /**
     * ID of the current J2Commerce 6 cart without creating one (0 if unknown).
     */
    public static function currentCartId(): int
    {
        $class = self::CART_HELPER;

        if (!class_exists($class)) {
            return 0;
        }

        try {
            $cart = $class::getInstance()->getCart(0, false);

            return (int) ($cart->j2commerce_cart_id ?? 0);
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * Evaluate a J2Commerce checkout request.
     *
     * @param   Input     $input          Request input
     * @param   object    $session        Session with get($name, $default), set($name, $value), remove($name)
     * @param   Registry  $privacyParams  Params of the privacy plugin
     * @param   int       $cartId         Current J2Commerce cart ID
     * @param   string    $formToken      Expected form token name
     *
     * @return  array{type: string, message: string}|null  Null lets J2Commerce handle the request.
     */
    public function handleCheckoutRequest(Input $input, object $session, Registry $privacyParams, int $cartId, string $formToken): ?array
    {
        if ($input->getCmd('option', '') !== 'com_j2commerce' || !(int) $privacyParams->get('show_consent_checkbox', 1)) {
            return null;
        }

        $task     = self::resolveTask($input);
        $required = (bool) (int) $privacyParams->get('consent_required', 1);

        if ($task === self::TASK_VALIDATE) {
            // Same token rule as J2Commerce's CheckoutController::validateAjaxToken(); without a
            // valid token J2Commerce rejects the request itself and nothing is stored here.
            if ($input->getMethod() !== 'POST' || !self::hasValidToken($input, $formToken)) {
                return null;
            }

            $decision = self::decide($privacyParams, $input->post->getString(self::CONSENT_FIELD, '') === '1');

            // Without a cart the consent cannot be bound to a purchase: nothing is stored, and a
            // required consent is refused like an unticked one.
            if ($decision === self::DECISION_RECORD && $cartId <= 0) {
                $session->remove(self::SESSION_CONSENT);

                return $required ? ['type' => self::RESPONSE_STEP_ERROR, 'message' => self::requiredMessage()] : null;
            }

            if ($decision === self::DECISION_RECORD) {
                $session->set(self::SESSION_CONSENT, ['cart' => $cartId, 'at' => time()]);

                return null;
            }

            $session->remove(self::SESSION_CONSENT);

            return $decision === self::DECISION_REJECT
                ? ['type' => self::RESPONSE_STEP_ERROR, 'message' => self::requiredMessage()]
                : null;
        }

        if (($task !== self::TASK_CONFIRM && $task !== self::TASK_CONFIRM_PAYMENT)
            || !$required
            || self::hasConsentForCart($session, $cartId)
        ) {
            return null;
        }

        if ($task === self::TASK_CONFIRM) {
            return ['type' => self::RESPONSE_CONFIRM_ERROR, 'message' => self::requiredMessage()];
        }

        // confirmPayment: off-site gateway returns arrive as GET without form data and are not refused.
        if ($input->getMethod() !== 'POST') {
            return null;
        }

        $isAjax = $input->getString('paction', '') === 'process'
            || strtolower($input->server->getString('HTTP_X_REQUESTED_WITH', '')) === 'xmlhttprequest';

        return [
            'type'    => $isAjax ? self::RESPONSE_PAYMENT_ERROR : self::RESPONSE_REDIRECT,
            'message' => self::requiredMessage(),
        ];
    }

    public function onAfterRoute(EventInterface $event): void
    {
        $app = $this->getApplication();

        if (!$app || !$app->isClient('site')) {
            return;
        }

        $input  = $app->getInput();
        $option = $input->getCmd('option');

        // MyProfile: the privacy plugin group is not imported in the frontend. Loading its
        // language here also serves MyProfile overrides deployed by earlier versions.
        if (\in_array($option, ['com_j2commerce', 'com_j2store'], true)
            && $input->getCmd('view') === 'myprofile'
            && $this->getPrivacyParams() !== null
        ) {
            $app->getLanguage()->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce')
                || $app->getLanguage()->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR);
        }

        if ($option !== 'com_j2commerce'
            || !\in_array(self::resolveTask($input), [self::TASK_VALIDATE, self::TASK_CONFIRM, self::TASK_CONFIRM_PAYMENT], true)
        ) {
            return;
        }

        $privacyParams = $this->getPrivacyParams();

        if ($privacyParams === null) {
            return;
        }

        $response = $this->handleCheckoutRequest($input, $app->getSession(), $privacyParams, self::currentCartId(), Session::getFormToken());

        if ($response === null) {
            // Placing the order (POST) or returning from an off-site gateway (GET): remember order,
            // consent cart and request data now (J2Commerce clears the checkout session in the
            // controller) and record the consent after J2Commerce has handled the request.
            $captured = $this->capturePlacement(
                $input,
                $this->getSessionConsentCartId(),
                (string) $app->getUserState('j2commerce.order_id', ''),
                Session::getFormToken()
            );

            if ($captured !== null) {
                register_shutdown_function(function () use ($captured): void {
                    try {
                        $this->recordAcceptedOrderConsent($captured);
                    } catch (\Throwable $e) {
                        // Never break the checkout because the consent log failed.
                        Log::add('Checkout consent could not be recorded: ' . $e->getMessage(), Log::WARNING, 'plg_system_j2commerceprivacy');
                    }
                });
            }

            return;
        }

        switch ($response['type']) {
            case self::RESPONSE_STEP_ERROR:
                // J2Commerce's checkout script shows error keys next to the element with that id.
                $this->sendAndClose(json_encode(['error' => [self::CONSENT_FIELD => $response['message']]]), 'application/json');
                break;

            case self::RESPONSE_PAYMENT_ERROR:
                $this->sendAndClose(json_encode(['success' => false, 'error' => $response['message']]), 'application/json');
                break;

            case self::RESPONSE_CONFIRM_ERROR:
                // The confirm step is loaded as HTML into the checkout.
                $this->sendAndClose(
                    '<div class="alert alert-danger" role="alert">' . htmlspecialchars($response['message'], ENT_QUOTES, 'UTF-8') . '</div>',
                    'text/html'
                );
                break;

            default:
                $app->enqueueMessage($response['message'], 'warning');
                $app->redirect(Route::_('index.php?option=com_j2commerce&view=checkout', false));
        }
    }

    /**
     * Render the consent checkbox in J2Commerce's shipping & payment step.
     */
    public function onJ2CommerceAfterDisplayShippingPayment(EventInterface $event): void
    {
        $privacyParams = $this->getPrivacyParams();

        if ($privacyParams === null || !(int) $privacyParams->get('show_consent_checkbox', 1) || !method_exists($event, 'addResult')) {
            return;
        }

        self::markCheckboxRendered();
        $event->addResult(self::renderConsentCheckbox($privacyParams));
    }

    /**
     * Data of a checkout.confirmPayment request that may complete an order, read before
     * J2Commerce's controller runs.
     *
     * Data path in J2Commerce 6 (CheckoutController, pin 7edb6e11): confirm() stores the saved order
     * number with setUserState('j2commerce.order_id', ...) (L1807, context orders L1659);
     * confirmPayment() reads it with getUserState('j2commerce.order_id') (L1962), checks the form
     * token for POST requests itself, runs AfterOrderValidate (L2047) and the payment plugins, and
     * clears the checkout session (clearCartAndSession(), CheckoutCleanup event) and the user state
     * (L2377) at the end. The order number and the consent are therefore captured here.
     *
     * - POST (the shopper places the order): only with a valid form token.
     * - GET (return from an off-site gateway): no form token exists; the order must still pass the
     *   checks of recordAcceptedOrderConsent().
     *
     * @return  array{order_id: string, cart_id: int, ip: string, user_agent: string}|null
     */
    public function capturePlacement(Input $input, ?int $consentCartId, string $orderId, string $formToken): ?array
    {
        if ($consentCartId === null
            || $consentCartId <= 0
            || $input->getCmd('option', '') !== 'com_j2commerce'
            || self::resolveTask($input) !== self::TASK_CONFIRM_PAYMENT
            || !class_exists(ConsentRepository::class)
            || !ConsentRepository::isValidOrderId($orderId)
        ) {
            return null;
        }

        $method = $input->getMethod();

        if ($method === 'POST' && !self::hasValidToken($input, $formToken)) {
            return null;
        }

        if ($method !== 'POST' && $method !== 'GET') {
            return null;
        }

        return [
            'order_id'   => $orderId,
            'cart_id'    => $consentCartId,
            'ip'         => $this->getClientIp(),
            'user_agent' => $this->getClientUserAgent(),
        ];
    }

    /**
     * Record the consent once J2Commerce has accepted the order: the order exists, was created from
     * the cart the consent was given for, and is no longer incomplete (order_state_id 5, the state
     * of an order saved by the confirmation step; CheckoutController L1790). A rejected order
     * (AfterOrderValidate, failed payment) stays incomplete and gets no record. Called after the
     * controller (shutdown), so redirects and $app->close() are covered.
     *
     * @param   array{order_id: string, cart_id: int, ip: string, user_agent: string}  $captured
     *
     * @return  array{id: int, created: bool}|null
     */
    public function recordAcceptedOrderConsent(array $captured): ?array
    {
        $orderId = (string) ($captured['order_id'] ?? '');

        if (!class_exists(ConsentRepository::class) || !ConsentRepository::isValidOrderId($orderId)) {
            return null;
        }

        $db    = $this->getDatabase();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName(['order_id', 'user_id', 'cart_id', 'order_state_id']))
            ->from($db->quoteName('#__j2commerce_orders'))
            ->where($db->quoteName('order_id') . ' = :orderid')
            ->bind(':orderid', $orderId)
            ->setLimit(1);
        $db->setQuery($query);
        $order = $db->loadObject();

        if (!$order
            || (int) $order->cart_id !== (int) ($captured['cart_id'] ?? 0)
            || (int) $order->order_state_id === self::ORDER_STATE_INCOMPLETE
            || (int) $order->order_state_id <= 0
        ) {
            return null;
        }

        return $this->recordConsentForOrder($order, (string) ($captured['ip'] ?? ''), (string) ($captured['user_agent'] ?? ''));
    }
    public function onJ2CommerceCheckoutCleanup(EventInterface $event): void
    {
        $app = $this->getApplication();

        if ($app && $app->isClient('site')) {
            $app->getSession()->remove(self::SESSION_CONSENT);
        }
    }

    /**
     * Write (or find) the consent record for a saved order.
     *
     * @return  array{id: int, created: bool}|null
     */
    public function recordConsentForOrder(object $order, string $ipAddress, string $userAgent): ?array
    {
        if (!class_exists(ConsentRepository::class)) {
            return null;
        }

        $orderId    = (string) ($order->order_id ?? '');
        $repository = new ConsentRepository($this->getDatabase());

        if (!ConsentRepository::isValidOrderId($orderId)) {
            return null;
        }

        return $repository->ensureOrderConsent(
            $orderId,
            (int) ($order->user_id ?? 0),
            $repository->buildBody($orderId, $ipAddress, $userAgent)
        );
    }


    /**
     * Cart ID of the ticked consent stored in the session, or null.
     */
    protected function getSessionConsentCartId(): ?int
    {
        $app = $this->getApplication();

        if (!$app || !$app->isClient('site')) {
            return null;
        }

        $consent = $app->getSession()->get(self::SESSION_CONSENT);

        return \is_array($consent) && isset($consent['cart']) ? (int) $consent['cart'] : null;
    }

    protected function getClientIp(): string
    {
        // Respects the "Behind Load Balancer" setting (X-Forwarded-For).
        return (string) IpHelper::getIp();
    }

    protected function getClientUserAgent(): string
    {
        return (string) $this->getApplication()->getInput()->server->getString('HTTP_USER_AGENT', '');
    }

    /**
     * Params of the enabled privacy plugin, or null when it is not installed/enabled.
     */
    protected function getPrivacyParams(): ?Registry
    {
        $plugin = PluginHelper::getPlugin('privacy', 'j2commerce');

        return empty($plugin) ? null : new Registry($plugin->params ?? '{}');
    }

    private static function hasConsentForCart(object $session, int $cartId): bool
    {
        $consent = $session->get(self::SESSION_CONSENT);

        return $cartId > 0 && \is_array($consent) && isset($consent['cart']) && (int) $consent['cart'] === $cartId;
    }

    private static function hasValidToken(Input $input, string $formToken): bool
    {
        return $input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum') === $formToken
            || (bool) $input->post->get($formToken, '', 'alnum');
    }

    private static function requiredMessage(): string
    {
        $language = self::currentLanguage();

        $language->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR)
            || $language->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');

        return $language->_('PLG_PRIVACY_J2COMMERCE_CONSENT_REQUIRED_ERROR');
    }

    private function sendAndClose(string $body, string $contentType): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: ' . $contentType . '; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        echo $body;
        $this->getApplication()->close();
    }

    /**
     * Current language: the application's, or (CLI without application) a language object of the
     * default site language. Factory::getLanguage() is deprecated.
     */
    private static function currentLanguage(): Language
    {
        try {
            $app = Factory::getApplication();

            if (method_exists($app, 'getLanguage')) {
                return $app->getLanguage();
            }
        } catch (\Throwable $e) {
            // No application (CLI script).
        }

        static $fallback = null;

        if ($fallback === null) {
            try {
                $tag = (string) ComponentHelper::getParams('com_languages')->get('site', 'en-GB');
            } catch (\Throwable $e) {
                $tag = 'en-GB';
            }

            $fallback = Factory::getContainer()->get(LanguageFactoryInterface::class)->createLanguage($tag);
        }

        return $fallback;
    }
}
