# Template Integration

## Two Rendering Mechanisms

| Mechanism | Recommended | How |
|-----------|-------------|-----|
| Template override | Yes | `default.php` checks for plugin via `PluginHelper`, renders `default_privacy.php` |
| `onAfterRender` fallback | No | Plugin injects HTML by searching rendered output for CSS selectors |

Use the template override. The `onAfterRender` fallback is fragile — it searches for patterns like `j2store-myprofile` in the rendered HTML and silently fails if the markup differs.

## Files to Copy

Template overrides under the site template's `html/com_j2store/` directory:

**MyProfile privacy tab:**
```
myprofile/default.php
myprofile/default_privacy.php
myprofile/orderitems.php
```

**Checkout consent checkbox:**
```
checkout/default_shipping_payment.php
```

Target: `templates/{your-template}/html/com_j2store/`

## Requirements

- Bootstrap 5 template (the override uses BS5 tab markup)
- Plugin installed and enabled

## How `default.php` Activates the Tab

```php
$privacyPlugin = PluginHelper::getPlugin('privacy', 'j2commerce');
if ($privacyPlugin) {
    $privacyParams = new \Joomla\Registry\Registry($privacyPlugin->params);
    $showPrivacyTab = (bool) $privacyParams->get('show_privacy_section', 1);
    Factory::getLanguage()->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');
}
```

If the plugin is disabled or not installed, `$showPrivacyTab` is `false` — no errors, tab simply hidden.

## Checkout Consent

`default_shipping_payment.php` renders the checkbox (`id`/`name` `j2commerce_privacy_consent`) when `PluginHelper::getPlugin('privacy', 'j2commerce')` returns the plugin and `show_consent_checkbox` is on.

**J2Commerce 6 (verified against J2Commerce 6.6.1):** steps are loaded via AJAX and `J2CommerceDom.adopt()` strips `<script>` tags, so the step cannot load a validation script. The privacy group is not imported during the checkout either. The bundled **system** plugin `plg_system_j2commerceprivacy` handles consent instead:

1. `onAfterRoute` on `task=checkout.shippingPaymentMethodValidate` (POST, valid form token): ticked → session flag; unticked + `consent_required` + hidden marker `j2commerce_privacy_consent_rendered` posted → JSON `{"error":{"j2commerce_privacy_consent": "…"}}` (the checkout script shows it next to the checkbox).
2. `onJ2CommerceAfterSaveOrder` (dispatched by `CartOrder::saveOrder()` on the application dispatcher, argument 0 = saved order): with session flag → `ConsentRepository::ensureOrderConsent()` writes one record per order.
3. `onJ2CommerceCheckoutCleanup` clears the flag.

Consent is never created retroactively from an existing order.

**J2Commerce 4 / J2Store:** the `com_j2store` override keeps `media/js/consent-validator.js` (client-side). No checkout consent record is written.

## Privacy Tab Content

`myprofile/default_privacy.php` (both components) calls `ConsentRepository::getStatus()` (logged-in: `user_id`; guest: session `guest_order_token` + `guest_order_email` → guest orders with that e-mail) and renders `layouts/privacy_tab.php` via `LayoutHelper`. Logged-in users get a link to `index.php?option=com_privacy&view=request`; guests a `mailto:` link to `support_email` (fallback `mailfrom`), because the com_privacy Dispatcher redirects guests to login.
