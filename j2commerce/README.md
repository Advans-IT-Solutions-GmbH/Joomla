# J2Commerce Extensions

Extensions for [J2Commerce](https://github.com/j2commerce/j2commerce) e-commerce platform.

## Available Extensions

### Plugins

#### OSMap J2Commerce (`plg_osmap_j2commerce`)

Adds publicly visible J2Store/J2Commerce products to OSMap using direct catalogue queries and optional hidden menu routes; hidden menu items are not required. [→ Details](plg_osmap_j2commerce/README.md)

#### Privacy - J2Commerce (`plg_privacy_j2commerce`)
GDPR/DSGVO compliance solution with automated data retention management and lifetime license handling.

[Documentation](plg_privacy_j2commerce/README.md)

Its bundled [system plugin](plg_privacy_j2commerce/plugins/system/j2commerceprivacy/README.md) handles J2Commerce 6 checkout consent and guest-confirmation mail links. Its bundled [task plugin](plg_privacy_j2commerce/plugins/task/j2commerceprivacy/README.md) handles scheduled retention with task-local settings. Both are managed through the parent ZIP, not independent downloads.

#### J2Commerce - Product Compare (`plg_j2commerce_productcompare`)
Product comparison functionality for J2Commerce stores.

[Documentation](plg_j2commerce_productcompare/README.md)

### Components

#### J2Commerce Import/Export (`com_j2commerce_importexport`)
Complete-product JSON/CSV import and JSON/CSV/XML export. Additional export-only types include basic products, variants, tier prices and categories; they are not partial-update import formats.

[Documentation](com_j2commerce_importexport/README.md)

#### J2Store Cleanup (`com_j2store_cleanup`)
Scans J2Store/J2Commerce-related extensions for APIs removed in the running Joomla version and uninstalls selected ones.

[Documentation](com_j2store_cleanup/README.md)

## Testing

All extensions have automated test suites that run via GitHub Actions.

View test results: https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions

---

**Advans IT Solutions GmbH**  
https://advans.ch

Copyright (C) 2026 Advans IT Solutions GmbH. Licensed under the GNU General Public License version 3 or later (`GPL-3.0-or-later`); see [LICENSE.txt](../LICENSE.txt).
