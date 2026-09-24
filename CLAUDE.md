# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Überblick

Monorepo mehrerer eigenständiger Joomla-/J2Commerce-Extensions von Advans IT Solutions GmbH. Jede Extension ist unabhängig: eigene Version, eigenes Build-/Test-/Release-Setup, eigener Tag-Präfix. Es gibt kein gemeinsames Release über alle Extensions hinweg — Änderungen, Tests und Releases erfolgen pro Extension.

## Konventionen & Git

`AGENTS.md` ist die maßgebliche Quelle für Git-, Branch- und Commit-Konventionen — dort lesen, nicht hier duplizieren. Kernpunkte, die das Arbeiten in diesem Repo prägen:

- Nie direkt auf `main`; jede Änderung über Feature-Branch + PR, der vom Maintainer gemergt wird (Squash).
- Dieses Repo ist **öffentlich**; auf `main` verlangen das Organization-Ruleset und der Branch-Schutz **verifizierte Signaturen**. Das erfüllt der von GitHub signierte Squash-Merge. Feature-Branch-Commits signierst du, wenn ein Key vorhanden ist (Commit-E-Mail passend zum Key); ohne Key dürfen sie unsigniert sein (Details: `AGENTS.md`).
- Conventional Commits steuern den automatischen Version-Bump (siehe Release).
- Regeln, Review-Takt, Merge und weitere Entscheidungen: `AGENTS.md` (maßgebliche Quelle, nicht hier duplizieren).
- Lizenz: alle Extensions GPL-3.0-or-later; Kopfvorlage und Regeln für Fremdcode in `AGENTS.md` (Abschnitt „Lizenz“).

## Tiefenwissen (Skills)

Bevor du an einer Extension arbeitest, den passenden Skill in `.claude/skills/` lesen — Inhalt dort, nicht hier:

- `joomla-extensions` — Repo-Struktur, Test- und Release-Workflow, `update.xml`-Anforderungen, Sprachdateien-Konventionen.
- `privacy-plugin` — Domänenwissen zu `plg_privacy_j2commerce` (GDPR/DSGVO, Retention, Consent, MyProfile-Tab, Events).

## Architektur / Das große Bild

```
Joomla/
├── plg_ajax_joomlaajaxforms/   # eigenständiges Joomla-Plugin (Gruppe: ajax)
├── j2commerce/                 # J2Commerce-bezogene Extensions
│   ├── plg_privacy_j2commerce/         # Privacy-Plugin (Gruppe: privacy) — größte Extension
│   ├── plg_osmap_j2commerce/           # OSMap-Sitemap-Plugin (Gruppe: osmap)
│   ├── plg_j2commerce_productcompare/  # Produktvergleich (Gruppe: j2commerce; auf Joomla 5 zusätzlich als `j2store` gespiegelt)
│   ├── com_j2commerce_importexport/    # Komponente: Bulk-Import/Export
│   └── com_j2store_cleanup/            # Komponente: Migrationsbereinigung J2Store→J2Commerce
├── shared/                     # gemeinsame Build- und Test-Skripte (Single Source of Truth)
└── .github/workflows/          # je Extension ein Test- und zwei Release-/Publish-Workflows
```

**`shared/` ist der Kern.** Kern sind zwei generische Skripte (unten); daneben liegen gemeinsame Hilfsdateien (`verify-package.sh`, Docker-Vorlage, Entrypoint, Installer). Die Extensions kopieren keine Logik:

- `shared/build/build.sh` — generischer Paketierer. Liest `build.env` der Extension (`EXTENSION_NAME`, `VERSION`, `EXTENSION_TYPE`), rsync't die Dateien unter Ausschluss von Tests/Docker/Composer-Artefakten, erzeugt das Installations-ZIP und ruft anschließend `verify-package.sh`. Jede Extension hat nur einen dünnen Wrapper `build.sh`, der hierher delegiert.
- `shared/tests/run-tests.sh` — generischer Test-Runner. Liest `test.env` (`CONTAINER_NAME`, `TEST_SCRIPTS`-Array im Format `"name:script.php"`), wartet bis der Joomla-Docker-Container bereit ist, kopiert die PHP-Testskripte hinein und führt sie aus. Jede Extension hat einen Wrapper `tests/run-tests.sh`, der via `exec` hierher delegiert.

**Pro Extension** (Details: `.claude/skills/joomla-extensions/references/repo-structure.md`): `build.env`, Manifest (`{plugin}.xml` bzw. Komponenten-Manifest), `script.php` (install/update/uninstall), `services/provider.php` (DI), `src/`, `language/{de-DE,en-GB,fr-FR}/`, `updates/update.xml` und `tests/` (Komponenten weichen ab: siehe jeweiliges README). `VERSION`, das Manifest und `update.xml` werden **vom Release-Workflow verwaltet — nie von Hand setzen**. Plugin-`update.xml` muss `<client>site</client>` enthalten (Plugins installieren mit `client_id=0`).

**Tests** laufen Docker-basiert gegen eine echte Joomla-(+J2Commerce-)Installation. Es gibt zwei Test-Ebenen:

- Integrations-/Funktionstests über die PHP-Skripte in `tests/scripts/` + `shared/tests/run-tests.sh` (das, was die CI ausführt).
- Optionale PHPUnit-Unit-/Integrationstests dort, wo `phpunit.xml` + `composer.json` vorliegen (aktuell `plg_privacy_j2commerce` mit den Suites `Unit` und `Integration`, ausserdem `com_j2commerce_importexport`, `com_j2store_cleanup` und `plg_j2commerce_productcompare`).

Manche Extensions testen gegen mehrere Plattformen über parallele Test-Ordner bzw. `docker-compose.joomla6.yml` (z. B. `plg_ajax_joomlaajaxforms` mit `tests/`, `tests-j2c4/`, `tests-j2c6/`).

## Häufige Befehle

Hinweis: Build und Tests sind Bash-/Docker-basiert. Unter Windows in WSL oder Git-Bash mit laufendem Docker ausführen. Pfade unten relativ zum Extension-Verzeichnis.

**Eine Extension paketieren** (ZIP via `shared/build/build.sh`):

```bash
cd j2commerce/plg_privacy_j2commerce
./build.sh
```

**Voraussetzungen für lokale Tests** (vollständig mit allen Befehlen: `.claude/skills/joomla-extensions/references/testing.md`, Abschnitt „Local Prerequisites"). Die Test-Images kopieren die Pakete beim Build hinein; ohne diese Dateien schlägt `docker compose up -d` fehl:

- Paket bauen und als `tests/extension.zip` ablegen (wie der CI-Job `Build Package`): im Extension-Verzeichnis `./build.sh`, `mkdir -p tests`, `cp *.zip tests/extension.zip`. AJAX Forms zusätzlich nach `tests-j2c4/` bzw. `tests-j2c6/` kopieren und die Images mit den `docker build`-Befehlen aus `joomla-ajax-forms.yml` vom Repo-Root aus bauen.
- Joomla-6-Stacks brauchen zusätzlich `tests/j2commerce6.zip` (AJAX Forms: `tests-j2c6/j2commerce6.zip`), gebaut aus dem im Workflow gepinnten J2Commerce-6-Commit (`J2C6_REF`).

**Integrations-Tests einer Extension** (Docker hochfahren, dann Suite laufen lassen):

```bash
cd j2commerce/plg_privacy_j2commerce/tests
docker compose up -d
./run-tests.sh all        # wartet selbst auf Joomla-Readiness, dann alle Suites aus TEST_SCRIPTS in test.env
docker compose down -v
```

`run-tests.sh` pollt bis zu 180 s auf die Joomla-Readiness (`health.txt`), bevor es die Suites startet — ein zusätzliches manuelles `sleep` ist nicht nötig.

**Einen einzelnen Integrations-Test** ausführen — `run-tests.sh` nimmt einen Suite-Namen (klein, wie der `name:` aus `test.env`):

```bash
./run-tests.sh installation     # nur die Installations-Suite
./run-tests.sh gdpr             # nur die GDPR-Suite (Privacy-Plugin)
```

**PHPUnit** (nur wo `composer.json`/`phpunit.xml` vorhanden, z. B. `plg_privacy_j2commerce`):

```bash
composer install
composer test                                   # alle Suites
composer test:unit                              # nur Unit
vendor/bin/phpunit --testsuite=Unit             # eine Suite
vendor/bin/phpunit --filter testMethodName      # ein einzelner Test
```

**Joomla-6-Variante** (wo vorhanden). `test.env` nennt den Joomla-5-Container; deshalb den J6-Container beim Aufruf mitgeben, Variablen genau wie im Workflow:

```bash
docker compose -f docker-compose.joomla6.yml up -d
J2COMMERCE_STACK=j6 CONTAINER_NAME=plg_privacy_j2commerce_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v
```

- OSMap: `CONTAINER_NAME=plg_osmap_j2commerce_j6_test J2COMMERCE_STACK=j6 ./run-tests.sh all`
- Import/Export: `CONTAINER_NAME=com_j2commerce_importexport_j6_test J2COMMERCE_STACK=j6 ./run-tests.sh all`
- Product Compare: `J2COMMERCE_STACK=j6 CONTAINER_NAME=plg_j2commerce_productcompare_j6_test ./run-tests.sh all`
- Cleanup (Workflow setzt nur den Container): `CONTAINER_NAME=com_j2store_cleanup_j6_test ./run-tests.sh all`
- AJAX Forms `tests/`: `CONTAINER_NAME=plg_ajax_joomlaajaxforms_j6_test ./run-tests.sh all`; `tests-j2c4/` und `tests-j2c6/` haben ein eigenes `test.env` mit passendem Container (`./run-tests.sh all`).

Alle Extensions verlangen Joomla 5.4 oder neuer (5.4.x, 6.x) und PHP 8.1 oder neuer; `shared/tests/requirements-check.php` prüft das im CI-Job „Language Files". Die Test-Images nutzen mitlaufende offizielle Tags (`joomla:5.4-php8.3-apache` = neuestes 5.4.x, `joomla:6-php8.4-apache` = neuestes 6.x), keine gepinnte Joomla-Patch-Version. Jeder Job gibt `Tested versions: Joomla X.Y.Z, PHP A.B.C` aus; ein roter Lauf kann daher von einem neuen Joomla-Release kommen, erst die ausgegebene Version prüfen. J2Commerce 6 wird aus einem gepinnten Commit gebaut. Der Job „Language Files" führt zusätzlich `shared/tests/deprecated-api-scan.php` aus; die produktionsnahen Lanes werten mit `shared-deprecations.php` alle Deprecations aus, die Code der Extension direkt auslöst. Die CI setzt `TEST_STRICT_SKIP=1` (ein SKIP gilt als Fehler). Pflicht-Check auf PRs ist allein „Collect Results" aus `collect-results.yml`; nach dem erneuten Ausführen eines fehlgeschlagenen Extension-Workflows auch „Collect Results" neu starten.

## Release-Workflow

Zwei-stufig und PR-basiert; pro Extension getrennt. Maßgebliche Beschreibung: `README.md` (Abschnitt „Releases") und `.claude/skills/joomla-extensions/references/release-workflow.md`. Kurz:

1. In GitHub Actions den `Release - …`-Workflow der jeweiligen Extension via **Run workflow** starten (Bump-Level wählen oder Auto-Detect).
2. Der Workflow bumpt `VERSION`/Manifest/`update.xml` auf einem `release/…`-Branch und öffnet einen `release: …`-PR — **er pusht nicht auf `main`**.
3. Ein Mensch reviewt und mergt (Squash). Beim Merge baut der `Publish - …`-Workflow das Paket, setzt den Tag (`{prefix}-v*`) und erstellt das GitHub-Release.

Nach dem Publish löscht der Workflow ältere Releases und Tags derselben Extension — gewollt, nicht „reparieren".

Wichtig für Agents: **nie den `release:`-PR selbst mergen**, **nie `VERSION`/Manifest/`update.xml` manuell ändern**, immer nur einen Release-Workflow gleichzeitig. Auto-Detect liest Conventional Commits seit dem letzten `{prefix}-v*`-Tag, die nur den Pfad dieser Extension berühren. Nur erkannte Typen öffnen einen Release-PR: `fix:` → Patch, `feat:` → Minor, `!`/`BREAKING CHANGE` → Major. Alles andere (`docs:`, `chore:`, …) wird ignoriert und löst keinen Release aus. Wenn ein Bump gewollt ist, muss die Änderung gemäß `AGENTS.md` als erkannter Typ (`fix(...)`/`feat(...)` mit passendem Scope) formuliert werden — nicht als `docs:`/`chore:`.
