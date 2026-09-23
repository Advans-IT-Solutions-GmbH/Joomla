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
- Database queries: `method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true)`.
  `createQuery()` is not declared on `DatabaseInterface` in Joomla 5.4, but the database drivers
  provide it; `getQuery(true)` is deprecated. The static scan only accepts `getQuery(true)` behind
  this check.
- Plugin service providers: `new Plugin((array) PluginHelper::getPlugin(...))`, then
  `setApplication()`/`setDatabase()` as needed. Never pass the dispatcher to the constructor and never
  call `setDispatcher()` (both deprecated since Joomla 5.2; `PluginHelper` sets the dispatcher).
- Deprecated APIs are rejected by `php shared/tests/deprecated-api-scan.php <extension dir>` (tokenizer
  based; allowlist with reasons at the top of the script). Fix the code instead of extending the
  allowlist.
- Namespaces: Plugins `Advans\Plugin\{Group}\{Name}` (e.g. `Advans\Plugin\Privacy\J2Commerce`); Components: `Advans\Component\{Name}`
- Follow Joomla Coding Standards
- No direct `$_GET`/`$_POST` — use `$app->getInput()`
- No `JFactory::` — use DI container or `$this->getApplication()`

## Language Files

- Three locales required: `de-DE`, `en-GB`, `fr-FR`
- `de-DE` uses Swiss High German (no `ß`, use `ss` instead). The lint enforces this in the language
  files **and** in text the shipped PHP, JavaScript and XML hard-codes; language files, `tests…/`,
  `vendor/` and `node_modules/` are out of scope, and a line that must keep the character carries
  the marker `lang-lint-allow-eszett`
- README is always in English
- Example output in README uses English (not German)
- No fixed wording in code: every text a visitor or administrator reads (PHP, layouts, JavaScript,
  `confirm()`/`alert()`, `aria-label`, messages) comes from a language key. JavaScript receives its
  texts through `Text::script()` / script options and has no fallback text of its own. Joomla core
  keys (`JENABLED`, `JCLOSE`, …) are fine for generic words, because every language pack has them.
  Only log lines for developers may stay English.
- Keys are written out in full. No `'PREFIX_' . $action` assembly; use a map of complete keys
  instead, so the usage check sees every key. Joomla's `langConstPrefix` (`<prefix>_TITLE`,
  `<prefix>_DESC`) is the one accepted exception.
- `php shared/tests/lang-lint.php <extension dir>` (CI job `Language Files`) fails on a key the code
  uses but a language does not define, on a defined key no code uses, and on assembled keys. Keys a
  plugin ships only for site template overrides go into
  `tests/language-keys-for-template-overrides.txt` of the extension.

### Checklist: add a language

1. Copy the `en-GB` folder of every `language/` directory of the extension (plugin folder, bundled
   sub-plugins, `administrator/language` of components) to the new tag, rename the files if their
   name carries the tag, and translate every value. Keep the keys and the `printf` placeholders.
2. Register the files where the manifest lists them. Extensions with `<folder>language</folder>`
   (Privacy with its sub-plugins, OSMap, Product Compare) need nothing else. Import/Export, Cleanup
   and AJAX Forms list each file in `<languages>`: add one `<language tag="xx-XX">` line per file.
   These three stay on `<languages>` on purpose: installed sites have copies in the global
   `administrator/language/<tag>/` folder, which Joomla loads before the extension folder, and a
   switch would leave those copies behind unchanged.
3. Run `php shared/tests/lang-lint.php <extension dir>` for every extension; it compares the new
   files with `en-GB` (keys, placeholders) and checks the usage of every key.
4. Add the tag to the language checks of the tests where they name languages explicitly (for example
   the installer-messages suite and the `de-DE, en-GB, fr-FR` loops) and to this list.

## Database

- Always use `$db->quoteName()` and `$db->quote()`
- Never use raw string concatenation in queries
- Table prefix: `#__` (never hardcode `jos_` or similar)
- J2Commerce tables: J2Commerce 4: `#__j2store_*` (e.g. `#__j2store_orders`, `#__j2store_orderinfos`, `#__j2store_orderitems`, `#__j2store_addresses`, `#__j2store_carts`, `#__j2store_cartitems`, `#__j2store_product_customfields`); J2Commerce 6: `#__j2commerce_*` (detected at runtime)

## Security

- All AJAX handlers validate `Session::checkToken()`
- No secrets, API keys, or credentials in code or commits
- `script.php` must define `minimumJoomla` and `minimumPhp`
