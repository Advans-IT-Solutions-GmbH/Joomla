# Repository Structure

## Plugins

| Plugin | Path | Group | Description |
|--------|------|-------|-------------|
| Privacy | `j2commerce/plg_privacy_j2commerce/` | privacy | GDPR/DSGVO for J2Commerce |
| Import/Export | `j2commerce/plg_import_export/` | j2store | Product import/export |
| Product Compare | `j2commerce/plg_product_compare/` | j2store | Product comparison |
| AJAX Forms | `plg_ajax_joomlaajaxforms/` | ajax | Joomla AJAX form handler |

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
│   ├── de-CH/
│   ├── de-DE/               # Identical to de-CH
│   ├── en-GB/
│   └── fr-FR/
├── updates/
│   └── update.xml           # Joomla update server manifest — managed by release workflow
└── tests/
    ├── run-tests.sh          # Delegates to shared/tests/run-tests.sh
    ├── docker-compose.yml
    └── integration/
```

## GitHub Workflows

| Workflow file | Trigger | Purpose |
|---|---|---|
| `j2commerce-privacy.yml` | push to `main` (privacy paths) | Build & Test |
| `release-privacy.yml` | `workflow_dispatch` | Release: bump version, build ZIP, create GitHub Release |
| `j2commerce-import-export.yml` | push to `main` | Build & Test |
| `release-importexport.yml` | `workflow_dispatch` | Release |
| `joomla-ajax-forms.yml` | push to `main` | Build & Test |
| `release-joomla-ajax-forms.yml` | `workflow_dispatch` | Release |
| `release-cleanup.yml` | schedule | Clean up old release artifacts |
