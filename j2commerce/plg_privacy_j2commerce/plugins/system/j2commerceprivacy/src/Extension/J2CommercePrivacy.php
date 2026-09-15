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
use Joomla\CMS\Factory;
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

/**
 * Server-side checkout consent for J2Commerce 6.
 *
 * WHY A SYSTEM PLUGIN
 * The privacy plugin (group "privacy") is only imported by com_privacy. J2Commerce 6 runs its
 * AJAX checkout without importing that group, so the privacy plugin cannot see the checkout.
 * System plugins are imported on every request, and J2Commerce 6 dispatches its
 * onJ2Commerce* events on the application dispatcher, so this plugin receives them.
 *
 * FLOW (state lives in the Joomla session and is bound to the J2Commerce cart ID)
 * 1. The checkout override calls markCheckboxRendered() while it renders the checkbox.
 * 2. checkout.shippingPaymentMethodValidate: ticked -> consent stored for the cart;
 *    not ticked, rendered for this cart and required -> JSON field error.
 * 3. checkout.confirm and checkout.confirmPayment (browser POST): rendered and required but no
 *    consent for this cart -> refused. Covers zero-total orders and requests that skip step 2.
 * 4. onJ2CommerceAfterSaveOrder: consent for the order's cart -> one #__privacy_consents record.
 * 5. onJ2CommerceCheckoutCleanup: session state is cleared.
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

    public const SESSION_RENDERED = 'plg_system_j2commerceprivacy_rendered';
    public const SESSION_CONSENT  = 'plg_system_j2commerceprivacy_consent';

    public const TASK_VALIDATE        = 'checkout.shippingpaymentmethodvalidate';
    public const TASK_CONFIRM         = 'checkout.confirm';
    public const TASK_CONFIRM_PAYMENT = 'checkout.confirmpayment';

    private const CART_HELPER = 'J2Commerce\\Component\\J2commerce\\Administrator\\Helper\\CartHelper';

    public static function getSubscribedEvents(): array
    {
        return [
            'onAfterRoute'                => 'onAfterRoute',
            'onJ2CommerceAfterSaveOrder'  => 'onJ2CommerceAfterSaveOrder',
            'onJ2CommerceCheckoutCleanup' => 'onJ2CommerceCheckoutCleanup',
        ];
    }

    /**
     * Decide what to do with a submitted shipping & payment step.
     *
     * @param   Registry  $privacyParams     Params of the privacy plugin (plg_privacy_j2commerce).
     * @param   bool      $consentTicked     The consent checkbox was posted with value 1.
     * @param   bool      $checkboxRendered  The override rendered the checkbox for the current cart (session).
     */
    public static function decide(Registry $privacyParams, bool $consentTicked, bool $checkboxRendered): string
    {
        if (!(int) $privacyParams->get('show_consent_checkbox', 1)) {
            return self::DECISION_IGNORE;
        }

        if ($consentTicked) {
            return self::DECISION_RECORD;
        }

        // Only enforce when the checkbox was actually shown; a template without the override
        // must not block every checkout.
        if ($checkboxRendered && (int) $privacyParams->get('consent_required', 1)) {
            return self::DECISION_REJECT;
        }

        return self::DECISION_IGNORE;
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
     * Called by the J2Commerce 6 checkout override while it renders the consent checkbox.
     * Records server-side (session, bound to the current cart) that the checkbox was shown.
     */
    public static function markCheckboxRendered(bool $required): void
    {
        try {
            Factory::getApplication()->getSession()->set(
                self::SESSION_RENDERED,
                ['cart' => self::currentCartId(), 'required' => $required]
            );
        } catch (\Throwable $e) {
            Log::add('Consent checkbox state could not be stored: ' . $e->getMessage(), Log::WARNING, 'plg_system_j2commerceprivacy');
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

        $task = self::resolveTask($input);

        if ($task === self::TASK_VALIDATE) {
            // Same token rule as J2Commerce's CheckoutController::validateAjaxToken(); without a
            // valid token J2Commerce rejects the request itself and nothing is stored here.
            if ($input->getMethod() !== 'POST' || !self::hasValidToken($input, $formToken)) {
                return null;
            }

            $decision = self::decide(
                $privacyParams,
                $input->post->getString(self::CONSENT_FIELD, '') === '1',
                self::isRenderedForCart($session, $cartId)
            );

            if ($decision === self::DECISION_RECORD) {
                $session->set(self::SESSION_CONSENT, ['cart' => $cartId, 'at' => time()]);

                return null;
            }

            $session->remove(self::SESSION_CONSENT);

            return $decision === self::DECISION_REJECT
                ? ['type' => self::RESPONSE_STEP_ERROR, 'message' => self::requiredMessage()]
                : null;
        }

        if ($task !== self::TASK_CONFIRM && $task !== self::TASK_CONFIRM_PAYMENT) {
            return null;
        }

        if (!self::isRenderedForCart($session, $cartId)
            || !(int) $privacyParams->get('consent_required', 1)
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

        $input = $app->getInput();

        if ($input->getCmd('option') !== 'com_j2commerce'
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

    public function onJ2CommerceAfterSaveOrder(EventInterface $event): void
    {
        $arguments = $event->getArguments();
        $order     = $arguments[0] ?? $arguments['order'] ?? null;

        if (!\is_object($order) || $this->getPrivacyParams() === null || !$this->hasCheckoutConsent($order)) {
            return;
        }

        try {
            $this->recordConsentForOrder($order, $this->getClientIp(), $this->getClientUserAgent());
        } catch (\Throwable $e) {
            // Never break order creation because the consent log failed.
            Log::add('Checkout consent could not be recorded: ' . $e->getMessage(), Log::WARNING, 'plg_system_j2commerceprivacy');
        }
    }

    public function onJ2CommerceCheckoutCleanup(EventInterface $event): void
    {
        $app = $this->getApplication();

        if ($app && $app->isClient('site')) {
            $session = $app->getSession();
            $session->remove(self::SESSION_CONSENT);
            $session->remove(self::SESSION_RENDERED);
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
     * Whether the session holds a ticked consent for the cart the order was created from.
     */
    protected function hasCheckoutConsent(object $order): bool
    {
        $cartId = $this->getSessionConsentCartId();

        return $cartId !== null && $cartId === (int) ($order->cart_id ?? 0);
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
        return (string) $this->getApplication()->getInput()->server->getString('REMOTE_ADDR', '');
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

    private static function isRenderedForCart(object $session, int $cartId): bool
    {
        $rendered = $session->get(self::SESSION_RENDERED);

        return \is_array($rendered) && isset($rendered['cart']) && (int) $rendered['cart'] === $cartId;
    }

    private static function hasConsentForCart(object $session, int $cartId): bool
    {
        $consent = $session->get(self::SESSION_CONSENT);

        return \is_array($consent) && isset($consent['cart']) && (int) $consent['cart'] === $cartId;
    }

    private static function hasValidToken(Input $input, string $formToken): bool
    {
        return $input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum') === $formToken
            || (bool) $input->post->get($formToken, '', 'alnum');
    }

    private static function requiredMessage(): string
    {
        $language = Factory::getLanguage();

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
}
