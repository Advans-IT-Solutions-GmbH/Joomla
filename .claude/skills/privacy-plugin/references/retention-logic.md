# Retention Logic

## How Retention Works

`checkRetentionPeriod(int $userId): array` checks all orders for the user:

1. Retention end per order = end of the fiscal year of the order (`fiscal_year_end`, MM-DD, default 12-31) + `retention_years` (`src/Retention/RetentionPeriod.php`, OR Art. 958f). The fiscal year is determined in the site time zone (`offset`); `created_on` is UTC, `RetentionPeriod::cutoff()` returns the UTC boundary: `created_on <= cutoff` means expired (checked against `isExpired()` in test 05, four zones). `02-29` = last day of February; non-existent days are rejected by the form rule `FiscalyearendRule` (`src/Rule`, `addruleprefix` on both forms) and otherwise count as 12-31 (task logs it).
2. Orders within the retention period are listed under `orders` with `retention_end` but do **not** block the request (`can_delete` stays true): `processDataRemoval()` deletes addresses, carts, AcyMailing data, anonymizes expired orders, keeps these orders and reports them (admin flash message, admin notification/log, e-mail to the request address). Joomla's `plg_privacy_user` pseudonymises the account in the same request.
3. Lifetime licenses (provisional rule "variant A", pending maintainer confirmation): never block. `can_delete` is always true. Expired lifetime orders are listed under `lifetime_expired`; `anonymizeOrders()` keeps only their `user_email` (`LifetimeLicenses::orderIds()`, fail-closed). The task applies the same rule per order (`anonymizeOrderTables()`), for users and guests.
4. `processDataRemoval()` captures username, account e-mail and request e-mail first (Joomla's `plg_privacy_user` pseudonymises the shared `User` object and may run first); AcyMailing is looked up with these addresses. `reportRetainedOrders()` sends the customer e-mail first (language: `customer_language` of the newest order, else site default) and then enqueues the admin message: sent (message), invalid address or failed (warning).
5. Return array with `can_delete` (bool), `orders` (retained, with `retention_end`, `lifetime`) and `lifetime_expired`

## Anonymization (orders outside retention)

`anonymizeOrders(int $userId)` sets these fields on expired orders:

| Field | Value |
|-------|-------|
| `user_email` | `anonymized@deleted.invalid` |
| `billing_first_name` | `Anonymized` |
| `billing_last_name` | `User` |
| `shipping_first_name` | `''` (cleared) |
| `shipping_last_name` | `''` (cleared) |
| `customer_note` | `''` (cleared) |
| `ip_address` | `''` (cleared) |
| Phone, address fields | `''` (cleared) |

Order numbers, dates, amounts, and product information are preserved (required for accounting).

## Lifetime License Exception

Lifetime detection: J2Commerce 4 `#__j2store_product_customfields.field_value`, J2Commerce 6 `#__j2commerce_metafields.metavalue` (`metakey = is_lifetime_license`, case-insensitive `yes`). `#__license_keys` is not used by this plugin.

Per order (provisional rule, pending confirmation):

- Removal request and task anonymize a lifetime-license order after its retention period but keep its order e-mail (license reactivation); other orders of the customer lose it
- A removal request is not refused because of a lifetime license
- Task: `anonymizeUserData()` returns the number of lifetime orders; `partialAnonymizeUserData()` is kept as an alias; status KNOCKOUT if guest orders failed or all users failed

## Retention Periods by Country

| Country | Years | Legal Basis |
|---------|-------|-------------|
| Switzerland | 10 | OR Art. 958f, MWSTG Art. 70 |
| Germany | 10 | AO §147, HGB §257 |
| Austria | 7 | BAO §132, UGB §212 |
| France | 10 | Code de commerce |
| Spain | 6 | Código de Comercio |
| UK | 6 | Companies Act 2006 |
| USA | 7 | IRS guidelines |

Default: 10 years (Swiss standard).

## Scheduled Cleanup

The task plugin class `J2CommercePrivacy` (`plugins/task/j2commerceprivacy/`) provides the routine `plg_task_j2commerceprivacy.autocleanup`, run via Joomla Scheduler. It processes, in every J2Commerce data set (`J2CommerceStack::dataSets()`), all users whose most recent order is outside the retention period, and guest orders (`user_id = 0`) outside it per order (`anonymizeExpiredGuestOrders()`: only orders that still contain personal data; lifetime-license orders keep the e-mail, lookup fail-closed). Consent records of anonymized orders lose IP address and user agent (`ConsentRepository::removeOrderEvidence()`); afterwards `anonymizeLegacyConsents()` and `removeStaleEvidence()` (records outside the retention period, deleted or anonymized orders) run in their own try/catch, an error there is logged and ends the run with KNOCKOUT without stopping the order cleanup. The task uses its own parameters (`retention_years`, `fiscal_year_end`, `anonymize_orders`, `delete_addresses`), not the privacy plugin params; it loads `RetentionPeriod`/`ConsentRepository` from `plugins/privacy/j2commerce/src` if the namespace map does not resolve them. Configure under `System → Manage → Scheduled Tasks`.
