# Appointments Scheduler Plugin

<a href="https://www.swissmadesoftware.org/en/about/swiss-made-software.html">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb-on-white.png">
    <img src="../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb.png" width="110" alt="swiss made software">
  </picture>
</a>

This extension carries the swiss made software label.

**Installation and updates:** see the [shared backup/approval policy](../../../../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_task_appointments` advertises Appointments booking and internet-calendar maintenance routines to Joomla's Scheduler. It is bundled in [com_appointments](../../../README.md), not a daemon or a separate release.

## Features

| Routine ID | Work |
|---|---|
| `plg_task_appointments.reminders` | Sends due reminders for confirmed future bookings |
| `plg_task_appointments.retention` | Erases eligible ended bookings and prunes eligible audit/rate-limit rows |
| `plg_task_appointments.teamcleanup` | Advances blocked-device cleanup and purges old sign-in failure counters |
| `plg_task_appointments.teamlog` | Prunes internet-calendar audit history |
| `plg_task_appointments.teaminvitations` | Closes expired internet-calendar invitations |
| `plg_task_appointments.teamretention` | Runs calendar retention and booking-calendar maintenance/encryption upkeep |

These are the six routines in the component source; advertising one does not create or enable a scheduled task for it.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, the component with sodium and MySQL/MariaDB, and Joomla Scheduler with a working execution trigger. Reminder delivery requires configured Joomla mail and a usable public site URL for signed links.

## Installation

The component installs/enables **Task - Appointments**. A fresh component installation creates reminder and booking-retention tasks enabled, with five-minute and daily schedules respectively. An update creates a missing task disabled. Any existing task of that routine, including disabled/trashed tasks, prevents recreation; schedules and parameters are not overwritten.

## Updating

Update through the component ZIP. Existing plugin/task states are preserved; the child has no independent feed. Review missing-task notices after updates rather than assuming retention started.

## Uninstall

Component uninstall removes this plugin and its Scheduler tasks. Follow the [component's data/backup instructions](../../../README.md#uninstall); removing scheduling alone does not erase bookings or restore anonymised data.

## Configuration

Reminder and booking-retention tasks have `batch_size=100`, validated from **1 to 1000**. Configure offsets, booking/audit retention, erasure mode and public URL in component options. Per-event booking retention can override the default. Internet-calendar routines use their service-specific controls, not this batch form.

Use traffic-triggered lazy scheduling only when its timing is sufficient. For regular unattended execution configure system/web cron; the Joomla CLI entry is `php cli/joomla.php scheduler:run`. Create the additional calendar tasks deliberately. Calendar routines normally do nothing while internet calendars are disabled, but `teamretention` still performs booking-calendar upkeep.

## Usage, Permissions and Data Handling

Restrict Scheduler/component configuration to trusted administrators. Routines execute component services, not an interactive event editor's request. Reminders use the existing mail/ICS path; retention changes actual encrypted booking/calendar data and audits. The plugin has no separate report store, external scheduler or dry-run option.

Booking retention, calendar-object retention and audit history have distinct cutoffs; see [Scheduler, reminders and retention](../../../README.md#scheduler-reminders-and-retention) and [Internet calendars](../../../README.md#internet-calendars). Do not treat calendar `0` and booking `0` as equivalent.

## Development

Build the owning component with its `./build.sh`; there is no separate child release. See [component development](../../../README.md#development).

## Automated Testing

The component registers `scheduler:12-scheduler.php` and `teamcalendars:21-teamcalendars.php`; related suites cover mail, erasure and lifecycle. Use the [component test commands](../../../README.md#running-tests-locally). Test with isolated synthetic data, not a live site. A passing routine test does not verify a deployment's mail delivery or cron.

## Troubleshooting

No execution: check plugin/task publication, schedule, trigger and history separately. Reminder failure marks the routine failed and leaves the offset eligible for retry; SMTP does not provide crash-proof exactly-once delivery. A backlog can exceed a retention batch; inspect counts and increase frequency as appropriate. Exceptions are logged and return `KNOCKOUT`; correct the cause rather than treating missing work as success.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR**, with Swiss High German in de-DE. Parent language lint includes these files.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
