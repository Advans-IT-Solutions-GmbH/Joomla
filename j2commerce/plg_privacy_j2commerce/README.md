# Privacy - J2Commerce Plugin

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/j2commerce-privacy.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/j2commerce-privacy.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-privacy.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-privacy.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

## Description

GDPR/DSGVO compliance solution for J2Commerce shops on Joomla 5.4 or later (5.4.x, 6.x). Integrates with Joomla's native Privacy Suite (`com_privacy`) to handle data export, deletion requests, and consent — specifically for J2Commerce order and customer data. Supports J2Commerce 4.x (`#__j2store_*` tables) and J2Commerce 6.x (`#__j2commerce_*` tables) via runtime detection.

### Compatibility Test Scope

The CI uses the official Joomla Docker images (newest Joomla 5.4.x and 6.x) plus real J2Commerce/J2Store runtimes for the core privacy export, anonymization, cart cleanup, retention, and uninstall paths. The bundled checkout and MyProfile template overrides are now also **rendered** for both stacks (`com_j2store` on J5/J2Store 4 and `com_j2commerce` on J6/J2Commerce 6) and asserted to actually emit the consent checkbox and Privacy tab markup, reading the real installed-and-enabled plugin params. Optional AcyMailing paths are exercised against a minimal database fixture only; they are not a full AcyMailing installation/runtime compatibility proof. Lifetime-license detection is covered through the J2Commerce metafield database path, but it does not replace an end-to-end license plugin runtime test.

## Features

- **Checkout Consent Checkbox** — Privacy consent during checkout via template override
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

1. Download `plg_privacy_j2commerce_<version>.zip` from the latest release
2. **System → Install → Extensions**
3. Upload and install
4. **Enable via System → Manage → Plugins → Privacy - J2Commerce**

The installer also installs the bundled task plugin **Task - J2Commerce Privacy Cleanup** (`plugins/task/j2commerceprivacy`) and enables it on the first installation. Updates install the new version of the task plugin but leave its enabled/disabled state unchanged. On install and on every update the installer also migrates scheduled tasks created with the old routine ID `plg_privacy_j2commerce.autocleanup` to `plg_task_j2commerceprivacy.autocleanup` and removes update sites of this plugin that still point to the repository's former path (`advansit/Joomla`), so Joomla only queries the current update server.

### Updating

The manifest registers an update server (`updates/update.xml` in this repository). New versions appear under **System → Update → Extensions**. On update, template overrides that were already deployed are not touched (see [Updating overrides after plugin updates](#updating-overrides-after-plugin-updates)).

### Uninstall

Uninstall via **System → Manage → Extensions**. In addition to Joomla's standard removal of the privacy plugin, `script.php` (`uninstall()`):

- deletes all scheduled tasks of type `plg_task_j2commerceprivacy.autocleanup` from `#__scheduler_tasks`
- removes the bundled task plugin (its `#__extensions`, `#__schemas` and `#__update_sites_extensions` rows and the folder `plugins/task/j2commerceprivacy`)

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

This menu item is required for any link to the `com_privacy` request form (for example from your own `default_privacy.php` layout, see [Profile Privacy Tab](#profile-privacy-tab)) to resolve for logged-in users. Guests cannot use `com_privacy` (Joomla redirects them to the login).

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

The plugin adds a privacy consent checkbox to the J2Commerce checkout (step 4: Shipping & Payment) via the template override `default_shipping_payment.php`. See [Template Integration](#template-integration) for deployment details.

**Validation:** When *Consent Required* is enabled, the checkbox carries the HTML `required` attribute and the override loads a small script (`media/plg_privacy_j2commerce/js/consent-validator.js`). The script listens to `submit` events of J2Store checkout forms (`form[action*="j2store"]`, `form.j2store-checkout-form`) and blocks submission with an alert if the consent checkbox is unchecked. The plugin performs no server-side consent check.

- **J2Commerce 4 (`com_j2store`):** the step is submitted by a `type="submit"` button, so the unchecked required checkbox blocks submission.
- **J2Commerce 6 (`com_j2commerce`):** the checkbox is displayed, but the "Continue" button of the step is a `type="button"` handled by J2Commerce's own JavaScript (no native form submit). Neither `consent-validator.js` nor the `required` attribute blocks the step, so consent is displayed only and not enforced.

### Consent Recording

The checkout consent is **not stored**. The checkbox is only displayed (and, on J2Commerce 4, required before the step can be submitted). The plugin does not write to `#__privacy_consents`, does not add the consent to the order, and does not evaluate the submitted `j2commerce_privacy_consent` value on the server. Joomla's own consent tracking (`#__privacy_consents`, e.g. via the core *System - Privacy Consent* plugin) is independent of this checkbox.

### Profile Privacy Tab

The bundled MyProfile override (`default.php`) adds a **Privacy** tab (tab id `j2commerce-privacy-tab`) whenever the plugin is installed and enabled.

> **Template override required.** The privacy tab is only rendered when the MyProfile template override (`default.php`) is in place. The override is deployed automatically on first install. See [Template Integration](#template-integration) for details.

The tab content is loaded with `$this->loadTemplate('privacy')`, i.e. from a `myprofile/default_privacy.php` layout. **The plugin does not ship this layout.** Your site template must provide `templates/{template}/html/com_j2store/myprofile/default_privacy.php` (J2Commerce 4) or `templates/{template}/html/com_j2commerce/myprofile/default_privacy.php` (J2Commerce 6). The plugin itself provides no consent status display and no privacy request buttons; links to Joomla's `com_privacy` request form (see [Required: Privacy Request Menu Item](#required-privacy-request-menu-item)) have to be part of that layout.

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
```

**J2Commerce 6.x** (`com_j2commerce`):
```
templates/{template}/html/com_j2commerce/checkout/default_shipping_payment.php
templates/{template}/html/com_j2commerce/myprofile/default.php
templates/{template}/html/com_j2commerce/myprofile/default_addresses.php
```

Rules:
- Files are **only copied if they do not already exist** — existing customisations are never overwritten.
- On **updates**, no files are copied. Manage overrides manually after updating.
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

`default.php` checks for the plugin at runtime:

```php
$_privacyPlugin  = PluginHelper::getPlugin('privacy', 'j2commerce');
$_privacyEnabled = !empty($_privacyPlugin);
$_privacyTabId   = 'j2commerce-privacy-tab';
```

If the plugin is not installed or disabled, `$_privacyEnabled` is `false` and the tab is not rendered. The plugin parameter *Show Privacy Section* (`show_privacy_section`) is **not evaluated** by the bundled overrides; the tab depends only on whether the plugin is enabled.

### Language file

Because this is a native Joomla privacy plugin and not a J2Commerce plugin, Joomla does not auto-import it in the frontend, so its language file is not loaded automatically. The bundled `default.php` does not load it. Only the bundled `default_addresses.php` loads `plg_privacy_j2commerce` (from `JPATH_ADMINISTRATOR`) when the plugin is enabled. If a `PLG_PRIVACY_J2COMMERCE_*` key renders as a raw string, load the language file in your override before it is used.

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
| `anonymize_orders` | Yes | Anonymize the users' orders and order billing/shipping data. |
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
| Consent Required | Yes | Make consent mandatory: blocks the checkout step if unchecked on J2Commerce 4; on J2Commerce 6 the checkbox is displayed but does not block the step (see [Checkout Consent Checkbox](#checkout-consent-checkbox)) |
| Privacy Policy Article | (none) | Joomla article containing your privacy policy — linked in the consent text |
| Consent Text | (default) | Checkbox label text. Use `{privacy_policy}` as placeholder for the policy link |

#### Frontend Self-Service

| Setting | Default | Description |
|---------|---------|-------------|
| Show Privacy Section | Yes | Intended to toggle the Privacy tab in the J2Commerce MyProfile page. Currently not evaluated: the bundled overrides show the tab whenever the plugin is enabled |
| Show Delete Address Buttons | Yes | Intended to toggle the per-address delete buttons in the Addresses tab. Currently not evaluated: the bundled `default_addresses.php` shows them whenever the plugin is enabled |
| Show Delete All Data | Yes | Intended for a data deletion request button in the Privacy tab. Currently not evaluated by the plugin or the bundled overrides |
| Show Export Data | Yes | Intended for a data export request button in the Privacy tab. Currently not evaluated by the plugin or the bundled overrides |

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
7. **Data Anonymization** — `onPrivacyRemoveData` retention logic
8. **GDPR Compliance** — all DSGVO-relevant methods and hooks
9. **Template Overrides** — override source files and deployment verification
10. **Consent UI Render** — renders the deployed checkout and MyProfile overrides for the active stack (`com_j2store` / `com_j2commerce`) and asserts the real consent checkbox (`id`/`name="j2commerce_privacy_consent"`) and Privacy tab markup (`j2commerce-privacy-tab`, shield icon) actually appear in the produced HTML. Limitation: to render in CLI the test injects the application and the plugin cache via reflection; it does not prove that Joomla loads the plugin or that a real checkout request reaches these layouts
11. **AutoCleanup Task** — scheduled task registration; executes the cleanup routine through `php cli/joomla.php scheduler:run --id=<id>`
12. **AcyMailing Integration** — detects AcyMailing via a minimal AcyMailing table structure created by the test environment (no AcyMailing installation), checks the export queries against a test subscriber, and triggers the plugin's own AcyMailing removal path against that structure to confirm that the subscriber and all related rows are deleted; also checks that detection does not fail when AcyMailing is absent
13. **Installer Messages** — shared suite: removes and reinstalls the package through the Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a non-zero exit code
14. **Uninstall** — clean removal from database and filesystem

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
