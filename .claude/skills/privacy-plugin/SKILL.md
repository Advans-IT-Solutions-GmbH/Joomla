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

`j2commerce/plg_privacy_j2commerce/` (sources of the privacy plugin and, below `plugins/`, of the consent system plugin and the cleanup task plugin)

Shipped only as the Joomla package `j2commerce/pkg_privacy_j2commerce/` (`pkg_privacy_j2commerce`): release asset `pkg_privacy_j2commerce_X.Y.Z.zip`, tag prefix `privacy-v` unchanged. The package installs the three plugins (privacy plugin first), is their only update channel and removes them together (`blockChildUninstall`). Package, plugin and both bundled plugins always carry the same version (the package build enforces it).

## What This Plugin Does

Extends Joomla's `com_privacy` with J2Commerce-specific data handling:

- Exports J2Commerce orders and addresses, optional Joomla user/profile/action-log data, and AcyMailing subscriber data on privacy export requests
- Carries out removal requests: deletes addresses, the carts of the account and AcyMailing data, anonymizes orders outside the retention period, keeps orders within it and reports them (administrator message, customer e-mail)
- Handles the other consent kinds in `#__privacy_consents` (registration, newsletter form, checkout newsletter) for export and removal, and deletes Account export shows and account removal removes AcyMailing contacts with `cms_id` = the verified account (sync may already have changed their address; listener gets the original address). AcyMailing placeholder contacts of pseudonymised accounts
- A detected AcyMailing subscriber-lookup failure must remain visible through the existing privacy callback: sanitized log, administrator warning and newsletter-kept/unverified notices. Repair and retry native Remove; dispatch success alone is not deletion proof. See the extension README, "AcyMailing Integration".
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
| `plugins/system/j2commerceprivacy/src/Extension/J2CommercePrivacy.php` | `onMailBeforeRendering`: links the com_privacy administrator request mail to the confirmation page; checkout consent on J2Commerce 6 (`onAfterRoute`, `onJ2CommerceAfterDisplayShippingPayment`, `onJ2CommerceCheckoutCleanup`; records the consent on `checkout.confirmPayment`); bundled system plugin, installed by the package |
| `src/Consent/ConsentRepository.php` | `#__privacy_consents`: one record per order without duplicates, status lookup by `user_id` or, for guests, only the order of the session token + e-mail; export, anonymization and evidence removal of the other consent kinds (see Decisions) |
| `src/Newsletter/AcymailingPlaceholders.php` | AcyMailing table prefix detection; deletes placeholder contacts of pseudonymised accounts (guarded) |
| `layouts/privacy_tab.php` | Privacy tab markup, rendered by the `myprofile/default_privacy.php` overrides |
| `src/Frontend/PrivacyOptions.php` | Frontend options for the MyProfile overrides (`show_privacy_section`, `show_delete_address`, `show_export_data`, `show_delete_all`), all off while the plugin is disabled; loads the plugin language |
| `script.php` | Install/update/uninstall of the privacy plugin: refuses any installation that does not come from the package (`PLG_PRIVACY_J2COMMERCE_INSTALL_PACKAGE_REQUIRED`, `Pkg_Privacy_j2commerceInstallerScript::installing()`), attempts table, template overrides, legacy migrations (old manifest name, routine ID, legacy consents), post-install guide; uninstall deletes the cleanup tasks |
| `../pkg_privacy_j2commerce/script.php` | Package: extracts and snapshots the bundled plugins, refuses a downgrade of one, shows the backup recommendation also when it takes over the standalone plugin, adopts the plugins (`package_id`), enables the consent and task plugins only when they were not installed before (remembered across an interrupted attempt), removes every update site of the plugins, installs LICENSE.txt; the shared installer then creates the required cleanup task |
| `../pkg_privacy_j2commerce/build.sh` | Builds the plugin with its own `build.sh`, splits it into the three child archives under `packages/`, strips their `<updateservers>`, zips the package |
| `updates/update.xml` | Legacy channel of the standalone plugin (sites installed before the package): the release workflow writes it to announce the package asset under the plugin's element, so Joomla Update migrates those sites |
| `language/en-GB/plg_privacy_j2commerce.ini` | All translatable strings |

## Events Handled

| Event | Purpose |
|-------|---------|
| `onPrivacyExportRequest` | Collect J2Commerce data for export |
| `onPrivacyCanRemoveData` | Does not block for retention reasons (orders within the retention period are kept and reported; lifetime-license orders keep only their e-mail, provisional); only exception: Joomla found an account of a different address (`muller@` for `müller@`), see the e-mail comparison decision |
| `onPrivacyRemoveData` | Anonymize/delete data |
| `onAjaxJ2commercePrivacy` | Address deletion (`task=deleteAddress`, logged-in, JSON) and the confirmation page for privacy requests without login (`task=confirmRequest`, HTML); results go through `$event->addResult()`. The form token is checked by the plugin itself (`hasValidToken()`: X-CSRF-Token header, POST field or query parameter, as AJAX Forms), **never** with `Session::checkToken()`: for a request without session cookie that redirects with 303 to `Route::_('index.php')`, the router merges the com_ajax query into it with `&amp;`, and every hop loops (dev check 13.10.2026). Plugin classes call `Route::_()` with `xhtml=false`; 04b checks both statically |

See `references/architecture.md` for full details.

## Decisions (maintainer, PR #187)

| Topic | Decision |
|-------|----------|
| Compatibility | Joomla 5.4 or later (5.4.x, 6.x), PHP 8.1+; no compatibility layer for Joomla 5.0 to 5.3. No dispatcher in plugin constructors or `setDispatcher()` in service providers (Joomla sets it on load) |
| Guest access | One order token = exactly one order (native J2Commerce logic): the Privacy tab of a guest session shows only the order of `guest_order_token` + `guest_order_email`, never other orders of the same address |
| Checkout checkbox | J2Commerce 6: rendered by the system plugin through the core event `AfterDisplayShippingPayment` (J2Commerce 6.3.4 or later, installer warns for older versions); no J2Commerce 6 checkout override is shipped. Required only through the plugin options "Show Consent Checkbox" and "Consent Required". No cart (ID 0): not stored, a required consent is refused. Recorded only after J2Commerce accepted the order (`capturePlacement()` on `checkout.confirmPayment` with form token or gateway return GET, `recordAcceptedOrderConsent()` at shutdown: order exists, same cart, `order_state_id` != 5), once per order (`removeDuplicateConsents()`) |
| IP address and user agent | Stored in the consent record as proof of consent and for shop security (legitimate interest; IP via `IpHelper::getIp()`). Removed when the order is anonymized, and by the task when the record is outside the retention period (10 years by default, from the end of the fiscal year in the site time zone) or its order was deleted or already anonymized (`removeStaleEvidence()`); the record stays as evidence without personal data |
| Retention period | Counted from the end of the fiscal year of the order (`fiscal_year_end`, default 12-31, OR Art. 958f) in the site time zone; the SQL cutoff is converted to UTC |
| Removal requests | Not refused (only exception: Joomla found an account of a different address (`muller@` for `müller@`), see the e-mail comparison decision): everything not subject to a retention obligation is removed; orders within the retention period are kept and reported to the administrator and, by e-mail in the customer's language, to the customer |
| Lifetime licenses | **Variant A, provisional (confirmation pending):** a removal request is carried out; after the retention period only the order e-mail of lifetime-license orders is kept (license reactivation); the same rule per order in the removal request and the task, fail-closed |
| Action log | Joomla's action log (`#__action_logs`, e.g. login records) is separate: the plugin only exports it (`include_joomla_data`) and, with `activity_logging`, adds its own entries (with IP address); it never changes or deletes existing entries |
| Operators | Must define the storage period of consent records and describe the stored data (IP address, user agent, order number, time), its purpose and storage period in their privacy policy; consent records without personal data are not deleted automatically |
| J2Commerce stack | `Support\J2CommerceStack`: the active shop (display, recording consent) is decided by the enabled component, the same rule as in all Advans J2Commerce extensions: `com_j2commerce` enabled with its tables → `#__j2commerce_*`; otherwise `com_j2store` enabled with its tables → `#__j2store_*`; an enabled component without tables is skipped, an installed but disabled one does not count; otherwise the J2Commerce 6 tables if they exist. Removal, anonymization, retention cleanup and export run on **all** existing sets (`dataSets()`, `forEachDataSet()` / task `dataSetOverride`), so migration copies in `#__j2store_*` are cleaned too |
| Legacy consents | Records of the earlier advans template override (`PLG_PRIVACY_J2COMMERCE`, e-mail in the body, partly created afterwards) are **never** assigned to an order: only anonymized (`anonymizeLegacyConsents()`: installation and update for **all** accounts with an admin message, removal request scoped to the user plus guest records with the request/account e-mail, task; batches), subject `PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT_LEGACY`, neutral body in the website's **default site language** (`com_languages` → `site`, fallback the packaged `en-GB` file, and if even that is missing the key itself is stored and the next unscoped run replaces it with the text) and `state = -1` (invalid, hidden from the com_privacy lists), not shown or counted in the Privacy tab. The unscoped path also repairs bodies that an older version stored as the raw language key; a scoped removal request never touches another account's record |
| Guest requests (owner decision 2026-10-12) | Export, retention check, anonymization, guest addresses and the guest consent export match the account (`user_id`) **and** guest records (`user_id = 0`) with the request or account e-mail (`ownerCondition()`, trimmed, case-insensitive, never another account, never `anonymized@deleted.invalid`); `user = null` is processed by the request e-mail; guest carts are never touched. Guests confirm an administrator-created request on `task=confirmRequest` (e-mail + code, checks as com_privacy ConfirmModel, status 0 -> 1, super users + action log, expired matching code -> -1, failures limited in 15 min: 5 per session, 5 per address and 20 per client IP in the table `#__privacy_j2commerce_attempts` (`Security\ConfirmAttempts`, HMAC hashes with the site secret only, purged on write and by the task, created by manifest SQL plus a postflight backstop), uniform answer and work while blocked); the system plugin points `{TOKENURL}`/`{FORMURL}` of `com_privacy.notification.admin.*` there (relies on MailTemplate replacing `tokenurl` before `token`). Option `guest_confirmation` (default on) |
| E-mail comparison (finding 13.10.2026) | Exact after `ConsentRepository::normaliseAddress()` (trim, Unicode lower case): SQL only preselects (`addressPrefilter()`: ASCII values by ASCII lower case under `utf8mb4_bin` / PostgreSQL "C", every value with non-ASCII characters as a candidate, so no database case table or locale loses a match; `addressValues()` reads in batches of `BATCH_SIZE`), PHP decides (`addressValues()`, `normaliseAddress()`), rows are then addressed by the exact stored value (`exactValuesCondition()`: `utf8mb4_bin`, plain on PostgreSQL). No SQL `LOWER()` decides. Under `utf8mb4_unicode_ci`/`general_ci` `muller@` = `müller@`. Core finds the account with the collation: an account of another address is refused for removal (`PLG_PRIVACY_J2COMMERCE_REMOVAL_ACCOUNT_MISMATCH`) and left out of the export (warning). Action log entries carry language keys with `{requestid}`, counts, `{addressid}`, no English details |
| Consent kinds of other writers (owner decision 2026-11-17) | `#__privacy_consents` also holds Joomla registration consents (`CORE_SUBJECT`, account `user_id`), newsletter form records of the site template (marker `NEWSLETTER_FORM_MARKER`, and old header-form rows with `CORE_SUBJECT`, `user_id` 0, `<p>Email: …</p>`) and checkout newsletter consents (`NEWSLETTER_MARKER` + `<!-- advans-newsletter-order:ID -->`). Export: guest rows only (`user_id` 0, Joomla exports account rows), domain `newsletter_consents`: form kinds by `findNewsletterFormConsents()`, checkout kind of the J2Commerce 6 guest orders. Address match only in the subscriber field (`subscriberAddresses()`: `E-Mail-Adresse:` line / `Email:` paragraph directly followed by the IP field, exactly one such field with exactly one whole address, else none: fail-closed), never another account. Removal (`processRequestConsents()`): registration + form kinds anonymized at once (`anonymizeRequestConsents()`, `ERASED_BODY_KEY`, `state = -1`); checkout newsletter consents `state = -1` at once, IP/UA kept until the order is anonymized (`removeOrderEvidence()` / `removeStaleEvidence()`, all data sets for the task); a record whose order is no longer attributable (`attributableOrders()`: no account, no address left; lifetime-license orders keep theirs) also becomes `state = -1`, age alone only strips; every writer goes through `transformConsent()` (row re-read with FOR UPDATE); order: subscriber, consents, then addresses/carts/orders. While a newsletter subscriber was kept, guest records wait for the retry (found again by address), but account records (the retry arrives without account after Joomla's pseudonymisation) and checkout newsletter consents of guest orders due for anonymization (`guestOrdersDueForAnonymization()`) are processed at once. A failed consent step enqueues `PLG_PRIVACY_J2COMMERCE_CONSENTS_INCOMPLETE`, skips the order anonymization of that run (`removeShopData(..., false)`) and replaces the completion notices (no customer e-mail, `REMOVAL_INCOMPLETE_RETAINED` warning), so the retry finds everything. An account removal stores (in `onPrivacyCanRemoveData`, refusing the request if that fails) HMAC hashes of the addresses in the account params (`PENDING_PARAM`, `markPendingAccount()`, in the database and in the shared `User` object, which Joomla's user plugin may save afterwards) before any fallible step and clears them when done (consents processed, no subscriber kept); a retry with `user = null` continues with the one matching pseudonymised account (`pendingAccount()`; a failed or ambiguous lookup throws and makes the run incomplete: warning, no order anonymization). Guest checkout newsletter consents of the request get `ConsentRepository::pendingMarker()` (same HMAC) before any fallible step (`markNewsletterOrderConsentsPending()`), found and removed by `invalidateNewsletterOrderConsents(..., $pendingHashes)`, so a retry finds them after the cleanup task anonymized their order. Account export shows and account removal removes AcyMailing contacts with `cms_id` = the verified account (sync may already have changed their address; listener gets the original address). AcyMailing placeholder contacts of pseudonymised accounts (`Newsletter\AcymailingPlaceholders`: `cms_id` of an account whose e-mail is exactly `useridNremoved@email.invalid`, same contact address, no list rows, no pending removal marker unless called by the owning request) are deleted by the account's removal request (`onPrivacyRemoveData` subscribed with `Priority::MIN`, so it runs after `plg_privacy_user` saved the account) and the task |
| Packaging (owner decision 2026-11) | Multi-part extensions ship as real Joomla packages: `pkg_privacy_j2commerce` bundles the three plugins. A site with the standalone plugin (1.9.0 and earlier) migrates automatically through the legacy channel or a manual install of the package: rows, settings, data and enabled states are kept, a missing bundled plugin is installed and enabled, a newer installed plugin stops the installation |
| Not built | Licenses tab / `#__license_keys`; retroactive consent records; consent recording on J2Commerce 4 / J2Store |

## swiss made software label

This extension carries the swiss made software label. Logo, link and origin claims follow the
section "swiss made software label" in the skill `joomla-extensions` and `/AGENTS.md`, section
"Label swiss made software". The rules on external services (house rule) are in the same sections.
