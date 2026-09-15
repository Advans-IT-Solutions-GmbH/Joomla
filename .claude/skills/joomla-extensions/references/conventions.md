# Conventions

## Commit Messages

Conventional Commits format is required — the release workflow uses it for version bump detection.

```
fix(privacy): correct onAfterRender documentation
feat(privacy): add de-DE language support
docs(privacy): translate README to English
chore(deps): bump actions/upload-artifact
test(privacy): add retention period integration tests
refactor(privacy): extract anonymization into separate method
```

Scope is the extension identifier as used by the release/publish workflows: `privacy`,
`importexport`, `productcompare`, `osmap-j2commerce`, `cleanup`, `ajaxforms`.

## PHP

- PHP 8.1+ minimum (all extensions)
- Joomla 5.4+ minimum (all extensions, Cleanup included): Joomla 5.4.x and 6.x. `script.php` sets
  `$minimumJoomla = '5.4'` and `$minimumPhp = '8.1'`; `preflight()` must call the parent, so Joomla's
  `InstallerScript::preflight()` rejects older versions with the translated core message
- Manifests, `updates/update.xml` and the release workflows use `targetplatform` `(5\.[4-9]|6\.[0-9])`
  (accepts 5.4.x and 6.x, rejects 4.x and 5.0 to 5.3); keep it in line with `minimumJoomla`
- The `Language Files` CI job runs `php shared/tests/requirements-check.php <extension dir>`, which
  fails if `minimumJoomla`/`minimumPhp`, the parent `preflight()` call, a manifest or `update.xml`
  `targetplatform`/`php_minimum`, or the release workflow `targetplatform` deviates from this rule
- Database queries: `$db->getQuery(true)` on Joomla 5 (Joomla 5.4's `DatabaseInterface` has no
  `createQuery()`), `$db->createQuery()` on Joomla 6; select at runtime
- Namespaces: Plugins `Advans\Plugin\{Group}\{Name}` (e.g. `Advans\Plugin\Privacy\J2Commerce`); Components: `Advans\Component\{Name}`
- Follow Joomla Coding Standards
- No direct `$_GET`/`$_POST` — use `$app->getInput()`
- No `JFactory::` — use DI container or `$this->getApplication()`

## Language Files

- Three locales required: `de-DE`, `en-GB`, `fr-FR`
- `de-DE` uses Swiss High German (no `ß`, use `ss` instead)
- README is always in English
- Example output in README uses English (not German)

## Database

- Always use `$db->quoteName()` and `$db->quote()`
- Never use raw string concatenation in queries
- Table prefix: `#__` (never hardcode `jos_` or similar)
- J2Commerce tables: J2Commerce 4: `#__j2store_*` (e.g. `#__j2store_orders`, `#__j2store_orderinfos`, `#__j2store_orderitems`, `#__j2store_addresses`, `#__j2store_carts`, `#__j2store_cartitems`, `#__j2store_product_customfields`); J2Commerce 6: `#__j2commerce_*` (detected at runtime)

## Security

- All AJAX handlers validate `Session::checkToken()`
- No secrets, API keys, or credentials in code or commits
- `script.php` must define `minimumJoomla` and `minimumPhp`

## License

All extensions are licensed under **GPL-3.0-or-later**. Every extension directory ships the unmodified GPL-3.0 text (https://www.gnu.org/licenses/gpl-3.0.txt) as `LICENSE.txt`, identical to the repository root.

- Manifests: `<license>GNU General Public License version 3 or later; see LICENSE.txt</license>` and `<copyright>(C) <year> Advans IT Solutions GmbH</copyright>` (no "All rights reserved").
- `composer.json` and `joomla.asset.json`: `"license": "GPL-3.0-or-later"`.
- Header for new PHP/JS/CSS files:

  ```php
  /**
   * @package     <Extension name>
   *
   * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
   * @license     GNU General Public License version 3 or later; see LICENSE.txt
   * SPDX-License-Identifier: GPL-3.0-or-later
   */
  ```

- Code derived from third-party sources (for example template overrides copied from J2Commerce or J2Store): keep the original copyright line first, add `Modifications (C) <year> Advans IT Solutions GmbH`, never remove third-party notices, and record the source in the extension's `THIRD-PARTY-NOTICES.txt`.
- Only incorporate code under a GPL-3.0-compatible license (GPL-2.0-or-later, GPL-3.0-or-later, LGPL, MIT, BSD, Apache-2.0). Never copy GPL-2.0-only code.