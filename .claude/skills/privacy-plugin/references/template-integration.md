# Template Integration

## Rendering Mechanism

The MyProfile Privacy tab, the address delete buttons and (J2Commerce 4) the checkout consent checkbox are rendered via the bundled template overrides. On J2Commerce 6 the checkout consent checkbox is rendered by the bundled system plugin through the J2Commerce event `AfterDisplayShippingPayment` (see below). `onAfterRender` was removed; there is no HTML-injection fallback.

## Bundled Override Files

Shipped under `overrides/com_j2store/` (J2Commerce 4) **and** `overrides/com_j2commerce/` (J2Commerce 6):

```
checkout/default_shipping_payment.php   (com_j2store only)
myprofile/default.php
myprofile/default_addresses.php
myprofile/default_privacy.php
```

No J2Commerce 6 checkout override ships: the core templates (J2Commerce 6.3.4 or later, `script.php` `MIN_J2COMMERCE_EVENT_VERSION`) fire `AfterDisplayShippingPayment`. The event came with J2Commerce commit `d7992c66` (PR #1109, merged 2026-05-28, after the 6.3.3 bump of 2026-05-27; 6.3.4 bump 2026-06-01). `warnIfJ2CommerceTooOld()` warns for older versions (version from the `com_j2commerce` manifest cache).

`script.php` (`copyTemplateOverrides()`) copies them on **first install** into every frontend template, for both components:

```
templates/{template}/html/com_j2store/...
templates/{template}/html/com_j2commerce/...
```

Existing files are never overwritten (listed as skipped in the post-installation message). On updates only `myprofile/default_privacy.php` is added, and only where the template already has `myprofile/default.php` for an installed component and the file is missing. Install and update warn (`warnOutdatedCheckoutOverrides()`) about J2Commerce 6 checkout overrides in `checkout/`, `checkout/bootstrap5/`, `checkout/uikit/` or `checkout/uikit3/` (J2Commerce before 6.3.6) that neither fire `AfterDisplayShippingPayment` nor contain `j2commerce_privacy_consent`.

`retireBundledCheckoutOverrides()` (install and update) handles `html/com_j2commerce/checkout/default_shipping_payment.php` copies of earlier plugin versions (header marker `Template override for plg_privacy_j2commerce`): SHA-256 (CRLF normalised) in `BUNDLED_CHECKOUT_OVERRIDE_HASHES` and J2Commerce >= 6.3.4 → renamed with `.plg_privacy_j2commerce-disabled` (message); other hash → kept, warning `WARN_CHECKOUT_OVERRIDE_BUNDLED`; older/unknown J2Commerce → kept. Renaming, not deleting: the file stays for comparison. Test 12 uses `tests/scripts/fixture-checkout-override-1.5.5.php` (must stay byte-identical to the 1.5.5 file).

`myprofile/default.php` calls `$this->loadTemplate('privacy')`; `myprofile/default_privacy.php` renders `privacy_tab` via `FileLayout` with the include paths `templates/{template}/html/layouts/plg_privacy_j2commerce/` (and the parent template) before `plugins/privacy/j2commerce/layouts/`.

## Requirements

- Bootstrap 5 template (the override uses BS5 tab markup)
- Plugin installed and enabled

## How `default.php` Activates the Tab

```php
$_privacyOptions = 'Advans\\Plugin\\Privacy\\J2Commerce\\Frontend\\PrivacyOptions';
$_privacyEnabled = class_exists($_privacyOptions) && $_privacyOptions::showPrivacyTab();
$_privacyTabId   = 'j2commerce-privacy-tab';
```

The tab is rendered while the plugin is enabled and `show_privacy_section` is on; then `PrivacyOptions::loadLanguage()` loads the plugin language (tab title `PLG_PRIVACY_J2COMMERCE_MYPROFILE_TAB_TITLE`). `default_addresses.php` gates the delete button with `PrivacyOptions::showDeleteAddress()`, `default_privacy.php` returns early when `show_privacy_section` is off and passes `showExport`/`showDelete` (`show_export_data`/`show_delete_all`) to the layout. The system plugin's `onAfterRoute` also loads the language for `view=myprofile`.

## Checkout Consent

**J2Commerce 6 (verified against J2Commerce 6.6.1, `7edb6e11`):** steps are loaded via AJAX and `J2CommerceDom.adopt()` strips `<script>` tags; the privacy group is not imported during the checkout. The bundled **system** plugin `plg_system_j2commerceprivacy` handles consent.

- Checkbox: `tmpl/checkout/bootstrap5/default_shipping_payment.php` (L131) and `tmpl/checkout/uikit/default_shipping_payment.php` (L125) call `J2CommerceHelper::plugin()->eventWithHtml('AfterDisplayShippingPayment', [$this->order])` before the Continue button. `eventWithHtml()` dispatches `onJ2CommerceAfterDisplayShippingPayment` on the application dispatcher and concatenates the string results (`PluginEvent::addResult()`) into the `html` argument. The listener adds `renderConsentCheckbox()` (id/name `j2commerce_privacy_consent`, no script) and calls `markCheckboxRendered()`, which removes a stored consent.
- Policy link: `Route::_($url, false)` + one `htmlspecialchars()` (with xhtml `Route::_()` already escapes; escaping twice gives `&amp;amp;`). Nothing else (no template check, no request input).
- Session key `plg_system_j2commerceprivacy_consent` = `['cart' => cart ID]` (`CartHelper::getInstance()->getCart(0, false)`).

Flow:

1. `onAfterRoute` calls `handleCheckoutRequest()`; the task is resolved like `ComponentDispatcher` (`controller` + `task` without dot).
   - `checkout.shippingPaymentMethodValidate` (POST, valid form token): ticked, consent for the current cart; unticked while required, JSON `{"error":{"j2commerce_privacy_consent": "…"}}`.
   - `checkout.confirm` (HTML alert) and `checkout.confirmPayment` (POST; AJAX JSON error, form redirect) are refused while required and no consent exists for the current cart (skipped step 4, cart change). GET gateway returns pass.
2. `onJ2CommerceAfterSaveOrder` (dispatched by `CartOrder::saveOrder()`, argument 0 = saved order): consent cart equals `order->cart_id`, then `ConsentRepository::ensureOrderConsent()`.
3. `onJ2CommerceCheckoutCleanup` removes the consent.

Consent is never created retroactively from an existing order. IP address and user agent are kept (not anonymized); guest rows (`user_id = 0`) are outside com_privacy export and deletion.

**J2Commerce 4 / J2Store:** `overrides/com_j2store/checkout/default_shipping_payment.php` renders the checkbox and loads `media/js/consent-validator.js` (client-side). No checkout consent record is written.

## Privacy Tab Content

`myprofile/default_privacy.php` (both components) calls `ConsentRepository::getStatus()`:

- logged-in: valid rows with the user's `user_id` and subject `PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT` or `PLG_SYSTEM_PRIVACYCONSENT_SUBJECT` (other subjects are not shown);
- guest (session `guest_order_token` + `guest_order_email`): the one order with that token and e-mail (`user_id = 0`), nothing else of that address.

One button per enabled request type (`show_export_data` → `export`, `show_delete_all` → `remove`; none → no request section). Logged-in users: both link to `index.php?option=com_privacy&view=request` (the form offers both types, no URL preset). Verified guests: `mailto:` to `support_email` (fallback `mailfrom`) with the type as subject, because the com_privacy Dispatcher redirects guests to login.
