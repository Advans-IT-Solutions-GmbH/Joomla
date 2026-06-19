---
name: privacy-plugin
description: Deep knowledge of the plg_privacy_j2commerce plugin. Use when working on GDPR/DSGVO compliance, retention logic, lifetime license detection, template integration, checkout consent, or the MyProfile privacy tab. Triggers on "privacy", "GDPR", "DSGVO", "retention", "anonymize", "consent", "lifetime license", "onPrivacy", "com_privacy", "MyProfile", "privacy tab".
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

- Exports J2Commerce orders, addresses, cart data on privacy export requests
- Enforces configurable retention periods before allowing data deletion
- Anonymizes orders outside the retention period instead of deleting them
- Detects lifetime licenses to preserve email after retention expires
- Adds a consent checkbox to the J2Commerce checkout
- Renders a self-service privacy tab in the J2Commerce MyProfile page
- Runs a scheduled automatic cleanup task

## Key Files

| File | Purpose |
|------|---------|
| `src/Extension/J2Commerce.php` | Main plugin class — all event handlers |
| `src/Task/AutoCleanupTask.php` | Scheduled cleanup task |
| `script.php` | Install/update/uninstall, post-install message |
| `language/en-GB/plg_privacy_j2commerce.ini` | All translatable strings |

## Events Handled

| Event | Purpose |
|-------|---------|
| `onPrivacyExportRequest` | Collect J2Commerce data for export |
| `onPrivacyCanRemoveData` | Check if retention blocks deletion |
| `onPrivacyRemoveData` | Anonymize/delete data |
| `onAfterRender` | Inject consent checkbox + privacy section (fallback) |
| `onAjaxJ2commercePrivacy` | Handle address deletion AJAX requests |

See `references/architecture.md` for full details.
