# J2Commerce Privacy package (`pkg_privacy_j2commerce`)

**Installation and updates:** see the [shared installer conflict and recovery policy](../../README.md#installation-safety-and-repeatable-system-checks).

The Joomla package that installs, updates and removes the three plugins of the J2Commerce privacy solution together:

| Plugin | Purpose | Enabled by the package |
|--------|---------|------------------------|
| [Privacy - J2Commerce](../plg_privacy_j2commerce/README.md) (`plg_privacy_j2commerce`) | Export, removal and retention of shop data, privacy tab in the customer profile, template overrides | No: you enable it after configuring it |
| [System - J2Commerce Privacy Consent](../plg_privacy_j2commerce/plugins/system/j2commerceprivacy/README.md) (`plg_system_j2commerceprivacy`) | Checkout consent (J2Commerce 6), links of the administrator request mail | Yes, on its first installation |
| [Task - J2Commerce Privacy Cleanup](../plg_privacy_j2commerce/plugins/task/j2commerceprivacy/README.md) (`plg_task_j2commerceprivacy`) | Scheduled anonymisation | Yes, on its first installation |

Requirements: Joomla 5.4 or later (5.4.x, 6.x), PHP 8.1 or later, J2Commerce 4 or 6 (see the [plugin README](../plg_privacy_j2commerce/README.md#requirements)).

## Release asset

`pkg_privacy_j2commerce_<version>.zip`, published under the tag `privacy-v<version>` (the tag prefix of the standalone plugin is unchanged, so the release line continues). The package, the privacy plugin and both bundled plugins always carry the same version. The plugins have no update channel of their own: the package's `updates/update.xml` is the update channel, and each plugin refuses an installation that does not come from the package.

## Installation

Install the ZIP under **System → Install → Extensions**, then enable **Privacy - J2Commerce** and work through the guide the installation shows. The package installs the privacy plugin first, so that a refusal (requirements, a checkout override of an earlier release to review) stops it before anything changed, then the consent and task plugins. After all three are in place the shared installer creates the required cleanup task **J2Commerce - Automatic data cleanup** if it is missing and shows the system check.

## Migration from the standalone plugin

Releases up to 1.9.0 shipped the privacy plugin as a standalone ZIP (`plg_privacy_j2commerce_<version>.zip`) that installed the two other plugins itself. Such a site moves to the package without a manual step:

- **Joomla Update:** the update channel of the standalone plugin (`../plg_privacy_j2commerce/updates/update.xml`) announces the package as the next version of the plugin, with the same download and SHA-256 as the package channel. **System → Update → Extensions** installs the package over the plugin.
- **Manual installation** of the package ZIP over the standalone plugin has the same result.

What the package does then (`script.php`):

- **Adopts the installed plugins:** Joomla updates each plugin in place, so its `#__extensions` row, settings, data and enabled state stay and no duplicate arises. The package assigns the three plugins to itself (`package_id`).
- **Completes a partial installation:** a bundled plugin that was missing is installed and enabled. A plugin that is installed but disabled stays disabled.
- **Moves the update channel:** every update site of the three plugins is removed (also those that still point to the repository's former path `advansit/Joomla`), and the package's own update site is registered. If that fails, the installation is still complete and a warning asks to update the package again.
- **Keeps the installer guarantees:** the full-site backup recommendation is shown once for the package (the migration counts as an update), the shared file guard covers the files of all three plugins, and one installer lock covers the whole run.
- **Refuses a downgrade:** when a bundled plugin is installed in a newer version than the package brings, the installation stops before anything is changed.
- **Can be repeated:** an interrupted attempt is completed by the next one. Plugins it had to enable are remembered (`administrator/advans-installer-state/pkg_privacy_j2commerce/enable-pending.php`, valid for one day), so a plugin installed by the interrupted attempt is still enabled.

## Uninstall

Uninstall **J2Commerce Privacy package** under **System → Manage → Extensions**. It removes all three plugins, the scheduled cleanup tasks and the table `#__privacy_j2commerce_attempts`. A single plugin cannot be removed on its own. What stays (shop data, consent records, template overrides): [plugin README, Uninstall](../plg_privacy_j2commerce/README.md#uninstall).

## Development

```bash
cd j2commerce/pkg_privacy_j2commerce
./build.sh
```

The build runs the privacy plugin's own build (`../plg_privacy_j2commerce/build.sh`, which stages the shared installer support and the catalog of every earlier release), splits it into `packages/plg_privacy_j2commerce.zip`, `packages/plg_system_j2commerceprivacy.zip` and `packages/plg_task_j2commerceprivacy.zip` (without `<updateservers>`), adds the package metadata and verifies the result. It fails when `build.env`, `VERSION`, the package manifest, the plugin (`build.env`, `VERSION`, manifest) and both bundled plugins do not carry the same version. Tests: the [plugin's test suites](../plg_privacy_j2commerce/README.md#automated-testing) install this package.

## License

GNU General Public License version 3 or later (GPL-3.0-or-later), see [LICENSE.txt](LICENSE.txt).
