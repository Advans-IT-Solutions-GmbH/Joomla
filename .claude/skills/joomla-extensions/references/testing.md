# Testing

## Test Infrastructure

Tests run via Docker against a real Joomla (+ J2Commerce) installation. The shared runner is
`shared/tests/run-tests.sh`; each extension's `tests/run-tests.sh` delegates to it. The runner:

- reads `tests/test.env` (`CONTAINER_NAME`, `TEST_SCRIPTS`); a `CONTAINER_NAME` set on the command
  line overrides the value in `test.env`,
- waits up to 180 s for `/var/www/html/health.txt` in the container,
- copies `tests/scripts/*.php` and the shared suites `shared/tests/scripts/shared-*.php` into the
  container (`test.env` decides which of them run),
- passes `TEST_STRICT_SKIP`, `J2COMMERCE_STACK`, `PREVIOUS_PACKAGE` and `INSTALLED_PLUGIN_FOLDERS` into
  the container when they are set on the host or in `test.env` (`INSTALLED_PLUGIN_FOLDERS` lists every
  plugin group the extension may be registered in; Product Compare uses `j2commerce,j2store` because
  its installer registers `folder=j2store` on Joomla 5),
- prints `Tested versions: Joomla X.Y.Z, PHP A.B.C` (read from the container) and, in GitHub Actions,
  appends it to the job summary. `shared-update-from-previous.php` prints the same line.

## Joomla Versions Under Test

No Joomla patch version is pinned. The test images use moving official Docker tags:

| Stack | Base image | Resolves to |
|---|---|---|
| Joomla 5 (`Dockerfile`, `tests-j2c4/`, integration compose files, `shared/tests/Dockerfile.template`) | `joomla:5.4-php8.3-apache` | newest Joomla 5.4.x; PHP 8.3 is the newest PHP offered for 5.4 |
| Joomla 6 (`Dockerfile.joomla6`, `tests-j2c6/`, production-like lane) | `joomla:6-php8.4-apache` | newest Joomla 6.x; PHP 8.4 is the newest PHP offered for 6.x |

Incompatibilities with a new Joomla release therefore show up immediately. A red CI run can be caused
by a new Joomla release rather than by the change under test: check the `Tested versions` line in the
job log or summary and compare it with the last green run. Locally, Docker reuses a cached base image;
pull it (`docker pull joomla:6-php8.4-apache`) or build with `--pull` to test the same version as CI.

The OSMap Joomla 6 SEF lane also installs the real Joomla German `de-DE` pack. Because the moving
`joomla:6-php8.4-apache` tag may advance before `joomlagerman` publishes the exact same patch, the fixture first tries
the current Joomla patch and then walks older patches of the same major.minor, each with release suffixes
`v1`..`v3`. Downloads use curl retries/timeouts so transient network or 5xx failures do not make the SEF
fixture flaky.

Pinned on purpose: J2Commerce 4 stays on release 4.1.4; J2Commerce 6 is built from the commits listed
under "J2Commerce 6 package" below.

CI sets `TEST_STRICT_SKIP=1`, so a test that would SKIP fails instead. Set it locally to reproduce CI.

## Local Prerequisites

Run the commands in Bash (WSL or Git Bash on Windows) with Docker running. The test images copy the
packages in at build time, so `docker compose up -d` fails until the files below exist. Docker Compose
reuses an existing image: rebuild the image after replacing a ZIP.

### 1. Extension package as `extension.zip`

Same commands as the workflow job `Build Package`, run from the extension directory
(`j2commerce/<extension>`):

```bash
chmod +x build.sh
./build.sh
mkdir -p tests
cp *.zip tests/extension.zip
```

`cp *.zip` expects exactly one ZIP in the extension directory; delete ZIPs from earlier builds first.

**AJAX Forms** uses three test directories. Run from the repository root (the `tests-j2c4/` and
`tests-j2c6/` images are built with the repository root as build context, as in
`joomla-ajax-forms.yml`):

```bash
(cd plg_ajax_joomlaajaxforms && chmod +x build.sh && ./build.sh)

# tests/ (Joomla 5 and Joomla 6, no J2Commerce)
cp plg_ajax_joomlaajaxforms/plg_ajax_joomlaajaxforms_*.zip plg_ajax_joomlaajaxforms/tests/extension.zip

# tests-j2c4/ (Joomla 5 + J2Commerce 4; the J2Commerce 4 ZIP is downloaded during the image build)
cp plg_ajax_joomlaajaxforms/plg_ajax_joomlaajaxforms_*.zip plg_ajax_joomlaajaxforms/tests-j2c4/extension.zip
docker build \
  -f plg_ajax_joomlaajaxforms/tests-j2c4/Dockerfile \
  --build-arg J2C4_URL=https://github.com/j2commerce/j2cart/releases/download/v4.1.4/com_j2store_v4-4.1.4-pro.zip \
  -t plg_ajax_j2c4_test \
  .

# tests-j2c6/ (Joomla 6 + J2Commerce 6; place tests-j2c6/j2commerce6.zip first, see step 2)
cp plg_ajax_joomlaajaxforms/plg_ajax_joomlaajaxforms_*.zip plg_ajax_joomlaajaxforms/tests-j2c6/extension.zip
docker build \
  -f plg_ajax_joomlaajaxforms/tests-j2c6/Dockerfile \
  -t plg_ajax_j2c6_test \
  .
```

### 2. J2Commerce 6 package as `j2commerce6.zip` (Joomla 6 stacks only)

J2Commerce 6 publishes no release ZIP. CI builds it from a pinned commit of
`j2commerce/j2commerce` (PHP 8.3 with the `zip` extension). All workflows and all Joomla 6 jobs use
the same pin, `7edb6e11ae9148bf996b06c47a0d8266865af7b2` (the commit used in production), and build
it with `--no-minify` (without it, `build_package.php` of this commit requires esbuild). OSMap reads
the pin from `J2C6_REF`, which a manual run can override with the `j2commerce6_ref` input; its
production-like lane uses `J2C6_PROD_REF` and ignores that input. Cleanup sets it as `J2C6_COMMIT`
in the job `Build J2Commerce 6`.

Commands from the workflow step `Build J2Commerce 6 from source`; only `$GITHUB_WORKSPACE` is
replaced by the repository root. Run from the repository root; `/tmp/j2commerce6-src` must not exist
from an earlier run.

```bash
J2C6_REF=7edb6e11ae9148bf996b06c47a0d8266865af7b2
REPO_ROOT="$(pwd)"
git init /tmp/j2commerce6-src
git -C /tmp/j2commerce6-src remote add origin https://github.com/j2commerce/j2commerce.git
git -C /tmp/j2commerce6-src fetch --depth 1 origin "$J2C6_REF"
git -C /tmp/j2commerce6-src checkout --detach FETCH_HEAD
cd /tmp/j2commerce6-src && php build/build_package.php --no-minify   # without it, esbuild is required
ZIP=$(ls docs/packages/pkg_j2commerce_*.zip docs/packages/com_j2commerce_*.zip 2>/dev/null | head -1)
cp "$ZIP" "$REPO_ROOT/j2commerce/plg_privacy_j2commerce/tests/j2commerce6.zip"
```

Target path: `j2commerce/<extension>/tests/j2commerce6.zip`; for AJAX Forms
`plg_ajax_joomlaajaxforms/tests-j2c6/j2commerce6.zip`. The AJAX Forms stack in `tests/`
(`docker-compose.joomla6.yml`) installs no J2Commerce and needs no `j2commerce6.zip`.

## Running Tests Locally

Joomla 5 (from `tests/`):

```bash
docker compose up -d
./run-tests.sh all        # waits for health.txt itself
docker compose down -v
```

Joomla 6: start `docker compose -f docker-compose.joomla6.yml up -d` and pass the Joomla 6 container,
because `test.env` names the Joomla 5 container. Variables exactly as in the workflows:

| Extension | Command in `tests/` |
|---|---|
| Privacy | `J2COMMERCE_STACK=j6 CONTAINER_NAME=plg_privacy_j2commerce_j6_test ./run-tests.sh all` |
| OSMap | `CONTAINER_NAME=plg_osmap_j2commerce_j6_test J2COMMERCE_STACK=j6 ./run-tests.sh all` |
| OSMap SEF (J5, `docker-compose.sef.yml`) | `CONTAINER_NAME=plg_osmap_j2commerce_j5_sef_test ./run-tests.sh sitemap-http-sef` |
| OSMap SEF (J6, `docker-compose.joomla6-sef.yml`) | `CONTAINER_NAME=plg_osmap_j2commerce_j6_sef_test J2COMMERCE_STACK=j6 ./run-tests.sh sitemap-http-sef` |
| Import/Export | `CONTAINER_NAME=com_j2commerce_importexport_j6_test J2COMMERCE_STACK=j6 ./run-tests.sh all` |
| Product Compare | `J2COMMERCE_STACK=j6 CONTAINER_NAME=plg_j2commerce_productcompare_j6_test ./run-tests.sh all` |
| Cleanup | `CONTAINER_NAME=com_j2store_cleanup_j6_test ./run-tests.sh all` |
| AJAX Forms | `CONTAINER_NAME=plg_ajax_joomlaajaxforms_j6_test ./run-tests.sh all` |
| AJAX Forms `tests-j2c4/`, `tests-j2c6/` | `./run-tests.sh all` (own `test.env` with the right container) |

`all` runs every entry of `TEST_SCRIPTS`. For OSMap this includes `sitemap-http-sef`, which CI runs
only against the SEF stack; run suites by name to mirror the CI matrix.

Production-like lane for Privacy and OSMap (`tests/docker-compose.production.yml`, needs
`tests/j2commerce6.zip` from the pin `7edb6e11…`), commands from the workflow:

```bash
docker compose -f docker-compose.production.yml up -d
CONTAINER_NAME=plg_osmap_j2commerce_prod_test J2COMMERCE_STACK=j6 ./run-tests.sh installation   # CI runs every suite of test.env by name except sitemap-http-sef
docker exec -e TEST_STRICT_SKIP=1 plg_osmap_j2commerce_prod_test \
  php /var/www/html/tests/scripts/shared-deprecations.php
docker compose -f docker-compose.production.yml down -v
```

(Privacy: container `plg_privacy_j2commerce_prod_test`, `./run-tests.sh all`.)

Production-like lane for AJAX Forms: no separate compose file. The workflow builds the `tests-j2c6/`
image with PHP 8.4 and logged deprecations and starts the normal `tests-j2c6/` stack (needs
`tests-j2c6/extension.zip` and `tests-j2c6/j2commerce6.zip`). From the repository root:

```bash
docker build \
  -f plg_ajax_joomlaajaxforms/tests-j2c6/Dockerfile \
  --build-arg JOOMLA_BASE_IMAGE=joomla:6-php8.4-apache \
  --build-arg PHP_STRICT_DEPRECATIONS=1 \
  -t plg_ajax_j2c6_test \
  .
cd plg_ajax_joomlaajaxforms/tests-j2c6
docker compose up -d
TEST_STRICT_SKIP=1 ./run-tests.sh all
docker exec -e TEST_STRICT_SKIP=1 plg_ajax_j2c6_test \
  php /var/www/html/tests/scripts/shared-deprecations.php
docker compose down -v
```

The image uses the same tag `plg_ajax_j2c6_test` as the regular `tests-j2c6/` lane; rebuild it
without the build arguments before running that lane again.

## Test Suites

The authoritative suite list and order is `TEST_SCRIPTS` in each extension's `tests/test.env`
(`name:script.php`); the workflow matrices mirror it. Do not copy the lists into this file.

Shared suites (`shared/tests/scripts/`):

| Suite | Where | What it checks |
|---|---|---|
| `backend-views` (`shared-backend-views.php`) | every **component's** `test.env`, before `install-messages` | Logs into `/administrator` and really renders every backend view of the component: the entry point without a view, every `View\<Name>\HtmlView` class, every `tmpl/<name>` folder and every name in `BACKEND_VIEWS_EXTRA` (set in `test.env`). Each response must be HTTP 200, without a PHP error or Joomla error page, without an untranslated language key, and must contain at least one string of the extension's own language file. Reflection and `file_exists()` never open a page — this suite exists because a view calling `getDatabase()` without being database-aware answered HTTP 500 while every other suite stayed green. |
| `install-messages` (`shared-install-messages.php`) | every extension's `test.env`, before `uninstall` | Removes and reinstalls the package through the Joomla CLI in en-GB, de-DE and fr-FR, then installs once more over it (update). Fails on untranslated language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings/notices/fatal errors or a non-zero exit code. |
| `shared-update-from-previous.php` | CI job `Update from previous release (J6)` (Privacy, OSMap, AJAX Forms) | Installed previous release with an update site that still uses the former organisation name; the package under test is installed over it. Checks new manifest/installer script and version, exactly one update site with the current organisation name, bundled plugins installed and enabled, no untranslated key or error in the installer output. |
| `shared-deprecations.php` | CI production-like lane | `--arm` (before the suites) installs `shared-deprecation-tracer.php` as `auto_prepend_file`, enables Joomla's deprecation log and proves with a CLI and HTTP canary that logging works. After the suites it repeats the canary, lints the package with the container's PHP and reads the PHP error log, the tracer log (call stacks, also for @-suppressed deprecations) and Joomla's `deprecated.php`. A deprecation fails when extension code calls the deprecated function directly; calls made inside Joomla's libraries are listed as Joomla's own. |

Notable test details:

- **Privacy:** the test environment installs the plugin through the Joomla web installer (backend
  path) and records all plugin states in `/tmp/test-state/plugins-before-activation.tsv` before the
  setup enables every plugin via SQL; `01-installation.php` checks the installer effects from that
  file. `09-autocleanup-task.php` executes the cleanup routine with
  `php cli/joomla.php scheduler:run --id=<id>`.
- **AJAX Forms:** `12-htaccess-check.php` is a behaviour test: it places `.htaccess` fixtures in the
  Joomla root, installs the real package through the CLI and evaluates the queued installer messages.
- **OSMap:** `09-mixed-migration.php` covers J2Store and J2Commerce 6 registered at the same time
  (plugin serves `com_j2store` while both are enabled, `com_j2commerce` after `com_j2store` is
  disabled, a component without tables does not break the sitemap).

## CI

- **Triggers:** each `Build & Test` workflow runs on push and pull request to `main` for
  `<extension path>/**`, `shared/**` and its own workflow file, and manually (`workflow_dispatch`).
- **Jobs per extension:** `Build Package`; `PHP Syntax Check` (`php -l` on every PHP file of the
  extension; AJAX Forms does this in `Validate Package`); `Language Files`
  (`php shared/tests/lang-lint.php <extension dir>`: Joomla INI parsing, unescaped double quotes,
  keys and printf placeholders equal to en-GB, de-DE without `ß` and without ae/oe/ue spellings,
  fr-FR without missing accents; every own-prefix key the code uses is defined in every language,
  every defined key is used by the code, derived from a `langConstPrefix` or listed in
  `tests/language-keys-for-template-overrides.txt`, no key is assembled at runtime) followed by
  `php shared/tests/requirements-check.php <extension dir>`
  (`minimumJoomla '5.4'`/`minimumPhp '8.1'` in `script.php`, `preflight()` calls the parent, every
  manifest and `update.xml` `targetplatform`/`php_minimum`, the release workflow `targetplatform`, and
  that the expression accepts 5.4.x/6.x and rejects 4.x and 5.0 to 5.3) and
  `php shared/tests/deprecated-api-scan.php <extension dir>` (static scan for deprecated Joomla APIs);
  the Joomla 5 and Joomla 6 suite matrices; the
  `official-j5-j2c4` / `official-j6-j2c6` gates; and a final job `<Extension> / all jobs` that fails
  unless every job succeeded (`failure`, `cancelled` and `skipped` count as failed).
- **Privacy, OSMap, AJAX Forms** additionally run:
  - `Update from previous release (J6)`: builds the previous release with `build.sh` from the last
    `release: <prefix> v` commit on `main` (older release tags are deleted by the publish workflow,
    see `release-workflow.md`), installs it, and updates to the package under test
    (`shared-update-from-previous.php`).
  - `Production-like (newest J6, PHP 8.4, MariaDB 10.6, J2C6 production pin)`: Privacy/OSMap via
    `tests/docker-compose.production.yml`; AJAX Forms via `tests-j2c6/Dockerfile` built with
    `--build-arg JOOMLA_BASE_IMAGE=joomla:6-php8.4-apache --build-arg PHP_STRICT_DEPRECATIONS=1`.
    Runs all suites (`./run-tests.sh all`; OSMap runs each suite by name without the SEF-only `sitemap-http-sef`), then `shared-deprecations.php`.
- **J2Commerce 6 pin:** every workflow builds J2Commerce 6 from
  `7edb6e11ae9148bf996b06c47a0d8266865af7b2` with `build_package.php --no-minify` (see
  "J2Commerce 6 package" above).
- **Required check:** only `collect-results.yml` produces the required status check
  `Collect Results`. It runs on every pull request to `main`, reads the `pull_request.paths` of every
  workflow, matches them against the files changed by the pull request, waits for those workflow runs
  on the pull request head commit and fails unless each concluded `success`. A pull request that
  triggers no extension workflow passes immediately. **After re-running a failed extension workflow,
  re-run `Collect Results` as well.**
- **Security Scan** (`security.yml`) runs on push and pull request to `main` and manually: Gitleaks is
  blocking; Semgrep and zizmor are report-only.

## Known Limitations

- **No PHP 8.5 lane:** there is no official Joomla Docker image with PHP 8.5; the production-like
  lane uses the newest available one (`joomla:6-php8.4-apache`). PHP 8.5 is not tested.
- Privacy: the checkout consent checkbox and the Privacy tab markup are render-tested
  (`11-consent-ui-render.php`); real browser interaction is not. To render in CLI the test injects
  the application and the plugin cache via reflection, so it does not prove that Joomla loads the
  plugin or that a real checkout request reaches the layouts (details in the script header).
- The update test covers only the previous release on Joomla 6, not older versions or Joomla 5.
- Subscription/recurring product lifecycle is not covered.
