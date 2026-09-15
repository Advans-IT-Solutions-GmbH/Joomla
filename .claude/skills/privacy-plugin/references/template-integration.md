# Template Integration

## Rendering Mechanism

Checkout consent, the MyProfile Privacy tab and the address delete buttons are rendered only via the bundled template overrides. `onAfterRender` was removed; there is no HTML-injection fallback.

## Bundled Override Files

Shipped under `overrides/com_j2store/` (J2Commerce 4) **and** `overrides/com_j2commerce/` (J2Commerce 6):

```
checkout/default_shipping_payment.php
myprofile/default.php
myprofile/default_addresses.php
```

`script.php` (`copyTemplateOverrides()`) copies them on **first install only** into every frontend template, for both components:

```
templates/{template}/html/com_j2store/...
templates/{template}/html/com_j2commerce/...
```

Existing files are never overwritten (listed as skipped in the post-installation message). On updates nothing is copied.

`myprofile/default.php` calls `$this->loadTemplate('privacy')` for the Privacy tab; no `default_privacy.php` is shipped with the plugin.

## Requirements

- Bootstrap 5 template (the override uses BS5 tab markup)
- Plugin installed and enabled

## How `default.php` Activates the Tab

```php
$_privacyPlugin  = PluginHelper::getPlugin('privacy', 'j2commerce');
$_privacyEnabled = !empty($_privacyPlugin);
$_privacyTabId   = 'j2commerce-privacy-tab';
```

The tab is rendered when the plugin is enabled. If the plugin is disabled or not installed, the tab is hidden without errors.

## Checkout Consent

`default_shipping_payment.php` reads plugin params via `PluginHelper::getPlugin('privacy','j2commerce')`:

```php
$_privacyPlugin   = PluginHelper::getPlugin('privacy', 'j2commerce');
$_privacyEnabled  = !empty($_privacyPlugin);
$_privacyParams   = $_privacyEnabled ? new \Joomla\Registry\Registry($_privacyPlugin->params) : null;
$_showConsent     = $_privacyEnabled && $_privacyParams->get('show_consent_checkbox', 1);
$_consentRequired = $_privacyEnabled && $_privacyParams->get('consent_required', 1);
```

When `consent_required` is set, the checkbox gets the HTML `required` attribute and `media/plg_privacy_j2commerce/js/consent-validator.js` is loaded. The script listens to `submit` events of `form[action*="j2store"]` / `form.j2store-checkout-form` and blocks submission with an alert if the checkbox is unchecked. There is no server-side consent check.

- **J2Commerce 4 (`com_j2store`):** the step uses a `type="submit"` button, so an unchecked required checkbox blocks submission.
- **J2Commerce 6 (`com_j2commerce`):** the checkbox is displayed, but the "Continue" button (`#button-payment-method`) is a `type="button"` handled by J2Commerce's own JavaScript (no native form submit). Neither `consent-validator.js` nor `required` blocks the step: consent is displayed only and not enforced.

This plugin does not record consent in `#__privacy_consents`.
