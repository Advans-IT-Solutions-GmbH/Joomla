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

### Joomla Extensions

| Extension | Description | Joomla |
|-----------|-------------|--------|
| [Joomla! AJAX Forms](plg_ajax_joomlaajaxforms/) | AJAX login, registration, MFA, profile editing, password reset, username reminder; optional J2Commerce 4.x/6.x cart operations | 5.4 – 6.x |

### J2Commerce Extensions

| Extension | Description |
|-----------|-------------|
| [Import/Export](j2commerce/com_j2commerce_importexport/) | Bulk data import/export for J2Commerce |
| [J2Store Cleanup](j2commerce/com_j2store_cleanup/) | Detect incompatible extensions after J2Store-to-J2Commerce migration |
| [OSMap J2Commerce](j2commerce/plg_osmap_j2commerce/) | Adds J2Commerce products to the OSMap sitemap automatically |
| [Product Compare](j2commerce/plg_j2commerce_productcompare/) | Compare products side-by-side |
| [Privacy](j2commerce/plg_privacy_j2commerce/) | GDPR compliance for J2Commerce |

## Testing

Each extension has automated tests that run via GitHub Actions on pushes and pull requests to `main` that change the extension directory, one of the files under `shared/` that the extension uses, or the extension's own workflow file. Every workflow can also be started manually. Every suite keeps its own job on every lane, so a red check names the suite and the lane straight away.

| Workflow | Extension | Trigger paths (push/PR to `main`, plus manual dispatch) |
|----------|-----------|---------|
| `joomla-ajax-forms.yml` | Joomla AJAX Forms | `plg_ajax_joomlaajaxforms/**`, the shared files below, own workflow file |
| `j2commerce-import-export.yml` | Import/Export | `j2commerce/com_j2commerce_importexport/**`, the shared files below, own workflow file |
| `j2store-cleanup.yml` | J2Store Cleanup | `j2commerce/com_j2store_cleanup/**`, the shared files below, own workflow file |
| `osmap-j2commerce.yml` | OSMap J2Commerce | `j2commerce/plg_osmap_j2commerce/**`, the shared files below, own workflow file |
| `j2commerce-product-compare.yml` | Product Compare | `j2commerce/plg_j2commerce_productcompare/**`, the shared files below, own workflow file |
| `j2commerce-privacy.yml` | Privacy | `j2commerce/plg_privacy_j2commerce/**`, the shared files below, own workflow file |

**Shared trigger paths.** No `Build & Test` workflow watches `shared/**` as a whole; each one lists the files it really uses, so a change to a shared file starts only the extensions that consume it. The guard below is the one workflow that still watches `shared/**`, because it has to see every change there.

| File under `shared/` | Starts |
|---|---|
| `build/build.sh`, `build/verify-package.sh` | all six (every `build.sh` is a wrapper) |
| `tests/run-tests.sh` | all six (every `tests/run-tests.sh` is a wrapper) |
| `tests/lang-lint.php`, `tests/requirements-check.php`, `tests/deprecated-api-scan.php` | all six (job `Language Files`) |
| `tests/scripts/shared-test-helpers.php` | all six (loaded by the shared suites) |
| `tests/scripts/shared-install-messages.php` | all six (in every `test.env`) |
| `tests/scripts/shared-update-from-previous.php` | AJAX Forms, OSMap, Privacy (job `Update from previous release (J6)`) |
| `tests/scripts/shared-deprecations.php`, `tests/scripts/shared-deprecation-tracer.php` | AJAX Forms, OSMap, Privacy (production-like lane) |
| `tests/Dockerfile.template`, `tests/scripts/docker-entrypoint.sh`, `tests/scripts/install-extension.php` | nothing: templates that every extension has copied into its own `tests/` tree, read by no lane |

`Shared Path Coverage` (`shared-path-coverage.yml`) fails if a file under `shared/` is matched by the `pull_request.paths` of no other workflow and is not on the documented exception list in `.github/scripts/check-shared-path-coverage.sh`. It requires one match, not every workflow that uses the file: it excludes itself from the search and ignores workflows without a `paths` filter, and it cannot tell whether a lane really reads the file. The table above stays the record of which workflow consumes what. **A new file under `shared/` has to be added to the workflows that use it, otherwise that check fails.**

**Superseded runs are cancelled.** Every `Build & Test` workflow has a `concurrency` group of workflow plus ref with `cancel-in-progress: true`, so a new push to a pull request or branch cancels the previous run of that same ref instead of letting it finish. Runs of different pull requests never cancel each other, on `main` the group key is the unique run id so nothing on the default branch is ever cancelled, and the publish and release workflows have no concurrency group at all.

Besides the test suites, every workflow lints all PHP files of the extension and checks its language files (`shared/tests/lang-lint.php`: Joomla INI parsing, keys and placeholders equal to en-GB, Swiss High German and French spelling checks) and its declared requirements (`shared/tests/requirements-check.php`: Joomla 5.4 or later, PHP 8.1 or later). The suites always run against the newest Joomla 5.4.x and 6.x releases (official Docker images `joomla:5.4-php8.3-apache` and `joomla:6-php8.4-apache`, no pinned patch version); each job log prints the tested Joomla and PHP versions, so a failure caused by a new Joomla release is recognisable immediately. The pull request check **Collect Results** (`collect-results.yml`) determines which of the workflows above are triggered by the changed files, waits for them and fails unless all succeeded; a pull request that triggers none of them passes immediately. After re-running a failed workflow, re-run *Collect Results* as well.

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

