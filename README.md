# Joomla Extensions Repository

<a href="https://www.swissmadesoftware.org/en/about/swiss-made-software.html">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/labels/sms-logo-1v-300dpi-rgb-on-white.png">
    <img src=".github/assets/labels/sms-logo-1v-300dpi-rgb.png" width="110" alt="swiss made software">
  </picture>
</a>

The Joomla and J2Commerce extensions in this repository carry the swiss made software label.

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
├── plg_system_privacyrequests/ # Account-free native Core privacy request adapter
├── j2commerce/               # J2Commerce Extensions
│   ├── com_j2commerce_importexport/
│   ├── com_j2store_cleanup/
│   ├── plg_osmap_j2commerce/
│   ├── plg_j2commerce_productcompare/
│   └── plg_privacy_j2commerce/
├── shared/                   # Shared installer support, build and test scripts
└── .github/                  # CI/CD workflows
```

## Available Extensions

### Joomla Extensions

| Extension | Description | Joomla |
|-----------|-------------|--------|
| [Joomla! AJAX Forms](plg_ajax_joomlaajaxforms/) | AJAX login, registration, MFA, profile editing, password reset, username reminder; optional J2Commerce 4.x/6.x cart operations | 5.4 – 6.x |
| [DMARC Reports](com_dmarcreports/) | Administrator component for RFC 7489 DMARC aggregate and RFC 8460 TLS-RPT reports (IMAP/file upload), multi-domain analysis, Action Center, MTA-STS guidance and CSV export | 5.4 – 6.x |
| [DMARC Reports Dashboard Module](mod_dmarcreports/) | Administrator control-panel module that surfaces the DMARC Reports multi-domain summary and top Action Center items on the Joomla backend dashboard, linking into the component and degrading gracefully when it is absent | 5.4 – 6.x |
| [DMARC Reports Scheduler Task](plg_task_dmarcreports/) | Joomla scheduler task plugin that automatically fetches, parses and stores DMARC and TLS-RPT reports from every active IMAP source on a configurable interval, reusing the component's ingestion and locking so automatic and on-demand runs stay parallel-safe | 5.4 – 6.x |
| [DMARC Reports MTA-STS Policy](plg_system_dmarcmtasts/) | System plugin that serves the generated MTA-STS policy (RFC 8461) at `/.well-known/mta-sts.txt` for each configured domain, so the policy stays in sync with the component options without a hand-maintained static file | 5.4 – 6.x |
| [DMARC Reports Package](pkg_dmarcreports/) | Joomla package that bundles the DMARC Reports component, dashboard module, scheduler task plugin and MTA-STS system plugin into one install, update and uninstall for the administrator | 5.4 – 6.x |
| [Appointments](com_appointments/) | **Pre-release.** Self-hosted scheduling, no-JavaScript booking, approval, signed management links, invitations, reminders/retention and opt-in conferencing; encrypted internet calendars with CalDAV, device credentials and grants; Joomla privacy integration. See the component README for setup and limitations | 5.4 – 6.x |
| [Core privacy requests](plg_system_privacyrequests/) | **Pre-release.** Standalone, opt-in no-account removal request and email confirmation through the native Core privacy lifecycle; administrators and active privacy plugins retain authorization, legal blocking and separate completion responsibility | 5.4 – 6.x |

### J2Commerce Extensions

| Extension | Description |
|-----------|-------------|
| [Import/Export](j2commerce/com_j2commerce_importexport/) | Bulk data import/export for J2Commerce |
| [J2Store Cleanup](j2commerce/com_j2store_cleanup/) | Detect incompatible extensions after J2Store-to-J2Commerce migration |
| [OSMap J2Commerce](j2commerce/plg_osmap_j2commerce/) | Adds enabled, visible J2Commerce products whose article is published and accessible to guests to the OSMap sitemap automatically |
| [Product Compare](j2commerce/plg_j2commerce_productcompare/) | Compare products side-by-side |
| [Privacy](j2commerce/plg_privacy_j2commerce/) | GDPR compliance for J2Commerce |

### Bundled Plugin Inventory

The tables above list the **12 independently built extensions** on this branch. Their production manifests also declare these **six bundled plugins**. Each child has its own manifest/languages and is installed, updated and removed through its parent; do not treat it as a separate release download. A package's four DMARC children are already listed above, not four additional extensions. Product Compare's Joomla 5 `j2store` mirror is a deployment variant of that plugin, not another source extension.

| Bundled plugin | Parent and role | Initial state |
|---|---|---|
| [Privacy - Appointments](com_appointments/plugins/privacy/appointments/README.md) | Appointments: native privacy export, removal and legal-blocker callbacks | Enabled |
| [Task - Appointments](com_appointments/plugins/task/appointments/README.md) | Appointments: reminders, retention and internet-calendar maintenance routines | Enabled; task states and triggers are separate |
| [System - Appointments CalDAV](com_appointments/plugins/system/appointmentsdav/README.md) | Appointments: DAV endpoint and sign-in guard | Disabled until operator setup |
| [User - Appointments internet calendars](com_appointments/plugins/user/appointments/README.md) | Appointments: key sessions, account events and cleanup | Disabled until operator setup |
| [System - J2Commerce Privacy](j2commerce/plg_privacy_j2commerce/plugins/system/j2commerceprivacy/README.md) | J2Commerce Privacy: checkout consent and guest-confirmation mail links | Enabled when first introduced |
| [Task - J2Commerce Privacy Cleanup](j2commerce/plg_privacy_j2commerce/plugins/task/j2commerceprivacy/README.md) | J2Commerce Privacy: automatic retention cleanup | Enabled when first introduced; configure a task/trigger |

Existing enabled/disabled plugin states are preserved on updates. This inventory describes implemented source, not a statement of release, installed acceptance or website-wide privacy coverage.

## Installation safety and repeatable system checks

Every distributable uses the same installer support from `shared/installer/`, staged into its
ZIP by `shared/build/prepare-installer.php` (builds require PHP CLI 8.1+ as well as Bash,
rsync and zip/unzip). Each extension keeps its own lifecycle work, but
the common installer adds a live prerequisite report after installation and update. **Passed
checks, blockers and warnings are separate.** A disabled plugin is not a missing PHP extension,
and an unverified cron trigger or mail delivery is never reported as a successful end-to-end test.
The report is translated into en-GB, de-DE and fr-FR.

The **System check** tab is read-only and can be reopened and refreshed without reinstalling:

| Extension type | Administrator menu path |
|---|---|
| Component | **System → Setup → Global Configuration → extension → System check** (also through **Options** where the component offers it, or the configuration link in the installation report) |
| Plugin, including bundled plugins | **System → Manage → Plugins → plugin → System check** |
| Administrator module | **System → Manage → Administrator Modules → module → System check** |
| DMARC package | **Components → DMARC Reports → Options → System check**, which also repeats the installed package's child checks; open the children's individual tabs for details |

These are local checks of the running platform, required PHP extensions, database driver where
applicable, installed/enabled dependencies, enabled shop tables and writable extension directory.
Optional archive support and disabled plugins are warnings. The report names the next menu path
for configuration, scheduler history and mail testing. It also checks the current administrator
request's HTTPS scheme (unknown during CLI installation) and reads the root `.htaccess` for
known component/option redirect or deny patterns, using the same analyzer as AJAX Forms.
Missing or unreadable rules, possible routing blocks and unverified HTTP routing are warnings:
neither an HTTPS administrator request nor the absence of known rewrite patterns proves that
public endpoints work. It sends no HTTP probes and never changes web-server configuration.
Keep using DMARC Reports' existing
**System Check** view for its deeper IMAP/DNS/MTA-STS diagnostics; the common tab does not contact
external systems or replace that view.

**Existing files are not permission to overwrite them.** Before Joomla copies files, the
installer compares every declared destination, including media, languages, bundled plugins,
the Joomla 5 Product Compare mirror and the known installer-managed external changes.
Identical files and absent destinations need no approval. Differing existing files are backed
up byte-for-byte, the installation stops, and a review plan identifies each destination, its
old/new SHA-256 hashes, backup and operation. This intentionally applies to ordinary upgrades
as well as customised or foreign files: a previous release's changed file is still an existing file.
Obsolete declared paths and known legacy-file retirement actions also require approval.
Unrelated files in the Product Compare mirror are retained rather than deleting the whole tree.
Existing template overrides that are not being retired are left untouched.

Backups and guarded JSON state are stored in **`administrator/advans-installer-state/`**, outside
Joomla's disposable cache and extension directories. Backup containers are non-executable PHP
envelopes containing the original bytes as base64, their hash and destination binding. Opening
one over HTTP does not disclose its contents. No automatic cleanup removes these backups;
the server administrator must retain them securely for the required rollback period.

Joomla's installer is not interactive. After an installation stops, the server administrator must:

1. Unpack the intended ZIP privately and review its incoming files against the review plan and
   decoded backup contents. A `remove` or retirement operation is not an ordinary file update;
   its incoming hash represents the named operation marker, not replacement content.
2. Verify the review plan's SHA-256 against the fingerprint printed in the installer error,
   then explicitly approve that exact plan using the support shipped in the unpacked ZIP:

   ```text
   php /path/to/unpacked/advansinstaller/approve.php --approve /path/to/site/administrator/advans-installer-state/EXTENSION/plan.php REVIEWED_PLAN_SHA256
   ```

   Run this command as the same filesystem user that performs the Joomla install, so that
   Joomla can read the protected approval file. The server administrator must arrange that
   user context; do not make the state directory or approval files publicly readable.

3. Retry the same ZIP under **System → Install → Extensions** (or repeat the CLI install).
   Different incoming or existing bytes require a new backup and approval. A package inspects
   all child archives first; its exact-hash approval also applies when those children install.

Approval requires filesystem-administrator access; there is no public approval endpoint and no
query-string bypass. Backups must verify before approval is accepted. An unreadable destination,
symbolic link, invalid manifest or failed backup stops the install rather than claiming success.
Do not run concurrent installs or edit destination files between review and retry.
For a first-install conflict, no extension options need to exist yet: use the CLI support in the
unpacked package. Review plans/approvals do not grant permission to overwrite different bytes
later, and do not authorise unrelated files or uninstall data deletion.

## Testing

Each extension has automated tests that run via GitHub Actions on pushes and pull requests to `main` that change the extension directory, one of the files under `shared/` that the extension uses, or the extension's own workflow file. Every workflow can also be started manually. Every suite keeps its own job on every lane, so a red check names the suite and the lane straight away.

| Workflow | Extension | Trigger paths (push/PR to `main`, plus manual dispatch) |
|----------|-----------|---------|
| `joomla-ajax-forms.yml` | Joomla AJAX Forms | `plg_ajax_joomlaajaxforms/**`, the shared files below, own workflow file |
| `dmarc-reports.yml` | DMARC Reports | `com_dmarcreports/**`, the shared files below, own workflow file |
| `moddmarcreports.yml` | DMARC Reports Dashboard Module | `mod_dmarcreports/**`, the shared files below, own workflow file |
| `task-dmarcreports.yml` | DMARC Reports Scheduler Task | `plg_task_dmarcreports/**`, the shared files below, own workflow file |
| `system-dmarcmtasts.yml` | DMARC Reports MTA-STS Policy | `plg_system_dmarcmtasts/**`, the shared files below, own workflow file |
| `system-privacyrequests.yml` | Core Privacy Requests | `plg_system_privacyrequests/**`, consumed shared files, own build/release/publish workflows |
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
| `build/**`, `installer/**` | every extension workflow (the DMARC package also stages the support and verifies its assembled bundle) |
| `tests/installer-*.php` | Infrastructure Tests: offline destination, translation, backup and approval regressions |
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

**Collect Results** (`collect-results.yml`) determines which path-filtered workflows are triggered by the changed files and also requires every unconditional PR workflow, including **Security Scan**. It waits for successful runs tied to the exact PR/base/head context; missing, failed, cancelled or skipped required runs fail closed. GitHub associates PR check runs with the source head SHA, so the collector discovers them there rather than assuming checks exist on the synthetic merge SHA. Each PR workflow's run title records the event-derived PR number and base/head/merge SHAs; the collector requires an exact title and, when GitHub supplies PR associations, an exact association as well. The summary reports the event's synthetic merge SHA used by the default checkout, without treating the API check-suite SHA as independent proof of the tested tree. Security Scan must actually execute its expected scanner jobs: Gitleaks blocks secret findings; Semgrep and zizmor findings remain report-only, while scanner/tool errors fail. Documentation-only PRs still require Security Scan. After re-running a failed workflow, re-run *Collect Results* as well. **Infrastructure Tests** exercises the publish helper and gate against offline fixtures without making release API writes.

Local test prerequisites and commands: [`.claude/skills/joomla-extensions/references/testing.md`](.claude/skills/joomla-extensions/references/testing.md).

## Languages

Every extension and bundled plugin ships exactly **de-DE, en-GB and fr-FR**, with complete key
and placeholder parity per language file. This also applies to future extensions. Sites run in
de-DE, whose wording is **Swiss High German** (`ss`, never `ß`, Swiss terminology, `z.B.`).
No de-CH language folders or manifest declarations are shipped or generated at build time.
The source language files remain checked in. The shared lint enforces this rule across all
extensions, while package verification checks that the ZIP contains the same supported file set.
No text is written into the code: PHP, layouts and JavaScript show language keys only.

### E-mail templates

Every extension that sends mail ships complete, equivalent subjects and message texts in
**de-DE, en-GB and fr-FR**, even when only German is active on the website. HTML generated from
translated text uses that same language; there is no second, untranslated HTML default.
The recipient's supported account language wins, otherwise the **default site language** applies,
including recipients without an account. A request, administrator session, order or another
recipient must not choose the mail language. An unsupported site language falls back to en-GB.
Operator-customised templates remain operator content and are not overwritten on update.

| Extension | Outgoing templates |
|---|---|
| Appointments | 18 booking/invitee/guest/host purposes and 7 internet-calendar/device/activation/security purposes; subject and text in each locale, with HTML rendered through the configured Joomla mail layout |
| AJAX Forms | Password reset, username reminder, self/admin activation and new-registration notice; Joomla `com_mails` templates with translated plugin fallbacks |
| J2Commerce Privacy | Customer retention/removal notice (8 account/guest, address and newsletter variants) and 3 administrator action notices, including translated order details; plain-text mail |
| Core privacy requests | Confirmation uses Joomla-owned `com_privacy.notification.remove` in the recipient's supported account language or site default. Native administrator messages include the plugin's translated delivery-failure notice; delivery false/exception retains a real pending receipt and an operator-visible failure |
| Other extensions and bundled plugins | No outgoing message bodies of their own. DMARC ingestion receives reports, it does not send notifications. The Privacy system plugin changes confirmation URLs in Joomla-owned `com_privacy` mail without replacing its text or language selection |

To add a language:

The supported language set is a repository-wide decision. A new locale requires maintainer
approval and must be added to all extensions, bundled plugins, the shared lint and package checks,
not just to a single extension. Swiss wording is maintained in de-DE, not a separate de-CH locale.

1. Copy the `en-GB` folder of every `language/` directory of the extension (bundled sub-plugins and `administrator/language` of components included) to the new tag and translate every value. Keep keys and `printf` placeholders. Also translate `shared/installer/language/en-GB/advans_installer.ini` under the new tag; staging fails if a shipped language lacks this shared translation.
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

If a merged `release:` commit was never published, for example because Actions could not start jobs at that moment, start the extension's **Publish - …** workflow manually with **Run workflow** on `main` and enter the released version. The run takes the release commit from the merged `release: <prefix> v<version> (#N)` PR, which GitHub must confirm, checks out exactly that commit and builds from it, never from the current `main`. Before building, every publish run compares the trees of all bundled paths (the extension, a package's children and the build scripts) between the release commit and the checked-out source and aborts on any difference or extra file. The tag, the release notes ("Built from the release commit …") and the signed provenance all name that verified release commit; a manual run attests an SLSA provenance that lists the commit of the run first, as GitHub's attestation API requires, and the verified release commit as the built source (`name: release-commit`). The publish helper still refuses a version whose tag already exists on another commit.

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
| `release-system-privacyrequests.yml` | `system-privacyrequests-v*` |
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
