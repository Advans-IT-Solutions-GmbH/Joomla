---
name: privacy-plugin
description: Deep knowledge of the plg_privacy_j2commerce plugin. Use when working on GDPR/DSGVO compliance, retention logic, lifetime license detection, template integration, checkout consent, or data export/deletion.
triggers:
  - privacy
  - GDPR
  - DSGVO
  - retention
  - anonymize
  - consent
  - lifetime license
  - onPrivacy
  - com_privacy
  - MyProfile
  - privacy tab
  - data export
  - data deletion
  - plg_privacy_j2commerce
references:
  - references/architecture.md
  - references/retention-logic.md
  - references/template-integration.md
  - references/known-issues.md
---

# Privacy Plugin — Domain Knowledge

## Location

`j2commerce/plg_privacy_j2commerce/`

## What This Plugin Does

Extends Joomla's `com_privacy` with J2Commerce-specific data handling:

- Exports J2Commerce orders and addresses, optional Joomla user/profile/action-log data, and AcyMailing subscriber data on privacy export requests
- Carries out removal requests: deletes addresses, carts and AcyMailing data, anonymizes orders outside the retention period, keeps orders within it and reports them (administrator message, customer e-mail)
- Anonymizes orders outside the retention period instead of deleting them (registered users and, via the task, guest orders)
- Detects lifetime licenses: those orders keep only their order e-mail after the retention period (provisional, see Decisions)
- Adds a consent checkbox to the J2Commerce checkout; on J2Commerce 6 validates it server-side and records one `#__privacy_consents` row per order (bundled system plugin)
- Renders a self-service privacy tab in the J2Commerce MyProfile page (consent status, request buttons per option: `com_privacy` form for logged-in users, `mailto:` for guests)
- Runs a scheduled automatic cleanup task

## Key Files

| File | Purpose |
|------|---------|
| `src/Extension/J2Commerce.php` | Main plugin class — all event handlers |
| `plugins/task/j2commerceprivacy/src/Extension/J2CommercePrivacy.php` | Scheduled cleanup task (separate task plugin) |
| `plugins/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php` | Checkout consent on J2Commerce 6 (`onAfterRoute`, `onJ2CommerceAfterDisplayShippingPayment`, `onJ2CommerceCheckoutCleanup`; records the consent on `checkout.confirmPayment`); bundled system plugin installed by `script.php` |
| `src/Consent/ConsentRepository.php` | `#__privacy_consents`: one record per order without duplicates, status lookup by `user_id` or, for guests, only the order of the session token + e-mail |
| `layouts/privacy_tab.php` | Privacy tab markup, rendered by the `myprofile/default_privacy.php` overrides |
| `src/Frontend/PrivacyOptions.php` | Frontend options for the MyProfile overrides (`show_privacy_section`, `show_delete_address`, `show_export_data`, `show_delete_all`), all off while the plugin is disabled; loads the plugin language |
| `script.php` | Install/update/uninstall, post-install message |
| `language/en-GB/plg_privacy_j2commerce.ini` | All translatable strings |

## Events Handled

| Event | Purpose |
|-------|---------|
| `onPrivacyExportRequest` | Collect J2Commerce data for export |
| `onPrivacyCanRemoveData` | Never blocks (orders within the retention period are kept and reported; lifetime-license orders keep only their e-mail, provisional) |
| `onPrivacyRemoveData` | Anonymize/delete data |
| `onAjaxJ2commercePrivacy` | Handle address deletion AJAX requests |

See `references/architecture.md` for full details.

## Decisions (maintainer, PR #187)

| Topic | Decision |
|-------|----------|
| Compatibility | Joomla 5.4 or later (5.4.x, 6.x), PHP 8.1+; no compatibility layer for Joomla 5.0 to 5.3. No dispatcher in plugin constructors or `setDispatcher()` in service providers (Joomla sets it on load) |
| Guest access | One order token = exactly one order (native J2Commerce logic): the Privacy tab of a guest session shows only the order of `guest_order_token` + `guest_order_email`, never other orders of the same address |
| Checkout checkbox | J2Commerce 6: rendered by the system plugin through the core event `AfterDisplayShippingPayment` (J2Commerce 6.3.4 or later, installer warns for older versions); no J2Commerce 6 checkout override is shipped. Required only through the plugin options "Show Consent Checkbox" and "Consent Required" (no template or request parameter switches it off) |
| IP address and user agent | Stored in the consent record as proof of consent and for shop security (legitimate interest), not anonymized at checkout. Removed when the order is anonymized, i.e. after the retention period of the order (10 years by default, from the end of the fiscal year, determined in the site time zone); the record stays as evidence without personal data |
| Retention period | Counted from the end of the fiscal year of the order (`fiscal_year_end`, default 12-31, OR Art. 958f) in the site time zone; the SQL cutoff is converted to UTC |
| Removal requests | Never refused: everything not subject to a retention obligation is removed; orders within the retention period are kept and reported to the administrator and, by e-mail in the customer's language, to the customer |
| Lifetime licenses | **Variant A, provisional (confirmation pending):** a removal request is carried out; after the retention period only the order e-mail of lifetime-license orders is kept (license reactivation); the same rule per order in the removal request and the task, fail-closed |
| Action log | Joomla's action log (`#__action_logs`, e.g. login records) is separate: the plugin only exports it (`include_joomla_data`) and, with `activity_logging`, adds its own entries (with IP address); it never changes or deletes existing entries |
| Operators | Must define the storage period of consent records and describe the stored data (IP address, user agent, order number, time), its purpose and storage period in their privacy policy; consent records without personal data are not deleted automatically |
| J2Commerce stack | The enabled component decides (`Support\J2CommerceStack`), not the tables: migrated sites keep `#__j2store_*` |
| Legacy consents | Records of the earlier advans template override (`PLG_PRIVACY_J2COMMERCE`, e-mail in the body) are assigned to their order when unambiguous, otherwise anonymized (evidence kept) |
| Not built | Licenses tab / `#__license_keys`; retroactive consent records; consent recording on J2Commerce 4 / J2Store |
