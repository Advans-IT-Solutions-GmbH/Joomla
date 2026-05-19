<?php
/**
 * J2Commerce Checkout — Shipping & Payment Step
 * Template override for plg_privacy_j2commerce
 *
 * This file is a copy of the J2Commerce default template with one addition:
 * the privacy consent checkbox is rendered before the "Continue" button via
 * PluginHelper, without any HTML patching or regex.
 *
 * WHY THIS OVERRIDE IS NEEDED
 * J2Commerce's eventWithHtml() only imports plugins in the 'j2store' group.
 * The privacy plugin is in the 'privacy' group (required for Joomla's native
 * com_privacy integration). There is no hook available to a privacy-group
 * plugin inside the J2Commerce checkout flow — hence this template override.
 *
 * INSTALLATION
 * This file is automatically copied to:
 *   templates/{active-template}/html/com_j2store/checkout/default_shipping_payment.php
 * on first install. On updates it is never overwritten.
 * If you use a custom template, copy this file manually to the path above
 * and adapt it to your template's markup.
 *
 * @package     J2Commerce Privacy Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */
defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\Router\Route;

// Load privacy plugin params for consent configuration
$_privacyPlugin       = PluginHelper::getPlugin('privacy', 'j2commerce');
$_privacyEnabled      = !empty($_privacyPlugin);
$_privacyParams       = $_privacyEnabled ? new \Joomla\Registry\Registry($_privacyPlugin->params) : null;
$_showConsent         = $_privacyEnabled && $_privacyParams->get('show_consent_checkbox', 1);
$_consentRequired     = $_privacyEnabled && $_privacyParams->get('consent_required', 1);
$_consentText         = $_privacyEnabled ? $_privacyParams->get('consent_text', Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_DEFAULT_TEXT')) : '';
$_privacyArticleId    = $_privacyEnabled ? (int) $_privacyParams->get('privacy_article', 0) : 0;

if ($_showConsent && $_privacyArticleId) {
    $_privacyLink    = Route::_('index.php?option=com_content&view=article&id=' . $_privacyArticleId);
    $_privacyLinkHtml = '<a href="' . $_privacyLink . '" target="_blank" rel="noopener noreferrer">'
        . Text::_('PLG_PRIVACY_J2COMMERCE_POLICY_LINK') . '</a>';
    $_consentText = str_replace('{privacy_policy}', $_privacyLinkHtml, $_consentText);
}
?>
<?php echo J2Store::plugin()->eventWithHtml('BeforeDisplayShippingPayment', [$this->order]); ?>

<div class="j2store-checkout-shipping-payment">

    <?php // ── Shipping methods ──────────────────────────────────────────── ?>
    <?php if (!empty($this->shippingPlugins)) : ?>
        <div class="j2store-shipping-methods mb-4">
            <h4><?php echo Text::_('J2STORE_CHECKOUT_SHIPPING_METHOD'); ?></h4>
            <?php foreach ($this->shippingPlugins as $plugin) : ?>
                <?php echo J2Store::plugin()->eventWithHtml('BeforeDisplayShippingMethod', [$plugin->element, $this->order]); ?>
                <div class="j2store-shipping-method">
                    <?php echo $plugin->html; ?>
                </div>
                <?php echo J2Store::plugin()->eventWithHtml('AfterDisplayShippingMethod', [$plugin->element, $this->order]); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php // ── Payment methods ───────────────────────────────────────────── ?>
    <?php if (!empty($this->paymentPlugins)) : ?>
        <div class="j2store-payment-methods mb-4">
            <h4><?php echo Text::_('J2STORE_CHECKOUT_PAYMENT_METHOD'); ?></h4>
            <?php foreach ($this->paymentPlugins as $plugin) : ?>
                <?php echo J2Store::plugin()->eventWithHtml('BeforeDisplayPaymentMethod', [$plugin->element, $this->order]); ?>
                <div class="j2store-payment-method">
                    <?php echo $plugin->html; ?>
                </div>
                <?php echo J2Store::plugin()->eventWithHtml('AfterDisplayPaymentMethod', [$plugin->element, $this->order]); ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php echo J2Store::plugin()->eventWithHtml('CheckoutShippingPayment', [$this->order]); ?>

    <?php // ── Privacy consent checkbox ──────────────────────────────────── ?>
    <?php if ($_showConsent) : ?>
        <div class="j2commerce-privacy-consent mb-3">
            <div class="form-check">
                <input type="checkbox"
                       class="form-check-input"
                       id="j2commerce_privacy_consent"
                       name="j2commerce_privacy_consent"
                       value="1"
                       <?php echo $_consentRequired ? 'required' : ''; ?>>
                <label class="form-check-label" for="j2commerce_privacy_consent">
                    <?php echo $_consentText; ?>
                    <?php if ($_consentRequired) : ?>
                        <span class="text-danger" aria-hidden="true">*</span>
                    <?php endif; ?>
                </label>
            </div>
        </div>
        <?php if ($_consentRequired) : ?>
            <div id="j2commerce-consent-validator"
                 data-error="<?php echo $this->escape(Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_REQUIRED_ERROR')); ?>"
                 style="display:none;"></div>
            <?php
            // Load external consent validator — avoids inline scripts for CSP compatibility
            Factory::getApplication()->getDocument()->getWebAssetManager()
                ->registerAndUseScript(
                    'plg_privacy_j2commerce.consent-validator',
                    Uri::root(true) . '/media/plg_privacy_j2commerce/js/consent-validator.js',
                    [],
                    ['defer' => true]
                );
            ?>
        <?php endif; ?>
    <?php endif; ?>

    <?php // ── Continue button ───────────────────────────────────────────── ?>
    <div class="j2store-checkout-actions mt-3">
        <button type="submit" class="btn btn-primary j2store-checkout-button">
            <?php echo Text::_('J2STORE_CHECKOUT_BTN_CONFIRM_ORDER'); ?>
        </button>
    </div>

</div>

<?php echo J2Store::plugin()->eventWithHtml('AfterDisplayShippingPayment', [$this->order]); ?>
