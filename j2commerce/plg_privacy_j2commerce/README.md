# Privacy - J2Commerce Plugin

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/j2commerce-privacy.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/j2commerce-privacy.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-privacy.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-privacy.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

## Description

GDPR/DSGVO compliance solution for J2Commerce shops on Joomla 5.4 or later (5.4.x, 6.x). Integrates with Joomla's native Privacy Suite (`com_privacy`) to handle data export, deletion requests, and consent — specifically for J2Commerce order and customer data. Supports J2Commerce 4.x (`#__j2store_*` tables) and J2Commerce 6.x (`#__j2commerce_*` tables) via runtime detection.

### Compatibility Test Scope

The CI uses the official Joomla Docker images (newest Joomla 5.4.x and 6.x) plus real J2Commerce/J2Store runtimes for the core privacy export, anonymization, cart cleanup, retention, and uninstall paths. The bundled checkout and MyProfile template overrides are now also **rendered** for both stacks (`com_j2store` on J5/J2Store 4 and `com_j2commerce` on J6/J2Commerce 6) and asserted to actually emit the consent checkbox and Privacy tab markup, reading the real installed-and-enabled plugin params (on J2Commerce 6 the checkbox comes from the consent system plugin through the J2Commerce event `AfterDisplayShippingPayment`; test 12 also requests the real checkout steps over HTTP). Optional AcyMailing paths are exercised against a minimal database fixture only; they are not a full AcyMailing installation/runtime compatibility proof. Lifetime-license detection is covered through the J2Commerce metafield database path, but it does not replace an end-to-end license plugin runtime test.

## Features

- **Checkout Consent Checkbox** — Privacy consent during checkout (J2Commerce 6: J2Commerce event, validated and recorded on the server; J2Commerce 4: template override)
- **Privacy Policy Link** — Configurable link to privacy policy article
- **Address Management** — Frontend delete buttons for saved addresses
- **Automated Data Cleanup** — Scheduled anonymization after configurable retention period
- **MyProfile Privacy Tab** — User-facing privacy management in J2Commerce profile
- **Admin Notifications** — Email alerts on privacy-related user actions
- **Activity Logging** — Audit trail for all privacy operations
- **Legal Compliance** — Swiss OR Art. 958f / MWSTG Art. 70 compliant retention

## Requirements

- Joomla 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or higher
- J2Commerce 4.0 or higher (J2Commerce 6 supported)
- J2Commerce 6: **6.3.4 or later** for the checkout consent checkbox. The core checkout templates fire the event `AfterDisplayShippingPayment` since J2Commerce commit `d7992c66` (PR #1109, merged 2026-05-28, after the 6.3.3 version bump of 2026-05-27); 6.3.4 (version bump of 2026-06-01) is the first version that contains it. The installer warns when an older J2Commerce 6 is installed
- Joomla Privacy Component enabled (`com_privacy`)

---

## Installation

### Scope and Limitations

**Supported Product Types:**
- One-time purchases with standard retention periods
- Perpetual software licenses with extended data retention

**Current Limitations:**

This version does not include automated handling of recurring subscription products. Subscription-based products require:
- Real-time payment gateway integration for subscription status verification
- Complex state management for active, paused, and cancelled subscriptions
- Renewal cycle tracking and grace period handling

Organizations with subscription-based business models should contact Advans IT Solutions for custom implementation requirements.

**Recommended Approach for Subscriptions:**
- Apply standard accounting retention periods to all subscription orders
- Implement manual review processes for active subscription accounts
- Maintain separate documentation of subscription-specific data retention policies

### Install Steps

1. Download `plg_privacy_j2commerce_<version>.zip` (e.g. `plg_privacy_j2commerce_1.5.5.zip`) from the latest release
2. **System → Install → Extensions**
3. Upload and install
4. **Enable via System → Manage → Plugins → Privacy - J2Commerce**

The installer also installs and enables the bundled task plugin **Task - J2Commerce Privacy Cleanup** (`plugins/task/j2commerceprivacy`). On install and on every update it re-enables that task plugin and migrates scheduled tasks created with the old routine ID `plg_privacy_j2commerce.autocleanup` to `plg_task_j2commerceprivacy.autocleanup`.

### Updating

The manifest registers an update server (`updates/update.xml` in this repository). New versions appear under **System → Update → Extensions**. On update, template overrides that were already deployed are not touched (see [Updating overrides after plugin updates](#updating-overrides-after-plugin-updates)).

### Uninstall

Uninstall via **System → Manage → Extensions**. In addition to Joomla's standard removal of the privacy plugin, `script.php` (`uninstall()`):

- deletes all scheduled tasks of type `plg_task_j2commerceprivacy.autocleanup` from `#__scheduler_tasks`
- removes the bundled task plugin (its `#__extensions`, `#__schemas` and `#__update_sites_extensions` rows and the folder `plugins/task/j2commerceprivacy`)
- removes the bundled consent system plugin (same rows and the folder `plugins/system/j2commerceprivacy`)

Not removed:

- template overrides copied into `templates/{template}/html/com_j2store/` and `templates/{template}/html/com_j2commerce/`
- J2Commerce orders, addresses and all other shop data (already anonymized data stays anonymized)
- lifetime-license flags in `#__j2commerce_metafields` / `#__j2store_product_customfields` (including a manually created `#__j2store_product_customfields` table)
- entries already written to `#__action_logs`

### Post-Installation Configuration

The plugin requires mandatory configuration before operation. Detailed setup steps are shown in the post-installation message. The following steps must be completed:

1. Enable the plugin in Joomla's plugin manager
2. Configure retention periods and legal compliance parameters
3. **Verify template overrides** — deployed automatically on first install; check the postflight message for which files were copied or skipped (see [Template Integration](#template-integration))
4. **Create a hidden menu item for Privacy Requests** (see below)
5. Configure lifetime-license flags if your shop sells perpetual licenses (optional)
6. Establish automated cleanup scheduling with the bundled task plugin

Note: Failure to configure the lifetime-license flag for perpetual-license products can result in full anonymization after the retention period expires.

### Required: Privacy Request Menu Item

Joomla's `com_privacy` component requires a frontend menu item to generate valid SEF URLs. Without it, privacy request links in the user profile redirect to the home page.

**Create the menu item:**

1. Navigate to **Menus → Main Menu → New**
2. Set **Menu Item Type** to **Privacy → Create Request**
3. Set **Title** to e.g. "Datenschutzanfrage" / "Privacy Request"
4. Set **Access** to **Registered**
5. Set **Status** to **Published**
6. Under **Link Type**, set **Display in Menu** to **No** (hidden menu item)
7. Save

This menu item is required for the privacy request link in the J2Commerce profile privacy tab to work for logged-in users (the request form lets the user choose export or deletion). Guest users see a mailto link instead (see below).

---

## Joomla Privacy Framework

This plugin is a **privacy group plugin** that extends Joomla's built-in Privacy Suite (`com_privacy`). It does not replace or duplicate the core privacy system — it adds J2Commerce-specific data handling on top of it.

**What Joomla's Privacy Suite provides (core):**
- Privacy request management UI (`Users → Privacy → Requests`)
- Export and deletion request workflow
- Consent tracking (`#__privacy_consents`)
- Action logging (`#__action_logs`)
- Scheduled task infrastructure

**What this plugin adds:**
- J2Commerce order, address, and cart data in exports (`onPrivacyExportRequest`)
- Retention period enforcement before deletion (`onPrivacyCanRemoveData`)
- Order anonymization with configurable retention (`onPrivacyRemoveData`)
- Lifetime license detection to preserve email after retention expires
- Checkout consent checkbox
- Self-service privacy tab in the J2Commerce MyProfile page
- Scheduled automatic cleanup task

For a full overview of how Joomla's Privacy Suite works, see the [Joomla Privacy Suite Guide](https://docs.joomla.org/Privacy_Suite_Guide).

---

## Checkout and Profile Behavior

### Checkout Consent Checkbox

The plugin adds a privacy consent checkbox to the J2Commerce checkout (step 4: Shipping & Payment).

- **J2Commerce 6:** the bundled system plugin **System - J2Commerce Privacy Consent** (`plg_system_j2commerceprivacy`, installed together with this plugin and enabled on first installation) renders the checkbox through the J2Commerce event `AfterDisplayShippingPayment`, which the J2Commerce 6 core templates (bootstrap5 and uikit, J2Commerce 6.3.4 or later) fire directly before the Continue button. The checkbox therefore appears with the J2Commerce core templates and with any template override that keeps this event call. No J2Commerce 6 checkout override is shipped or deployed; for your own override, copy the J2Commerce core template (`components/com_j2commerce/tmpl/checkout/bootstrap5/default_shipping_payment.php`) and keep the event call.
- **Checkout overrides of earlier plugin versions (J2Commerce 6):** versions up to 1.5.5 copied `html/com_j2commerce/checkout/default_shipping_payment.php` into the site templates. That copy replaces both core templates and lacks newer J2Commerce features (payment-step custom fields, payment method descriptions, image URL handling, uikit markup). On installation and update, a copy that is still **unchanged** (identical to a shipped version) is renamed to `default_shipping_payment.php.plg_privacy_j2commerce-disabled` when J2Commerce 6.3.4 or later is installed, so the core template takes over; the installer lists the renamed files. Renaming instead of deleting keeps the file for comparison and can be undone. A **changed** copy may contain your own changes: it is left in place and the installer warns about it. With an older or unknown J2Commerce version nothing is renamed, because the copy is then the only place that renders the checkbox.
- **J2Commerce 4 / J2Store:** the checkbox is rendered by the template override `default_shipping_payment.php`. See [Template Integration](#template-integration).

**Validation (J2Commerce 6):** J2Commerce 6 loads the checkout steps via AJAX and removes `<script>` tags from the step HTML, so the consent is validated on the server by the system plugin.

- **When consent is enforced** depends only on the plugin options: **Show Consent Checkbox** and **Consent Required** are Yes. Neither the template nor any request parameter (`template`, `templateStyle`, `Itemid`) nor a skipped step switches the check off.
- Every render of the shipping & payment step discards an earlier consent, so the checkbox has to be ticked again.
- Shipping & payment step (`checkout.shippingPaymentMethodValidate`, also when called as `controller=checkout&task=shippingPaymentMethodValidate`): ticked, the consent is stored in the session for the current J2Commerce cart; not ticked while required, J2Commerce receives a field error for `j2commerce_privacy_consent` and the checkout does not advance.
- Confirmation (`checkout.confirm`) and payment submission (`checkout.confirmPayment`, browser POST) are refused while required and no consent is stored for the current cart. This covers requests that skip the shipping & payment step, zero-total orders and cart changes (for example a login in another tab): the shopper has to tick the checkbox again. Off-site payment returns (GET) are not refused.
- A custom J2Commerce 6 checkout override that neither fires `AfterDisplayShippingPayment` nor renders the checkbox (`j2commerce_privacy_consent`) cannot complete a checkout while consent is required. The installer warns about such overrides (also in the `bootstrap5/`, `uikit/` and, for J2Commerce before 6.3.7, `uikit3/` subfolders) on installation and update.

**Validation (J2Commerce 4 / J2Store):** the `com_j2store` checkout override keeps the client-side script `media/js/consent-validator.js`, which blocks the checkout form submit while the required checkbox is unticked.

### Consent Recording

Consent is stored in Joomla's core table `#__privacy_consents`. No table of its own is created.

| Column | Value |
|--------|-------|
| `user_id` | `user_id` of the order (`0` for guest orders) |
| `state` | `1` (valid). Core semantics apply: `0` obsolete, `-1` invalidated in **Users → Privacy → Consents** |
| `created` | Time the order was saved |
| `subject` | `PLG_SYSTEM_J2COMMERCEPRIVACY_CONSENT_SUBJECT` (translated in the backend) |
| `body` | Order number, IP address and user agent (the same evidence Joomla's registration consent stores), plus the marker `<!-- j2commerce-order:ORDER_ID -->` |
| `remind`, `token` | Core defaults (`0`, empty) |

**When:** on J2Commerce 6, when J2Commerce saves the order in the confirm step (`onJ2CommerceAfterSaveOrder`) and the shopper ticked the checkbox in the shipping & payment step for the cart of that order. Exactly one record is written per order: a second save of the same order finds the existing valid record and writes nothing.

**Guests:** the record gets `user_id = 0`. The e-mail address is **not** copied into the consent; the guest is traced through the order (order token, `user_email` of the order and the order number in `body`).

**What is stored and why:** the time of the consent (`created`), the order number, the IP address and the browser user agent (`body`), and the `user_id` of the order. IP address and user agent are stored as proof of the consent and for the security of the shop (legitimate interest).

**How long:** IP address and user agent are kept as long as the order they belong to has to be kept (accounting retention period). When this plugin anonymizes an order, they are removed from the checkout consent records of that order. This applies only to orders outside the retention period (**Retention Period**), anonymized either through a removal request or through the cleanup task. The record itself stays as evidence without these personal data: `user_id`, `state`, `created`, `subject` and the order number are kept, and `body` then says that IP address and user agent were removed (marker `<!-- j2commerce-evidence-removed -->`). Consent records of other orders are not changed. With **Anonymize Orders** (plugin) or `anonymize_orders` (task) off, orders are not anonymized and their consent records keep IP address and user agent. Guest orders (`user_id = 0`) are not anonymized by this plugin, so their consent records keep them too.

**Export and deletion:** Joomla's privacy export (`plg_privacy_consents`) only includes consent records with the requesting user's `user_id`, and Joomla deletes consent records only when a user account is deleted. Checkout consents of guest orders (`user_id = 0`) are therefore not covered by com_privacy export or deletion requests, and this plugin does not export or delete checkout consent records itself (it only removes IP address and user agent when it anonymizes the order, see above). Handle requests of guest customers manually in **Users → Privacy → Consents**.

**Operator responsibilities:** describe the stored data (IP address, user agent, order number, time), its purpose (proof of consent, shop security) and its storage period (the retention period of the order) in your privacy policy. Schedule the cleanup task (or process removal requests) so that expired orders are anonymized, and handle guest orders manually. The consent records themselves, without IP address and user agent, are not deleted automatically; decide how long you keep them.

**Not recorded:** consents are never created retroactively from the mere existence of an order, because an order is no evidence that the checkbox was ticked. Orders placed before this version, and checkouts on J2Commerce 4 / J2Store, have no checkout consent record.

**Uninstall:** consent records are Joomla core data and remain in `#__privacy_consents`.

### Profile Privacy Tab

The privacy tab in J2Commerce's "My Profile" shows consent status and a privacy request link. Its content is the override `myprofile/default_privacy.php`, which renders the layout `privacy_tab`: a copy in `templates/{template}/html/layouts/plg_privacy_j2commerce/privacy_tab.php` takes precedence over the plugin's `layouts/privacy_tab.php`.

> **Template override required.** The privacy tab is only rendered when the MyProfile template overrides (`default.php`, `default_privacy.php`) are in place. See [Template Integration](#template-integration) for details.

**Consent status lookup** (valid records only, `state = 1`):

| User Type | Lookup |
|-----------|--------|
| Logged-in | Records with the user's `user_id` and the subject of this plugin or of Joomla's registration/profile consent (`PLG_SYSTEM_PRIVACYCONSENT_SUBJECT`). Consents of other extensions are not shown |
| Guest (order token and `guest_order_email` in the J2Commerce session) | The checkout consent of exactly the one order identified by that token and e-mail address, the same order J2Commerce shows the guest. Other orders of the same e-mail address are not shown |

**Privacy request buttons** (logged-in users and verified guest sessions): one button for a data export request while **Show Export Data** is Yes, and one for a data deletion request while **Show Delete All Data** is Yes. With both options off, the tab shows only the consent status.

| User Type | Link |
|-----------|------|
| Logged-in | Both buttons open the `com_privacy` request form (`index.php?option=com_privacy&view=request`; requires the menu item, see above). The form offers both request types and cannot be preselected through the URL, so the shopper selects the type there |
| Guest | `mailto:` link to **Support Email**, or the site e-mail address if empty, with the request type as subject (guests cannot use `com_privacy`: Joomla's Dispatcher redirects them to login) |

**Show Privacy Section** off hides the tab. **Show Delete Address Buttons** off hides the delete button per address in the Addresses tab (`myprofile/default_addresses.php`). Every option applies only while the plugin is enabled; with the plugin disabled, the overrides show neither the tab nor the delete buttons.

---

## Template Integration

This plugin is a **native Joomla privacy plugin**, not a J2Commerce plugin. It is registered under Joomla's `privacy` plugin group, which is what allows it to participate in Joomla's built-in Privacy Suite (`com_privacy`): handling data export requests, deletion requests, consent tracking, and the scheduled cleanup task.

The trade-off is that J2Commerce does not know about it. J2Commerce's `eventWithHtml()` — the mechanism J2Commerce uses to let plugins inject content into its views — only loads plugins from its own `j2store` group. Plugins in the `privacy` group are invisible to it. This means there is no event hook available inside J2Commerce's checkout or MyProfile views that this plugin can use.

Template overrides are the solution: by placing PHP files in `templates/{active-template}/html/com_j2store/` (J2Commerce 4.x) or `templates/{active-template}/html/com_j2commerce/` (J2Commerce 6.x), the overrides run as part of J2Commerce's own rendering and can check for this plugin via `PluginHelper` to conditionally add the consent checkbox and Privacy tab.

### Automatic deployment on first install

On first install, `script.php` copies the bundled overrides into every active frontend template.

**J2Commerce 4.x** (`com_j2store`):
```
templates/{template}/html/com_j2store/checkout/default_shipping_payment.php
templates/{template}/html/com_j2store/myprofile/default.php
templates/{template}/html/com_j2store/myprofile/default_addresses.php
templates/{template}/html/com_j2store/myprofile/default_privacy.php
```

**J2Commerce 6.x** (`com_j2commerce`; no checkout override, the core templates show the consent checkbox):
```
templates/{template}/html/com_j2commerce/myprofile/default.php
templates/{template}/html/com_j2commerce/myprofile/default_addresses.php
templates/{template}/html/com_j2commerce/myprofile/default_privacy.php
```

Rules:
- Files are **only copied if they do not already exist** — existing customisations are never overwritten.
- On **updates**, only `myprofile/default_privacy.php` is copied, and only into templates that already have `myprofile/default.php` for an installed J2Commerce component and where it is still missing (the deployed `default.php` loads it for the Privacy tab). All other overrides are not changed, except that unchanged J2Commerce 6 checkout overrides of earlier plugin versions are renamed (see [Checkout Consent Checkbox](#checkout-consent-checkbox)); manage them manually after updating. Installation and update warn about J2Commerce 6 checkout overrides (including `bootstrap5/`, `uikit/` and, for J2Commerce before 6.3.7, `uikit3/` subfolders) that neither fire the J2Commerce event `AfterDisplayShippingPayment` nor render the consent checkbox; with a required consent the checkout cannot be completed with them.
- The postflight message lists which files were copied and which were skipped.

### Manual deployment

If the automatic copy was skipped (file already existed, or you are installing on a non-standard template path), copy the source files manually:

```
# Source (inside the installed plugin)
JPATH_PLUGINS/privacy/j2commerce/overrides/com_j2store/      # J2Commerce 4.x
JPATH_PLUGINS/privacy/j2commerce/overrides/com_j2commerce/   # J2Commerce 6.x

# Destination (repeat for each active template)
templates/{your-template}/html/com_j2store/      # J2Commerce 4.x
templates/{your-template}/html/com_j2commerce/   # J2Commerce 6.x
```

### Requirements

- Joomla template based on **Bootstrap 5** (`default.php` uses BS5 tab markup)
- J2Commerce MyProfile view enabled

---

## AcyMailing Integration

This plugin includes optional AcyMailing support for both the **Privacy Suite** (data export and deletion) and the **MyProfile Newsletter tab** (subscription management).

### How it works

The integration uses **direct DB queries** against AcyMailing's tables — no AcyMailing PHP classes, helper files, or `acym_get()` are loaded. This means:

- Works with all AcyMailing versions: 6.x, 7.x, 8.x, 9.x, 10.x
- Works with all license tiers: Starter, Essential, Enterprise
- Works whether AcyMailing is enabled or disabled in Joomla
- Gracefully skipped when AcyMailing is not installed — no errors, no impact on existing behaviour

The plugin detects AcyMailing by scanning the database for a table ending in `acym_configuration`. If not found, all AcyMailing code paths are bypassed.

### Privacy Suite: what is exported and deleted

**Export (`onPrivacyExportRequest`):**

A `newsletter_subscriptions` domain is added to the export containing:

| Field | Source table | Description |
|---|---|---|
| email | `acym_user` | Subscriber e-mail address |
| name | `acym_user` | Subscriber name |
| confirmed | `acym_user` | Whether the subscriber confirmed their opt-in |
| created | `acym_user` | Subscription creation date |
| list / status / dates | `acym_user_has_list` | List name, subscribed/unsubscribed, subscription and unsubscribe dates |
| field / value | `acym_user_has_field` | Custom field values (name, address, phone, etc.) |
| campaign / send_date / opened / bounce / device | `acym_user_stat` | Per-campaign delivery and engagement stats |
| campaign / url / clicks / last_click | `acym_url_click` + `acym_url` | URL click tracking per campaign |
| date / ip / action / unsubscribe_reason | `acym_history` | Action log incl. IP address and unsubscribe reasons |

**Deletion (`onPrivacyRemoveData`):**

All rows referencing the subscriber are deleted before the subscriber record itself (foreign key order):

| Table | Data removed |
|---|---|
| `acym_user_has_list` | List subscriptions |
| `acym_user_has_field` | Custom field values (name, address, phone, etc.) |
| `acym_user_stat` | Per-campaign open/click/bounce/device stats |
| `acym_url_click` | URL click tracking |
| `acym_history` | Action log incl. IP address |
| `acym_queue` | Pending outbound emails |
| `acym_user` | Subscriber record (email, name, confirmation status) |

### MyProfile Newsletter tab

The bundled overrides do not include a Newsletter tab; this section only applies if your own template provides `default_newsletter.php`.

Such a Newsletter tab in J2Commerce MyProfile (`default_newsletter.php`) lets logged-in users manage their subscriptions directly — no redirect to a separate AcyMailing frontend page.

**To enable the Newsletter tab:**

1. Install AcyMailing (any version, any license tier) on the Joomla site.
2. Ensure the template override `templates/{template}/html/com_j2store/myprofile/default_newsletter.php` exists (provided by your site template).
3. Ensure `default.php` includes the Newsletter tab block (present in your site template override).
4. In AcyMailing, set the lists you want to expose to users: **AcyMailing → Lists → Edit → Visible: Yes**.

The tab is hidden automatically when AcyMailing is not installed — no configuration needed.

**What users can do in the Newsletter tab:**

- See all visible AcyMailing lists with their current subscription status
- Subscribe or unsubscribe per list via checkboxes
- Unsubscribe from all lists at once (with confirmation dialog)

**Tables accessed by `default_newsletter.php`:**

| Table | Purpose |
|---|---|
| `{prefix}acym_configuration` | Presence check only (to detect AcyMailing) |
| `{prefix}acym_user` | Read/create subscriber record |
| `{prefix}acym_user_has_list` | Read/write subscription status per list |
| `{prefix}acym_list` | Read visible list names and descriptions |

No AcyMailing PHP classes are loaded. All queries use Joomla's `DatabaseDriver` API.

### How `default.php` activates the privacy tab

`default.php` checks the plugin and the option **Show Privacy Section** at runtime through `PrivacyOptions` (`src/Frontend/PrivacyOptions.php`):

```php
$_privacyOptions = 'Advans\\Plugin\\Privacy\\J2Commerce\\Frontend\\PrivacyOptions';
$_privacyEnabled = class_exists($_privacyOptions) && $_privacyOptions::showPrivacyTab();

if ($_privacyEnabled) {
    $_privacyOptions::loadLanguage();
}
```

If the plugin is not installed or disabled, or the option is off, `$_privacyEnabled` is `false` and the tab is not rendered — no errors. `default_addresses.php` uses `PrivacyOptions::showDeleteAddress()` the same way.

### Language file must be loaded manually

Because this is a native Joomla privacy plugin and not a J2Commerce plugin, Joomla does not auto-import it in the frontend. Its language file is therefore not loaded automatically either. Without the `PrivacyOptions::loadLanguage()` call in `default.php`, all `PLG_PRIVACY_J2COMMERCE_*` keys (including the tab title `PLG_PRIVACY_J2COMMERCE_MYPROFILE_TAB_TITLE`) render as raw strings. This call is already included in the provided `default.php` — do not remove it. The consent system plugin also loads this language file for MyProfile pages.

### Updating overrides after plugin updates

When the plugin is updated, the override files in `JPATH_PLUGINS/privacy/j2commerce/overrides/` are updated but the deployed copies in `templates/` are **not** touched. After a plugin update:

1. Compare your deployed override with the new source file.
2. Merge any changes relevant to your customisation.
3. The postflight message on update will remind you of this.

---

## Implementation Guide

### Estimated Implementation Time: 20-30 minutes

### Step 1: Configure Lifetime-License Flags (Optional)

This step is only required if your shop sells products with perpetual (lifetime) licenses. The plugin functions fully without it — lifetime license detection is simply skipped.

**One table is used, depending on the J2Commerce version:**

| Table | Purpose | How to populate |
|-------|---------|-----------------|
| `#__j2commerce_metafields` (J2Commerce 6.x) | Marks which products are lifetime licenses | Insert product metafields with `owner_resource = product`, `metakey = is_lifetime_license`, `metavalue = yes` |
| `#__j2store_product_customfields` (J2Commerce 4.x) | Marks which products are lifetime licenses | Optional custom field table used by this plugin |

For J2Commerce 6, insert the metafield row shown in the post-installation message for every perpetual-license product. For J2Commerce 4 / J2Store, create and populate `#__j2store_product_customfields` as shown in the post-installation message.

---

### Step 2: Product Classification

For each product requiring perpetual license handling:

1. Find the product ID in J2Commerce / J2Store.
2. Insert the appropriate lifetime-license flag for your J2Commerce version.
3. Verify the stored value is `yes` or `Yes`; the cleanup query compares case-insensitively.

Repeat this process for all products requiring perpetual license data retention.

---

### Step 3: Plugin Activation

1. Navigate to: `System → Manage → Plugins`
2. Locate: `Privacy - J2Commerce`
3. Change status from Disabled to Enabled

---

### Step 4: Configure Retention Parameters

Navigate to: `System → Manage → Plugins → Privacy - J2Commerce`

#### Data Handling Configuration

- **Include Joomla Core Data:** Enable to include Joomla user account data in privacy exports
- **Anonymize Orders:** Enable to anonymize rather than delete order records (recommended for accounting compliance)
- **Delete Addresses:** Enable to remove stored address data upon data removal requests

#### Retention Settings

**Retention Period (Years):**
- Switzerland: `10`
- Germany: `10`
- Austria: `7`
- France: `10`
- Spain: `6`
- UK: `6`
- USA: `7`

**Legal Basis:** (Example for Switzerland)
```
• Switzerland: OR Art. 958f (10 years)
```

See [Legal Basis Examples](#legal-basis-examples) for more countries.

**Support Email:**
```
privacy@example.com
```

⚠️ **Important:** The field is empty by default. If left empty, retention messages show `support@example.com` — set a real address before going live. This address is shown to users in all retention messages.

**Where to change:**
- `System → Manage → Plugins → Privacy - J2Commerce`
- Field: "Support Email"
- Example: `privacy@your-company.com`

Persist configuration changes.

---

### Step 5: Automated Cleanup Scheduling

Navigate to: `System → Manage → Scheduled Tasks → New`

1. Verify the plugin **Task - J2Commerce Privacy Cleanup** is enabled.
2. Select task type: **J2Commerce - Automatic data cleanup**
3. Configure task parameters to match the Privacy plugin retention settings.
4. Configure execution parameters:
   - **Execution Frequency:** Daily
   - **Execution Time:** 02:00 (recommended for minimal system load)
   - **Status:** Enabled
5. Save configuration

**Task parameters** (`plugins/task/j2commerceprivacy/forms/autocleanup.xml`). The task reads its own parameters, not the privacy plugin settings:

| Parameter | Default | Description |
|-----------|---------|-------------|
| `retention_years` | 10 | Retention period in years (required, integer 1–30). Users whose most recent order is older than this are processed. |
| `anonymize_orders` | Yes | Anonymize the users' orders and order billing/shipping data; also removes IP address and user agent from the checkout consent records of these orders. |
| `delete_addresses` | Yes | Delete the users' saved addresses. |

---

### Implementation Verification

Validate the implementation using the following test procedure:

1. Create a test product with lifetime-license flag `is_lifetime_license = yes`
2. Generate a test order for the configured product
3. Initiate a data removal request via `Users → Privacy → Requests → New Request`
4. Attempt data deletion via `Complete Request → Delete Data`
5. **Expected Result:** System blocks deletion and displays retention notification

Successful display of the retention notification confirms correct implementation.

---

## Features Overview

### Core Capabilities

- **Regulatory Compliance Export:** XML-formatted data exports compliant with GDPR Article 15 (Right of Access)
- **Automated Data Lifecycle Management:** Scheduled anonymization upon retention period expiration
- **Perpetual License Handling:** Selective data retention for software license reactivation requirements
- **Flexible Retention Frameworks:** Configurable retention periods (1-30 years) supporting multiple jurisdictions
- **Multi-Jurisdictional Support:** Configurable legal basis documentation for international operations
- **Automated Compliance Processing:** Scheduled task execution for expired data handling
- **Internationalization:** Multi-language interface support (German de-DE, English en-GB, French fr-FR)
- **Platform Integration:** Optional Joomla core user data inclusion in privacy exports

### Limitations

- **Recurring Subscriptions:** Automated subscription lifecycle management not included in current version
- **Payment Gateway Integration:** Real-time subscription status verification requires custom implementation
- **Subscription State Management:** Active subscription handling requires manual administrative processes

### Data Handling

**What gets exported:**
- J2Store orders and order items
- Billing and shipping addresses
- Joomla user account (optional)
- User profile data (optional)
- Activity logs (optional)

**Payment Data Notice:**
Payment data (credit card details, bank information) is stored by payment service providers (Stripe, PayPal, etc.), not by this system. For payment data inquiries, users must contact the respective payment provider directly.

**What gets anonymized (only for orders OUTSIDE retention period):**
- Email address → `anonymized@deleted.invalid`
- Billing first/last name → `Anonymized` / `User`
- Shipping first/last name → (cleared)
- Phone → (cleared)
- Addresses → (cleared)
- Customer note → (cleared)
- IP address → (cleared)

**What stays intact (for orders WITHIN retention period):**
- Complete order data including addresses
- Required by Swiss law: OR Art. 958f, MWSTG Art. 70 (10 years)

**What stays (anonymized orders):**
- Order numbers
- Order dates
- Order amounts
- Product information

**Exception for Lifetime Licenses:**
- Email address preserved (for license activation)
- All other data anonymized

---

## How It Works

### User Workflow

```
1. User requests data deletion
   ↓
2. System checks: Has orders within retention period?
   ↓
3a. NO → Immediate deletion/anonymization
3b. YES → Deletion blocked, show retention message
   ↓
4. After retention period expires
   ↓
5. Scheduled task automatically anonymizes data
   ↓
6. Exception: Lifetime licenses keep email for activation
```

### Retention Logic

**For ALL orders:**
- Retention period: Configurable (default 10 years)
- Applies to: ALL orders, not just licenses
- Legal basis: Accounting requirements (e.g., Swiss OR Art. 958f)

**For Lifetime Licenses:**
- After accounting retention expires (10 years)
- Partial anonymization: Keep email, delete everything else
- Reason: License activation requires email
- Legal basis: GDPR Art. 6 Abs. 1 lit. b (Contract fulfillment)

### Automatic Cleanup

**Scheduled Task runs daily:**
1. Finds users with all orders older than retention period
2. Checks for lifetime licenses
3. Full anonymization: No lifetime licenses
4. Partial anonymization: Has lifetime licenses (keep email)
5. Logs all actions

---

## Lifetime License Detection

### How It Works

**Detection Method:** Version-specific product metadata

The plugin and bundled task check `is_lifetime_license` for each product:

```sql
-- J2Commerce 4.x
SELECT field_value 
FROM #__j2store_product_customfields 
WHERE product_id = ? 
AND field_name = 'is_lifetime_license'

-- J2Commerce 6.x
SELECT metavalue
FROM #__j2commerce_metafields
WHERE owner_resource = 'product'
AND owner_id = ?
AND metakey = 'is_lifetime_license'
```

**Result:**
- `metavalue` / `field_value` = `yes` → Lifetime License
- missing or any other value → Regular Product

### Technical Implementation Rationale

**Previous Implementation (Deprecated):** Heuristic keyword detection in product metadata
- Unreliable due to typographical variations
- Lacked explicit configuration control
- Language-dependent pattern matching
- Prone to false positive classifications

**Current Implementation:** Structured product metadata
- Explicit boolean classification per product
- Eliminates ambiguity in product type determination
- Language-agnostic implementation
- Provides clear administrative visibility

### Product Type Support Matrix

**Supported:**
- One-time purchase transactions with standard retention
- Perpetual software licenses with extended retention

**Not Supported:**
- Recurring subscription products with active lifecycle management
- Real-time subscription status verification

**Note:** Organizations requiring subscription handling should implement manual review processes or contact Advans IT Solutions for custom development.

### Configuration

Insert the flag via SQL as shown in [Implementation Guide](#implementation-guide) → Step 1 / the post-installation message (J2Commerce 6: `#__j2commerce_metafields`; J2Commerce 4: `#__j2store_product_customfields`). There is no product-edit UI for this flag.

---

## Usage Guide

### Data Export

The export request flow is handled by Joomla's core Privacy component. See the [Joomla Privacy Suite Guide](https://docs.joomla.org/Privacy_Suite_Guide) for how users submit export requests and how administrators process them.

This plugin extends the export with J2Commerce-specific data: orders, order items, addresses, optional Joomla user/profile/action logs, and AcyMailing data.

---

### Data Deletion

#### Scenario 1: User without orders

**Result:** • Immediate deletion

```
User requests deletion
  ↓
No orders found
  ↓
Data immediately anonymized
```

---

#### Scenario 2: User with recent orders

**Result:** • Deletion blocked

```
User requests deletion
  ↓
Orders found (e.g., 3 years old)
  ↓
Retention: 10 years → 7 years remaining
  ↓
Deletion blocked with message
```

**Error Message:**
```
═══════════════════════════════════════════════════════
DATA DELETION NOT POSSIBLE
═══════════════════════════════════════════════════════

Your data cannot be deleted at this time because you
have placed orders subject to a statutory retention
obligation.

YOUR ORDERS:
1. Order #123
   Date: 15.03.2020
   Amount: 99.00 CHF
   Retained until: 15.03.2030
   Remaining: 7.0 years

AUTOMATIC DELETION:
• Your data will be AUTOMATICALLY deleted from: 15.03.2030
• You do NOT need to take any further action
```

---

#### Scenario 3: User with old orders (retention expired)

**Result:** • Automatic deletion

```
Scheduled task runs daily (02:00)
  ↓
Finds users with orders older than 10 years
  ↓
Checks for lifetime licenses
  ↓
No lifetime licenses → Full anonymization
Has lifetime licenses → Partial anonymization (keep email)
```

---

#### Scenario 4: User with Lifetime License

**Result:** Partial anonymization

```
After 10 years:
  ↓
Accounting retention expired
  ↓
Has lifetime license
  ↓
Partial anonymization:
  • Email preserved (for license activation)
  • Name anonymized
  • Address deleted
  • Phone anonymized
```

**Error Message:**
```
═══════════════════════════════════════════════════════
LIFETIME LICENSES (accounting retention expired)
═══════════════════════════════════════════════════════

WHAT IS RETAINED?

Required for license activation:
• Email address (for activation)
• License key
• Purchase date

Already deleted/anonymized:
• Full name
• Billing address
• Phone number
```

---

## Administrator Guide

### Where to See Retention Messages

**Location 1: Flash Message (Immediate)**
- Appears at top of screen after clicking "Delete Data"
- Red error message with full details
- Shows retention period, orders, automatic deletion date

**Location 2: Action Log (Permanent)**
- Visible in request detail view
- `Users → Privacy → Requests → [Click Request]`
- Under "Action Log" section

**Location 3: Request Status**
- Request remains in "Confirmed" status if blocked
- Changes to "Complete" when deletion succeeds

---

### Managing Requests

Request management (viewing, confirming, completing export and deletion requests) is handled by Joomla's core Privacy component. See the [Joomla Privacy Suite Guide](https://docs.joomla.org/Privacy_Suite_Guide) for the full workflow.

This plugin intercepts the deletion step to apply retention logic before any data is removed. If retention blocks deletion, the request stays in "Confirmed" status and the user receives a retention message.

---

### Monitoring Automatic Cleanup

**View scheduled task:**
```
System → Manage → Scheduled Tasks → J2Commerce - Automatic data cleanup
```

**View logs:**
```
System → Manage → Scheduled Tasks → [Task] → View Logs
```

**Example log:**
```
[2025-01-15 02:00:15] Starting automatic data cleanup...
[2025-01-15 02:00:15] Retention period: 10 years
[2025-01-15 02:00:16] Found 10 users with expired retention
[2025-01-15 02:00:16] Fully anonymized: 8 users
[2025-01-15 02:00:16] Partially anonymized: 2 users (lifetime licenses)
[2025-01-15 02:00:17] Cleanup complete: 0 errors
```

---

## Workflow Examples

### Example 1: Regular Product, Recent Order

**Situation:**
- User purchased regular product 3 years ago
- Retention: 10 years
- Remaining: 7 years

**User Action:** Requests deletion

**System Response:**
```
• Deletion blocked
Message: "Automatic deletion from: 15.03.2030"
Status: Confirmed (not Complete)
```

**What happens:**
- Data stays until 2030
- Automatic cleanup deletes in 2030
- User doesn't need to do anything

---

### Example 2: Lifetime License, Old Order

**Situation:**
- User purchased lifetime license 11 years ago
- Retention: 10 years (expired)
- License: Lifetime

**User Action:** Requests deletion

**System Response:**
```
• Deletion blocked (partial)
Message: "Email required for license activation"
Status: Confirmed
```

**What happens:**
- Automatic cleanup runs
- Name, address, phone → anonymized
- Email → preserved
- User can still activate license

---

### Example 3: No Orders

**Situation:**
- User registered but never ordered
- No orders in database

**User Action:** Requests deletion

**System Response:**
```
• Deletion successful
Status: Complete
```

**What happens:**
- Immediate deletion
- All data anonymized
- No retention applies

---

## Legal Compliance

### Swiss Data Retention Requirements

This plugin complies with Swiss legal requirements:

| Law | Requirement |
|-----|-------------|
| **OR Art. 958f** | Business documents must be retained for 10 years |
| **MWSTG Art. 70** | VAT-relevant documents must be retained for 10 years |
| **DSG Art. 32 para. 2 lit. c** | Right to have personal data deleted or destroyed; statutory retention obligations (OR, MWSTG) still apply |

**Implementation:**
- Orders within 10-year retention period: **Kept intact** (not anonymized)
- Orders outside retention period: **Anonymized** on deletion request
- Address book entries: **Deleted** immediately on request
- Cart data: **Deleted** immediately on request — cart items are deleted via a subquery on `#__j2store_carts` / `#__j2commerce_carts` (neither `#__j2store_cartitems` nor `#__j2commerce_cartitems` has a `user_id` column)

### Payment Provider Data

Payment data is processed by third-party payment providers:
- **Stripe** - https://stripe.com/privacy
- **PayPal** - https://www.paypal.com/privacy
- **Other providers** - Contact directly

This plugin does not store or have access to complete payment details. Users must contact payment providers directly for payment data inquiries.

---

## Configuration

### Plugin Settings

**Access:** `System → Manage → Plugins → Privacy - J2Commerce`

#### Privacy Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Include Joomla Core Data | Yes | Include user account, profile, and activity logs in privacy exports |
| Anonymize Orders | Yes | Anonymize order data instead of deleting on removal requests (recommended for accounting compliance) |
| Delete Addresses | Yes | Delete saved addresses on removal requests |

#### Data Retention

| Setting | Default | Description |
|---------|---------|-------------|
| Retention Period (Years) | 10 | Legal retention period. Switzerland: 10 (OR Art. 958f), Germany: 10 (AO §147), Austria: 7, UK/Spain: 6 |
| Legal Basis | (pre-filled with CH/DE/EU examples) | Legal grounds shown in retention error messages to users |
| Support Email | (empty) | Contact address shown to users for privacy inquiries. If left empty, retention messages show `support@example.com` — set a real address before going live. |

#### Checkout Consent

| Setting | Default | Description |
|---------|---------|-------------|
| Show Consent Checkbox | Yes | Display privacy consent checkbox in checkout step 4 |
| Consent Required | Yes | Make consent mandatory — blocks checkout if unchecked |
| Privacy Policy Article | (none) | Joomla article containing your privacy policy — linked in the consent text |
| Consent Text | (default) | Checkbox label text. Use `{privacy_policy}` as placeholder for the policy link |

#### Frontend Self-Service

| Setting | Default | Description |
|---------|---------|-------------|
| Show Privacy Section | Yes | Render the Privacy tab in the J2Commerce MyProfile page (`myprofile/default.php` override) |
| Show Delete Address Buttons | Yes | Show per-address delete buttons in the Addresses tab (`myprofile/default_addresses.php` override) |
| Show Delete All Data | Yes | Show the data deletion request button in the Privacy tab |
| Show Export Data | Yes | Show the data export request button in the Privacy tab |

These options are evaluated by the deployed MyProfile overrides; see [Profile Privacy Tab](#profile-privacy-tab).

#### Notifications & Logging

| Setting | Default | Description |
|---------|---------|-------------|
| Admin Notifications | No | Send email to admin when users perform privacy actions (address deletion, export/deletion requests) |
| Admin Email | (empty) | Recipient for admin notifications. Leave empty to use Global Configuration → Mail → From Email |
| Activity Logging | No | Write all privacy actions to Joomla's action log (`#__action_logs`) for audit purposes |

---

### Scheduled Task Settings

**Access:** `System → Manage → Scheduled Tasks → J2Commerce - Automatic data cleanup`

**Recommended Settings:**
- **Frequency:** Daily
- **Time:** 02:00 (2 AM, low traffic)
- **Enabled:** Yes

**What it does:**
- Finds users with expired retention
- Anonymizes data automatically
- Logs all actions
- Handles lifetime licenses correctly

---

## Legal Basis Examples

### Switzerland

```
• Switzerland: OR Art. 958f (10 years)
```

**Retention Years:** 10

---

### Germany

```
• Germany: AO §147 (10 years)
• Germany: HGB §257 (10 years)
```

**Retention Years:** 10

---

### Austria

```
• Austria: BAO §132 (7 years)
• Austria: UGB §212 (7 years)
```

**Retention Years:** 7

---

### France

```
• France: Code de commerce L123-22 (10 ans)
```

**Retention Years:** 10

---

### Spain

```
• España: Código de Comercio Art. 30 (6 años)
```

**Retention Years:** 6

---

### United Kingdom

```
• UK: Companies Act 2006 (6 years)
• UK: HMRC requirements (6 years)
```

**Retention Years:** 6

---

### USA

```
• USA: IRS regulations (7 years)
```

**Retention Years:** 7

---

### Multi-Country (EU)

```
• EU: GDPR Art. 6 Abs. 1 lit. c
• Germany: AO §147 (10 years)
• France: Code de commerce L123-22 (10 ans)
• Austria: BAO §132 (7 years)
```

**Retention Years:** 10 (use longest period)

---

### DACH Region

```
• Switzerland: OR Art. 958f (10 years)
• Germany: AO §147 (10 years)
• Austria: BAO §132 (7 years)
```

**Retention Years:** 10

---

## Multi-Language Support

### Supported Languages

- • **German** - `de-DE`
- • **English (UK)** - `en-GB`
- • **French** - `fr-FR`

### Adding New Languages

**Step 1: Create language folder**
```bash
mkdir -p language/it-CH
```

**Step 2: Copy files**
```bash
cp language/en-GB/plg_privacy_j2commerce.ini language/it-CH/
cp language/en-GB/plg_privacy_j2commerce.sys.ini language/it-CH/
cp language/en-GB/index.html language/it-CH/
```

**Step 3: Translate**
Open `language/it-CH/plg_privacy_j2commerce.ini` and translate all strings.

**Step 4: Reinstall plugin**
```bash
./build.sh
# Install ZIP in Joomla
```

---

## Lifetime License Metadata

### Why Explicit Metadata?

**The plugin uses explicit product metadata to detect Lifetime Licenses.**

**Advantages:**
- • No J2Store code modifications needed
- • Explicit configuration per product
- • No typos or false positives
- • Clear Yes/No choice
- • Visible in product overview

### Setup

**See [Implementation Guide](#implementation-guide) → Step 1**

### Technical Details

**Database Table:** `#__j2commerce_metafields` (J2Commerce 6.x) / `#__j2store_product_customfields` (J2Commerce 4.x)

**Example Data (J2Commerce 6.x):**
```sql
INSERT INTO `#__j2commerce_metafields`
  (`owner_id`, `owner_resource`, `metakey`, `metavalue`)
VALUES
  (123, 'product', 'is_lifetime_license', 'yes');
```

**Structure (J2Commerce 4.x):**
```sql
CREATE TABLE `#__j2store_product_customfields` (
  `j2store_customfield_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `field_name` varchar(255) NOT NULL,
  `field_value` text,
  PRIMARY KEY (`j2store_customfield_id`)
);
```

**Example Data (J2Commerce 4.x):**
```sql
INSERT INTO `#__j2store_product_customfields` VALUES
(1, 123, 'is_lifetime_license', 'Yes'),
(2, 456, 'is_lifetime_license', 'No');
```

**Query (J2Commerce 4.x):**
```sql
SELECT field_value
FROM #__j2store_product_customfields
WHERE product_id = 123
AND field_name = 'is_lifetime_license';
-- Result: 'Yes'
```

**Query (J2Commerce 6.x):**
```sql
SELECT metavalue
FROM #__j2commerce_metafields
WHERE owner_resource = 'product'
AND owner_id = 123
AND metakey = 'is_lifetime_license';
-- Result: 'yes'
```

---

## Development

### Building

```bash
./build.sh
```

Creates: `plg_privacy_j2commerce_<version>.zip`

## Automated Testing

This plugin has automated tests that run via GitHub Actions (`j2commerce-privacy.yml`) on pushes and pull requests to `main` that change this directory, `shared/**` or the workflow file. Besides the Joomla 5 and Joomla 6 suites, CI runs a PHP syntax check, the language file lint, an update from the previous release and a production-like lane (newest Joomla 6.x, PHP 8.4, MariaDB 10.6, J2Commerce 6 production pin). CI always tests the newest Joomla 5.4.x and 6.x releases (no pinned patch version) and prints them in each job log (`Tested versions: …`); a red run can therefore be caused by a new Joomla release. Details: [testing.md](../../.claude/skills/joomla-extensions/references/testing.md).

### Test Suites

Order as in `tests/test.env`:

1. **Installation** — the test environment installs the plugin through the Joomla web installer; plugin registration in DB, file deployment, template overrides; installer effects are checked against the plugin states recorded before the test setup enables all plugins
2. **Configuration** — plugin params, language files, XML manifest
3. **Privacy Plugin Base** — method existence and class structure
4. **Data Integration** — test data setup and CRUD operations
5. **Data Isolation** — cart and address deletion affect only the target user; `checkRetentionPeriod()` result structure
6. **Data Export** — `onPrivacyExportRequest` output validation
7. **Data Anonymization** — `onPrivacyRemoveData` retention logic; IP address and user agent removed from the consent record of the anonymized order, consent records of a recent order and of another user unchanged
8. **GDPR Compliance** — all DSGVO-relevant methods and hooks
9. **Template Overrides** — override source files and deployment verification (no J2Commerce 6 checkout override)
10. **Consent UI Render** — renders the checkout (J2Store override, or on J2Commerce 6 the `AfterDisplayShippingPayment` output) and the MyProfile override for the active stack (`com_j2store` / `com_j2commerce`) and asserts the real consent checkbox (`id`/`name="j2commerce_privacy_consent"`) and Privacy tab markup (`j2commerce-privacy-tab`, shield icon, translated tab title) actually appear in the produced HTML; the frontend options (Show Privacy Section hides the tab, all options off while the plugin is disabled, address delete button depends on its option). Limitation: to render in CLI the test injects the application and the plugin cache via reflection; it does not prove that Joomla loads the plugin or that a real checkout request reaches these layouts
11. **Consent Logging** — writes checkout consents to `#__privacy_consents` (logged-in and guest order), no duplicates, no e-mail copied; status lookup by `user_id` (checkout and registration subjects only) and for guests strictly by one order (token + e-mail); the consent system plugin's server-side checks for the shipping & payment step (incl. `controller=checkout` variant), confirmation and payment submission, enforced only from the plugin options, with the consent bound to the cart; checkbox rendering through `AfterDisplayShippingPayment`; real HTTP requests against the test site (real session and J2Commerce cart) for rendering, ticking, a skipped shipping & payment step, `template`/`templateStyle`/`Itemid` request parameters, a cart change and a checkout without any template override (accepted steps must return J2Commerce JSON without error), and the privacy policy link with SEF URLs off and on (escaped once); `onJ2CommerceAfterSaveOrder` only for the consent's cart; Privacy tab links (`com_privacy` form vs. `mailto:`, one button per enabled request option); update path through the Joomla CLI (a disabled system plugin stays disabled, `default_privacy.php` is only added next to an existing MyProfile override of an installed component, an unchanged checkout override of 1.5.5 is renamed, a changed one is kept with a warning, custom checkout overrides without the event are reported)
12. **AutoCleanup Task** — scheduled task registration; executes the cleanup routine through `php cli/joomla.php scheduler:run --id=<id>`, including the removal of IP address and user agent from the consent record of the anonymized order
13. **AcyMailing Integration** — newsletter consent sync
14. **Installer Messages** — shared suite: removes and reinstalls the package through the Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a non-zero exit code
15. **Uninstall** — clean removal from database and filesystem

### Running Tests Locally

Prerequisites: the package as `tests/extension.zip`; for Joomla 6 also `tests/j2commerce6.zip`, built from the J2Commerce 6 commit pinned in the workflow (`7edb6e11ae9148bf996b06c47a0d8266865af7b2`). Full commands: [Local Prerequisites](../../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites).

```bash
# in j2commerce/plg_privacy_j2commerce
./build.sh
mkdir -p tests
cp *.zip tests/extension.zip

cd tests
docker compose up -d
timeout 600 bash -c 'until docker exec plg_privacy_j2commerce_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v

# Joomla 6 (requires tests/j2commerce6.zip)
docker compose -f docker-compose.joomla6.yml up -d
timeout 600 bash -c 'until docker exec plg_privacy_j2commerce_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
J2COMMERCE_STACK=j6 CONTAINER_NAME=plg_privacy_j2commerce_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v
```

CI sets `TEST_STRICT_SKIP=1` (a test that would SKIP fails); prefix the command with it to reproduce CI.

## Troubleshooting

### Common Issues

**Issue: Lifetime products not detected**
- Check: Field name is exactly `is_lifetime_license`
- Check: Field value is `yes` (case-insensitive; `1` is not accepted)
- Check: The row references the correct product ID

**Issue: Scheduled task not running**
- Check: Joomla Cron configured
- Test: Run task manually
- Check: Task logs for errors

**Issue: Subscription-based products**
- **Status:** Recurring subscription lifecycle management not included in current version
- **Recommended Approach:** Apply standard accounting retention periods to subscription orders
- **Enterprise Requirements:** Contact Advans IT Solutions for custom subscription handling implementation

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Proprietary software. Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
