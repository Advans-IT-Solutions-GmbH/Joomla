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

## Installation, updates and help

All extensions install with Joomla's extension installer (**System → Install → Extensions**) and update through **System → Update → Extensions**. The installer decides by itself and keeps no file copies. Before updating, take a current, complete site backup that can be restored independently; the first update attempt of each extension displays this recommendation and stops before changing files, and you then start the update again. A modified or unknown file that an update would replace stops it before anything is copied, with the path and the SHA-256 hashes listed: resolve it manually or defer the update, then retry. There is no approval command or bypass. Details: [installer conflict and recovery policy](../README.md#installation-safety-and-repeatable-system-checks).

Each extension explains what it does, every setting with its default, permissions, scheduled tasks, mails, data handling and typical problems in its README and in its own help (de-DE, en-GB, fr-FR): the components have a **Help** button in their toolbar, the plugins have a **Help** tab on their edit screen. Only Privacy - J2Commerce needs a scheduled task (the cleanup task, created and checked by its installer); the other extensions need none.
## Testing

All extensions have automated test suites that run via GitHub Actions.

View test results: https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions

---

**Advans IT Solutions GmbH**  
https://advans.ch

Copyright (C) 2026 Advans IT Solutions GmbH. Licensed under the GNU General Public License version 3 or later (`GPL-3.0-or-later`); see [LICENSE.txt](../LICENSE.txt).
