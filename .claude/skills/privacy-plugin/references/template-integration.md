# Template Integration

## Rendering Mechanism

Checkout consent, the MyProfile Privacy tab and the address delete buttons are rendered only via the bundled template overrides. `onAfterRender` was removed; there is no HTML-injection fallback.

## Bundled Override Files

Shipped under `overrides/com_j2store/` (J2Commerce 4) **and** `overrides/com_j2commerce/` (J2Commerce 6):

```
checkout/default_shipping_payment.php
myprofile/default.php
myprofile/default_addresses.php
myprofile/default_privacy.php
```

`script.php` (`copyTemplateOverrides()`) copies them on **first install** into every frontend template, for both components:

```
templates/{template}/html/com_j2store/...
templates/{template}/html/com_j2commerce/...
```

Existing files are never overwritten (listed as skipped in the post-installation message). On updates only `myprofile/default_privacy.php` is added, and only where the template already has `myprofile/default.php` for an installed component and the file is missing. Updates also warn about J2Commerce 6 checkout overrides that render the checkbox but do not call `markCheckboxRendered()`.

`myprofile/default.php` calls `$this->loadTemplate('privacy')`; `myprofile/default_privacy.php` renders `privacy_tab` via `FileLayout` with the include paths `templates/{template}/html/layouts/plg_privacy_j2commerce/` (and the parent template) before `plugins/privacy/j2commerce/layouts/`.

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

`default_shipping_payment.php` renders the checkbox (`id`/`name` `j2commerce_privacy_consent`) when `PluginHelper::getPlugin('privacy', 'j2commerce')` returns the plugin and `show_consent_checkbox` is on.

**J2Commerce 6 (verified against J2Commerce 6.6.1, `7edb6e11`):** steps are loaded via AJAX and `J2CommerceDom.adopt()` strips `<script>` tags; the privacy group is not imported during the checkout. The bundled **system** plugin `plg_system_j2commerceprivacy` handles consent.

Enforced = `show_consent_checkbox` and `consent_required` on **and** the active site template (or its parent) has `html/com_j2commerce/checkout/default_shipping_payment.php` containing `markCheckboxRendered` (`templateReportsCheckbox()`, cached per request). Never decided from request or session. The only session key is `plg_system_j2commerceprivacy_consent` = `['cart' => cart ID]` (`CartHelper::getInstance()->getCart(0, false)`).

1. The override calls `J2CommercePrivacy::markCheckboxRendered($required)` on every render of step 4; it removes the stored consent (fresh tick required).
2. `onAfterRoute` calls `handleCheckoutRequest()`; the task is resolved like `ComponentDispatcher` (`controller` + `task` without dot).
   - `checkout.shippingPaymentMethodValidate` (POST, valid form token): ticked, consent for the current cart; unticked while enforced, JSON `{"error":{"j2commerce_privacy_consent": "…"}}`.
   - `checkout.confirm` (HTML alert) and `checkout.confirmPayment` (POST; AJAX JSON error, form redirect) are refused while enforced and no consent exists for the current cart (skipped step 4, cart change). GET gateway returns pass.
3. `onJ2CommerceAfterSaveOrder` (dispatched by `CartOrder::saveOrder()`, argument 0 = saved order): consent cart equals `order->cart_id`, then `ConsentRepository::ensureOrderConsent()`.
4. `onJ2CommerceCheckoutCleanup` removes the consent.

Consent is never created retroactively from an existing order. IP address and user agent are kept (not anonymized); guest rows (`user_id = 0`) are outside com_privacy export and deletion.

**J2Commerce 4 / J2Store:** the `com_j2store` override keeps `media/js/consent-validator.js` (client-side). No checkout consent record is written.

## Privacy Tab Content

`myprofile/default_privacy.php` (both components) calls `ConsentRepository::getStatus()`:

- logged-in: valid rows with the user's `user_id` and subject `PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT` or `PLG_SYSTEM_PRIVACYCONSENT_SUBJECT` (other subjects are not shown);
- guest (session `guest_order_token` + `guest_order_email`): the one order with that token and e-mail (`user_id = 0`), nothing else of that address.

Logged-in users get a link to `index.php?option=com_privacy&view=request`; verified guests a `mailto:` link to `support_email` (fallback `mailfrom`), because the com_privacy Dispatcher redirects guests to login.
