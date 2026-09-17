# J2Store Extension Cleanup Component

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/j2store-cleanup.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/j2store-cleanup.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-cleanup.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-cleanup.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Safe migration tool for transitioning from J2Store to [J2Commerce](https://github.com/j2commerce/j2commerce).

## Description

Until now, there was no automated way to remove old J2Store extensions that are no longer compatible with J2Commerce. The J2Store Cleanup Component solves this problem by identifying and safely removing incompatible J2Store extensions. Scan your [Joomla](https://github.com/joomla/joomla-cms) installation, review detailed extension information, and remove outdated components with confidence. Protects your valuable data while cleaning up legacy extensions that could cause conflicts.

## Features

- Scan for incompatible extensions
- List all J2Store extensions
- Safe removal process
- Backup recommendations
- Detailed extension info
- One-click cleanup

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or higher
- Administrator access
- ⚠️ Backup recommended before use

## Joomla 6 Compatibility

On Joomla 6, `Factory::getContainer()->get('DatabaseDriver')` was removed. The component uses `Factory::getContainer()->get(DatabaseInterface::class)` and a `createDbQuery()` helper that calls `$db->getQuery(true)` on Joomla 5 (Joomla 5.4 has no `createQuery()` on `DatabaseInterface`) and `$db->createQuery()` on Joomla 6. No configuration required — the correct API is selected at runtime.

## Installation
1. Download `com_j2store_cleanup_<version>.zip` from the latest release
2. **System → Install → Extensions**
3. Upload and install
4. Access via **Components → J2Store Extension Cleanup**

## Updating

The manifest registers this repository's `updates/update.xml` as update server (`<updateservers>`), and the install script makes sure the update site is present after every install or update. New versions appear under **System → Update → Extensions**. You can also install a newer ZIP over the existing installation.

## Uninstall

Uninstall via **System → Manage → Extensions**. The component has no uninstall routine of its own and creates no database tables; Joomla removes the component files and its language files. Extensions that were removed with this tool are not restored, and J2Store/J2Commerce data is not touched.

## Usage

### Access
Navigate to: **Components → J2Store Extension Cleanup**

URL: `administrator/index.php?option=com_j2store_cleanup`

### Interface Overview
The component displays:

1. **Compatibility scan box** ("Compatibility scan for Joomla X") - Explains what is checked on the running Joomla version and the four result types
2. **Incompatible Extensions** - Extensions whose PHP files use APIs removed in the running Joomla version (selectable for removal)
3. **No Files Found** - Extensions registered in `#__extensions` whose folder is missing on disk (selectable for removal)
4. **Compatible Extensions** - Extensions without findings, plus the core component (not selectable)

The **Remove Selected Extensions** button is only shown if at least one extension is *Incompatible* or *No files*.

### Workflow

1. **Review the scan box** - Check which Joomla version the scan ran against
2. **Check Incompatible Extensions** - Expand the "Issues found" column to see the detected APIs
3. **Select Extensions** - Use checkboxes to select extensions for removal
4. **Remove** - Click "Remove Selected Extensions"
5. **Confirm** - Read the confirmation dialog and confirm removal

### Table Columns

| Table | Columns |
|-------|---------|
| Incompatible Extensions | Checkbox, Name, Type, Element, Enabled, Issues found |
| No Files Found | Checkbox, Name, Type, Element, Details |
| Compatible Extensions | Name, Type, Element, Enabled, Details |

⚠️ **Always create a full backup (Akeeba Backup) before removing extensions!**

## What Gets Removed

When you remove an extension, the component uses **Joomla's Installer API** for proper uninstallation:

1. **Uninstall scripts** - Runs the extension's `uninstall()` method
2. **Extension files** - Deletes all files from the extension folder
3. **Database entry** - Removes the record from `#__extensions`
4. **Media files** - Removes files from `/media/` folder
5. **Language files** - Removes language strings

**Note:** Extension-specific database tables (e.g., `#__j2store_*`) are only removed if the extension's uninstall script handles them.

If Joomla's uninstaller fails (e.g. missing manifest), only the `#__extensions` record is deleted and a warning ("DB only — files may remain") is shown; leftover files must be removed manually.

## What Stays

- The J2Store/J2Commerce core component (`com_j2store` / `com_j2commerce`)
- Product, order and customer data (tables are only dropped if an uninstalled extension's own uninstall script does so)
- Extensions you did not select

## Automated Testing

This component has automated tests that run via GitHub Actions (`j2store-cleanup.yml`) on pushes and pull requests to `main` that change this directory, `shared/**` or the workflow file. CI also runs a PHP syntax check and the language file lint. Details: [testing.md](../../.claude/skills/joomla-extensions/references/testing.md).

The full suite runs against **two real stacks**, so the cleanup/classification
logic is exercised with the actual core component present in each:

- **Joomla 5 + J2Store/J2Commerce 4** (`test-j5`) — J2Store 4 is installed into
  the image; `com_j2store` is the protected core component.
- **Joomla 6 + J2Commerce 6** (`test-j6`) — J2Commerce 6 is built from official
  source (`github.com/j2commerce/j2commerce`) and installed into the Joomla 6
  container; `com_j2commerce` is the protected core component
  (`EXPECTED_CORE_COMPONENT=com_j2commerce`).

Both stacks run the **same full suite** from `tests/test.env` (installation, scanning,
cleanup, component functions, safety checks, installer messages, uninstall). The
`official-j5-j2c4` job runs the safety checks against J2Store 4 as expected core
component; `official-j6-j2c6` asserts that the Joomla 6 matrix passed.

### Test Suites

Order as in `tests/test.env`:

1. **Installation** - Component registration, file deployment
2. **Scanning** - Version detection, authorUrl/authorEmail checks, protected extensions
3. **Cleanup** - Extension removal, batch removal, isolation tests, and a real
   register-extension → cleanup → verify round trip that confirms the installed
   core component (`com_j2store` on J5, `com_j2commerce` on J6) is protected
4. **Component Functions** - Main file function validation (`createDbQuery`, `cleanupExtensions`)
5. **Safety Checks** - Protected extensions list, edge cases, DB verification that
   the expected core component is installed
6. **Installer Messages** - shared suite: removes and reinstalls the package through the
   Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated language
   keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a non-zero exit code
7. **Uninstall** - Component removal, verification

### Running Tests Locally

Prerequisites: the package as `tests/extension.zip`; for Joomla 6 also `tests/j2commerce6.zip`,
built from the J2Commerce 6 commit pinned in the workflow (`7edb6e11ae9148bf996b06c47a0d8266865af7b2`).
Full commands: [Local Prerequisites](../../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites).

```bash
# from the repository root
(cd j2commerce/com_j2store_cleanup && ./build.sh && cp *.zip tests/extension.zip)
```

Joomla 5 + J2Store/J2Commerce 4:

```bash
cd j2commerce/com_j2store_cleanup/tests
docker compose up -d
# Wait for container readiness (health.txt written by docker-entrypoint.sh)
timeout 300 bash -c 'until docker exec com_j2store_cleanup_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v
```

Joomla 6 + J2Commerce 6 (requires a `j2commerce6.zip` built from source placed
in `tests/`, mirroring the CI `Build J2Commerce 6 from source` step):

```bash
cd j2commerce/com_j2store_cleanup/tests
docker compose -f docker-compose.joomla6.yml up -d
timeout 300 bash -c 'until docker exec com_j2store_cleanup_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
CONTAINER_NAME=com_j2store_cleanup_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v
```

Test results are saved in `tests/test-results/`.

## Development

### Structure
```
com_j2store_cleanup/
├── README.md
├── VERSION                           # Managed by release workflow
├── LICENSE.txt
├── com_j2store_cleanup.xml           # Joomla manifest
├── script.php                        # Install/update script
├── administrator/
│   ├── components/
│   │   └── com_j2store_cleanup/
│   │       └── j2store_cleanup.php   # Main component file
│   └── language/
│       ├── en-GB/
│       ├── de-DE/
│       └── fr-FR/
├── updates/
│   └── update.xml                    # Joomla update server
└── tests/
    ├── scripts/                      # Test scripts (01–05, 07)
    ├── docker-compose.yml
    ├── run-tests.sh
    └── test.env
```

## Troubleshooting

### No J2Store Extensions Found
**Problem:** Scan shows no extensions but you know they exist  
**Solution:** Check if extensions are already uninstalled. Verify in **System → Manage → Extensions**.

### Removal Fails with Database Error
**Problem:** Cannot remove extension due to database constraints  
**Solution:** Manually disable extension first in **System → Manage → Plugins** or the module manager, then retry removal.

### J2Commerce Extensions Listed or Flagged
**Problem:** J2Commerce plugins or modules appear in the list or are marked incompatible  
**Solution:** This is expected. The list contains every extension matching the search patterns (see *Extension Detection*), and the classification is based only on the code scan. Review the reported issues before removing anything.

### Cannot Access Component After Installation
**Problem:** Menu item missing or permission denied  
**Solution:** Verify administrator permissions. Check **System → Global Configuration → Permissions**.

### Backup Recommendations Ignored
**Problem:** Removed extensions without backup  
**Solution:** If data loss occurs, restore from Joomla backup or database snapshot. Always backup before cleanup.

## Safety Features

### Pre-Removal Checks
- Requires the `core.manage` permission on `com_j2store_cleanup` and a valid CSRF token
- Requires explicit confirmation in a browser dialog
- Blocks the core components `com_j2store` and `com_j2commerce` (also server-side)
- No dependency check and no automatic backup; create a backup yourself

### Protected Extensions
Only the core components `com_j2store` and `com_j2commerce` are protected (also server-side). Any other listed extension, including J2Commerce plugins/modules and Advans extensions, can be selected for removal if it is classified as *Incompatible* or *No files*. Review each entry before removing.

### Rollback Options
- No built-in rollback; removed extensions cannot be restored by this component
- Restore from your own backup (files + database)
## Migration Workflow

### Step 1: Preparation
1. **Backup entire site** (files + database)
2. Install J2Commerce
3. Migrate data from J2Store to J2Commerce
4. Test J2Commerce functionality

### Step 2: Verification
1. Verify all products migrated
2. Check order history
3. Test checkout process
4. Confirm customer accounts

### Step 3: Cleanup
1. Install J2Store Cleanup Component
2. Run scan
3. Review detected extensions
4. Remove J2Store extensions
5. Verify site functionality

### Step 4: Post-Cleanup
1. Clear Joomla cache
2. Test all store functions
3. Monitor for errors
4. Remove cleanup component (optional)

## Extension Detection

### Finding J2Store Extensions
The component lists extensions from the `#__extensions` table where:
- Element contains `j2store` or `j2commerce`
- Element starts with `j2`, `mod_j2` or `com_j2`
- Plugin folder equals `j2store`

### Compatibility Scan
Each listed extension is classified in this order:

| Result | Condition |
|--------|-----------|
| **Core** | Element is `com_j2store` or `com_j2commerce` |
| **No files** | No extension folder on disk (registered but missing, or an extension type without a single folder, e.g. `file` or `package`) |
| **Compatible** | No removed APIs found in the PHP files |
| **Incompatible** | At least one removed API found |

All PHP files in the extension folder are scanned recursively (with `/* */` and `//` comments stripped) for APIs removed in the running Joomla version:

| Running Joomla | Flagged APIs |
|----------------|--------------|
| 5 | J3 legacy classes: `JPlugin`, `JModel`/`JModelLegacy`, `JTable`, `JView`/`JViewLegacy`, `JController`/`JControllerLegacy`, `JForm` |
| 6 | All of the above, plus `JFactory`, `JText`, `JHtml`, `JRoute`, `JUri`, `JSession`, `Factory::getUser()`, `Factory::getDbo()`, `Factory::getSession()`, `Factory::getDocument()` and `$this->app` |

The result therefore depends on the Joomla version: an extension that still uses `JFactory` or `JText` is shown as *Compatible* on Joomla 5, where these classes still work, but as *Incompatible* on Joomla 6. On Joomla versions below 6 the scan box shows a note about this. Version numbers, author data and the enabled status are not evaluated, and there are currently no J2Store-specific patterns.

### False Positives
The scan is a text pattern match, so a class name inside a string, for example, is also flagged. If a working extension is flagged:
1. Do not remove it
2. Check the listed issues against the extension's code
3. Check whether an updated version of the extension is available
## Multi-Language Support

This extension supports the following languages:
- **English (en-GB)** - Default
- **German (de-DE)**
- **French (fr-FR)**

Most UI texts of the component page are currently hard-coded in English; the menu entry and the removal result messages use language keys. Users can add additional language files by creating new language folders following Joomla's language structure:
```
administrator/language/{language-tag}/com_j2store_cleanup.ini
administrator/language/{language-tag}/com_j2store_cleanup.sys.ini
```

## Known Limitations

- Cannot restore removed extensions automatically
- Requires manual backup before use
- Does not migrate data (use separate migration tool)
- Cannot detect custom J2Store modifications

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
