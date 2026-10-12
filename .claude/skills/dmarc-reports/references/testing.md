# Testing & validation

Two layers: Docker-based integration suites (what CI runs) and a PHPUnit unit suite. The general
workflow/CI rules live in the `joomla-extensions` skill (`references/testing.md`) — this file only
notes what is specific to `com_dmarcreports`.

## Integration suites (`tests/`)

Driven by `shared/tests/run-tests.sh` via `tests/test.env` (`CONTAINER_NAME=com_dmarcreports_test`,
`TEST_SCRIPTS`). Runs against **two real stacks**, Joomla 5 (`test-j5`) and Joomla 6 (`test-j6`);
**no J2Commerce** is needed, so there is no `j2commerce6.zip` step. Suites, in `test.env` order:

| Suite | Script | Checks |
|-------|--------|--------|
| installation | `01-installation.php` | Registration, files, the five `#__dmarc_*` tables created |
| structure | `02-structure.php` | Namespaces, services, forms and SQL present/well-formed |
| parser | `03-parser.php` | RFC 7489 XML parsed into the expected records |
| storage | `04-storage.php` | Idempotent store (dedupe by `report_hash`) |
| action-center | `05-action-center.php` | Recommendations derived correctly from the data |
| metrics | `06-metrics.php` | `Metrics` aggregator over a seeded DB: lifetime totals, rolling-window rates, new sources, timeline |
| backend-views | `shared-backend-views.php` | Renders each admin page: 200, no PHP/Joomla error, no untranslated key |
| install-messages | `shared-install-messages.php` | Install/reinstall/update in en-GB/de-DE/fr-FR, no `[ERROR]`/`[WARNING]` |
| uninstall | `10-uninstall.php` | Removal, table drop, verification |

CI sets `TEST_STRICT_SKIP=1` (a SKIP counts as failure). The required PR check is **Collect
Results**; after re-running a failed extension workflow, re-run Collect Results too.

IMAP is **not** integration-tested (no IMAP server in CI) — the streaming client's correctness
rests on code review. See `ingestion.md`.

## Unit suite (`tests/Unit/`, PHPUnit)

Separate CI job, no Docker. Covers the parser, `DnsDiagnostics` and `ActionCenter` (pure logic).
The database-backed `Metrics` aggregation is exercised by the `metrics` integration suite instead,
because it needs a real database. `composer install` then `composer test`. Local run (Windows, no
Docker/composer): run `phpunit.phar` from the `com_dmarcreports` dir (so it finds `phpunit.xml`)
with a php.ini that loads mbstring/openssl/zip/fileinfo.

## Validation gates (run before every push)

All must be green; CI runs the same:

```
php -l <changed files>
php shared/tests/lang-lint.php
php shared/tests/requirements-check.php com_dmarcreports
php shared/tests/deprecated-api-scan.php com_dmarcreports      # must be 0 findings
# PHPUnit Unit suite
```

lang-lint specifics: de-DE uses real umlauts (ä/ö/ü, no ß); fr-FR must use accented forms for the
`FRENCH_UNACCENTED_WORDS` blocklist words; en-GB/de-DE/fr-FR key sets must stay aligned.
deprecated-api-scan flags the removed `setQuery($q,$offset,$limit)` and `getQuery(true)` shapes —
use `$query->setLimit()` and the `createQuery()`/`getQuery(true)` runtime helper.
