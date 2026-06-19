# Template Integration

## Two Rendering Mechanisms

| Mechanism | Recommended | How |
|-----------|-------------|-----|
| Template override | Yes | `default.php` checks for plugin via `PluginHelper`, renders `default_privacy.php` |
| `onAfterRender` fallback | No | Plugin injects HTML by searching rendered output for CSS selectors |

Use the template override. The `onAfterRender` fallback is fragile — it searches for patterns like `j2store-myprofile` in the rendered HTML and silently fails if the markup differs.

## Files to Copy

From `advansit/advans.ch` repo, `src/template/html/com_j2store/`:

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

`default_shipping_payment.php` reads plugin params directly from `#__extensions` (not via `PluginHelper`) because the privacy plugin group is not imported during checkout AJAX requests:

```php
$_privacyPlugin = PluginHelper::getPlugin('privacy', 'j2commerce');
if ($_privacyPlugin) {
    $_pp = new Registry($_privacyPlugin->params);
    if ($_pp->get('show_consent_checkbox', 1)) {
        // render checkbox
    }
}
```

Consent is recorded in `#__privacy_consents` when the user reaches the confirm step.
