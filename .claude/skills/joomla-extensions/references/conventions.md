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
- Joomla 5.0+ minimum; exception: `com_j2store_cleanup` still supports Joomla 4.0 (`minimumJoomla '4.0'`,
  release `targetplatform` includes 4.x; its README states Joomla 4 support)
- Release workflows write `targetplatform` `(5\.[0-9]|6\.[0-9])` into `update.xml` (Cleanup: 4.x–6.x);
  keep it in line with `minimumJoomla` in `script.php`
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
