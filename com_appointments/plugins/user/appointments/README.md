# Appointments Internet-Calendar User Plugin

<a href="https://www.swissmadesoftware.org/en/about/swiss-made-software.html">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb-on-white.png">
    <img src="../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb.png" width="110" alt="swiss made software">
  </picture>
</a>

This extension carries the swiss made software label.

**Installation and updates:** see the [shared backup/approval policy](../../../../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_user_appointments` connects native Joomla account events to Appointments internet-calendar key sessions, lockout and device/account cleanup. It is bundled in [com_appointments](../../../README.md).

## Features

- Handles successful/failed login, authorisation and logout.
- Prepares key-session access after genuine password authentication; silent/passkey/remembered authentication supplies no usable password key.
- Applies the calendar-user lockout and records calendar-user login audit events.
- Delegates password changes, account blocking and deletion to the calendar services.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, the component with sodium and MySQL/MariaDB, and configured internet calendars. Use the [CalDAV companion](../../system/appointmentsdav/README.md) and the component's group/MFA/key setup. A Joomla account or administrative permission alone does not grant calendar contents.

## Installation

The component installs this plugin **disabled**. Enable **User - Appointments internet calendars** deliberately with the CalDAV system plugin after completing [internet-calendar setup](../../../README.md#internet-calendars).

## Updating

Update the owning component; its installer preserves the existing plugin state. The child has no independent release/update feed.

## Uninstall

The component removes the child during uninstall. Disabling it stops its event handling, not the underlying storage or remote clients. Do not treat disabling account/key cleanup as data removal; follow the [component's backup and uninstall procedure](../../../README.md#uninstall).

## Configuration

No plugin-specific parameters are declared. Component options determine whether internet calendars are enabled and which user groups participate. Key release and calendar actions enforce their own password/MFA/grant checks.

## Usage, Permissions and Data Handling

All handlers are inactive while internet calendars are switched off. Relevant Joomla account events drive the work; the plugin is not a public account endpoint and has no separate Scheduler routine.

Logout clears key-session access. Password changes, blocking and account deletion invoke component key/device cleanup. Login audit and failure counters are component data, not a second user database. The component's maintenance tasks handle their retention. Credential/request details are not included in the plugin's exception log messages.

## Development

Build with the [owning component](../../../README.md#development). The component ZIP is the distribution unit for this plugin.

## Automated Testing

The component's `teamcalendars:21-teamcalendars.php` suite covers key sessions, grants, device lifecycle and lockout. Use [component test commands](../../../README.md#running-tests-locally); there is no separate child stack or manual-acceptance claim here.

## Troubleshooting

No usable key session: check feature/plugin state, group eligibility, genuine password sign-in and completed approved MFA; remembered/passkey-only login does not supply the password needed to unlock the key session. A pending grant without key wraps is not effective content access.

Most handler failures are guarded/logged so account save/logout/login is not broken by calendar maintenance. The intentional exception is authorisation refusal while an applicable calendar-user lockout is active, including with a correct password. Joomla may add its own login-denied message. Inspect System Check and the calendar log; do not infer working key cleanup from an otherwise successful account save.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR**, with Swiss High German in de-DE. Parent language lint includes the child.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
