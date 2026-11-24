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
`importexport`, `productcompare`, `osmap-j2commerce`, `cleanup`, `ajaxforms`, `dmarcreports`,
`moddmarcreports`, `appointments`. The `pkg_dmarcreports` package and its bundled
`plg_task_dmarcreports` and `plg_system_dmarcmtasts` plugins share the `dmarcreports` scope.

No `Co-authored-by` trailer and no agent signature (no Copilot/Ona/etc.) in commits, PR titles or
bodies.

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
- Mail templates: Joomla 6.2 deprecates `new MailTemplate($id, $lang)` without the mailer, the
  language factory and the database. Pass all three: `MailerFactoryInterface::createMailer()` and
  `LanguageFactoryInterface` from the DI container, plus the plugin's database. Joomla 5.4 takes only
  the mailer and ignores the further arguments. The production-like Joomla 6 lanes fail on the deprecation otherwise
  (`plg_ajax_joomlaajaxforms`).
- Deprecated APIs are rejected by `php shared/tests/deprecated-api-scan.php <extension dir>` (tokenizer
  based; allowlist with reasons at the top of the script). Fix the code instead of extending the
  allowlist.
- Namespaces: Plugins `Advans\Plugin\{Group}\{Name}` (e.g. `Advans\Plugin\Privacy\J2Commerce`); Components: `Advans\Component\{Name}`
- Follow Joomla Coding Standards
- No direct `$_GET`/`$_POST` — use `$app->getInput()`
- No `JFactory::` — use DI container or `$this->getApplication()`

## Packaging

A multi-part extension (more than one installable extension: component, module, plugin, template,
library) is always shipped as a real Joomla package `pkg_<name>`. The package is the release asset
(`pkg_<name>_<version>.zip`); the ZIPs of its parts exist only inside it (`packages/`). A single-part
extension is shipped as its own ZIP. `php shared/tests/package-structure-check.php` (job
«Infrastructure Tests») fails when an extension root holds several installable extensions without a
package listing every one of them, or when a package manifest lists a child that does not exist or
misses a child its `build.sh` stages (`"../dir:archive.zip"` entries, relative to the package
folder). `PENDING` in that script names roots still to be converted (empty now, the list only
shrinks); `COMPANIONS` names manifests a package ships beside its children on purpose
(Appointments Recovery, installed on its own when an update needs a file review).

Packages: `pkg_dmarcreports`, `pkg_appointments` (sources stay in `com_appointments/`, its
`modules/` and `plugins/`), `pkg_privacy_j2commerce` (sources stay in
`j2commerce/plg_privacy_j2commerce/`). The build of the two newer packages runs the main
extension's own build and splits it into the child archives.

A package adopts the parts that are already installed (also standalone, or installed by an older
script) without a manual step: no data or parameters are lost, no extension row is duplicated, the
old update sites give way to the package's, and uninstalling the package removes all its parts.
How this is done (`shared/installer/PackageChildren.php`, used by the package scripts):

- Joomla updates an installed child in place (same row). The package script snapshots the children
  in preflight, refuses when one is installed in a newer version than it brings, then assigns all of
  them to itself (`package_id`), removes their update sites and enables or publishes only children
  that were not installed before (kept in `enable-pending.php` for a retry after an interruption).
- The package shows the backup recommendation once (also on the first takeover, hook
  `migratesExistingInstallation()`), and its preflight acknowledges it for every child and hands its
  file decisions (including reviewed files) to the children.
- The main extension refuses to be installed outside its package (`Pkg_…InstallerScript::installing()`).
- When an extension becomes a package, the package keeps its release tag prefix and version line,
  the package, the main extension and every child carry the same version, and the release workflow
  rewrites the former extension's `updates/update.xml` to announce the package under the former
  element (same version and sha256), so sites that still run it migrate through Joomla Update.
- The package catalog of released hashes includes the catalogs of its children
  (`shared/build/known-hashes.php`), so files of the former standalone releases are known.
- The scheduled tasks of a bundled task plugin belong to the package entry in
  `shared/installer/catalog.php` (`tasks`), because the main extension is installed before its task
  plugin.

## Frontend Forms

Every form an extension shows on the site follows the same reference pattern, whatever the extension:

- Sign-in style forms (login and similar two-part pages) follow the checkout pattern: two columns,
  the form beside the supporting part (alternatives, notes), stacked on narrow screens.
- Single-column forms (requests, confirmations, reminders) follow the password reset pattern: one
  column, the label above the field, one primary button, the secondary way out beside or below it.
- Privacy requests follow the password reset pattern.
- Forms that belong to an appointment follow the appointment form: the choice of day and time first,
  then the details and the confirmation.

The extensions are public and must look right in any site template, so a form uses only what Joomla
and the template provide: Joomla and Bootstrap standard classes (`form-control`, `form-label`,
`form-check`, `btn btn-primary`, `row`/`col-*`, `card`, `alert`) and the CSS custom properties of the
active template. No colours, fonts, logos or brand texts of any company in an extension's markup,
CSS or language files, and no fixed colour values where a template variable or a Bootstrap class
exists. Check a form on a template other than the one it was developed on before it ships.
## Language Files

- Every extension, including every bundled plugin and all future extensions, ships exactly
  `de-DE`, `en-GB` and `fr-FR`, with identical key sets and printf placeholders in each file group.
  Sites run in `de-DE`; its text is Swiss High German, including Swiss terminology.
  Do not ship `de-CH` folders or manifest declarations, and do not generate them during a build.
  Language files stay checked in, matching the installation paths declared by each manifest.
  The shared lint enforces the language set; package verification checks the delivered file set.
- All outgoing mail defaults (subjects and bodies, including HTML where used) are complete in
  the same three languages, even on a German-only site. Use the supported recipient account
  language, otherwise the default **site** language, including guests; never the current request,
  order or another recipient's language. If the site language is unsupported, use en-GB.
  Preserve custom templates. HTML derived from translated text does not need duplicate strings.
  Lint checks named `{placeholders}` as well as printf placeholders and rejects empty subject/body
  strings. Sender inventory: root README, "E-mail templates".
- `de-DE` uses Swiss High German (no `ß`, use `ss` instead, and `z.B.` without a space). The lint enforces the `ß` rule in the language
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
- Options screens never show a raw key. Every `<fieldset>` of a component's `config.xml` has a
  `label` (otherwise com_config titles the tab `COM_CONFIG_<name>_FIELDSET_LABEL`), and that label,
  a fieldset `description` and the page title `<COMPONENT>_CONFIGURATION` are defined in the
  administrator `<component>.ini` (not only the `.sys.ini`) of every language. Plugin and module
  parameter fieldsets other than `basic` and `advanced` have a label, and their label and
  description are defined in every language in the file the edit screen loads, as installed by
  the same manifest (`plg_<group>_<element>.ini` or its `.sys.ini`, `mod_<name>.ini`). A Joomla
  core key counts as defined only when it is listed in `CORE_OPTIONS_KEYS` of the lint. The lint enforces this in source and `--package` mode.

### Checklist: add a language

The supported set is repository-wide, not per extension. Adding another locale requires a
maintainer decision and updates to **every** extension, bundled plugin, shared lint and package
verification. Swiss wording is maintained in `de-DE`, not in an additional `de-CH` locale.

1. Copy the `en-GB` folder of every `language/` directory of the extension (plugin folder, bundled
   sub-plugins, `administrator/language` of components) to the new tag, rename the files if their
   name carries the tag, and translate every value. Keep the keys and the `printf` placeholders.
2. Register the files where the manifest lists them. Extensions with `<folder>language</folder>`
   (Privacy with its sub-plugins, OSMap, Product Compare) need nothing else. Import/Export, Cleanup,
   DMARC Reports, DMARC Reports Dashboard Module, Appointments and AJAX Forms list each file in
   `<languages>`: add one `<language tag="xx-XX">` line per file. Appointments has two such blocks,
   one for the administrator files and one for the site files.
   These stay on `<languages>` on purpose: installed sites have copies in the global
   `administrator/language/<tag>/` folder, which Joomla loads before the extension folder, and a
   switch would leave those copies behind unchanged.
3. Update the shared supported-language set, then run `php shared/tests/lang-lint.php <extension dir>` for every extension; it compares the new
   files with `en-GB` (keys, placeholders) and checks the usage of every key.
4. Add the tag to the language checks of the tests where they name languages explicitly (for example
   the installer-messages suite and the `de-DE, en-GB, fr-FR` loops) and to this list.

## Database

- Always use `$db->quoteName()` and `$db->quote()`
- Never use raw string concatenation in queries
- Table prefix: `#__` (never hardcode `jos_` or similar)
- J2Commerce tables: J2Commerce 4: `#__j2store_*` (e.g. `#__j2store_orders`, `#__j2store_orderinfos`, `#__j2store_orderitems`, `#__j2store_addresses`, `#__j2store_carts`, `#__j2store_cartitems`, `#__j2store_product_customfields`); J2Commerce 6: `#__j2commerce_*` (detected at runtime)

## Security

- All AJAX handlers validate the form token. `Session::checkToken()` itself is not usable in a JSON endpoint: for a request without a session it **redirects and then returns true**, so the client receives a 3xx instead of a JSON rejection. AJAX Forms and Product Compare therefore compare the request token with `Session::getFormToken()` themselves (`hasValidToken()`, `hash_equals` for the `X-CSRF-Token` header) and answer with a JSON error. Every handler must go through such a check.
- No secrets, API keys, or credentials in code or commits
- `script.php` must define `minimumJoomla` and `minimumPhp`

## License

All extensions are licensed under **GPL-3.0-or-later**. Every extension directory ships the unmodified GPL-3.0 text (https://www.gnu.org/licenses/gpl-3.0.txt) as `LICENSE.txt`, identical to the repository root.

- Every manifest declares `LICENSE.txt` in the `<files>` list that installs it, and the file exists at that source path: next to the manifest for a plugin, inside the `folder="administrator/components/<com>"` of `<administration><files>` for a component, and in its own directory for a bundled sub-plugin (the privacy plugin's system and task plugins). Joomla copies only the entries of that list, so a `LICENSE.txt` that merely sits in the ZIP never reaches the installed extension although `<license>` and every source header point at it. The privacy plugin declares `THIRD-PARTY-NOTICES.txt` the same way. `php shared/tests/requirements-check.php <extension dir>` (CI job `Language Files`) fails on a missing declaration, a declared file that does not exist, and a `LICENSE.txt` that differs from the repository root.

- Manifests: `<license>GNU General Public License version 3 or later; see LICENSE.txt</license>` and `<copyright>(C) <year> Advans IT Solutions GmbH</copyright>` (no "All rights reserved").
- `composer.json` and `joomla.asset.json`: `"license": "GPL-3.0-or-later"`.
- Header for new PHP/JS/CSS files that ship in an installation package. Test scripts, Docker entry points and CI helpers are excluded from the package by `shared/build/build.sh` and need no header:

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