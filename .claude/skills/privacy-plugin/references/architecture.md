# Plugin Architecture

## Relationship to Joomla Core

This plugin is a `privacy` group plugin that extends `com_privacy`. It does not replace the core privacy system — it adds J2Commerce data on top.

**Joomla core handles:** request management UI, export/deletion workflow, consent tracking (`#__privacy_consents`), action logging.

**This plugin handles:** J2Commerce-specific data in exports, retention enforcement, order anonymization, lifetime license detection, checkout consent, MyProfile tab.

Reference: [Joomla Privacy Suite Guide](https://docs.joomla.org/Privacy_Suite_Guide)

## Class Structure

```
J2Commerce extends CMSPlugin (via PrivacyPlugin)
├── onPrivacyExportRequest()      — collect export domains
│   └── collectExportDomains()
│       ├── createOrdersDomain()
│       ├── createAddressesDomain()
│       └── createCartDomain()
├── onPrivacyCanRemoveData()      — check retention
│   └── checkRetentionPeriod()   — NOT checkOrderRetention()
├── onPrivacyRemoveData()         — anonymize/delete
│   ├── anonymizeOrders()
│   ├── deleteAddresses()
│   └── deleteCartData()
├── onAfterRender()               — fallback injection
│   ├── injectConsentCheckbox()
│   ├── injectDeleteAddressButtons()
│   └── injectPrivacySection()
└── onAjaxJ2commercePrivacy()     — AJAX address deletion

AutoCleanupTask extends CMSPlugin
└── autoCleanup()                 — scheduled task
    ├── hasLifetimeLicense()
    ├── partialAnonymizeUserData()
    └── anonymizeUserData()
```

## AJAX

Address deletion uses Joomla's `com_ajax`:
```
index.php?option=com_ajax&plugin=j2commerce_privacy&group=privacy&format=json&task=deleteAddress&address_id={id}
```

No dependency on `plg_ajax_joomlaajaxforms` — uses Joomla Core `com_ajax` only.

## Language Loading

The `privacy` plugin group is not auto-imported in the Joomla frontend. The plugin has `$autoloadLanguage = true` which loads the language when the plugin is triggered. For template overrides that need the language earlier:

```php
Factory::getLanguage()->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');
```

## Database Tables Used

| Table | Purpose |
|-------|---------|
| `#__j2store_orders` | Order data, anonymization target |
| `#__j2store_orderinfos` | Billing/shipping addresses per order |
| `#__j2store_orderitems` | Order line items |
| `#__j2store_addresses` | Saved user addresses |
| `#__j2store_carts` | Active carts |
| `#__j2store_cartitems` | Cart line items |
| `#__j2store_product_customfields` | Lifetime license flag per product |
| `#__license_keys` | Issued license keys per user (separate from J2Commerce UI) |
| `#__privacy_consents` | Joomla core consent records |
