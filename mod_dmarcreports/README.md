# DMARC Reports Dashboard Module

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/moddmarcreports.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/moddmarcreports.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-moddmarcreports.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-moddmarcreports.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Administrator control-panel module that surfaces the DMARC Reports summary and the most important next steps right on the Joomla backend dashboard.

## Description

The [DMARC Reports Component](../com_dmarcreports) collects and analyses DMARC aggregate reports inside your [Joomla](https://github.com/joomla/joomla-cms) installation. This module brings the headline result of that analysis to the administrator control panel (the `cpanel` dashboard), so the state of your mail authentication is visible without opening the component.

It is a thin presenter: every figure and every recommendation comes from the component's own `Dashboard` model and `ActionPresenter`, so the data and wording stay consistent with the component and no query logic is duplicated. The volume, pass-rate and action figures are the component's own values; the would-be-rejected share is a percentage this module derives from the same counts (the component shows it as an absolute message count). The module runs entirely on your own server and transmits no data; the only outbound traffic it causes is Joomla's own periodic extension-update check against the registered update server on GitHub, which carries version metadata only.

When the component is not installed, disabled, or the current user is not allowed to manage it, the module shows a short notice instead of failing — it never produces a fatal error on the dashboard.

## Features

- Renders on the administrator control-panel (`cpanel`) position
- Multi-domain summary: monitored domains, total messages, SPF/DKIM pass rate and would-be-rejected share
- Top Action Center items, each with a severity label and the owning domain, capped by a configurable limit
- A "more actions" count and an **Open dashboard** button that links straight into the component
- Reuses the component's `Dashboard` model and `ActionPresenter` — data and wording from the same source, no duplicated queries (the would-be-rejected share is shown as a derived percentage)
- ACL-aware: respects the `com_dmarcreports` `core.manage` permission
- Graceful degradation: a translated notice when the component is absent, disabled or not authorised
- Administrator-only; no site part is shipped

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or higher
- Administrator access
- The [DMARC Reports Component](../com_dmarcreports) (`com_dmarcreports`) installed and enabled for live data; without it the module degrades gracefully

## Joomla 6 Compatibility

The module contains no direct database access — it reads everything through the component's `Dashboard` model, which already selects the correct database API at runtime on Joomla 5 and Joomla 6. The module's manifest `targetplatform` covers 5.4.x and 6.x, and both are exercised in CI.

## Installation

1. Download `mod_dmarcreports_<version>.zip` from the latest release
2. **System → Install → Extensions**
3. Upload and install
4. Publish the module under **Content → Site Modules** … **Administrator Modules** (**System → Manage → Administrator Modules**), set its position to `cpanel` and status to published

## Updating

The manifest registers this repository's `updates/update.xml` as update server (`<updateservers>`). New versions appear under **System → Update → Extensions**. You can also install a newer ZIP over the existing installation.

## Uninstall

Uninstall via **System → Manage → Extensions**. Joomla removes the module files, its administrator module instances and its language files. The module stores no data of its own; the component and its collected reports are unaffected.

## Configuration

Edit the module instance (**System → Manage → Administrator Modules → DMARC Reports Dashboard Module**):

| Option | Default | Purpose |
|--------|---------|---------|
| Show summary cards | `Yes` | Show the multi-domain summary (domains, messages, pass rate, would-be-rejected). Turn off to show only the Action Center items |
| Action items to show | `5` | Maximum number of top Action Center items listed (0–20; `0` hides the list) |

The **Advanced** fieldset offers the standard Joomla module layout and module-class-suffix fields.

## Usage

Once published to the `cpanel` position, the module appears on the backend home dashboard (`administrator/index.php`). It shows:

1. The **summary cards** — monitored domains, total messages, SPF/DKIM pass rate and would-be-rejected share (if *Show summary cards* is on)
2. The **top Action Center items** — the highest-severity recommendations across all domains, each with its severity label and domain
3. A **more actions** count when there are more active items than the configured limit
4. An **Open dashboard** button linking to **Components → DMARC Reports** for the full dashboard, drill-downs and the complete Action Center

All recommendations, thresholds and progress (done/snoozed/ignored status) come from the component, so what the module shows always agrees with the component dashboard.

## Development

### Structure

```
mod_dmarcreports/
├── README.md
├── VERSION                       # Managed by release workflow
├── LICENSE.txt
├── mod_dmarcreports.xml          # Joomla manifest (type="module", administrator client)
├── script.php                    # Install/update/uninstall script
├── composer.json                 # Dev-only (PHPUnit)
├── phpunit.xml
├── services/
│   └── provider.php              # DI service provider (module dispatcher)
├── src/
│   ├── Dispatcher/
│   │   └── Dispatcher.php         # Wires the helper payload into the layout
│   └── Helper/
│       └── DmarcReportsHelper.php # Reads com_dmarcreports Dashboard model + ActionPresenter
├── tmpl/
│   └── default.php               # Layout (summary cards + action list, or a notice)
├── language/
│   ├── en-GB/
│   ├── de-DE/
│   └── fr-FR/
├── updates/
│   └── update.xml                # Joomla update server
└── tests/
    ├── scripts/                  # Integration test scripts (01, 02, 03, 10)
    ├── Unit/                     # PHPUnit unit tests
    ├── docker-compose.yml
    ├── docker-compose.joomla6.yml
    ├── run-tests.sh
    └── test.env
```

## Automated Testing

This module has automated tests that run via GitHub Actions (`moddmarcreports.yml`) on pushes and pull requests to `main` that change this directory, the shared harness files it uses or the workflow file. CI also runs a PHP syntax check, the language file lint, the requirements check (Joomla 5.4+, PHP 8.1+), the deprecated-API scan and a PHPUnit unit suite. Details: [testing.md](../.claude/skills/joomla-extensions/references/testing.md).

The integration suite runs against **two real stacks** — Joomla 5 (`test-j5`) and Joomla 6 (`test-j6`). The module's test environment installs **only the module** (the component is deliberately absent), so the graceful-degradation path is tested directly. The module does not depend on J2Commerce.

### Test Suites

Order as in `tests/test.env`:

1. **Installation** - Module registration, file deployment, administrator client id
2. **Structure** - Namespace, service provider, dispatcher, helper and layout are present and well-formed
3. **Graceful Degradation** - With the component absent, the helper reports `unavailable` and the layout renders a translated notice with no raw language key, no PHP error and no summary cards
4. **Installer Messages** - shared suite: removes and reinstalls the package through the Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a non-zero exit code
5. **Uninstall** - Module removal, instance cleanup, verification

A separate **PHPUnit (Unit)** job runs the pure helper tests from `tests/Unit/` (`summariseTotals`, `rejectRate`, `limitActions`) without Docker. The database-backed figures are produced by the component and covered by the component's own suites.

### Running Tests Locally

Prerequisites: the package as `tests/extension.zip`.
Full commands: [Local Prerequisites](../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites).

```bash
# from the repository root
(cd mod_dmarcreports && ./build.sh && cp *.zip tests/extension.zip)
```

Joomla 5:

```bash
cd mod_dmarcreports/tests
docker compose up -d
# Wait for container readiness (health.txt written by docker-entrypoint.sh)
timeout 300 bash -c 'until docker exec mod_dmarcreports_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v
```

Joomla 6:

```bash
cd mod_dmarcreports/tests
docker compose -f docker-compose.joomla6.yml up -d
timeout 300 bash -c 'until docker exec mod_dmarcreports_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
CONTAINER_NAME=mod_dmarcreports_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v
```

PHPUnit unit suite (needs `composer install` once):

```bash
cd mod_dmarcreports
composer install
composer test
```

Test results are saved in `tests/test-results/`.

## Troubleshooting

### The module shows "DMARC Reports is not available"
**Problem:** The module renders a notice instead of the summary  
**Solution:** The [DMARC Reports Component](../com_dmarcreports) is not installed or is disabled. Install and enable `com_dmarcreports`; the module then shows live data automatically.

### The module shows "You are not authorised"
**Problem:** The notice states the component may not be managed  
**Solution:** The signed-in administrator lacks the `com_dmarcreports` **core.manage** permission. Grant it under **System → Global Configuration → Permissions** (or on the component's own Options → Permissions).

### The summary cards are missing but actions are shown
**Problem:** Only the Action Center list appears  
**Solution:** *Show summary cards* is set to **No** on the module instance. Turn it back on to show the domain/message/pass-rate cards.

### No action items appear
**Problem:** The Action Center list is empty  
**Solution:** Either there are no outstanding recommendations (nothing to do), or *Action items to show* is set to `0`. Raise the limit, or open the component dashboard to confirm there are no active items.

## Multi-Language Support

This extension supports the following languages:
- **English (en-GB)** - Default
- **German (de-DE)**
- **French (fr-FR)**

Every visible string comes from a language key; no admin-visible text is assembled at runtime. The Action Center wording is rendered by the component, so it follows the component's own language files. A further language needs these files, plus one `<language>` line per file in the manifest (see [Languages](../README.md#languages)):

```
language/{language-tag}/mod_dmarcreports.ini
language/{language-tag}/mod_dmarcreports.sys.ini
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
