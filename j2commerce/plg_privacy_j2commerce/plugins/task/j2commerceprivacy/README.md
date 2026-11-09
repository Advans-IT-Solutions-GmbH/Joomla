# J2Commerce Privacy Cleanup Task Plugin

<a href="https://www.swissmadesoftware.org/en/about/swiss-made-software.html">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="../../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb-on-white.png">
    <img src="../../../../../.github/assets/labels/sms-logo-1v-300dpi-rgb.png" width="110" alt="swiss made software">
  </picture>
</a>

This extension carries the swiss made software label.

**Installation and updates:** see the [shared backup/approval policy](../../../../../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_task_j2commerceprivacy` provides `plg_task_j2commerceprivacy.autocleanup` to Joomla Scheduler. It is bundled in [Privacy - J2Commerce](../../../README.md) and uses the parent's retention, consent and shop-table helpers.

## Features

- Anonymises eligible expired account and guest orders across every existing J2Commerce/J2Store data set.
- Keeps the order email for detected lifetime-license orders under the documented provisional rule.
- Anonymises legacy consent content and removes stale consent IP/user-agent evidence.
- Purges expired guest-confirmation attempt counters.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, the installed parent with its helpers, relevant shop tables, and Joomla Scheduler with an execution trigger. This plugin is not an external retention service.

## Installation

The parent installer installs/enables **Task - J2Commerce Privacy Cleanup** when first introduced. Create the task deliberately under **System → Manage → Scheduled Tasks**; plugin enablement does not schedule a daemon. The parent migrates the former routine ID `plg_privacy_j2commerce.autocleanup` to the current task ID.

## Updating

Update the parent privacy ZIP. Existing child plugin state is preserved; there is no independent release/update channel. Check the task's own parameters after a policy change.

## Uninstall

Parent uninstall removes this plugin and its Scheduler tasks. It does not reverse anonymisation or remove shop/consent/action-log records as a blanket cleanup. Follow the [parent uninstall contract](../../../README.md#uninstall). Disable the task if retention must pause; removing the parent also removes the helpers.

## Configuration

| Task parameter | Default | Meaning |
|---|---|---|
| `retention_years` | `10` | Form range 1–30; counted from the order's fiscal-year end |
| `fiscal_year_end` | `12-31` | Valid month-day; cutoff uses the site time zone, converted for stored order timestamps |
| `anonymize_orders` | `Yes` | Controls order anonymisation |
| `delete_addresses` | `Yes` | Controls address cleanup |

These are **task-local values**, not inherited from the parent plugin's options. Changing parent settings does not update an existing task. The routine uses its declared defaults when values are absent. An invalid fiscal-year end is logged and replaced with the retention helper's default.

Choose a schedule and a working trigger. Lazy scheduling depends on visitor traffic; system cron (`php cli/joomla.php scheduler:run`) or Joomla's configured web cron supports regular unattended work. There is no dry-run mode.

## Usage, Permissions and Data Handling

Restrict Scheduler configuration to trusted administrators and test on isolated synthetic data before enabling destructive retention. Cleanup examines **all existing shop data sets**, not only the active shop, so migration copies do not retain personal data unnoticed. With order anonymisation off, stale consent evidence can still be removed.

The task writes execution logs and modifies the parent's/shop's records, not a separate cleanup database. It is not a substitute for confirmed privacy requests, a website-wide erasure guarantee or backup retention. Refer to the [parent retention logic](../../../README.md#retention-logic) for the account/guest/lifetime distinctions.

Registered accounts are selected only when their **most recent order** in that data set is outside the cutoff; guest orders are evaluated individually. There is no task batch-size parameter. Plan execution time for the site's data volume and inspect partial/error counts: some account errors can coexist with an `OK` status when other accounts were processed successfully.

## Development

Use the [parent build](../../../README.md#development); this plugin is distributed and versioned with the parent.

## Automated Testing

The parent's `autocleanup_task:09-autocleanup-task.php` drives the real Scheduler routine with synthetic orders, consent evidence and migration data. See [parent test suites/commands](../../../README.md#automated-testing). The suite does not establish a deployment's real cron timing or policy/legal acceptance.

## Troubleshooting

No run: check plugin/task publication, schedule, trigger and execution history. Missing helpers or failed cleanup can return `KNOCKOUT`; inspect logs and repair the cause. A reported run does not mean every order was eligible: retention cutoff, lifetime flags and task options decide. Already anonymised data cannot be recovered by disabling the task; restoration requires an appropriate matching backup and privacy assessment.

## Multi-Language Support

Exactly **de-DE, en-GB and fr-FR**, with Swiss High German in de-DE. Parent language lint includes these files.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later; see [LICENSE.txt](LICENSE.txt).
