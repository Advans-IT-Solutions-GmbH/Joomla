# Repository Structure

## Extensions

| Extension | Path | Type | Description |
|-----------|------|------|-------------|
| Privacy | `j2commerce/plg_privacy_j2commerce/` | plugin (privacy) | GDPR/DSGVO for J2Commerce |
| Import/Export | `j2commerce/com_j2commerce_importexport/` | component | Product import/export |
| Product Compare | `j2commerce/plg_j2commerce_productcompare/` | plugin (j2store) | Product comparison |
| OSMap | `j2commerce/plg_osmap_j2commerce/` | plugin (osmap) | J2Commerce sitemap for OSMap |
| Cleanup | `j2commerce/com_j2store_cleanup/` | component | Remove legacy J2Store extensions |
| AJAX Forms | `plg_ajax_joomlaajaxforms/` | plugin (ajax) | Joomla AJAX form handler |

## Shared Infrastructure

`shared/tests/` — Docker-based test runner used by all plugins. Each plugin's `tests/run-tests.sh` delegates to `shared/tests/run-tests.sh`.

## Per-Plugin Structure

Each plugin follows this layout:

```
plg_*/
├── README.md
├── VERSION                  # Managed by release workflow — do not edit manually
├── LICENSE.txt
├── {plugin}.xml             # Manifest — version managed by release workflow
├── script.php               # Install/update/uninstall script
├── services/
│   └── provider.php         # DI container registration
├── src/
│   └── Extension/           # Main plugin class
├── language/
│   ├── de-DE/               # Swiss High German (no ß)
│   ├── en-GB/
│   └── fr-FR/
├── updates/
│   └── update.xml           # Joomla update server manifest — managed by release workflow
└── tests/
    ├── run-tests.sh          # Delegates to shared/tests/run-tests.sh
    ├── docker-compose.yml
    └── integration/
```

## update.xml Requirements

Every plugin `update.xml` **must** include `<client>site</client>`:

```xml
<update>
    <element>myplugin</element>
    <type>plugin</type>
    <folder>myfolder</folder>
    <client>site</client>   <!-- required: all our plugins install with client_id=0 -->
    ...
</update>
```

**Why:** Joomla defaults to `client_id=1` (Administrator) when `<client>` is absent. All our plugins are installed with `client_id=0` (Site). The mismatch prevents Joomla from matching the update record to the installed extension.

Components do not need this — they have no `client_id` conflict.

## GitHub Workflows

The release process is **two-stage** (see `release-workflow.md`): a `release-*.yml` workflow bumps the version on a `release/…` branch and opens a release PR — it never pushes to `main`. On merge of that PR, the matching `publish-*.yml` workflow builds the package, creates the tag, and publishes the GitHub Release.

| Workflow file | Trigger | Purpose |
|---|---|---|
| `j2commerce-privacy.yml` | push to `main` (privacy paths) | Build & Test |
| `release-privacy.yml` | `workflow_dispatch` | Stage 1: bump VERSION/manifest/update.xml on `release/…` branch, open release PR (no push to `main`) |
| `publish-privacy.yml` | release PR merged to `main` | Stage 2: build ZIP, create tag, publish GitHub Release |
| `j2commerce-import-export.yml` | push to `main` (importexport + shared paths) | Build & Test |
| `release-importexport.yml` | `workflow_dispatch` | Stage 1: bump + release PR |
| `publish-importexport.yml` | release PR merged | Stage 2: package, tag, GitHub Release |
| `j2commerce-product-compare.yml` | push to `main` (productcompare paths) | Build & Test |
| `release-productcompare.yml` | `workflow_dispatch` | Stage 1: bump + release PR |
| `publish-productcompare.yml` | release PR merged | Stage 2: package, tag, GitHub Release |
| `osmap-j2commerce.yml` | push to `main` (osmap + shared paths) | Build & Test |
| `release-osmap-j2commerce.yml` | `workflow_dispatch` | Stage 1: bump + release PR |
| `publish-osmap-j2commerce.yml` | release PR merged | Stage 2: package, tag, GitHub Release |
| `j2store-cleanup.yml` | push to `main` (cleanup paths) | Build & Test |
| `release-cleanup.yml` | `workflow_dispatch` | Stage 1: bump + release PR |
| `publish-cleanup.yml` | release PR merged | Stage 2: package, tag, GitHub Release |
| `joomla-ajax-forms.yml` | push to `main` (ajaxforms paths) | Build & Test |
| `release-joomla-ajax-forms.yml` | `workflow_dispatch` | Stage 1: bump + release PR |
| `publish-joomla-ajax-forms.yml` | release PR merged | Stage 2: package, tag, GitHub Release |
