# Joomla Extensions Repository

Extensions for [Joomla](https://github.com/joomla/joomla-cms) and [J2Commerce](https://github.com/j2commerce/j2commerce) developed and maintained by Advans IT Solutions GmbH.

## Repository Structure

```
Joomla/
├── plg_ajax_joomlaajaxforms/ # Joomla AJAX Forms Plugin
├── com_dmarcreports/         # DMARC Reports Component
├── mod_dmarcreports/         # DMARC Reports Dashboard Module
├── plg_task_dmarcreports/    # DMARC Reports Scheduler Task Plugin
├── plg_system_dmarcmtasts/   # DMARC Reports MTA-STS Policy System Plugin
├── pkg_dmarcreports/         # DMARC Reports Package (bundles the four extensions above)
├── com_appointments/         # Appointments Component
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
| [DMARC Reports](com_dmarcreports/) | Administrator component that ingests and analyses RFC 7489 DMARC aggregate reports (IMAP and file upload) across multiple domains, with an actionable rollout dashboard and CSV export | 5.4 – 6.x |
| [DMARC Reports Dashboard Module](mod_dmarcreports/) | Administrator control-panel module that surfaces the DMARC Reports multi-domain summary and top Action Center items on the Joomla backend dashboard, linking into the component and degrading gracefully when it is absent | 5.4 – 6.x |
| [DMARC Reports Scheduler Task](plg_task_dmarcreports/) | Joomla scheduler task plugin that automatically fetches, parses and stores DMARC and TLS-RPT reports from every active IMAP source on a configurable interval, reusing the component's ingestion and locking so automatic and on-demand runs stay parallel-safe | 5.4 – 6.x |
| [DMARC Reports MTA-STS Policy](plg_system_dmarcmtasts/) | System plugin that serves the generated MTA-STS policy (RFC 8461) at `/.well-known/mta-sts.txt` for each configured domain, so the policy stays in sync with the component options without a hand-maintained static file | 5.4 – 6.x |
| [DMARC Reports Package](pkg_dmarcreports/) | Joomla package that bundles the DMARC Reports component, dashboard module, scheduler task plugin and MTA-STS system plugin into one install, update and uninstall for the administrator | 5.4 – 6.x |
| [Appointments](com_appointments/) | **Pre-release.** Self-hosted scheduling with availability rules, no-JavaScript booking, approval, calendar invitations, signed reschedule/cancel/erase links, private subscriptions, reminders and retention, opt-in conferencing, Joomla privacy integration and per-event administration. See the component README for setup and limitations | 5.4 – 6.x |

### J2Commerce Extensions

| Extension | Description |
|-----------|-------------|
| [Import/Export](j2commerce/com_j2commerce_importexport/) | Bulk data import/export for J2Commerce |
| [J2Store Cleanup](j2commerce/com_j2store_cleanup/) | Detect incompatible extensions after J2Store-to-J2Commerce migration |
| [OSMap J2Commerce](j2commerce/plg_osmap_j2commerce/) | Adds enabled, visible J2Commerce products whose article is published and accessible to guests to the OSMap sitemap automatically |
| [Product Compare](j2commerce/plg_j2commerce_productcompare/) | Compare products side-by-side |
| [Privacy](j2commerce/plg_privacy_j2commerce/) | GDPR compliance for J2Commerce |

## Testing

Each extension has automated tests that run via GitHub Actions on pushes and pull requests to `main` that change the extension directory, one of the files under `shared/` that the extension uses, or the extension's own workflow file. Every workflow can also be started manually. Every suite keeps its own job on every lane, so a red check names the suite and the lane straight away.

| Workflow | Extension | Trigger paths (push/PR to `main`, plus manual dispatch) |
|----------|-----------|---------|
| `joomla-ajax-forms.yml` | Joomla AJAX Forms | `plg_ajax_joomlaajaxforms/**`, the shared files below, own workflow file |
| `dmarc-reports.yml` | DMARC Reports | `com_dmarcreports/**`, the shared files below, own workflow file |
| `moddmarcreports.yml` | DMARC Reports Dashboard Module | `mod_dmarcreports/**`, the shared files below, own workflow file |
| `task-dmarcreports.yml` | DMARC Reports Scheduler Task | `plg_task_dmarcreports/**`, the shared files below, own workflow file |
| `system-dmarcmtasts.yml` | DMARC Reports MTA-STS Policy | `plg_system_dmarcmtasts/**`, the shared files below, own workflow file |
| `pkg-dmarcreports.yml` | DMARC Reports Package | `pkg_dmarcreports/**`, the shared files below, own workflow file |
| `appointments.yml` | Appointments | `com_appointments/**`, the shared files below, own workflow file |
| `j2commerce-import-export.yml` | Import/Export | `j2commerce/com_j2commerce_importexport/**`, the shared files below, own workflow file |
| `j2store-cleanup.yml` | J2Store Cleanup | `j2commerce/com_j2store_cleanup/**`, the shared files below, own workflow file |
| `osmap-j2commerce.yml` | OSMap J2Commerce | `j2commerce/plg_osmap_j2commerce/**`, the shared files below, own workflow file |
| `j2commerce-product-compare.yml` | Product Compare | `j2commerce/plg_j2commerce_productcompare/**`, the shared files below, own workflow file |
| `j2commerce-privacy.yml` | Privacy | `j2commerce/plg_privacy_j2commerce/**`, the shared files below, own workflow file |

**Shared trigger paths.** No `Build & Test` workflow watches `shared/**` as a whole; each one lists the files it really uses, so a change to a shared file starts only the extensions that consume it. The guard below is the one workflow that still watches `shared/**`, because it has to see every change there.

| File under `shared/` | Starts |
|---|---|
| `build/build.sh`, `build/verify-package.sh` | every extension workflow (the DMARC package also verifies its assembled bundle) |
| `tests/run-tests.sh` | every extension workflow (the local runners delegate here) |
| `tests/lang-lint.php`, `tests/requirements-check.php`, `tests/deprecated-api-scan.php` | every extension workflow (job `Language Files`) |
| `tests/scripts/shared-test-helpers.php` | every extension workflow (loaded by the shared suites) |
| `tests/scripts/shared-install-messages.php` | every extension workflow (installer-message suites) |
| `tests/scripts/shared-backend-views.php` | Appointments, DMARC Reports component, Import/Export, Cleanup |
| `tests/scripts/shared-update-from-previous.php` | AJAX Forms, OSMap, Privacy (job `Update from previous release (J6)`) |
| `tests/scripts/shared-deprecations.php`, `tests/scripts/shared-deprecation-tracer.php` | AJAX Forms, OSMap, Privacy (production-like lane) |
| `tests/Dockerfile.template`, `tests/scripts/docker-entrypoint.sh`, `tests/scripts/install-extension.php` | nothing: templates that every extension has copied into its own `tests/` tree, read by no lane |

`Shared Path Coverage` (`shared-path-coverage.yml`) fails if a file under `shared/` is matched by the `pull_request.paths` of no other workflow and is not on the documented exception list in `.github/scripts/check-shared-path-coverage.sh`. It requires one match, not every workflow that uses the file: it excludes itself from the search and ignores workflows without a `paths` filter, and it cannot tell whether a lane really reads the file. The table above stays the record of which workflow consumes what. **A new file under `shared/` has to be added to the workflows that use it, otherwise that check fails.**

**Superseded test runs are cancelled.** Every `Build & Test` workflow has a `concurrency` group of workflow plus ref with `cancel-in-progress: true`, so a new push to a pull request or branch cancels the previous run of that same ref instead of letting it finish. Runs of different pull requests never cancel each other. On `main` and for a manual run (`workflow_dispatch`) the group key is the unique run id instead, so no test run on the default branch is cancelled and no manual test run cancels another one: a dispatch is started deliberately and may carry its own inputs (the OSMap workflow takes a `j2commerce6_ref`). Release preparation has no concurrency group. Publication is serialized per extension with `cancel-in-progress: false`, so an active publication is never interrupted by a newer one; GitHub may replace an older pending run in that group.

Besides the test suites, every extension workflow lints its PHP files on the minimum supported PHP 8.1 and checks its language files (`shared/tests/lang-lint.php`: Joomla INI parsing, keys and placeholders equal to en-GB, Swiss High German and French spelling checks, every key the code uses defined in every language, no unused and no assembled keys) and its declared requirements (`shared/tests/requirements-check.php`: Joomla 5.4 or later, PHP 8.1 or later). The suites always run against the newest Joomla 5.4.x and 6.x releases (official Docker images `joomla:5.4-php8.3-apache` and `joomla:6-php8.4-apache`, no pinned patch version); each job log prints the tested Joomla and PHP versions, so a failure caused by a new Joomla release is recognisable immediately.

Shop-dependent workflows use *Resolve Shop Releases* (`.github/actions/resolve-shops`) to resolve the newest aligned J2Commerce 6 commit of `j2commerce/j2commerce` `main` and the latest J2Store 4 release of `j2commerce/j2cart` (download verified against its sha256 digest) once per run. Shop-free DMARC and Appointments workflows do not resolve or install a shop. AJAX Forms and Cleanup build the resolved J2Commerce 6 package once and share that artifact between jobs; each test job still creates a fresh container and retains the full suite/platform matrix. Intermediate packages expire after one day; test-log artifacts after at most three.

**Collect Results** (`collect-results.yml`) determines which path-filtered workflows are triggered by the changed files and also requires every unconditional PR workflow, including **Security Scan**. It waits for successful runs on the current PR head; missing, failed, cancelled or skipped required runs fail closed. Security Scan must actually execute its expected scanner jobs: Gitleaks blocks secret findings; Semgrep and zizmor findings remain report-only, while scanner/tool errors fail. Documentation-only PRs still require Security Scan. After re-running a failed workflow, re-run *Collect Results* as well. **Infrastructure Tests** exercises the publish helper and gate against offline fixtures without making release API writes.

Local test prerequisites and commands: [`.claude/skills/joomla-extensions/references/testing.md`](.claude/skills/joomla-extensions/references/testing.md).

## Languages

All extensions ship de-DE (Swiss High German), en-GB and fr-FR. No text is written into the code: PHP, layouts and JavaScript show language keys only, so a further language needs language files and, for extensions using `<languages>`, one manifest line per file.

To add a language:

1. Copy the `en-GB` folder of every `language/` directory of the extension (bundled sub-plugins and `administrator/language` of components included) to the new tag and translate every value. Keep keys and `printf` placeholders.
2. Import/Export, Cleanup, DMARC Reports, DMARC Reports Dashboard Module, DMARC Reports Package, Appointments and AJAX Forms list their files in the manifest (`<languages>`): add one `<language tag="xx-XX">` line per file. Appointments has two such blocks, one for the administrator files and one for the site files. The other extensions load their whole `language/` folder.
3. Run `php shared/tests/lang-lint.php <extension dir>` for every extension.
4. Add the tag where tests name the languages explicitly (installer-messages suite, `de-DE, en-GB, fr-FR` loops).

Details: [`.claude/skills/joomla-extensions/references/conventions.md`](.claude/skills/joomla-extensions/references/conventions.md#checklist-add-a-language).

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

### Release permissions and CI approval

Release preparation uses the built-in `GITHUB_TOKEN`, with `contents: write` and `pull-requests: write`; no App or PAT is configured. A repository owner must enable **Settings → Actions → General → Workflow permissions → Allow GitHub Actions to create and approve pull requests**, subject to organisation policy. Without that permission the branch push can succeed but PR creation fails; the workflow reports an error and preserves the branch. Enable the permission and rerun preparation, or open the PR from that branch manually rather than deleting it.

For PRs created or updated using `GITHUB_TOKEN`, the `pull_request` events `opened`, `synchronize` and `reopened` create **approval-required** CI runs. A user with write access must select **Approve workflows to run** in the PR before checks can execute. Review and merge only after those checks pass; the merge must be performed by a human, not by the token. See [GitHub's workflow-trigger documentation](https://docs.github.com/en/actions/how-tos/write-workflows/choose-when-workflows-run/trigger-a-workflow).

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
| `release-dmarcreports.yml` | `dmarcreports-v*` |
| `release-moddmarcreports.yml` | `moddmarcreports-v*` |
| `release-task-dmarcreports.yml` | `task-dmarcreports-v*` |
| `release-system-dmarcmtasts.yml` | `system-dmarcmtasts-v*` |
| `release-pkg-dmarcreports.yml` | `pkgdmarcreports-v*` |
| `release-appointments.yml` | `appointments-v*` |
| `release-importexport.yml` | `importexport-v*` |
| `release-cleanup.yml` | `cleanup-v*` |
| `release-productcompare.yml` | `productcompare-v*` |
| `release-privacy.yml` | `privacy-v*` |
| `release-osmap-j2commerce.yml` | `osmap-j2commerce-v*` |

Only the newest release per extension is kept, intentionally. The shared publish helper checks numeric version order against remote tags and releases before any writes, rejects obsolete reruns and refuses an existing version tag that points anywhere except the actual built checkout commit. Retrying the same version from the same commit is supported without deleting its working release or overwriting existing assets. New releases are staged as drafts, downloaded and verified before publication; only then are strictly older releases and tags of that same prefix deleted. Failures are reported rather than silently skipping cleanup. Extension update URLs continue to use their explicit version tags, never the repository-wide `/releases/latest` endpoint, which cannot represent independent extension releases.

View all releases: https://github.com/Advans-IT-Solutions-GmbH/Joomla/releases

## License

All extensions in this repository are free software, licensed under the GNU General Public License version 3 or later (`GPL-3.0-or-later`); see [LICENSE.txt](LICENSE.txt). Each extension ships its own copy of the license text. Third-party template code bundled with the privacy plugin is listed in [THIRD-PARTY-NOTICES.txt](j2commerce/plg_privacy_j2commerce/THIRD-PARTY-NOTICES.txt).

## Security

Please report vulnerabilities via GitHub private vulnerability reporting; see the organization [security policy](https://github.com/Advans-IT-Solutions-GmbH/.github/blob/main/SECURITY.md). Pull requests to `main` require review and passing checks.

---

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

Copyright (C) 2026 Advans IT Solutions GmbH
