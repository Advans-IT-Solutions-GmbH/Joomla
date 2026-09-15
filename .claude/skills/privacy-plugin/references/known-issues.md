# Known Issues and Decisions

## Language Overrides Not Working in Emails

**Issue:** Language overrides created via Joomla's Language Manager do not apply to email tags like `[ORDERSTATUS]`, `[BILLING_COUNTRY]`, `[SHIPPING_METHOD]`.

**Root cause (analysis, not re-verified against J2Commerce 6):**
1. `helpers/email.php` loads overrides into `$jlang = JFactory::getLanguage()` (global instance, `Factory::$language`)
2. Tag resolution uses `$language = JLanguage::getInstance($order->customer_language)` (separate instance, `Language::$languages[]`)
3. These are two distinct static caches — overrides loaded into one are not visible to the other
4. Additionally, `loadLanguageOverrides()` only loads from `JPATH_ADMINISTRATOR`, but Joomla Language Manager writes overrides to `JPATH_SITE/language/overrides/`

**Status:** No upstream issue is tracked for this. (j2commerce/j2cart#273 is a different, closed issue about hard-coded UI texts.)

**Workaround:** None currently — JavaScript-based workarounds are fragile.

## `onAfterRender` Removed

`onAfterRender` was removed; checkout consent and the Privacy tab are rendered only via the bundled template overrides.

## Lifetime License Detection

Lifetime detection: J2Commerce 4 `#__j2store_product_customfields.field_value`, J2Commerce 6 `#__j2commerce_metafields.metavalue` (`metakey = is_lifetime_license`, case-insensitive `yes`). `#__license_keys` is not used by this plugin.

Both tables are populated via SQL (see the post-installation message); there is no product-edit UI for the flag. `#__j2store_product_customfields` is optional and must be created manually on J2Commerce 4.

## Updates Not Shown in Joomla Backend

**Issue:** `System → Update → Extensions` does not show available updates for our plugins.

**Root cause:** Plugins without `<client>` in `update.xml` get `client_id=1` in `jos_updates`. Our plugins install with `client_id=0`. Joomla cannot match the update record to the installed extension.

**Fix:** All plugin `update.xml` files must include `<client>site</client>`. See `joomla-extensions/references/repo-structure.md`.

**Discovered:** 2026-04-29, fixed in PR #69.

## Minimum Requirements

- Joomla 5.0+ (uses DI container, `Factory::getContainer()`)
- PHP 8.1+
- J2Commerce 4.0+

`script.php` enforces these via `$minimumJoomla` and `$minimumPhp`.

## Recurring Subscriptions

Automated handling of recurring subscription products is not implemented. Subscription lifecycle management requires manual intervention.
