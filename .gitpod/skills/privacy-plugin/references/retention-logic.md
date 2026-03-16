# Retention Logic

## How Retention Works

`checkRetentionPeriod(int $userId): array` checks all orders for the user:

1. For each order, calculate `order_date + retention_years`
2. If any order is within the retention period → block deletion
3. Return array with `can_remove` (bool) and details per order

## Anonymization (orders outside retention)

`anonymizeOrders(int $userId)` sets these fields on expired orders:

| Field | Value |
|-------|-------|
| `user_email` | `anonymized@example.com` |
| `billing_first_name` | `Anonymized` |
| `billing_last_name` | `User` |
| `shipping_first_name` | `''` (cleared) |
| `shipping_last_name` | `''` (cleared) |
| `customer_note` | `''` (cleared) |
| `ip_address` | `''` (cleared) |
| Phone, address fields | `''` (cleared) |

Order numbers, dates, amounts, and product information are preserved (required for accounting).

## Lifetime License Exception

If a user has a lifetime license (`#__license_keys` + `#__j2store_product_customfields.is_lifetime_license = Yes`):

- After retention expires: orders are anonymized BUT email is preserved
- Reason: email is needed for license activation/verification
- `partialAnonymizeUserData()` handles this case (email kept, all other PII cleared)
- `anonymizeUserData()` handles the normal case (email also cleared)

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

`AutoCleanupTask` runs via Joomla Scheduler. It processes all users with expired retention periods automatically, without requiring a manual deletion request. Configure under `System → Scheduled Tasks → J2Commerce - Automatic Data Cleanup`.
