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
