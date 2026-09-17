# Plugin Architecture

## Relationship to Joomla Core

This plugin is a `privacy` group plugin that extends `com_privacy`. It does not replace the core privacy system — it adds J2Commerce data on top.

**Joomla core handles:** request management UI, export/deletion workflow, consent tracking (`#__privacy_consents`), action logging, pseudonymisation of the user account (`plg_privacy_user`, same `User` object and ordering as this plugin, so it may run first).

**Action log:** `#__action_logs` (for example login records) stays under Joomla core. This plugin exports the user's entries (`include_joomla_data`) and, with `activity_logging`, writes its own entries (including the IP address of the request); it never changes or deletes existing entries.

**This plugin handles:** J2Commerce-specific data in exports, retention enforcement, order anonymization, lifetime license detection, checkout consent, MyProfile tab.

Reference: [Joomla Privacy Suite Guide](https://docs.joomla.org/Privacy_Suite_Guide)

## Class Structure

```
J2Commerce extends CMSPlugin implements SubscriberInterface
├── onPrivacyExportRequest()      — collect export domains
│   └── collectExportDomains()
│       ├── createOrdersDomain()
│       ├── createAddressesDomain()
│       ├── createJoomlaUserDomain()        — if include_joomla_data
│       ├── createJoomlaProfileDomain()     — if include_joomla_data
│       ├── createJoomlaActionLogsDomain()  — if include_joomla_data
│       └── createAcyMailingDomain()        — only if AcyMailing tables exist
├── onPrivacyCanRemoveData()      — never blocks; captures username + account e-mail per user ID
│   └── checkCanRemoveData()
├── onPrivacyRemoveData()         — anonymize/delete
│   └── processDataRemoval()      — uses the captured account data and the request e-mail
│       ├── checkRetentionPeriod()   — retained orders (+ lifetime flag), expired lifetime orders
│       │   └── lifetimeOrderIds()   — Retention\LifetimeLicenses (fail-closed)
│       ├── deleteAddresses()        — if delete_addresses
│       ├── deleteCartData()
│       ├── anonymizeOrders()        — if anonymize_orders; cutoff Retention\RetentionPeriod;
│       │                              lifetime orders keep user_email; consent IP/UA removed
│       ├── removeAcyMailingData()   — lookup by captured e-mails
│       └── reportRetainedOrders()   — customer e-mail first, then administrator message
└── onAjaxJ2commercePrivacy()     — AJAX address deletion
    └── deleteUserAddress()

J2CommercePrivacy extends CMSPlugin implements SubscriberInterface — separate task plugin:
│                    plugins/task/j2commerceprivacy/src/Extension/J2CommercePrivacy.php
│                    routine plg_task_j2commerceprivacy.autocleanup
└── autoCleanup()                 — scheduled task (site time zone, effective fiscal year end logged)
    ├── anonymizeExpiredGuestOrders()   — guest orders per order
    ├── anonymizeUserData()             — partialAnonymizeUserData() is an alias
    │   └── anonymizeOrderTables()      — lifetime orders keep user_email (per order)
    ├── ordersWithLifetimeLicense()     — Retention\LifetimeLicenses, fail-closed
    └── removeConsentEvidenceForOrders()  — Consent\ConsentRepository
    Status KNOCKOUT if guest orders failed or all users failed.
```

## AJAX

Address deletion uses Joomla's `com_ajax` (CSRF token required, logged-in users only):
```
index.php?option=com_ajax&plugin=j2commercePrivacy&group=privacy&format=json&task=deleteAddress&address_id={id}
```

No dependency on `plg_ajax_joomlaajaxforms` — uses Joomla Core `com_ajax` only.

## Language Loading

The `privacy` plugin group is not auto-imported in the Joomla frontend. The plugin has `$autoloadLanguage = true` which loads the language when the plugin is triggered. For template overrides that need the language earlier:

```php
\Advans\Plugin\Privacy\J2Commerce\Frontend\PrivacyOptions::loadLanguage();
```

Do not use `Factory::getLanguage()` (deprecated, flagged by `shared/tests/deprecated-api-scan.php`); plugin code uses the application language and, without an application (CLI), a language object from `LanguageFactoryInterface`.

## Database Tables Used

J2Commerce 4 (`#__j2store_*`) or J2Commerce 6 (`#__j2commerce_*`), decided by `Support\J2CommerceStack` (plugin, task, `ConsentRepository`): the active set (display, recording) is `#__j2commerce_*` when `com_j2commerce` is installed (also while disabled), otherwise `#__j2store_*` when `com_j2store` is enabled; without a component entry the J2Commerce 6 tables win. Removal, anonymization, retention cleanup and export run on every existing set (`dataSets()`), because the official migration keeps the `#__j2store_*` tables. Never decide by table presence alone.

| J2Commerce 4 | J2Commerce 6 | Purpose |
|--------------|--------------|---------|
| `#__j2store_orders` | `#__j2commerce_orders` | Order data, anonymization target |
| `#__j2store_orderinfos` | `#__j2commerce_orderinfos` | Billing/shipping addresses per order |
| `#__j2store_orderitems` | `#__j2commerce_orderitems` | Order line items |
| `#__j2store_addresses` | `#__j2commerce_addresses` | Saved user addresses |
| `#__j2store_carts` | `#__j2commerce_carts` | Carts |
| `#__j2store_cartitems` | `#__j2commerce_cartitems` | Cart line items |
| `#__j2store_product_customfields` (optional, created manually) | `#__j2commerce_metafields` | Lifetime license flag per product |

Joomla core tables: `#__users`, `#__user_profiles`, `#__action_logs` (export and activity logging).

AcyMailing (optional, detected via a table ending in `acym_configuration`): `acym_user`, `acym_user_has_list`, `acym_list`, `acym_user_has_field`, `acym_field`, `acym_user_stat`, `acym_mail`, `acym_url_click`, `acym_url`, `acym_history`, `acym_queue`.

`#__license_keys` is not used.

`#__privacy_consents` (Joomla core): checkout consent on J2Commerce 6, one row per order, `user_id` of the order (0 = guest), subject `PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT`, body = order number + IP address + user agent + marker `<!-- j2commerce-order:ID -->`; no e-mail copied. Written by the bundled system plugin (`plugins/system/j2commerceprivacy`), read by `src/Consent/ConsentRepository.php`. IP address and user agent are kept as long as the order: when the plugin or the cleanup task anonymizes an order, `ConsentRepository::removeOrderEvidence()` replaces the body with order number + marker `<!-- j2commerce-evidence-removed -->` (record, `created`, `subject`, `user_id`, `state` kept). Guest rows are not covered by com_privacy export or deletion.
