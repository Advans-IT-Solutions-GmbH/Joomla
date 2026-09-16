# Joomla! AJAX Forms

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/joomla-ajax-forms.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/joomla-ajax-forms.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-joomla-ajax-forms.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-joomla-ajax-forms.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

## Description

A Joomla plugin that provides AJAX handling for user forms, authentication, profile management, and J2Commerce cart operations — without page reloads.

## Features

| Feature | AJAX Task | Description |
|---------|-----------|-------------|
| Login | `login` | Authentication with redirect to Joomla's MFA captive page when 2FA is enabled |
| Logout | `logout` | Session termination with redirect (requires a logged-in user) |
| Registration | `register` | User registration with email verification and admin approval |
| Password Reset | `reset` | Password reset email request |
| Username Reminder | `remind` | Username reminder email request |
| Profile Editing | `saveProfile` | Update name, email, password |
| Cart: Remove Item | `removeCartItem` | Remove item from J2Commerce cart (v4 and v6) |
| Cart: Get Count | `getCartCount` | Get current cart item count |

Login, registration, password reset and username reminder can be disabled individually via plugin parameters. `logout`, `saveProfile`, `removeCartItem` and `getCartCount` are always available: the parameters `enable_profile` and `enable_j2store_cart` exist in the configuration but are currently not evaluated.

## Requirements

- Joomla 5.4 or later (5.4.x, 6.x)
- PHP 8.1+
- J2Commerce 4.x or 6.x (only for cart features)

## Installation

1. Download `plg_ajax_joomlaajaxforms_<version>.zip` from the [latest release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/releases?q=ajaxforms)
2. Install via Joomla Extension Manager
3. Enable under **System → Manage → Plugins → Joomla! AJAX Forms**

The installer checks the `.htaccess` on the web server. If rewrite rules block `/component/` or `index.php?option=com_*` URLs, `com_ajax` must be whitelisted — otherwise all AJAX calls will fail silently. The installer warns if exceptions are missing.

Required `.htaccess` exceptions (only if URL blocking is active):

```apache
# Allow com_ajax plugin calls through /component/ blocking
RewriteCond %{QUERY_STRING} !plugin= [NC]

# Allow com_ajax through index.php?option= blocking
RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]
```

## Updating

The manifest declares the update server
`https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/plg_ajax_joomlaajaxforms/updates/update.xml`.
New versions appear under **System → Update → Extensions**. Alternatively,
install the newer ZIP over the existing installation.

## Uninstall

Uninstall the plugin via **System → Manage → Extensions**. The plugin has no
uninstall routine; `.htaccess` exceptions you added for `com_ajax` are not
removed.

## Configuration

| Parameter | Description | Default |
|-----------|-------------|---------|
| Enable Login | AJAX login with MFA support | Yes |
| Enable Registration | AJAX user registration | Yes |
| Enable Password Reset | AJAX password reset | Yes |
| Enable Username Reminder | AJAX username reminder | Yes |
| Enable Profile Editing | AJAX profile save (name, email, password) — currently not evaluated, `saveProfile` is always available | Yes |
| Enable J2Store Cart | AJAX cart operations (requires J2Commerce 4.x or 6.x) — currently not evaluated, cart tasks are always available | Yes |

### J2Commerce Cart Compatibility

The cart features support both J2Store / J2Commerce 4.x (`#__j2store_*` tables) and J2Commerce 6.x (`#__j2commerce_*` tables). The active shop is decided by the enabled component, once per request:

1. `com_j2commerce` is enabled → J2Commerce 6.x (`#__j2commerce_*` tables)
2. otherwise `com_j2store` is enabled → J2Store / J2Commerce 4.x (`#__j2store_*` tables)
3. otherwise → no shop

The tables alone never decide: after a migration from J2Store to J2Commerce 6 the `#__j2store_*` tables remain in the database and are ignored. The cart table of the selected shop (`#__j2commerce_carts` or `#__j2store_carts`) must exist as well; otherwise no shop is used.

Without an active shop, `removeCartItem` returns an error with the `PLG_AJAX_JOOMLAAJAXFORMS_J2COMMERCE_NOT_FOUND` message and `getCartCount` returns `cartCount: 0`. After a login without a `return` URL, the plugin redirects to the `myprofile` page of the active shop (its menu item if one exists); without an active shop it redirects to the Joomla user profile (`com_users`). Other plugin functionality is not affected.

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
use Joomla\CMS\Factory;
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
JoomlaAjaxForms.saveProfile(formElement);

// Logout
JoomlaAjaxForms.logout(returnUrl);
```

### Request handling

Requests with `option=com_ajax`, `plugin=joomlaajaxforms` and `format=json` are handled by `onAjaxJoomlaajaxforms`; every task requires a valid CSRF token. In addition, the plugin subscribes to `onAfterRoute`: when that event reaches the plugin for one of its own `com_ajax` JSON requests, the plugin runs the handler, sends the JSON response and closes the application directly. This is a workaround for Joomla 5 SEF redirect loops (`&` encoded as `&amp;` in the redirect `Location` header). `onAfterRoute` is only delivered to plugins that are already loaded at that point (see *Troubleshooting*).

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

Creates: `plg_ajax_joomlaajaxforms_<version>.zip`

## Automated Testing

This plugin has automated tests that run via GitHub Actions (`joomla-ajax-forms.yml`) on pushes and pull requests to `main` that change this directory, `shared/**` or the workflow file. The tests use the package built by `build.sh`, the same script the publish workflow uses. Besides the suites below, CI runs package validation (manifest, PHP and JavaScript syntax), the language file lint, an update from the previous release and a production-like lane (newest Joomla 6.x, PHP 8.4, MariaDB 10.6, J2Commerce 6 production pin). CI always tests the newest Joomla 5.4.x and 6.x releases (no pinned patch version) and prints them in each job log (`Tested versions: …`); a red run can therefore be caused by a new Joomla release. Details: [testing.md](../.claude/skills/joomla-extensions/references/testing.md).

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
8. **Security** — CSRF rejection only: a no-token `GET` and a fake-token `POST` to the AJAX endpoint (`getCartCount`) must both be rejected. On the standard matrix this is the **only** part of the Security suite that runs — the IDOR/cart portion of `08-security.php` auto-skips because no J2Commerce cart tables exist (`SKIP: J2Commerce not installed — cart tables absent`). Real IDOR/cart coverage (seeding a victim cart row and verifying it is not deleted) runs **only** in the J2Commerce 4 and 6 full-install suites described below.
9. **Profile** — AJAX profile save (name, email, password)
10. **htaccess Check** — behaviour test: places `.htaccess` fixtures in the Joomla root, installs the real package through the Joomla CLI and checks the installer warnings (README rules and Joomla's `htaccess.txt` → no warning; missing exception → warning)
11. **Installer Messages** — shared suite: removes and reinstalls the package through the Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a non-zero exit code
12. **Uninstall** — clean removal from database and filesystem

The authoritative order is `TEST_SCRIPTS` in `tests/test.env` (the full-install directories additionally run `j2store-cart` and `shop-detection`).

### Full-Install Tests (J2Commerce) — authoritative cart coverage

Cart functionality is covered **only** by real, functional tests that run against
genuine J2Commerce installations with seeded cart data. These two CI jobs are the
authoritative cart gate: both are required (the final `Ajax Forms / all jobs` job
fails if any job fails, and the `official-j5-j2c4` / `official-j6-j2c6` checks verify
each matrix passed), so a broken cart genuinely fails CI for **both** stacks.

**`test-j2c4-full` (Joomla 5 + J2Commerce 4)** — runs on every push/PR. Downloads `com_j2store_v4-4.1.4-pro.zip` from the public [j2commerce/j2cart](https://github.com/j2commerce/j2cart/releases) GitHub release, installs it into a Joomla 5 container, seeds a cart for test user `999` (3 items), then verifies — via real HTTP requests with CSRF tokens plus direct DB-state assertions:
- `isJ2CommerceInstalled()` returns `true`, `isJ2Commerce4()` returns `true`
- `getCartCountForUser(999)` returns 3 (matching seeded rows)
- `getCartCount` HTTP endpoint returns `cartCount = 0` for a guest (guest guard)
- IDOR: unauthenticated `removeCartItem` rejected, victim row still present in `#__j2store_cartitems`
- Authenticated `removeCartItem` (login over HTTP) deletes the row, returns an updated `cartCount`, and the deletion is confirmed in the database

**`test-j2c6-full` (Joomla 6 + J2Commerce 6)** — runs on every push/PR. Builds J2Commerce 6 from source at the commit pinned in the workflow (`J2C6_REF`, `7edb6e11ae9148bf996b06c47a0d8266865af7b2`) since no public release ZIP exists. The cart test mirrors the J2C4 suite (HTTP + DB + IDOR + authenticated delete) against the `#__j2commerce_*` tables.

**Shop detection (`13-shop-detection.php`, both full-install lanes)** — the script lives in `tests-j2c6/scripts/`; `tests-j2c4/run-tests.sh` copies it. A dedicated test user gets a cart in both table sets with different quantities (5 in `#__j2commerce_*`, 7 in `#__j2store_*`), so the `cartCount` returned by the HTTP endpoint shows which tables the plugin used. In the J2C6 lane the suite adds stale `#__j2store_*` tables and a disabled `com_j2store` row (migration): the AJAX login redirects to `com_j2commerce`, `getCartCount` returns 5 and `removeCartItem` deletes only from `#__j2commerce_cartitems`; with `com_j2commerce` disabled and `com_j2store` enabled it returns 7. In the J2C4 lane it adds `#__j2commerce_*` tables and a disabled `com_j2commerce` row: the login redirects to `com_j2store` and `getCartCount` returns 7; with `com_j2commerce` enabled it returns 5. In both lanes, with both components disabled, `getCartCount` returns 0 and `removeCartItem` reports that no shop is installed. All changes are reverted at the end of the suite.

### Running Tests Locally

Prerequisites (full commands: [Local Prerequisites](../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites)):

- Build the plugin (`./build.sh`) and copy `plg_ajax_joomlaajaxforms_<version>.zip` as `extension.zip` into each test directory you use (`tests/`, `tests-j2c4/`, `tests-j2c6/`).
- `tests-j2c4/`: J2Commerce 4 is downloaded automatically during the image build. Build the image from the repository root:
  ```bash
  docker build \
    -f plg_ajax_joomlaajaxforms/tests-j2c4/Dockerfile \
    --build-arg J2C4_URL=https://github.com/j2commerce/j2cart/releases/download/v4.1.4/com_j2store_v4-4.1.4-pro.zip \
    -t plg_ajax_j2c4_test \
    .
  ```
- `tests-j2c6/`: build J2Commerce 6 from the pinned commit (see testing.md) and copy the package to `plg_ajax_joomlaajaxforms/tests-j2c6/j2commerce6.zip`, then build the image from the repository root:
  ```bash
  docker build \
    -f plg_ajax_joomlaajaxforms/tests-j2c6/Dockerfile \
    -t plg_ajax_j2c6_test \
    .
  ```

```bash
# Standard tests, Joomla 5 (no J2Commerce)
cd plg_ajax_joomlaajaxforms/tests
docker compose up -d
timeout 300 bash -c 'until docker exec plg_ajax_joomlaajaxforms_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v

# Standard tests, Joomla 6 (no J2Commerce)
docker compose -f docker-compose.joomla6.yml up -d
timeout 300 bash -c 'until docker exec plg_ajax_joomlaajaxforms_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
CONTAINER_NAME=plg_ajax_joomlaajaxforms_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v

# J2Commerce 4 full-install tests (Joomla 5 + J2Commerce 4; image built above)
cd ../tests-j2c4
docker compose up -d
timeout 360 bash -c 'until docker exec plg_ajax_j2c4_test cat /var/www/html/health.txt 2>/dev/null | grep -q OK; do sleep 5; done'
./run-tests.sh all
docker compose down -v

# J2Commerce 6 full-install tests (Joomla 6 + J2Commerce 6; image built above)
cd ../tests-j2c6
docker compose up -d
timeout 360 bash -c 'until docker exec plg_ajax_j2c6_test cat /var/www/html/health.txt 2>/dev/null | grep -q OK; do sleep 5; done'
./run-tests.sh all
docker compose down -v
```

CI sets `TEST_STRICT_SKIP=1` (a test that would SKIP fails); prefix the command with it to reproduce CI.

## Troubleshooting

### AJAX context differs from normal Joomla requests

The plugin runs inside `com_ajax` with `format=json`. This affects several Joomla APIs:

| Issue | Detail | Solution |
|---|---|---|
| `Route::_()` generates wrong URLs | The SEF router uses the active menu item (`com_ajax`), producing URLs like `/component/j2store/?Itemid=123` | Look up the target menu item via `$menu->getItems()` and call `Route::_('index.php?Itemid=' . $id)` with the explicit Itemid |
| `$menuItem->route` lacks language prefix | The `route` field contains only the alias path (e.g. `account`), not the language segment (`de/account`) | Always use `Route::_()` with Itemid — never use `$item->route` directly as a URL |
| An `ajax` plugin does not receive `onAfterRoute` | Joomla dispatches `onAfterRoute` only to `system` plugins. An `ajax` plugin is not loaded for `com_users` or other component requests | Logic that needs to intercept other components must go into a `system` plugin or a template override |

### MFA redirect flow

After AJAX login with MFA enabled, the plugin redirects the browser to Joomla's captive page. The post-MFA redirect destination is controlled by `com_users.return_url` in the session.

**Chain of responsibility:**

1. **Plugin** (`onAjaxJoomlaajaxforms`) — sets `com_users.return_url` and returns the captive URL with a `?return=` query parameter
2. **`MultiFactorAuthenticationHandler`** — runs on every request; overwrites the URL only if it is empty or fails `Uri::isInternal()`
3. **Captive template** — reads the `?return=` query parameter and restores the session value
4. **`CaptiveController::validate()`** — reads `com_users.return_url` from the session (no POST fallback) and redirects

**Key constraints:**

- `Uri::isInternal()` requires absolute URLs (`https://...`) or URLs starting with `index.php`. Relative SEF URLs like `/de/account` are rejected.
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
