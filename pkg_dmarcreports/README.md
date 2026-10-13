# DMARC Reports Package

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/pkg-dmarcreports.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/pkg-dmarcreports.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-pkg-dmarcreports.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-pkg-dmarcreports.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Joomla package that bundles the whole DMARC Reports solution — the component, the dashboard module, the ingestion task plugin and the MTA-STS system plugin — into a single install, update and uninstall.

## Description

The DMARC Reports solution is made of four extensions that work together. Installed separately they are four entries in the extension manager with four update streams; as a package they are **one install and one update stream** for the administrator. Joomla installs each bundled extension in dependency order and removes them together on uninstall.

The package itself holds no code and no database tables: it is a manifest (`type="package"`) that carries the four sub-extension archives plus its own language and update files. Each bundled extension ships its own installer script that creates and migrates its own tables, so installing or updating the package never loses collected report data.

| Bundled extension | Type | Role |
|-------------------|------|------|
| [`com_dmarcreports`](../com_dmarcreports) | Component (administrator) | Ingests and analyses RFC 7489 DMARC aggregate reports and RFC 8460 TLS-RPT reports (IMAP and file upload); dashboard, drill-downs, Action Center, system check and MTA-STS record generation |
| [`mod_dmarcreports`](../mod_dmarcreports) | Administrator module | Surfaces the multi-domain summary and top Action Center items on the backend control panel; degrades gracefully without the component |
| [`plg_task_dmarcreports`](../plg_task_dmarcreports) | Task plugin (`task`) | Scheduler task that automatically fetches, parses and stores reports from every active IMAP source on a configurable interval, reusing the component's ingestion and locking |
| [`plg_system_dmarcmtasts`](../plg_system_dmarcmtasts) | System plugin (`system`) | Serves the generated MTA-STS policy (RFC 8461) at `/.well-known/mta-sts.txt` so the published policy stays in sync with the component options |

The whole solution runs entirely on your own server. The only outbound traffic it causes on its own behalf is Joomla's periodic extension-update check against the registered update server on GitHub (version metadata only) and the IMAP/HTTP(S) connections the component and its plugins make to fetch reports and validate policies that you configure.

## Features

- One install, one update stream and one uninstall for the entire DMARC solution
- Installs the component, the dashboard module, the ingestion task plugin and the MTA-STS system plugin in a single step
- Each bundled extension keeps its own installer script, tables and settings — package updates preserve collected report data and IMAP passwords
- Sub-extensions remain individually buildable and releasable; the package bundles the built archives
- Administrator-facing; the component also ships a site entry point for the MTA-STS/TLS-RPT well-known delivery, served by the system plugin
- Own tag prefix (`pkgdmarcreports-v*`) and update server, independent of the sub-extension versions

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or higher
- Administrator access
- For automatic, continuous ingestion: a scheduler trigger for Joomla's Scheduled Tasks (the lazy scheduler is enabled by default; a real system or web cron is recommended for unattended operation — see [`plg_task_dmarcreports`](../plg_task_dmarcreports))
- For MTA-STS policy hosting: one DNS/host step the extension cannot perform for you (publish `mta-sts.<domain>` with valid TLS — see [`plg_system_dmarcmtasts`](../plg_system_dmarcmtasts))

## Joomla 6 Compatibility

Every bundled extension selects the correct database and HTTP APIs at runtime and declares a `targetplatform` covering 5.4.x and 6.x. The package is exercised against both Joomla 5 and Joomla 6 in CI, and the bundled extensions keep the native socket IMAP client so they work where `ext-imap` is absent (PHP 8.4 / Joomla 6).

## Installation

1. Download `pkg_dmarcreports_<version>.zip` from the latest release
2. **System → Install → Extensions**
3. Upload and install — Joomla installs the component, the module and both plugins in one step
4. Enable the plugins under **System → Manage → Plugins** (`Task - DMARC Reports` and `System - DMARC Reports MTA-STS`) and publish the module on the `cpanel` position if you want the dashboard tile

## Updating

The manifest registers this repository's `updates/update.xml` as update server (`<updateservers>`). New versions appear under **System → Update → Extensions**. You can also install a newer ZIP over the existing installation. Because each bundled extension migrates its own tables, updating the package keeps all collected DMARC and TLS-RPT data and the stored IMAP credentials.

## Uninstall

Uninstall the package via **System → Manage → Extensions**. Joomla removes the component, the module and both plugins together. As with any Joomla component, the component's tables and collected reports are removed by its own uninstall script; uninstall the package only when you no longer need the collected data.

## Development

### Structure

```
pkg_dmarcreports/
├── README.md
├── VERSION                       # Managed by release workflow
├── LICENSE.txt
├── pkg_dmarcreports.xml          # Joomla manifest (type="package")
├── script.php                    # Install/update/uninstall script (platform guard)
├── build.env                     # Build configuration (EXTENSION_TYPE=package)
├── build.sh                      # Builds each sub-extension, stages archives, zips the package
├── index.html
├── language/
│   ├── en-GB/                    # pkg_dmarcreports.ini + .sys.ini
│   ├── de-DE/
│   └── fr-FR/
├── updates/
│   └── update.xml                # Joomla update server
└── tests/
    ├── scripts/                  # Integration test scripts (01, 02, 05, 10)
    ├── docker-compose.yml
    ├── docker-compose.joomla6.yml
    ├── run-tests.sh
    └── test.env
```

### Building

Unlike the other extensions, the package does not use the generic shared build script directly (that script excludes `*.zip` during rsync and so cannot bundle the sub-extension archives). Its own `build.sh` builds every sub-extension with that extension's `build.sh`, stages each resulting archive under `packages/` with the stable, version-less name the manifest declares, copies the package metadata and zips the whole tree, then reuses `shared/build/verify-package.sh` for the final check. The sub-extensions therefore stay individually buildable; the package only bundles their built artifacts.

```bash
# from the repository root
cd pkg_dmarcreports
./build.sh
```

## Automated Testing

This package has automated tests that run via GitHub Actions (`pkg-dmarcreports.yml`) on pushes and pull requests to `main` that change this directory, the shared harness files it uses or the workflow file. CI also runs a PHP syntax check, the language file lint, the requirements check (Joomla 5.4+, PHP 8.1+) and the deprecated-API scan. Details: [testing.md](../.claude/skills/joomla-extensions/references/testing.md).

The integration suite runs against **two real stacks** — Joomla 5 (`test-j5`) and Joomla 6 (`test-j6`). It installs the package, verifies that all four bundled extensions are registered, updates the package over itself and confirms no collected data is lost, then uninstalls.

### Test Suites

Order as in `tests/test.env`:

1. **Installation** - The package and all four bundled extensions register; component tables are created
2. **Structure** - The package manifest bundles the component, module, task plugin and system plugin; each archive is present and well-formed
3. **Update** - Re-installing the package over an existing install keeps the component tables, collected reports and the stored IMAP password (no data loss)
4. **Installer Messages** - shared suite: removes and reinstalls the package through the Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a non-zero exit code
5. **Uninstall** - The package and all bundled extensions are removed cleanly

### Running Tests Locally

Prerequisites: the package as `tests/extension.zip`.
Full commands: [Local Prerequisites](../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites).

```bash
# from the repository root
(cd pkg_dmarcreports && ./build.sh && cp *.zip tests/extension.zip)
```

Joomla 5:

```bash
cd pkg_dmarcreports/tests
docker compose up -d
timeout 300 bash -c 'until docker exec pkg_dmarcreports_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v
```

Joomla 6:

```bash
cd pkg_dmarcreports/tests
docker compose -f docker-compose.joomla6.yml up -d
timeout 300 bash -c 'until docker exec pkg_dmarcreports_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
CONTAINER_NAME=pkg_dmarcreports_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v
```

Test results are saved in `tests/test-results/`.

## Multi-Language Support

This extension supports the following languages:
- **English (en-GB)** - Default
- **German (de-DE)**
- **French (fr-FR)**

The package ships only its own installer-visible strings; each bundled extension carries its own language files. The package lists its system language files in the manifest (`<languages>`), so a further language needs one `<language>` line per file (see [Languages](../README.md#languages)):

```
language/{language-tag}/pkg_dmarcreports.sys.ini
language/{language-tag}/pkg_dmarcreports.ini
```

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Copyright (C) 2026 Advans IT Solutions GmbH

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. It is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE.txt](LICENSE.txt) for the full license text.

SPDX-License-Identifier: `GPL-3.0-or-later`
