# J2Commerce Privacy Cleanup Task Plugin

**Installation and updates:** see the [shared installer conflict and recovery policy](../../../../../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_task_j2commerceprivacy` provides `plg_task_j2commerceprivacy.autocleanup` to Joomla Scheduler. It is bundled in [Privacy - J2Commerce](../../../README.md) and uses the parent's retention, consent and shop-table helpers.

## Features

- Anonymises eligible expired account and guest orders across every existing J2Commerce/J2Store data set.
- Keeps the order email for detected lifetime-license orders under the documented provisional rule.
- Anonymises legacy consent content and removes stale consent IP/user-agent evidence of checkout consents and checkout newsletter consents (outside the retention period, or the order no longer exists with personal data in any data set).
- Deletes AcyMailing placeholder contacts that Joomla's pseudonymisation of an account created (cms_id of a pseudonymised account, exactly its placeholder address, no list rows); real contacts are never touched. See the [parent AcyMailing section](../../../README.md#privacy-suite-what-is-exported-and-deleted).
- Purges expired guest-confirmation attempt counters.

## Requirements

Joomla 5.4.x or 6.x, PHP 8.1+, the installed parent with its helpers, relevant shop tables, and Joomla Scheduler with an execution trigger. This plugin is not an external retention service.

## Installation

The package `pkg_privacy_j2commerce_<version>.zip` ([package README](../../../../pkg_privacy_j2commerce/README.md)) installs **Task - J2Commerce Privacy Cleanup** after the parent privacy plugin and enables it when it was not installed before. This plugin cannot be installed or uninstalled on its own. The parent migrates the former routine ID `plg_privacy_j2commerce.autocleanup` to the current task ID.

### Scheduled tasks

The routine is **required** (it performs the legally required anonymisation). After every installation and update of the package, once this plugin is in place, the shared installer checks it: a missing task **J2Commerce - Automatic data cleanup** is created and switched on (every day at 02:15 UTC, task form defaults, retention years and fiscal year end taken from the parent's stored options) and reported; an existing task is never changed or duplicated; a task that is switched off or trashed stays so and is reported with the menu path to switch it on. Check it under **System → Manage → Scheduled Tasks** or in the **System check** tab of the parent or of this plugin, which also shows whether Joomla's Scheduler is started. Details: [parent `Scheduled tasks`](../../../README.md#scheduled-tasks).

## Updating

Update the package. Existing child plugin state is preserved; there is no independent release/update channel. A site that still runs the standalone parent plugin of an earlier version receives the package through the parent's update channel, and the package takes this plugin over with its settings and enabled state. Before updating, take a current, complete site backup that can be restored independently; the installer cannot verify this. The first update attempt displays this recommendation and stops before changing files; start the update again afterwards. The installer keeps no file copies; a modified or unknown file that the update would replace stops it before anything is copied, with the path and SHA-256 hashes listed, until you resolve it manually or defer the update. There is no approval command or bypass. Check the task's own parameters after a policy change.

## Uninstall

Uninstalling the package removes this plugin together with the parent; the parent's uninstall deletes the Scheduler tasks of the routine. It does not reverse anonymisation or remove shop/consent/action-log records as a blanket cleanup. Follow the [parent uninstall contract](../../../README.md#uninstall). Disable the task if retention must pause; removing the package also removes the helpers.

## Configuration

### Help

The plugin's edit screen has a **Help** tab (de-DE, en-GB, fr-FR): what each cleanup run does and leaves alone, the task settings and the retention calculation, setting up the task and its trigger, installation and disabling, and typical problems. The plugin itself has no settings; the note on the **Plugin** tab points to the help and to the parent's **Help** tab, which explains the whole function.

### Task parameters

| Task parameter | Default | Meaning |
|---|---|---|
| `retention_years` | `10` | Form range 1–30; counted from the order's fiscal-year end |
| `fiscal_year_end` | `12-31` | Valid month-day; cutoff uses the site time zone, converted for stored order timestamps |
| `anonymize_orders` | `Yes` | Controls order anonymisation |
| `delete_addresses` | `Yes` | Controls address cleanup |

These are **task-local values**. A task the installer creates starts with the parent's retention years and fiscal year end; changing parent settings later does not update an existing task. The routine uses its declared defaults when values are absent. An invalid fiscal-year end is logged and replaced with the retention helper's default.

The installer-created task runs daily at 02:15 UTC; Joomla still needs a working trigger. Lazy scheduling depends on visitor traffic; system cron (`php cli/joomla.php scheduler:run`) or Joomla's configured web cron supports regular unattended work. There is no dry-run mode.

## Usage, Permissions and Data Handling

The plugin ships no `access.xml` and no permissions of its own; Joomla's Scheduler permissions decide who may change, run or delete the task, and the task sends no mail and writes no table of its own. Restrict Scheduler configuration to trusted administrators and test on isolated synthetic data before enabling destructive retention. Cleanup examines **all existing shop data sets**, not only the active shop, so migration copies do not retain personal data unnoticed. With order anonymisation off, stale consent evidence can still be removed.

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
