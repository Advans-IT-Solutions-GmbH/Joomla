# Known Issues and Decisions

## Language Overrides Not Working in Emails

**Issue:** Language overrides created via Joomla's Language Manager do not apply to email tags like `[ORDERSTATUS]`, `[BILLING_COUNTRY]`, `[SHIPPING_METHOD]`.

**Root cause (confirmed, GitHub Issue #273):**
1. `helpers/email.php` loads overrides into `$jlang = JFactory::getLanguage()` (global instance, `Factory::$language`)
2. Tag resolution uses `$language = JLanguage::getInstance($order->customer_language)` (separate instance, `Language::$languages[]`)
3. These are two distinct static caches — overrides loaded into one are not visible to the other
4. Additionally, `loadLanguageOverrides()` only loads from `JPATH_ADMINISTRATOR`, but Joomla Language Manager writes overrides to `JPATH_SITE/language/overrides/`

**Status:** Reported upstream to j2commerce/j2cart#273. Fix pending from j2commerce team.

**Workaround:** None currently — JavaScript-based workarounds are fragile.

## `onAfterRender` and Privacy Plugin Group

The `privacy` plugin group IS able to hook into `onAfterRender`. The plugin explicitly registers this event. The README previously stated otherwise — this was corrected in v1.5.0.

## Custom Field vs License Keys Table

Two separate tables are involved in lifetime license detection:

- `#__j2store_product_customfields` — marks products as lifetime licenses (populated via J2Commerce Custom Fields UI)
- `#__license_keys` — stores issued license keys per user (separate table, not visible in J2Commerce UI, created via SQL in post-install message)

## Minimum Requirements

- Joomla 5.0+ (uses DI container, `Factory::getContainer()`)
- PHP 8.1+
- J2Commerce 4.0+

`script.php` enforces these via `$minimumJoomla` and `$minimumPhp`.

## Recurring Subscriptions

Automated handling of recurring subscription products is not implemented. Subscription lifecycle management requires manual intervention.
