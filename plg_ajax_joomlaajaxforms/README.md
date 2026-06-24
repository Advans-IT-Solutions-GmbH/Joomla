# Joomla! AJAX Forms

[![Build & Test](https://github.com/advansit/Joomla/actions/workflows/joomla-ajax-forms.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/joomla-ajax-forms.yml)
[![Release](https://github.com/advansit/Joomla/actions/workflows/release-joomla-ajax-forms.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/release-joomla-ajax-forms.yml)
[![Joomla 5](https://img.shields.io/badge/Joomla-5.x-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

## Description

A Joomla plugin that provides AJAX handling for user forms, authentication, profile management, and J2Commerce cart operations — without page reloads.

## Features

| Feature | AJAX Task | Description |
|---------|-----------|-------------|
| Login | `login` | Authentication with redirect to Joomla's MFA captive page when 2FA is enabled |
| Logout | `logout` | Session termination with redirect |
| Registration | `register` | User registration with email verification and admin approval |
| Password Reset | `reset` | Password reset email request |
| Username Reminder | `remind` | Username reminder email request |
| Profile Editing | `saveProfile` | Update name, email, password |
| Cart: Remove Item | `removeCartItem` | Remove item from J2Commerce cart (v4 and v6) |
| Cart: Get Count | `getCartCount` | Get current cart item count |

All features can be individually enabled/disabled via plugin parameters.

## Requirements

- Joomla 5.x or 6.x
- PHP 8.1+
- J2Commerce 4.x or 6.x (only for cart features)

## Installation

1. Download `plg_ajax_joomlaajaxforms.zip` from the [latest release](https://github.com/advansit/Joomla/releases?q=ajaxforms)
2. Install via Joomla Extension Manager
3. Enable under System > Plugins > "Joomla! AJAX Forms"

The installer checks the `.htaccess` on the web server. If rewrite rules block `/component/` or `index.php?option=com_*` URLs, `com_ajax` must be whitelisted — otherwise all AJAX calls will fail silently. The installer warns if exceptions are missing.

Required `.htaccess` exceptions (only if URL blocking is active):

```apache
# Allow com_ajax plugin calls through /component/ blocking
RewriteCond %{QUERY_STRING} !plugin= [NC]

# Allow com_ajax through index.php?option= blocking
RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]
```

## Configuration

| Parameter | Description | Default |
|-----------|-------------|---------|
| Enable Login | AJAX login with MFA support | Yes |
| Enable Registration | AJAX user registration | Yes |
| Enable Password Reset | AJAX password reset | Yes |
| Enable Username Reminder | AJAX username reminder | Yes |
| Enable Profile Editing | AJAX profile save (name, email, password) | Yes |
| Enable J2Store Cart | AJAX cart operations (requires J2Commerce 4.x or 6.x) | Yes |

### J2Commerce Cart Compatibility

The cart features support both J2Commerce 4.x (`#__j2store_*` tables) and J2Commerce 6.x (`#__j2commerce_*` tables). The version is detected at runtime by checking whether `#__j2store_carts` exists in the database.

If neither `#__j2store_carts` nor `#__j2commerce_carts` is found, cart operations return a `PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND` error — the cart feature is silently unavailable without breaking other plugin functionality.

**Schema differences handled automatically:**

| Operation | J2Commerce 4.x | J2Commerce 6.x |
|---|---|---|
| Cart item count | `SUM(product_qty)` from `#__j2store_cartitems` joined via `cart_id` | `SUM(product_qty)` from `#__j2commerce_cartitems` joined via `cart_id` |
| Cart total | Returns `"0.00"` — see limitation below | Returns `"0.00"` — see limitation below |
| Remove item | DELETE from `#__j2store_cartitems` WHERE `cart_id IN (SELECT j2store_cart_id ...)` | DELETE from `#__j2commerce_cartitems` WHERE `cart_id IN (SELECT j2commerce_cart_id ...)` |

**Limitation — cart total (both versions):**
`cartTotal` always returns `"0.00"`. Computing a correct total requires joining to the pricing engine (tier prices, customer group rules, coupons, taxes), which is not feasible in a lightweight plugin query. The frontend should suppress display of the total when `cartTotal === "0.00"` and rely on the J2Commerce cart view for the authoritative total.

## Usage

### Template Integration

Load the script in your template overrides:

```php
use Joomla\CMS\Plugin\PluginHelper;

if (PluginHelper::isEnabled('ajax', 'joomlaajaxforms')) {
    $wa = Factory::getApplication()->getDocument()->getWebAssetManager();
    $wa->registerAndUseScript('plg_ajax_joomlaajaxforms', 'plg_ajax_joomlaajaxforms/joomlaajaxforms.js', [], ['defer' => true]);
}
```

The plugin automatically initializes form handlers for login, reset, remind, and registration forms. For cart and profile operations, use the JavaScript API:

```javascript
// Remove cart item
JoomlaAjaxForms.removeCartItem(cartItemId, clickedElement, callback);

// Save profile form
JoomlaAjaxForms.saveProfile(formElement, callback);

// Logout
JoomlaAjaxForms.logout(returnUrl);
```

### JSON Response Format

```json
{
    "success": true,
    "message": "Success message",
    "data": { },
    "error": null
}
```

Error responses use J2Commerce-compatible format:

```json
{
    "success": false,
    "message": null,
    "data": null,
    "error": { "warning": "Error message" }
}
```

## Development

### Structure

```
plg_ajax_joomlaajaxforms/
├── joomlaajaxforms.xml
├── build.sh
├── script.php
├── services/provider.php
├── src/Extension/JoomlaAjaxForms.php
├── language/ (en-GB, de-DE, fr-FR)
├── media/js/
└── tests/
```

### Building

```bash
./build.sh
```

Creates: `plg_ajax_joomlaajaxforms.zip`

## Automated Testing

This plugin has automated tests that run on every push and on pull requests via GitHub Actions.

### Test Suites

The standard matrix (`test-j5`, `test-j6`) runs the Joomla 5 and Joomla 6 core
suites **without J2Commerce installed**. Because no cart backend exists in that
environment, a real functional cart test cannot run there — so the standard
matrix intentionally contains **no cart test**. Cart functionality is proven by
the full-install jobs described below, which are part of the same required CI
gate. (Previously the standard matrix shipped a `11 - J2Store Cart` step that was
a *pseudo* test — reflection checks and empty-database counts with no HTTP, IDOR
or authenticated-delete assertions — so it could pass even with a broken cart.
That misleading step has been removed; see issue #98.)

1. **Installation** — plugin registration in DB, file deployment
2. **Configuration** — plugin params, language files, XML manifest
3. **AJAX Endpoint** — unauthenticated access rejection
4. **Login** — AJAX login, MFA redirect flow
5. **Registration** — AJAX user registration
6. **Password Reset** — reset email request
7. **Username Reminder** — reminder email request
8. **Security** — CSRF rejection (no-token GET, fake-token POST), IDOR protection (unauthenticated removeCartItem rejected, victim row not deleted)
9. **Uninstall** — clean removal from database and filesystem
10. **Profile** — AJAX profile save (name, email, password)
11. **htaccess Check** — `.htaccess` rule validation

### Full-Install Tests (J2Commerce) — authoritative cart coverage

Cart functionality is covered **only** by real, functional tests that run against
genuine J2Commerce installations with seeded cart data. These two CI jobs are the
authoritative cart gate: both are required (the `collect-results` job fails if
either fails, and the `official-j5-j2c4` / `official-j6-j2c6` checks verify each
matrix passed), so a broken cart genuinely fails CI for **both** stacks.

**`test-j2c4-full` (Joomla 5 + J2Commerce 4)** — runs on every push/PR. Downloads `com_j2store_v4-4.1.4-pro.zip` from the public [j2commerce/j2cart](https://github.com/j2commerce/j2cart/releases) GitHub release, installs it into a Joomla 5 container, seeds a cart for test user `999` (3 items), then verifies — via real HTTP requests with CSRF tokens plus direct DB-state assertions:
- `isJ2CommerceInstalled()` returns `true`, `isJ2Commerce4()` returns `true`
- `getCartCountForUser(999)` returns 3 (matching seeded rows)
- `getCartCount` HTTP endpoint returns `cartCount = 0` for a guest (guest guard)
- IDOR: unauthenticated `removeCartItem` rejected, victim row still present in `#__j2store_cartitems`
- Authenticated `removeCartItem` (login over HTTP) deletes the row, returns an updated `cartCount`, and the deletion is confirmed in the database

**`test-j2c6-full` (Joomla 6 + J2Commerce 6)** — runs on every push/PR. Builds J2Commerce 6 from source (`git clone j2commerce/j2commerce && php build/build_package.php`) since no public release ZIP exists. The cart test mirrors the J2C4 suite (HTTP + DB + IDOR + authenticated delete) against the `#__j2commerce_*` tables.

### Running Tests Locally

```bash
# Standard tests (Joomla 5 + Joomla 6, no J2Commerce)
cd tests
docker compose up -d
timeout 300 bash -c 'until docker exec plg_ajax_joomlaajaxforms_test cat /var/www/html/health.txt 2>/dev/null | grep -q OK; do sleep 5; done'
./run-tests.sh all
docker compose down -v

# J2Commerce 4 full-install tests (Joomla 5 + J2Commerce 4)
cd tests-j2c4
# Place com_j2store ZIP as extension.zip and j2store4.zip in this directory first
docker compose up -d
timeout 360 bash -c 'until docker exec plg_ajax_j2c4_test cat /var/www/html/health.txt 2>/dev/null | grep -q OK; do sleep 5; done'
./run-tests.sh all
docker compose down -v

# J2Commerce 6 full-install tests (Joomla 6 + J2Commerce 6)
cd tests-j2c6
# Build J2Commerce 6 from source first:
#   git clone --depth=1 https://github.com/j2commerce/j2commerce.git /tmp/j2c6-src
#   cd /tmp/j2c6-src && php build/build_package.php
#   cp docs/packages/pkg_j2commerce_*.zip tests-j2c6/j2commerce6.zip
docker compose up -d
timeout 360 bash -c 'until docker exec plg_ajax_j2c6_test cat /var/www/html/health.txt 2>/dev/null | grep -q OK; do sleep 5; done'
./run-tests.sh all
docker compose down -v
```

## Troubleshooting

### AJAX context differs from normal Joomla requests

The plugin runs inside `com_ajax` with `format=json`. This affects several Joomla APIs:

| Issue | Detail | Solution |
|---|---|---|
| `Route::_()` generates wrong URLs | The SEF router uses the active menu item (`com_ajax`), producing URLs like `/component/j2store/?Itemid=240` | Look up the target menu item via `$menu->getItems()` and call `Route::_('index.php?Itemid=' . $id)` with the explicit Itemid |
| `$menuItem->route` lacks language prefix | The `route` field contains only the alias path (e.g. `benutzerkonto`), not the language segment (`de/benutzerkonto`) | Always use `Route::_()` with Itemid — never use `$item->route` directly as a URL |
| `onAfterRoute` only fires for `com_ajax` | Joomla dispatches `onAfterRoute` only to `system` plugins. An `ajax` plugin is not loaded for `com_users` or other component requests | Logic that needs to intercept other components must go into a `system` plugin or a template override |

### MFA redirect flow

After AJAX login with MFA enabled, the plugin redirects the browser to Joomla's captive page. The post-MFA redirect destination is controlled by `com_users.return_url` in the session.

**Chain of responsibility:**

1. **Plugin** (`onAjaxJoomlaajaxforms`) — sets `com_users.return_url` and returns the captive URL with a `?return=` query parameter
2. **`MultiFactorAuthenticationHandler`** — runs on every request; overwrites the URL only if it is empty or fails `Uri::isInternal()`
3. **Captive template** — reads the `?return=` query parameter and restores the session value
4. **`CaptiveController::validate()`** — reads `com_users.return_url` from the session (no POST fallback) and redirects

**Key constraints:**

- `Uri::isInternal()` requires absolute URLs (`https://...`) or URLs starting with `index.php`. Relative SEF URLs like `/de/benutzerkonto` are rejected.
- The handler does nothing when `isMultiFactorAuthenticationPage()` is true (captive view or `captive.validate` task).
- `CaptiveController::validate()` reads **only** from the session — it does not check POST parameters.

### Session persistence

Joomla registers `JoomlaStorage::close()` as a PHP shutdown function. Session data written via `$session->set()` is automatically serialized into `$_SESSION['joomla']` when `exit()` is called. An explicit `$session->close()` before `$app->close()` is not necessary.

### Joomla 6 API Compatibility

The plugin avoids all APIs deprecated in Joomla 6:

- Uses `$this->getApplication()` instead of `Factory::getApplication()`
- Uses `MailerFactoryInterface` instead of `Factory::getMailer()`
- Uses `UserFactoryInterface` instead of `User::getInstance()`
- Uses `->getInput()` instead of `->input`

## Multi-Language Support

- English (`en-GB`)
- German (`de-DE`)
- French (`fr-FR`)

Language keys cover all UI labels, error messages, email templates, and JavaScript strings.

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Proprietary software. Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
