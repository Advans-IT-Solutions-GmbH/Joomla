---
name: joomla-extensions
description: Development workflow for the advansit/Joomla repository. Use when working on Joomla/J2Commerce extensions, running tests, creating releases, or managing CI/CD workflows.
triggers:
  - release
  - workflow
  - plugin
  - extension
  - version bump
  - test
  - build
  - ZIP
  - branch
  - PR
  - conventional commits
  - j2commerce
  - joomla extension
references:
  - references/repo-structure.md
  - references/release-workflow.md
  - references/conventions.md
  - references/testing.md
---

# Joomla Extensions — Development Workflow

## Repository Structure

```
advansit/Joomla
├── j2commerce/
│   ├── plg_privacy_j2commerce/     # Privacy plugin (main)
│   ├── plg_import_export/          # Import/Export plugin
│   └── plg_product_compare/        # Product Compare plugin
├── plg_ajax_joomlaajaxforms/       # Joomla AJAX Forms plugin
├── shared/
│   └── tests/                      # Shared test infrastructure
├── tests/                          # Top-level test runner
└── .github/workflows/              # CI/CD workflows per plugin
```

## Key Rules

1. **Never set version manually** — the release workflow manages VERSION, j2commerce.xml, and update.xml
2. **Conventional Commits** — prefix all commits: `fix:`, `feat:`, `docs:`, `chore:`, `test:`, `refactor:`
3. **Auto-delete branches** is enabled — branches are deleted automatically after merge
4. **One PR per plugin** — do not mix changes across plugins in a single PR

## Lokale Dev-Umgebung (Docker-Tests pro Extension)

Es gibt keine gemeinsame Dev-Umgebung — jede Extension bringt ihr eigenes Docker-Setup mit,
das eine echte Joomla-(+J2Commerce-)Installation hochfährt. Unter Windows in WSL oder Git Bash
mit laufendem Docker ausführen. Pfade relativ zum Extension-Verzeichnis.

**Integrationstests einer Extension** (das, was die CI ausführt):
```bash
cd j2commerce/plg_privacy_j2commerce/tests
docker compose up -d
./run-tests.sh all            # pollt bis 180 s auf Joomla-Readiness (health.txt), dann alle Suites
docker compose down -v
```
`run-tests.sh` wartet selbst auf die Readiness — kein zusätzliches `sleep` nötig. Eine einzelne
Suite über den `name:` aus `test.env`: `./run-tests.sh installation` bzw. `./run-tests.sh gdpr`.

**PHPUnit** (nur wo `composer.json`/`phpunit.xml` vorliegen, z. B. `plg_privacy_j2commerce`):
```bash
composer install
composer test                          # alle Suites
vendor/bin/phpunit --testsuite=Unit    # eine Suite
vendor/bin/phpunit --filter testName   # ein einzelner Test
```

**Joomla-6-Variante** (wo vorhanden): `docker compose -f docker-compose.joomla6.yml up -d`
bzw. der eigene Ordner `tests-j2c6/` mit eigenem `run-tests.sh`.

**Eine Extension paketieren** (ZIP via `shared/build/build.sh`): `cd <extension> && ./build.sh`.

## Workflows

See `references/release-workflow.md` for the full release process.
See `references/testing.md` for running tests locally.
