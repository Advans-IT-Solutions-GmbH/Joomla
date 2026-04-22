# Joomla! AJAX Forms

[![Build & Test](https://github.com/advansit/Joomla/actions/workflows/joomla-ajax-forms.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/joomla-ajax-forms.yml)
[![Release](https://github.com/advansit/Joomla/actions/workflows/release-joomla-ajax-forms.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/release-joomla-ajax-forms.yml)
[![Joomla 5](https://img.shields.io/badge/Joomla-5.x-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![Joomla 7](https://img.shields.io/badge/Joomla-7.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

A Joomla plugin that provides AJAX handling for user forms, authentication, profile management, and J2Store/J2Commerce cart operations — without page reloads.

## Features

| Feature | AJAX Task | Description |
|---------|-----------|-------------|
| Login | `login` | Authentication with redirect to Joomla's MFA captive page when 2FA is enabled |
| Logout | `logout` | Session termination with redirect |
| Registration | `register` | User registration with email verification and admin approval |
| Password Reset | `reset` | Password reset email request |
| Username Reminder | `remind` | Username reminder email request |
| Profile Editing | `saveProfile` | Update name, email, password |
| Cart: Remove Item | `removeCartItem` | Remove item from J2Store/J2Commerce cart |
| Cart: Get Count | `getCartCount` | Get current cart item count |

All features can be individually enabled/disabled via plugin parameters.

## Requirements

- Joomla 5.x, 6.x, or 7.x
- PHP 8.1+
- J2Store or J2Commerce (only for cart features)

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
| Enable J2Store Cart | AJAX cart operations (requires J2Store/J2Commerce) | Yes |

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

## Known Pitfalls

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

## Joomla Compatibility

The plugin avoids all APIs deprecated in Joomla 6:

- Uses `$this->getApplication()` instead of `Factory::getApplication()`
- Uses `MailerFactoryInterface` instead of `Factory::getMailer()`
- Uses `UserFactoryInterface` instead of `User::getInstance()`
- Uses `->getInput()` instead of `->input`

## Tests

12 test scripts run on every push via GitHub Actions: installation, configuration, endpoint access, login/MFA, registration, reset, remind, security, profile, J2Store cart, .htaccess validation, and uninstall.

```bash
cd plg_ajax_joomlaajaxforms/tests
docker compose up -d
sleep 120
./run-tests.sh all
docker compose down -v
```

## Translations

- English (en-GB)
- German (de-DE) — Swiss Standard German (no ß)

82 language keys covering all UI labels, error messages, email templates, and JavaScript strings.

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Proprietary software. Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
