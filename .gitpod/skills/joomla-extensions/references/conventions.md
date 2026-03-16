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

Scope is the plugin name: `privacy`, `import-export`, `product-compare`, `ajax-forms`.

## PHP

- PHP 8.1+ minimum
- Joomla 5.0+ minimum
- Namespaces: `Advans\Plugin\{Group}\{Name}` (e.g. `Advans\Plugin\Privacy\J2Commerce`)
- Follow Joomla Coding Standards
- No direct `$_GET`/`$_POST` — use `$app->input`
- No `JFactory::` — use DI container or `$this->getApplication()`

## Language Files

- All four locales required: `de-CH`, `de-DE`, `en-GB`, `fr-FR`
- `de-DE` is always identical to `de-CH`
- README is always in English
- Example output in README uses English (not German)

## Database

- Always use `$db->quoteName()` and `$db->quote()`
- Never use raw string concatenation in queries
- Table prefix: `#__` (never hardcode `jos_` or similar)
- J2Commerce tables: `#__j2store_orders`, `#__j2store_orderinfos`, `#__j2store_orderitems`, `#__j2store_addresses`, `#__j2store_carts`, `#__j2store_cartitems`, `#__j2store_product_customfields`

## Security

- All AJAX handlers validate `Session::checkToken()`
- No secrets, API keys, or credentials in code or commits
- `script.php` must define `minimumJoomla` and `minimumPhp`
