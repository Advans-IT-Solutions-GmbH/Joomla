# Appointments CalDAV System Plugin

<a href="https://www.swissmadesoftware.org/en/about/swiss-made-software.html">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb-on-white.png">
    <img src="../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb.png" width="110" alt="swiss made software">
  </picture>
</a>

This extension carries the swiss made software label.

**Installation and updates:** see the [shared backup/approval policy](../../../../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_system_appointmentsdav` serves the component's self-hosted internet calendars over CalDAV and participates in its sign-in lockout. It is bundled in [com_appointments](../../../README.md); it is not a cloud-calendar connector.

## Features

- Handles matching site DAV requests before normal routing/page rendering.
- Provides `/.well-known/caldav` discovery and the local DAV endpoint.
- Delegates authentication, device/grant checks and encrypted calendar access to component services.
- Runs the website sign-in guard before password checking while internet calendars are enabled.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, Appointments with sodium and MySQL/MariaDB, HTTPS and a web server that forwards DAV methods/Authorization to Joomla. Calendar users need the component's configured groups, appropriate MFA, genuine key material and effective grants. See the [complete internet-calendar setup](../../../README.md#internet-calendars) before enabling access.

## Installation

The component installs this plugin **disabled**. Enable **System - Appointments CalDAV** only after configuring internet calendars, the companion user plugin, HTTPS/routing and recovery. Enabling this plugin alone does not create a usable device or content grant.

## Updating

Update through the component ZIP. Existing plugin state is preserved. There is no independent release or update channel.

## Uninstall

Component uninstall removes the plugin. Disabling it stops plugin DAV handling but does not erase calendar records or remote client copies. Follow the [component uninstall/backup policy](../../../README.md#uninstall); manually configured web-server rules are an operator responsibility.

## Configuration

The plugin's only own parameter is **DAV base URL** (`dav_base_url`, empty by default), an HTTPS URL for the local endpoint. The default endpoint path is `/dav/` relative to the Joomla root. Configure the component's internet-calendar options and validate the resulting device instructions; the parameter is not a remote provider credential.

## Usage, Permissions and Data Handling

Clients use per-device app passwords and the component's calendar rights. Joomla administrative privilege is not a substitute for a content key/grant. The plugin adds no separate calendar store, notification transport or Scheduler routine; requests use the component's encrypted objects, device records and audit services.

Discovery/routing does not by itself prove authentication or read/write access. Provision and test a real device with the required grant. Revocation and cleanup are distinct from deleting calendar data; copied client data cannot be remotely guaranteed erased.

## Development

Use the [component build](../../../README.md#development), which includes this child and its dependencies. Do not copy a partial plugin directory into a running installation.

## Automated Testing

The component's `teamcalendars:21-teamcalendars.php` suite includes DAV/device and access scenarios. Use the [component test commands](../../../README.md#running-tests-locally) and its real-client acceptance instructions. Automated HTTP/service coverage does not establish compatibility with every calendar client or an operator's reverse proxy.

## Troubleshooting

No discovery/endpoint: check the component feature, both plugins, HTTPS/base URL and host routing. Authentication failure: check device state, app password, effective grants and lockout rather than granting an administrator content bypass.

A sign-in-guard exception is logged without request details and does not deliberately break all website sign-ins; the user-plugin authorisation check is a second safeguard. DAV and device creation retain their own fail-closed checks. Inspect the component System Check instead of interpreting website login availability as proof that calendar protections work.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR**, with Swiss High German in de-DE. The parent language lint covers the plugin.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
