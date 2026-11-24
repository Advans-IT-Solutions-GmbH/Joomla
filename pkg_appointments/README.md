# Appointments package (`pkg_appointments`)

Self-hosted appointment scheduling for Joomla 5.4 and 6, delivered as one Joomla package. The
package is the release asset; its parts are archives inside it and are not released on their own.

| Part | Source | First installation |
|---|---|---|
| Component `com_appointments` | [`com_appointments/`](../com_appointments/README.md) | Installed first: when it refuses (sodium missing, unsupported database, inconsistent schedules) nothing else has changed |
| Module `mod_appointments` (administrator dashboard) | [`com_appointments/modules/mod_appointments/`](../com_appointments/modules/mod_appointments/README.md) | Published once at `cpanel` |
| Plugins `task/appointments`, `privacy/appointments`, `content/appointmentsprivacy`, `system/appointmentsattribution` | [`com_appointments/plugins/`](../com_appointments/README.md#bundled-plugins) | Enabled |
| Plugins `system/appointmentsdav`, `user/appointments` (internet calendars) | [`com_appointments/plugins/`](../com_appointments/README.md#bundled-plugins) | Disabled until deliberate setup |

The release ZIP also carries `appointments-recovery.zip`, the companion component **Appointments
Recovery**, at its root. It is installed on its own when an update stops on files that need a
review (see the component help, «Installation, updates and uninstalling»), so it is not a part of the package.

## Installation and updates

Install `pkg_appointments_<version>.zip` under **System → Install → Extensions**. Updates come
through **System → Update → Extensions** (update site `pkg_appointments/updates/update.xml`) or by
installing a newer package ZIP over the installed one. Uninstalling the package removes the
component, the module and the plugins; the component's own data choice («Delete all Appointments
data» in its Options) decides whether its tables and permission rules are kept. The module and the
plugins cannot be uninstalled on their own (`blockChildUninstall`).

Releases keep the tag prefix `appointments-v` of the standalone component, so the package continues
its version line. The package, the component, the module and the plugins always carry the same
version: the release workflow sets it in all of them and the package build refuses a package and a
component of different versions.

## Migration from the standalone component

Releases up to 1.6.0 shipped `com_appointments_<version>.zip`, whose installer installed the module
and the plugins itself. The package takes such an installation over by itself, with no manual step:

- **Discovery:** the update channel of the standalone component
  (`com_appointments/updates/update.xml`) announces the package under the component's element, so
  Joomla Update offers and installs it. Installing the package ZIP by hand does the same.
- **Adoption in place:** Joomla updates every installed part in place, so its row in
  `#__extensions`, its ID, options, data and enabled state stay and nothing is created twice. The
  package then assigns every part to itself (`package_id`), also a part that an interrupted earlier
  attempt installed.
- **Update channel:** the update sites of the parts (the standalone component's own included) are
  removed when no other extension uses them; only the package's update site remains.
- **First enablement only:** a part is enabled or published only when it was not installed before
  (or when an interrupted attempt installed it without finishing); a part the operator disabled
  stays disabled.
- **Safety:** the full-site backup recommendation is shown once for the package, also on this first
  takeover; the file review of Appointments (Appointments Recovery) and the shared file guard cover
  the files of every part; the shared installer lock serialises the installation. A part that is
  installed in a newer version than the package brings stops the installation before anything is
  changed.

The component alone refuses to be installed or updated: only the package installs it.

## Build

```bash
cd pkg_appointments
bash ./build.sh
```

The build runs `com_appointments/build.sh` (which stages the shared installer support of the
component and of every plugin, builds Appointments Recovery and writes the catalog of every earlier
release), splits the result into the child archives the manifest declares, stages the module and the
package, writes the package catalog (which includes the component's catalog of the standalone
releases, so files a site installed from them are recognised) and creates
`pkg_appointments_<version>.zip`. Requirements and tests: see the component README and the
`appointments` CI workflow, whose test sites install this package.
