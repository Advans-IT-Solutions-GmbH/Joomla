# J2Store Extension Cleanup Component

[![Build & Test](https://github.com/advansit/Joomla/actions/workflows/j2store-cleanup.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/j2store-cleanup.yml)
[![Release](https://github.com/advansit/Joomla/actions/workflows/release-cleanup.yml/badge.svg)](https://github.com/advansit/Joomla/actions/workflows/release-cleanup.yml)
[![Joomla 5](https://img.shields.io/badge/Joomla-5.x-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Safe migration tool for transitioning from J2Store to [J2Commerce](https://github.com/joomla-projects/j2commerce).

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

- [Joomla](https://github.com/joomla/joomla-cms) 5.x or 6.x
- PHP 8.1 or higher
- Administrator access
- ⚠️ Backup recommended before use

## Installation
1. Download `com_j2store_cleanup.zip`
2. **System → Extensions → Install**
3. Upload and install
4. Access via **Components → J2Store Cleanup**
## Usage

### Access
Navigate to: **Components → J2Store Cleanup**

URL: `administrator/index.php?option=com_j2store_cleanup`

### Interface Overview
The component displays:

1. **Detection Criteria Box** - Explains how incompatible extensions are identified
2. **Incompatible Extensions Table** - Red-highlighted extensions that need removal/upgrade
3. **Compatible Extensions Table** - Green-highlighted extensions that are safe

### Workflow

1. **Review Detection Criteria** - Understand why extensions are flagged
2. **Check Incompatible Extensions** - Review the "Reason" column for each
3. **Select Extensions** - Use checkboxes to select extensions for removal
4. **Remove** - Click "Remove Selected Extensions"
5. **Confirm** - Read the warning and confirm removal

### Table Columns

| Column | Description |
|--------|-------------|
| Checkbox | Select for removal |
| Name | Extension display name |
| Type | plugin, component, module, etc. |
| Element | Technical identifier (e.g., `app_gdpr`) |
| Version | Installed version (red badge if < 4.0.0) |
| Status | Enabled/Disabled badge |
| Reason | Why it's marked incompatible |

⚠️ **Always create a full backup (Akeeba Backup) before removing extensions!**

## What Gets Removed

When you remove an extension, the component uses **Joomla's Installer API** for proper uninstallation:

1. **Uninstall scripts** - Runs the extension's `uninstall()` method
2. **Extension files** - Deletes all files from the extension folder
3. **Database entry** - Removes the record from `#__extensions`
4. **Media files** - Removes files from `/media/` folder
5. **Language files** - Removes language strings

**Note:** Extension-specific database tables (e.g., `#__j2store_*`) are only removed if the extension's uninstall script handles them.

## What Stays

- J2Commerce extensions
- Product data
- Order history
- Customer information

## Automated Testing

This component has automated tests that run on every push via GitHub Actions.

### Test Suites

1. **Installation** - Component registration, file deployment
2. **Scanning** - Version detection, authorUrl/authorEmail checks, protected extensions
3. **Cleanup** - Extension removal, batch removal, isolation tests
4. **Component Functions** - Main file function validation
5. **Safety Checks** - Protected extensions list, edge cases
6. **Uninstall** - Component removal, verification

### Running Tests Locally

```bash
cd j2commerce/com_j2store_cleanup/tests
docker compose up -d
# Wait for container readiness (health.txt written by docker-entrypoint.sh)
timeout 300 bash -c 'until docker exec com_j2store_cleanup_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v
```

Test results are saved in `tests/test-results/`.

## Development

### Structure
```
com_j2store_cleanup/
├── README.md
├── VERSION                           # Current version (1.1.0)
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
    ├── scripts/                      # Test scripts (01-09)
    ├── docker-compose.yml
    ├── run-tests.sh
    └── test.env
```

## Troubleshooting

### No J2Store Extensions Found
**Problem:** Scan shows no extensions but you know they exist  
**Solution:** Check if extensions are already uninstalled. Verify in **System → Extensions → Manage**.

### Removal Fails with Database Error
**Problem:** Cannot remove extension due to database constraints  
**Solution:** Manually disable extension first in **System → Plugins** or **System → Modules**, then retry removal.

### J2Commerce Extensions Detected as J2Store
**Problem:** Component incorrectly identifies J2Commerce extensions  
**Solution:** This should not happen. Report as bug with extension details.

### Cannot Access Component After Installation
**Problem:** Menu item missing or permission denied  
**Solution:** Verify administrator permissions. Check **System → Global Configuration → Permissions**.

### Backup Recommendations Ignored
**Problem:** Removed extensions without backup  
**Solution:** If data loss occurs, restore from Joomla backup or database snapshot. Always backup before cleanup.

## Safety Features

### Pre-Removal Checks
- Verifies extension is J2Store (not J2Commerce)
- Checks for active dependencies
- Warns about data loss
- Requires explicit confirmation

### Protected Extensions
The component will **never** remove:
- J2Commerce core components
- J2Commerce plugins
- J2Commerce modules
- Active Joomla core extensions

### Rollback Options
- Database backup recommended before cleanup
- Akeeba Backup integration (if installed)
- Manual restoration from backup

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
The component finds extensions by searching the `#__extensions` table for:
- Element contains `j2store` or `j2commerce`
- Plugin folder equals `j2store`

### Incompatibility Detection
Extensions are marked as **incompatible** based on three criteria (checked in order):

| Criterion | Description | Reliability |
|-----------|-------------|-------------|
| **Version < 4.0.0** | Old J2Store plugins use versions like 1.x, 2.x, 3.x. J2Commerce 4.x plugins start at version 4.0.0 | High |
| **authorUrl contains "j2store.org"** | Legacy J2Store extensions link to the old j2store.org website | High |
| **authorEmail contains "@j2store.org"** | Extensions from the original J2Store team use @j2store.org emails | High |

### Why Not Just Check "Enabled" Status?
A disabled plugin is not necessarily incompatible - users may have intentionally disabled it. The version and author checks provide reliable detection regardless of enabled status.

### Protected Extensions
The following extensions are **never** marked as incompatible:
- `com_j2store` - Core J2Commerce component
- `com_j2store_cleanup` - This cleanup tool
- `com_j2commerce_importexport` - Advans Import/Export component
- `plg_privacy_j2commerce` - Advans Privacy plugin
- `plg_j2commerce_productcompare` - Advans Product Compare plugin
- Any extension with `authorUrl` containing `j2commerce.com`

### Detection Examples

**Incompatible (will be flagged):**
```json
{
  "name": "GDPR",
  "version": "1.0.16",
  "author": "Alagesan",
  "authorUrl": "http://www.j2store.org",
  "authorEmail": "supports@j2store.org"
}
```
Reason: Version 1.0.16 < 4.0.0

**Compatible (will NOT be flagged):**
```json
{
  "name": "GDPR",
  "version": "4.0.4",
  "author": "J2Commerce",
  "authorUrl": "https://www.j2commerce.com",
  "authorEmail": "support@j2commerce.com"
}
```

### False Positives
If legitimate extensions are detected:
1. Do not remove them
2. Report to support with extension details
3. Check if a J2Commerce 4.x version is available

## Multi-Language Support

This extension supports the following languages:
- **English (en-GB)** - Default
- **German (de-DE)**
- **French (fr-FR)** - French

Users can add additional language files by creating new language folders following Joomla's language structure:
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

Proprietary software. Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
