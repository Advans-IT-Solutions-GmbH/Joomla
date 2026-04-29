# System - J2Commerce Privacy Plugin

[![Build & Test](https://github.com/advansit/Joomla/actions/workflows/j2commerce-privacy.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/j2commerce-privacy.yml)
[![Release](https://github.com/advansit/Joomla/actions/workflows/release-privacy.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/release-privacy.yml)
[![Joomla 5](https://img.shields.io/badge/Joomla-5.x-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

## Description

GDPR/DSGVO compliance solution for J2Commerce shops on Joomla 5. Integrates with Joomla's native Privacy Suite (`com_privacy`) to handle data export, deletion requests, and consent — specifically for J2Commerce order and customer data.

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

- Joomla 5.0 or higher
- PHP 8.1 or higher
- J2Commerce 4.0 or higher
- Joomla Privacy Component enabled (`com_privacy`)

---

## Table of Contents

### Quick Start
1. [Installation](#installation)
2. [Quick Setup Guide](#quick-setup-guide)
3. [Configuration](#configuration)
4. [Joomla Privacy Framework](#joomla-privacy-framework)

### Core Features
4. [Features Overview](#features-overview)
5. [How It Works](#how-it-works)
6. [Lifetime License Detection](#lifetime-license-detection)
7. [Template Integration](#template-integration)

### User Guides
8. [Usage Guide](#usage-guide)
9. [Administrator Guide](#administrator-guide)
10. [Workflow Examples](#workflow-examples)

### Configuration
11. [Legal Basis Examples](#legal-basis-examples)
12. [Multi-Language Support](#multi-language-support)
13. [J2Store Custom Fields](#j2store-custom-fields)

### Technical
14. [Testing](#testing)
15. [Development](#development)
16. [Support](#support)

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

1. Download `plg_privacy_j2commerce.zip`
2. **System → Extensions → Install**
3. Upload and install
4. **Enable via System → Plugins → System - J2Commerce Privacy**

### Post-Installation Configuration

The plugin requires mandatory configuration before operation. A detailed setup wizard is displayed upon installation. The following steps must be completed:

1. Enable the plugin in Joomla's plugin manager
2. Configure retention periods and legal compliance parameters
3. **Verify template overrides** — deployed automatically on first install; check the postflight message for which files were copied or skipped (see [Template Integration](#template-integration))
4. **Create a hidden menu item for Privacy Requests** (see below)
5. Create J2Store Custom Field for license type identification (optional, for lifetime licenses)
6. Establish automated cleanup scheduling

Note: Failure to complete the Custom Field configuration will result in incorrect license type detection and potential data retention violations.

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

This menu item is required for the "Data Export" and "Data Deletion" buttons in the J2Commerce profile privacy tab to work for logged-in users. Guest users see mailto links instead (see below).

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

**Validation:** Client-side JavaScript validates both the AGB/TOS checkbox (J2Store built-in) and the privacy consent checkbox together. If either is unchecked, both error messages are shown simultaneously. The validation uses capturing-phase event listeners that run before J2Store's jQuery handler.

**Error containers** use class `j2-validation-error` (not `j2error`) so that J2Store's global `$('.j2error').remove()` in the AJAX success handler does not destroy them.

### Consent Recording

| User Type | When | Where | Identifier |
|-----------|------|-------|------------|
| Logged-in | Checkout confirm step | `#__privacy_consents` | `user_id` |
| Guest | First profile view after checkout | `#__privacy_consents` | `user_id=0`, email in `body` field |

**Logged-in users:** Consent is written to `#__privacy_consents` when the user reaches the checkout confirm step (step 5). The plugin params are read directly from `#__extensions` because the privacy plugin group is not imported during checkout AJAX requests.

**Guest users:** At checkout, `Factory::getApplication()->getIdentity()` returns `user_id=0`. The consent entry is created retroactively when the guest views the profile privacy tab (accessed via order token). The guest email is read from the J2Store session (`guest_order_email`), and orders are matched by email address.

### Profile Privacy Tab

The privacy tab in J2Commerce's "My Profile" shows consent status and privacy request buttons.

> **Template override required.** The privacy tab is only rendered when the MyProfile template override (`default.php`) is in place. The override is deployed automatically on first install. See [Template Integration](#template-integration) for details.

**Consent status lookup** checks three sources in order:
1. `#__privacy_consents` table (by `user_id` for logged-in, not available for guests)
2. `#__j2store_orders` by `user_id` (logged-in users)
3. `#__j2store_orders` by `user_email` (guest users, email from J2Store session)

If an order is found but no `#__privacy_consents` entry exists, one is auto-created.

**Privacy request buttons** (Data Export, Data Deletion):

| User Type | Button Behavior |
|-----------|----------------|
| Logged-in | Links to `com_privacy` request form (requires menu item, see above) |
| Guest | `mailto:` link to site admin email (guests cannot use `com_privacy` — Joomla's Dispatcher redirects them to login) |

---

## Template Integration

This plugin is a **native Joomla privacy plugin**, not a J2Commerce plugin. It is registered under Joomla's `privacy` plugin group, which is what allows it to participate in Joomla's built-in Privacy Suite (`com_privacy`): handling data export requests, deletion requests, consent tracking, and the scheduled cleanup task.

The trade-off is that J2Commerce does not know about it. J2Commerce's `eventWithHtml()` — the mechanism J2Commerce uses to let plugins inject content into its views — only loads plugins from its own `j2store` group. Plugins in the `privacy` group are invisible to it. This means there is no event hook available inside J2Commerce's checkout or MyProfile views that this plugin can use.

Template overrides are the solution: by placing PHP files in `templates/{active-template}/html/com_j2store/`, the overrides run as part of J2Commerce's own rendering and can check for this plugin via `PluginHelper` to conditionally add the consent checkbox and Privacy tab.

### Automatic deployment on first install

On first install, `script.php` copies the bundled overrides into every active frontend template:

```
templates/{template}/html/com_j2store/checkout/default_shipping_payment.php
templates/{template}/html/com_j2store/myprofile/default.php
templates/{template}/html/com_j2store/myprofile/default_addresses.php
```

Rules:
- Files are **only copied if they do not already exist** — existing customisations are never overwritten.
- On **updates**, no files are copied. Manage overrides manually after updating.
- The postflight message lists which files were copied and which were skipped.

### Manual deployment

If the automatic copy was skipped (file already existed, or you are installing on a non-standard template path), copy the source files manually:

```
# Source (inside the installed plugin)
JPATH_PLUGINS/privacy/j2commerce/overrides/com_j2store/

# Destination (repeat for each active template)
templates/{your-template}/html/com_j2store/
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

| Field | Description |
|---|---|
| email | Subscriber e-mail address |
| name | Subscriber name |
| confirmed | Whether the subscriber confirmed their opt-in |
| created | Subscription creation date |
| list (per row) | List name |
| status | `subscribed` or `unsubscribed` |
| subscription_date | Date subscribed to this list |
| unsubscribe_date | Date unsubscribed from this list (if applicable) |

**Deletion (`onPrivacyRemoveData`):**

The subscriber record in `acym_user` and all rows in `acym_user_has_list` are deleted. List-association rows are deleted first to respect the foreign key constraint.

### MyProfile Newsletter tab

The Newsletter tab in J2Commerce MyProfile (`default_newsletter.php`) lets logged-in users manage their subscriptions directly — no redirect to a separate AcyMailing frontend page.

**To enable the Newsletter tab:**

1. Install AcyMailing (any version, any license tier) on the Joomla site.
2. Ensure the template override `templates/{template}/html/com_j2store/myprofile/default_newsletter.php` exists. This file is maintained in the `advansit/advans.ch` repository under `src/template/html/com_j2store/myprofile/default_newsletter.php`.
3. Ensure `default.php` includes the Newsletter tab block (already present in the advans.ch template override).
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
$privacyPlugin = PluginHelper::getPlugin('privacy', 'j2commerce');
if ($privacyPlugin) {
    $privacyParams = new \Joomla\Registry\Registry($privacyPlugin->params);
    $showPrivacyTab = (bool) $privacyParams->get('show_privacy_section', 1);
    Factory::getLanguage()->load('plg_privacy_j2commerce', JPATH_PLUGINS . '/privacy/j2commerce');
}
```

If the plugin is not installed or disabled, `$showPrivacyTab` stays `false` and the tab is not rendered — no errors.

### Language file must be loaded manually

Because this is a native Joomla privacy plugin and not a J2Commerce plugin, Joomla does not auto-import it in the frontend. Its language file is therefore not loaded automatically either. Without the explicit `Factory::getLanguage()->load(...)` call in `default.php`, all `PLG_PRIVACY_J2COMMERCE_*` keys render as raw strings in the tab. This call is already included in the provided `default.php` — do not remove it.

### Updating overrides after plugin updates

When the plugin is updated, the override files in `JPATH_PLUGINS/privacy/j2commerce/overrides/` are updated but the deployed copies in `templates/` are **not** touched. After a plugin update:

1. Compare your deployed override with the new source file.
2. Merge any changes relevant to your customisation.
3. The postflight message on update will remind you of this.

### Licenses tab (optional)

`default.php` also conditionally renders a **Licenses** tab if the `#__license_keys` table exists and contains rows for the current user. This tab is unrelated to the privacy plugin — it is part of the Advans IT Solutions licensing system. If you do not use that system, the tab simply does not appear (the query is wrapped in a `try/catch`).

---

## Implementation Guide

### Estimated Implementation Time: 20-30 minutes

### Step 1: Configure J2Store Custom Field (Optional — Lifetime License Detection Only)

This step is only required if your shop sells products with perpetual (lifetime) licenses. The plugin functions fully without it — lifetime license detection is simply skipped.

**Two separate tables are involved:**

| Table | Purpose | How to populate |
|-------|---------|-----------------|
| `#__j2store_product_customfields` | Marks which products are lifetime licenses | J2Store Custom Fields UI |
| `#__license_keys` | Stores issued license keys per user | Separate SQL — see Post-Install Message |

**To mark products as lifetime licenses, navigate to:**
```
Components → J2Store → Setup → Custom Fields → New
```

**Configure as follows:**

| Setting | Value |
|---------|-------|
| **Field Name** | `is_lifetime_license` |
| **Field Label** | Lifetime License |
| **Field Type** | Radio |
| **Display in** | Product |
| **Required** | No |
| **Published** | Yes |
| **Options** | Yes / No |
| **Default Value** | No |

Click **Save & Close** to persist the configuration.

> **Note:** The `#__license_keys` table (for issued license keys) is a separate table not visible in the J2Commerce Custom Fields UI. See the Post-Install Message for the SQL to create it.

---

### Step 2: Product Classification

For each product requiring perpetual license handling:

1. Navigate to: `Components → J2Store → Catalog → Products`
2. Open the product
3. Scroll to **Custom Fields** section
4. Set **Lifetime License: Yes**
5. **Save & Close**

Repeat this process for all products requiring perpetual license data retention.

---

### Step 3: Plugin Activation

1. Navigate to: `System → Plugins`
2. Locate: `Privacy - J2Commerce`
3. Change status from Disabled to Enabled

---

### Step 4: Configure Retention Parameters

Navigate to: `System → Plugins → Privacy - J2Commerce`

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

⚠️ **Important:** Replace this with your actual privacy contact email. This address is shown to users in all retention messages.

**Where to change:**
- `System → Plugins → Privacy - J2Commerce`
- Field: "Support Email"
- Example: `privacy@your-company.com`

Persist configuration changes.

---

### Step 5: Automated Cleanup Scheduling

Navigate to: `System → Scheduled Tasks → New`

1. Select task type: **J2Commerce - Automatic Data Cleanup**
2. Configure execution parameters:
   - **Execution Frequency:** Daily
   - **Execution Time:** 02:00 (recommended for minimal system load)
   - **Status:** Enabled
3. Save configuration

---

### Implementation Verification

Validate the implementation using the following test procedure:

1. Create a test product with Custom Field `Lifetime License` set to `Yes`
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
- **Internationalization:** Multi-language interface support (German-CH, German-DE, English-GB, French-FR)
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
- Email address → `anonymized@example.com`
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

**Detection Method:** J2Store Custom Field

The plugin checks the J2Store Custom Field `is_lifetime_license` for each product:

```sql
SELECT field_value 
FROM #__j2store_product_customfields 
WHERE product_id = ? 
AND field_name = 'is_lifetime_license'
```

**Result:**
- `field_value = 'Yes'` → Lifetime License
- `field_value = 'No'` → Regular Product
- `field_value = NULL` → Regular Product (field not set)

### Technical Implementation Rationale

**Previous Implementation (Deprecated):** Heuristic keyword detection in product metadata
- Unreliable due to typographical variations
- Lacked explicit configuration control
- Language-dependent pattern matching
- Prone to false positive classifications

**Current Implementation:** Structured metadata via J2Store Custom Fields
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

**For each Lifetime License product:**

1. Open product in J2Store
2. Find "Custom Fields" section
3. Set "Lifetime License" to "Yes"
4. Save

**That's it!** No code changes, no database modifications needed.

---

## Usage Guide

### Data Export

The export request flow is handled by Joomla's core Privacy component. See the [Joomla Privacy Suite Guide](https://docs.joomla.org/Privacy_Suite_Guide) for how users submit export requests and how administrators process them.

This plugin extends the export with J2Commerce-specific data: orders, order items, addresses, and (if configured) cart data.

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
DATA DELETION CURRENTLY NOT POSSIBLE
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
System → Scheduled Tasks → J2Commerce - Automatic Data Cleanup
```

**View logs:**
```
System → Scheduled Tasks → [Task] → View Logs
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
| **nDSG Art. 17** | Right to deletion, with exception for legal obligations |

**Implementation:**
- Orders within 10-year retention period: **Kept intact** (not anonymized)
- Orders outside retention period: **Anonymized** on deletion request
- Address book entries: **Deleted** immediately on request
- Cart data: **Deleted** immediately on request

### Payment Provider Data

Payment data is processed by third-party payment providers:
- **Stripe** - https://stripe.com/privacy
- **PayPal** - https://www.paypal.com/privacy
- **Other providers** - Contact directly

This plugin does not store or have access to complete payment details. Users must contact payment providers directly for payment data inquiries.

---

## Configuration

### Plugin Settings

**Access:** `System → Plugins → Privacy - J2Commerce`

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
| Legal Basis | (empty) | Legal grounds shown in retention error messages to users |
| Support Email | support@example.com | Contact address shown to users for privacy inquiries. **Must be changed before going live.** |

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
| Show Privacy Section | Yes | Render the Privacy tab in the J2Commerce MyProfile page |
| Show Delete Address Buttons | Yes | Show per-address delete buttons in the Addresses tab |
| Show Delete All Data | Yes | Show data deletion request button in the Privacy tab |
| Show Export Data | Yes | Show data export request button in the Privacy tab |

#### Notifications & Logging

| Setting | Default | Description |
|---------|---------|-------------|
| Admin Notifications | No | Send email to admin when users perform privacy actions (address deletion, export/deletion requests) |
| Admin Email | (empty) | Recipient for admin notifications. Leave empty to use the site admin email |
| Activity Logging | No | Write all privacy actions to Joomla's action log (`#__action_logs`) for audit purposes |

---

### Scheduled Task Settings

**Access:** `System → Scheduled Tasks → J2Commerce - Automatic Data Cleanup`

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

**Note:** Error messages are currently in German. For full multi-language support, contact support.

---

## J2Store Custom Fields

### Why Custom Fields?

**The plugin uses J2Store's built-in Custom Fields feature to detect Lifetime Licenses.**

**Advantages:**
- • No J2Store code modifications needed
- • Explicit configuration per product
- • No typos or false positives
- • Clear Yes/No choice
- • Visible in product overview

### Setup

**See [Quick Setup Guide](#quick-setup-guide) Step 1**

### Technical Details

**Database Table:** `#__j2store_product_customfields`

**Structure:**
```sql
CREATE TABLE `#__j2store_product_customfields` (
  `j2store_customfield_id` int(11) NOT NULL AUTO_INCREMENT,
  `product_id` int(11) NOT NULL,
  `field_name` varchar(255) NOT NULL,
  `field_value` text,
  PRIMARY KEY (`j2store_customfield_id`)
);
```

**Example Data:**
```sql
INSERT INTO `#__j2store_product_customfields` VALUES
(1, 123, 'is_lifetime_license', 'Yes'),
(2, 456, 'is_lifetime_license', 'No');
```

**Query:**
```sql
SELECT field_value 
FROM #__j2store_product_customfields 
WHERE product_id = 123 
AND field_name = 'is_lifetime_license';
-- Result: 'Yes'
```

---

## Troubleshooting

### Common Issues

**Issue: Custom Field not visible**
- Check: Field is Published
- Check: Display in = Product
- Clear cache: System → Clear Cache

**Issue: Lifetime products not detected**
- Check: Field name is exactly `is_lifetime_license`
- Check: Field value is `Yes` (not `yes` or `1`)
- Check: Product saved after setting field

**Issue: Scheduled task not running**
- Check: Joomla Cron configured
- Test: Run task manually
- Check: Task logs for errors

**Issue: Subscription-based products**
- **Status:** Recurring subscription lifecycle management not included in current version
- **Recommended Approach:** Apply standard accounting retention periods to subscription orders
- **Enterprise Requirements:** Contact Advans IT Solutions for custom subscription handling implementation

## Automated Testing

This plugin has automated tests that run on every push and on pull requests via GitHub Actions.

### Test Suites

1. **Installation** — Plugin registration in DB, file deployment, template overrides
2. **Configuration** — Plugin params, language files, XML manifest
3. **Plugin Class** — Method existence and class structure
4. **Data Integration** — Test data setup and CRUD operations
5. **Privacy Export** — `onPrivacyExportRequest` output validation
6. **Data Anonymization** — `onPrivacyRemoveData` retention logic
7. **GDPR Compliance** — All DSGVO-relevant methods and hooks
8. **Template Overrides** — Override source files and deployment verification
9. **Uninstall** — Clean removal from database and filesystem

### Running Tests Locally

```bash
cd tests
docker compose up -d
./run-tests.sh all
docker compose down -v
```

## Development

### Build

```bash
./build.sh
```

Creates: `plg_privacy_j2commerce.zip`

### Key Classes

**J2Commerce.php:**
- `onPrivacyExportRequest()` — Export user data
- `onPrivacyCanRemoveData()` — Check if deletion allowed
- `onPrivacyRemoveData()` — Perform deletion/anonymization
- `checkRetentionPeriod()` — Check retention periods
- `isLifetimeLicense()` — Check custom field

**AutoCleanupTask.php:**
- `autoCleanup()` — Main cleanup logic
- `hasLifetimeLicense()` — Check for lifetime licenses
- `partialAnonymizeUserData()` — Keep email, delete rest
- `anonymizeUserData()` — Full anonymization

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Proprietary software. Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
