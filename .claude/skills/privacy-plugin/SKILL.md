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
- Enforces configurable retention periods before allowing data deletion
- Anonymizes orders outside the retention period instead of deleting them
- Detects lifetime licenses to preserve email after retention expires
- Adds a consent checkbox to the J2Commerce checkout; on J2Commerce 6 validates it server-side and records one `#__privacy_consents` row per order (bundled system plugin)
- Renders a self-service privacy tab in the J2Commerce MyProfile page (consent status, link to the `com_privacy` request form or `mailto:` for guests)
- Runs a scheduled automatic cleanup task

## Key Files

| File | Purpose |
|------|---------|
| `src/Extension/J2Commerce.php` | Main plugin class — all event handlers |
| `plugins/task/j2commerceprivacy/src/Extension/J2CommercePrivacy.php` | Scheduled cleanup task (separate task plugin) |
| `plugins/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php` | Checkout consent on J2Commerce 6 (`onAfterRoute`, `onJ2CommerceAfterDisplayShippingPayment`, `onJ2CommerceAfterSaveOrder`, `onJ2CommerceCheckoutCleanup`); bundled system plugin installed by `script.php` |
| `src/Consent/ConsentRepository.php` | `#__privacy_consents`: one record per order without duplicates, status lookup by `user_id` or, for guests, only the order of the session token + e-mail |
| `layouts/privacy_tab.php` | Privacy tab markup, rendered by the `myprofile/default_privacy.php` overrides |
| `src/Frontend/PrivacyOptions.php` | Frontend options for the MyProfile overrides (`show_privacy_section`, `show_delete_address`, `show_export_data`, `show_delete_all`), all off while the plugin is disabled; loads the plugin language |
| `script.php` | Install/update/uninstall, post-install message |
| `language/en-GB/plg_privacy_j2commerce.ini` | All translatable strings |

## Events Handled

| Event | Purpose |
|-------|---------|
| `onPrivacyExportRequest` | Collect J2Commerce data for export |
| `onPrivacyCanRemoveData` | Blocks deletion only for lifetime licenses outside the retention period; orders within it are kept and reported |
| `onPrivacyRemoveData` | Anonymize/delete data |
| `onAjaxJ2commercePrivacy` | Handle address deletion AJAX requests |

See `references/architecture.md` for full details.
