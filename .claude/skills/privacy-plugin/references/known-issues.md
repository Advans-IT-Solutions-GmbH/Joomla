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

`onAfterRender` was removed. The Privacy tab is rendered via the bundled MyProfile overrides; the checkout consent checkbox via the J2Store checkout override (J2Commerce 4) or, on J2Commerce 6, by the system plugin through the core event `AfterDisplayShippingPayment` (no J2Commerce 6 checkout override is shipped; unchanged copies of earlier versions are renamed on install/update).

## Lifetime License Detection

Lifetime detection: J2Commerce 4 `#__j2store_product_customfields.field_value`, J2Commerce 6 `#__j2commerce_metafields.metavalue` (`metakey = is_lifetime_license`, case-insensitive `yes`). `#__license_keys` is not used by this plugin.

Both tables are populated via SQL (see the post-installation message); there is no product-edit UI for the flag. `#__j2store_product_customfields` is optional and must be created manually on J2Commerce 4.

## Updates Not Shown in Joomla Backend

**Issue:** `System → Update → Extensions` does not show available updates for our plugins.

**Root cause:** Plugins without `<client>` in `update.xml` get `client_id=1` in `jos_updates`. Our plugins install with `client_id=0`. Joomla cannot match the update record to the installed extension.

**Fix:** All plugin `update.xml` files must include `<client>site</client>`. See `joomla-extensions/references/repo-structure.md`.

**Discovered:** 2026-04-29, fixed in PR #69.

## `getContainer()` Is Protected on the Application

`CMSApplication::getContainer()` is `protected`. Calling `$app->getContainer()` from a plugin throws
`\Error: Call to protected method`, which aborted removal requests before the customer notice was
sent. Always take services from `Factory::getContainer()` instead. The plugin funnels both mail paths
(customer retention notice and administrator notification) through one `protected createMailer()`,
which the tests override, and the administrator notification catches `\Throwable`, so a mailer
failure is logged and never breaks the removal.

**Discovered:** 2026-09-17 in a manual test on a test site, fixed in PR #188.

## Minimum Requirements

- Joomla 5.4+ (5.4.x and 6.x; uses DI container, `Factory::getContainer()`)
- PHP 8.1+
- J2Commerce 4.0+; J2Commerce 6.3.4+ for the checkout consent checkbox (event `AfterDisplayShippingPayment`, commit `d7992c66`, merged 2026-05-28 after the 6.3.3 version bump; `script.php` `MIN_J2COMMERCE_EVENT_VERSION`)

`script.php` enforces these via `$minimumJoomla` and `$minimumPhp`.

## A Second Open Request of the Same Type Is Refused by com_privacy

`com_privacy` accepts only one open request per e-mail address **and request type**.
`RequestModel::createRequest()` counts the rows in `#__privacy_requests` with the same `email`, the
same `request_type` and `status` 0 (created, not yet confirmed) or 1 (confirmed, not yet carried
out). If it finds one, it sets `COM_PRIVACY_ERROR_PENDING_REQUEST_OPEN` and creates nothing. So an
information request can be refused while a removal request for the same address goes through in the
same session, because only the export type was already open.

Two points matter for anyone who builds a front end around that form:

- **The refusal and the success look alike.** `RequestController::submit()` reports the refusal with
  the message type `notice` and the success with the type `info`. Both render as `alert-info`, so a
  check on `alert-danger`, `alert-error` or `alert-warning` never sees the refusal and a form that
  submits by AJAX would show a success that has nothing behind it. Judge the answer by its content,
  for example by looking for `alert-success` or for the success text, and treat everything else as
  not created. Do not treat a failed request as a success either.
- **An unconfirmed request keeps blocking.** Status 0 is reached by every request that was never
  confirmed through its e-mail link, so a visitor can be blocked by a request they never completed.
  A clear answer has to say that a request of this type is already running.

This plugin creates no privacy requests of its own. The MyProfile privacy tab only links to the core
form, so the behaviour is Joomla's, not the plugin's.

**Discovered:** 2026-09-24 in a manual test on a test site.

## Recurring Subscriptions

Automated handling of recurring subscription products is not implemented. Subscription lifecycle management requires manual intervention.
