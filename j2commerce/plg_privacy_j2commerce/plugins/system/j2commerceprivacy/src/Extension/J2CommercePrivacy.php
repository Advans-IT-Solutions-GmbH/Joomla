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
use Joomla\CMS\Log\Log;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\EventInterface;
use Joomla\Event\SubscriberInterface;
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
 * FLOW
 * 1. Step "shipping & payment" (task checkout.shippingPaymentMethodValidate) posts the consent
 *    checkbox. onAfterRoute reads it before J2Commerce handles the request:
 *    ticked -> remember in the session; not ticked but required -> JSON field error.
 * 2. J2Commerce saves the order in checkout.confirm and dispatches onJ2CommerceAfterSaveOrder.
 *    If the session holds a ticked consent, one record per order is written to
 *    #__privacy_consents (no duplicates).
 * 3. onJ2CommerceCheckoutCleanup clears the session flag after the order is finalised.
 */
class J2CommercePrivacy extends CMSPlugin implements SubscriberInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    public const CONSENT_FIELD  = 'j2commerce_privacy_consent';
    public const RENDERED_FIELD = 'j2commerce_privacy_consent_rendered';

    public const DECISION_RECORD = 'record';
    public const DECISION_REJECT = 'reject';
    public const DECISION_IGNORE = 'ignore';

    private const SESSION_NAMESPACE = 'plg_system_j2commerceprivacy';
    private const SESSION_KEY       = 'checkout_consent';
    private const VALIDATE_TASK     = 'checkout.shippingpaymentmethodvalidate';

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
     * @param   bool      $checkboxRendered  The override rendered the checkbox (hidden marker posted).
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

    public function onAfterRoute(EventInterface $event): void
    {
        $app = $this->getApplication();

        if (!$app || !$app->isClient('site')) {
            return;
        }

        $input = $app->getInput();

        if ($input->getCmd('option') !== 'com_j2commerce'
            || strtolower($input->getCmd('task')) !== self::VALIDATE_TASK
            || $input->getMethod() !== 'POST'
        ) {
            return;
        }

        $privacyParams = $this->getPrivacyParams();

        if ($privacyParams === null) {
            return;
        }

        // Same token rule as J2Commerce's CheckoutController::validateAjaxToken(); J2Commerce
        // rejects the request itself when the token is invalid.
        $token = Session::getFormToken();

        if ($input->server->get('HTTP_X_CSRF_TOKEN', '', 'alnum') !== $token
            && !$input->post->get($token, '', 'alnum')
        ) {
            return;
        }

        $decision = self::decide(
            $privacyParams,
            $input->post->getString(self::CONSENT_FIELD, '') === '1',
            $input->post->getString(self::RENDERED_FIELD, '') === '1'
        );

        $session = $app->getSession();

        if ($decision === self::DECISION_RECORD) {
            $session->set(self::SESSION_KEY, time(), self::SESSION_NAMESPACE);

            return;
        }

        $session->clear(self::SESSION_KEY, self::SESSION_NAMESPACE);

        if ($decision === self::DECISION_REJECT) {
            $app->getLanguage()->load('plg_privacy_j2commerce', JPATH_ADMINISTRATOR)
                || $app->getLanguage()->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');

            // J2Commerce's checkout script shows error keys next to the element with that id.
            $this->sendJson([
                'error' => [self::CONSENT_FIELD => $app->getLanguage()->_('PLG_PRIVACY_J2COMMERCE_CONSENT_REQUIRED_ERROR')],
            ]);
        }
    }

    public function onJ2CommerceAfterSaveOrder(EventInterface $event): void
    {
        if (!$this->hasCheckoutConsent()) {
            return;
        }

        $arguments = $event->getArguments();
        $order     = $arguments[0] ?? $arguments['order'] ?? null;

        if (!\is_object($order) || $this->getPrivacyParams() === null) {
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
            $app->getSession()->clear(self::SESSION_KEY, self::SESSION_NAMESPACE);
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

    protected function hasCheckoutConsent(): bool
    {
        $app = $this->getApplication();

        return $app && $app->isClient('site')
            && (int) $app->getSession()->get(self::SESSION_KEY, 0, self::SESSION_NAMESPACE) > 0;
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

    private function sendJson(array $payload): void
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate');
        }

        echo json_encode($payload);
        $this->getApplication()->close();
    }
}
