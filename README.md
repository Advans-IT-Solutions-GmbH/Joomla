# Joomla Extensions Repository

Extensions for [Joomla](https://github.com/joomla/joomla-cms) and [J2Commerce](https://github.com/j2commerce/j2commerce) developed and maintained by Advans IT Solutions GmbH.

## Repository Structure

```
Joomla/
├── plg_ajax_joomlaajaxforms/ # Joomla AJAX Forms Plugin
├── j2commerce/               # J2Commerce Extensions
│   ├── com_j2commerce_importexport/
│   ├── com_j2store_cleanup/
│   ├── plg_osmap_j2commerce/
│   ├── plg_j2commerce_productcompare/
│   └── plg_privacy_j2commerce/
├── shared/                   # Shared build and test scripts
└── .github/                  # CI/CD workflows
```

## Available Extensions

### Joomla Core Extensions

| Extension | Description | Joomla |
|-----------|-------------|--------|
| [Joomla! AJAX Forms](plg_ajax_joomlaajaxforms/) | AJAX login, registration, MFA, profile editing, password reset, username reminder, J2Store cart operations | 5.x – 6.x |

### J2Commerce Extensions

| Extension | Description |
|-----------|-------------|
| [Import/Export](j2commerce/com_j2commerce_importexport/) | Bulk data import/export for J2Commerce |
| [J2Store Cleanup](j2commerce/com_j2store_cleanup/) | Detect incompatible extensions after J2Store-to-J2Commerce migration |
| [OSMap J2Commerce](j2commerce/plg_osmap_j2commerce/) | Adds J2Commerce products to the OSMap sitemap automatically |
| [Product Compare](j2commerce/plg_j2commerce_productcompare/) | Compare products side-by-side |
| [Privacy](j2commerce/plg_privacy_j2commerce/) | GDPR compliance for J2Commerce |

## Testing

Each extension has automated tests that run via GitHub Actions on pushes and pull requests to `main` that change the extension directory, `shared/**` or the extension's own workflow file. Every workflow can also be started manually.

| Workflow | Extension | Trigger paths (push/PR to `main`, plus manual dispatch) |
|----------|-----------|---------|
| `joomla-ajax-forms.yml` | Joomla AJAX Forms | `plg_ajax_joomlaajaxforms/**`, `shared/**`, own workflow file |
| `j2commerce-import-export.yml` | Import/Export | `j2commerce/com_j2commerce_importexport/**`, `shared/**`, own workflow file |
| `j2store-cleanup.yml` | J2Store Cleanup | `j2commerce/com_j2store_cleanup/**`, `shared/**`, own workflow file |
| `osmap-j2commerce.yml` | OSMap J2Commerce | `j2commerce/plg_osmap_j2commerce/**`, `shared/**`, own workflow file |
| `j2commerce-product-compare.yml` | Product Compare | `j2commerce/plg_j2commerce_productcompare/**`, `shared/**`, own workflow file |
| `j2commerce-privacy.yml` | Privacy | `j2commerce/plg_privacy_j2commerce/**`, `shared/**`, own workflow file |

Besides the test suites, every workflow lints all PHP files of the extension and checks its language files (`shared/tests/lang-lint.php`: Joomla INI parsing, keys and placeholders equal to en-GB, Swiss High German and French spelling checks). The pull request check **Collect Results** (`collect-results.yml`) determines which of the workflows above are triggered by the changed files, waits for them and fails unless all succeeded; a pull request that triggers none of them passes immediately. After re-running a failed workflow, re-run *Collect Results* as well.

Local test prerequisites and commands: [`.claude/skills/joomla-extensions/references/testing.md`](.claude/skills/joomla-extensions/references/testing.md).

View test results: https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions

## Releases

Releases use a **two-stage, PR-based flow** so that no commit reaches `main` without review (pull requests to `main` require review and passing checks; see [Security](#security)). Each extension has its own release and publish workflow, tag prefix and independent version.

### How to create a release

1. Go to **Actions** → select the **Release - …** workflow for the extension
2. Click **Run workflow** and choose a bump level (or leave empty for auto-detect from commits)
3. The workflow bumps the version files on a `release/<prefix>-v<version>` branch and opens a **pull request** titled `release: <prefix> v<version>` — it does **not** push to `main`
4. **Review and merge** that pull request into `main` (squash)
5. On merge, the matching **Publish - …** workflow triggers automatically: it builds the package, creates and pushes the tag, generates the changelog and creates the GitHub release

Run release workflows one at a time. For planned compatibility releases, set the bump explicitly instead of relying on auto-detect.

Auto-detect uses [Conventional Commits](https://www.conventionalcommits.org/) since the last tag:

| Commit Prefix | Version Bump | Example |
|---------------|-------------|---------|
| `fix:` | Patch (1.0.0 → 1.0.1) | `fix: correct cart count query` |
| `feat:` | Minor (1.0.0 → 1.1.0) | `feat: add product export filter` |
| `feat!:` or `BREAKING CHANGE:` | Major (1.0.0 → 2.0.0) | `feat!: require Joomla 5+` |

Only `fix`, `feat` and `!`/`BREAKING CHANGE` trigger a bump; all other types (`docs:`, `chore:`, `test:`, `refactor:`) and unprefixed commits are ignored.

### Release workflows

| Workflow | Tag Pattern |
|----------|-------------|
| `release-joomla-ajax-forms.yml` | `ajaxforms-v*` |
| `release-importexport.yml` | `importexport-v*` |
| `release-cleanup.yml` | `cleanup-v*` |
| `release-productcompare.yml` | `productcompare-v*` |
| `release-privacy.yml` | `privacy-v*` |
| `release-osmap-j2commerce.yml` | `osmap-j2commerce-v*` |

After a new release is created, the publish workflow deletes older releases and tags of the same extension prefix; only the latest release of each extension is kept.

View all releases: https://github.com/Advans-IT-Solutions-GmbH/Joomla/releases

## Security

Please report vulnerabilities via GitHub private vulnerability reporting; see the organization [security policy](https://github.com/Advans-IT-Solutions-GmbH/.github/blob/main/SECURITY.md). Pull requests to `main` require review and passing checks.

---

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.

