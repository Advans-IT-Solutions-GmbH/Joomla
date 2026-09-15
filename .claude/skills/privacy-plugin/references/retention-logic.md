# Retention Logic

## How Retention Works

`checkRetentionPeriod(int $userId): array` checks all orders for the user:

1. For each order, calculate `order_date + retention_years`
2. If any order is within the retention period → block deletion
3. If an order contains a lifetime-license product and its retention period has expired → also block deletion (`can_delete = false`, listed under `lifetime_licenses_accounting`)
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

- A deletion request is blocked (`can_delete = false`), also after the retention period has expired
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

The task plugin class `J2CommercePrivacy` (`plugins/task/j2commerceprivacy/`) provides the routine `plg_task_j2commerceprivacy.autocleanup`, run via Joomla Scheduler. It processes all users whose most recent order is older than the retention period, without requiring a manual deletion request. The task uses its own parameters (`retention_years`, `anonymize_orders`, `delete_addresses`), not the privacy plugin params. Configure under `System → Manage → Scheduled Tasks`.
