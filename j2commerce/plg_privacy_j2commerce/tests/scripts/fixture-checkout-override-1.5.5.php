<?php
/**
 * J2Commerce 6 Checkout — Shipping & Payment Step
 * Template override for plg_privacy_j2commerce
 *
 * Adds a privacy consent checkbox before the "Continue" button.
 * Based on com_j2commerce/tmpl/checkout/bootstrap5/default_shipping_payment.php.
 *
 * WHY THIS OVERRIDE IS NEEDED
 * J2Commerce's plugin events only include plugins in the 'j2store' group.
 * The privacy plugin is in the 'privacy' group (required for Joomla's native
 * com_privacy integration). There is no hook available to a privacy-group
 * plugin inside the J2Commerce checkout flow — hence this template override.
 *
 * INSTALLATION
 * Automatically copied to:
 *   templates/{active-template}/html/com_j2commerce/checkout/default_shipping_payment.php
 * on first install. Never overwritten on updates.
 *
 * @package     J2Commerce Privacy Plugin
 * @copyright   Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
 * @license     Proprietary
 */

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

if (!class_exists('J2Commerce\Component\J2commerce\Administrator\Helper\J2CommerceHelper')) {
    return;
}

use J2Commerce\Component\J2commerce\Administrator\Helper\J2CommerceHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Router\Route;
use Joomla\CMS\Uri\Uri;

/** @var \J2Commerce\Component\J2commerce\Site\View\Checkout\HtmlView $this */

$showShipping        = $this->showShipping ?? false;
$showShippingMethods = $this->showShippingMethods ?? false;
$shippingRates       = $this->shippingRates ?? [];
$shippingValues      = $this->shippingValues ?? [];
$paymentMethods      = $this->paymentMethods ?? [];
$selectedPayment     = $this->selectedPayment ?? '';
$showPayment         = $this->showPayment ?? true;
$showTerms           = $this->showTerms ?? 0;
$termsDisplayType    = $this->termsDisplayType ?? 'link';
$currency            = J2CommerceHelper::currency();

// Privacy consent configuration
$_privacyPlugin    = PluginHelper::getPlugin('privacy', 'j2commerce');
$_privacyEnabled   = !empty($_privacyPlugin);
$_privacyParams    = $_privacyEnabled ? new \Joomla\Registry\Registry($_privacyPlugin->params) : null;
$_showConsent      = $_privacyEnabled && $_privacyParams->get('show_consent_checkbox', 1);
$_consentRequired  = $_privacyEnabled && $_privacyParams->get('consent_required', 1);
$_consentText      = $_privacyEnabled ? $_privacyParams->get('consent_text', Text::_('PLG_PRIVACY_J2COMMERCE_CONSENT_CHECKBOX_DEFAULT')) : '';
$_privacyArticleId = $_privacyEnabled ? (int) $_privacyParams->get('privacy_article', 0) : 0;

if ($_showConsent && $_privacyArticleId) {
    $_privacyLink     = Route::_('index.php?option=com_content&view=article&id=' . $_privacyArticleId);
    $_privacyLinkHtml = '<a href="' . $_privacyLink . '" target="_blank" rel="noopener noreferrer">'
        . Text::_('PLG_PRIVACY_J2COMMERCE_POLICY_LINK') . '</a>';
    $_consentText     = str_replace('{privacy_policy}', $_privacyLinkHtml, $_consentText);
}
?>
<div class="j2commerce-shipping-payment">

    <?php if ($showShippingMethods && !empty($shippingRates)) : ?>
    <h5 class="mb-1"><?php echo Text::_('COM_J2COMMERCE_SHIPPING_METHOD'); ?></h5>
    <div id="shipping_error_div"></div>

    <div class="shipping-methods-group list-group mb-4 mt-3" role="radiogroup" aria-label="<?php echo Text::_('COM_J2COMMERCE_SHIPPING_METHOD', true); ?>">
        <input type="hidden" name="shippingrequired" value="1">
        <?php foreach ($shippingRates as $i => $rate) : ?>
            <?php
            $rateName      = $rate['name'] ?? $rate->name ?? '';
            $ratePrice     = $rate['price'] ?? $rate->price ?? 0;
            $rateCode      = $rate['code'] ?? $rate->code ?? '';
            $rateElement   = $rate['element'] ?? $rate->element ?? '';
            $rateTax       = $rate['tax'] ?? $rate->tax ?? 0;
            $rateTaxClassId = $rate['tax_class_id'] ?? $rate->tax_class_id ?? 0;
            $rateExtra     = $rate['extra'] ?? $rate->extra ?? '';
            $rateImage     = $rate['image'] ?? $rate->image ?? '';
            $rateDesc      = $rate['desc'] ?? $rate->desc ?? '';
            $isSelected    = (!empty($shippingValues['shipping_name']) && $shippingValues['shipping_name'] === $rateName)
                || (\count($shippingRates) === 1);
            ?>
            <label class="shipping-method-item list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" for="shipping-rate-<?php echo $i; ?>">
                <input class="form-check-input flex-shrink-0 mt-0" type="radio" name="shipping_plugin" value="<?php echo htmlspecialchars($rateElement); ?>" id="shipping-rate-<?php echo $i; ?>" <?php echo $isSelected ? 'checked' : ''; ?>>
                <?php if (!empty($rateImage)) : ?>
                    <img src="<?php echo htmlspecialchars($rateImage); ?>" alt="" class="flex-shrink-0 shipping-method-image">
                <?php endif; ?>
                <div class="shipping-method-display flex-grow-1">
                    <div class="shipping-method-name fw-medium"><?php echo htmlspecialchars($rateName); ?></div>
                    <?php if (!empty($rateDesc)) : ?>
                        <div class="shipping-method-desc"><small class="text-muted"><?php echo htmlspecialchars($rateDesc); ?></small></div>
                    <?php endif; ?>
                </div>
                <span class="fw-semibold flex-shrink-0"><?php echo $currency->format($ratePrice); ?></span>
                <input type="hidden" name="shipping_name" value="<?php echo htmlspecialchars($rateName); ?>">
                <input type="hidden" name="shipping_price" value="<?php echo (float) $ratePrice; ?>">
                <input type="hidden" name="shipping_code" value="<?php echo htmlspecialchars($rateCode); ?>">
                <input type="hidden" name="shipping_tax" value="<?php echo (float) $rateTax; ?>">
                <input type="hidden" name="shipping_tax_class_id" value="<?php echo (int) $rateTaxClassId; ?>">
                <input type="hidden" name="shipping_extra" value="<?php echo htmlspecialchars($rateExtra); ?>">
            </label>
        <?php endforeach; ?>
    </div>
    <?php elseif ($showShippingMethods) : ?>
        <input type="hidden" name="shippingrequired" value="0">
    <?php endif; ?>

    <?php if ($showPayment) : ?>
        <h5 class="mb-1"><?php echo Text::_('COM_J2COMMERCE_PAYMENT_METHOD'); ?></h5>
        <p class="text-muted small mb-3"><?php echo Text::_('COM_J2COMMERCE_CHECKOUT_TRANSACTIONS_SECURE'); ?></p>

        <div class="payment-methods-group list-group mb-4" role="radiogroup" aria-label="<?php echo Text::_('COM_J2COMMERCE_PAYMENT_METHOD', true); ?>">
            <?php if (!empty($paymentMethods)) : ?>
                <?php foreach ($paymentMethods as $i => $method) : ?>
                    <?php
                    $element    = $method['element'] ?? $method->element ?? '';
                    $name       = $method['name'] ?? $method->name ?? $element;
                    $image      = $method['image'] ?? $method->image ?? '';
                    $isSelected = ($element === $selectedPayment) || (\count($paymentMethods) === 1);
                    ?>
                    <label class="payment-method-item list-group-item list-group-item-action d-flex align-items-center gap-3 py-3" for="payment-method-<?php echo $i; ?>">
                        <input class="form-check-input flex-shrink-0 mt-0" type="radio" name="payment_plugin" value="<?php echo htmlspecialchars($element); ?>" id="payment-method-<?php echo $i; ?>" <?php echo $isSelected ? 'checked' : ''; ?>>
                        <div class="fw-medium flex-grow-1"><?php echo htmlspecialchars($name); ?></div>

                        <?php echo J2CommerceHelper::plugin()->eventWithHtml('BeforeCheckoutPaymentImage', [$method, 'onJ2Commerce'])->getArgument('html', ''); ?>
                        <?php if (method_exists('J2Commerce\Component\J2commerce\Administrator\Helper\J2CommerceHelper', 'getPaymentCardIcons')) : ?>
                        <?php echo J2CommerceHelper::getPaymentCardIcons($element); ?>
                        <?php endif; ?>

                        <?php if (!empty($image)) : ?>
                            <img src="<?php echo htmlspecialchars($image); ?>" alt="" class="flex-shrink-0" style="height:24px;">
                        <?php endif; ?>
                    </label>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="alert alert-warning">
                    <?php echo Text::_('COM_J2COMMERCE_CHECKOUT_NO_PAYMENT_METHODS'); ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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

    <div class="mt-3">
        <button type="button" id="button-payment-method" class="btn btn-primary btn-checkout-step">
            <?php echo Text::_('COM_J2COMMERCE_CHECKOUT_CONTINUE'); ?>
        </button>
    </div>
</div>
