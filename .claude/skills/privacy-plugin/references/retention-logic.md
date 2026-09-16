# Retention Logic

## How Retention Works

`checkRetentionPeriod(int $userId): array` checks all orders for the user:

1. Retention end per order = end of the fiscal year of the order (`fiscal_year_end`, MM-DD, default 12-31) + `retention_years` (`src/Retention/RetentionPeriod.php`, OR Art. 958f). SQL filters use `RetentionPeriod::cutoff()`: `created_on <= cutoff` means expired (checked against `isExpired()` in test 05).
2. Orders within the retention period are listed under `orders` with `retention_end` but do **not** block the request (`can_delete` stays true): `processDataRemoval()` deletes addresses, carts, AcyMailing data, anonymizes expired orders, keeps these orders and reports them (admin flash message, admin notification/log, e-mail to the request address). Joomla's `plg_privacy_user` pseudonymises the account in the same request.
3. If an order contains a lifetime-license product and its retention period has expired → block deletion (`can_delete = false`, listed under `lifetime_licenses_accounting`). Open product decision: a lifetime order within the retention period does not block.
4. Return array with `can_delete` (bool) and details per order

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

If a user has a lifetime license:

- A deletion request is blocked (`can_delete = false`) once the retention period of the lifetime order has expired
- The scheduled task anonymizes the orders after retention expires BUT keeps the order email
- Reason: email is needed for license activation/verification
- In the task plugin, `partialAnonymizeUserData()` handles this case (email kept, all other PII cleared)
- `anonymizeUserData()` handles the normal case (email also set to `anonymized@deleted.invalid`)

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

The task plugin class `J2CommercePrivacy` (`plugins/task/j2commerceprivacy/`) provides the routine `plg_task_j2commerceprivacy.autocleanup`, run via Joomla Scheduler. It processes all users whose most recent order is outside the retention period, and guest orders (`user_id = 0`) outside it per order (`anonymizeExpiredGuestOrders()`: only orders that still contain personal data; lifetime-license orders keep the e-mail, lookup fail-closed). Consent records of anonymized orders lose IP address and user agent (`ConsentRepository::removeOrderEvidence()`). The task uses its own parameters (`retention_years`, `fiscal_year_end`, `anonymize_orders`, `delete_addresses`), not the privacy plugin params; it loads `RetentionPeriod`/`ConsentRepository` from `plugins/privacy/j2commerce/src` if the namespace map does not resolve them. Configure under `System → Manage → Scheduled Tasks`.
