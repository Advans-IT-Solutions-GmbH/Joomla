# DMARC Reports Ingestion Task Plugin

**Installation and updates:** see the [shared file-backup/approval policy and repeatable System check](../README.md#installation-safety-and-repeatable-system-checks).

## Description

`plg_task_dmarcreports` is a Joomla Scheduler task plugin (group `task`) that runs the
DMARC Reports mailbox import automatically. It reuses the `com_dmarcreports` component's
`SourcePoller` service, so an automatic run and a concurrent on-demand import share one
cursor- and lock-safe path and never process the same message twice.

With a task and a working Scheduler trigger configured, reports can be imported unattended.
The plugin is not a daemon: without a trigger, due tasks do not run. Each run makes outbound
IMAP connections to the configured mailbox sources; parsing and report storage remain local.

## Features

- Polls every active IMAP source bound to a published domain on each run.
- Imports both DMARC aggregate (XML) and TLS-RPT (JSON) reports through the component pipeline.
- Parallel-safe with manual/on-demand imports via the component's DomainLock/SourceLock/RowLock.
- Optional auto-creation of unknown policy domains.
- Per-source run logging and an aggregated summary in the task log.

## Requirements

- Joomla 5.4 or newer (tested on the latest 5.4.x and 6.x).
- PHP 8.1 or newer.
- The `com_dmarcreports` component installed and configured with at least one IMAP source.

## Installation

**Availability:** Standalone releases are published as `task-dmarcreports-v*` GitHub releases
(latest only). To build from source, run `cd plg_task_dmarcreports` and `./build.sh` (Bash, rsync
and zip required), or build the [DMARC Reports package](../pkg_dmarcreports) for the complete solution.

Install the ZIP like any Joomla extension (System → Install → Extensions). The plugin is
enabled automatically on a fresh install. It is also bundled in `pkg_dmarcreports`, which is the
recommended way to install the whole DMARC solution together.

Standalone installs register their own update server; package installs use the package channel. Release retention is latest-only per
extension/tag family, not the monorepo's global latest release.

**What the installer does.** It checks the platform (Joomla 5.4+, PHP 8.1+) and decides by itself, without questions. Before Joomla copies files it compares every file it would replace, remove or adjust with the installed-file record and the released hashes. A modified or unknown file stops the update before anything is copied; the notice lists the path, the current and incoming SHA-256 hashes and the reason. Resolve the conflict manually or defer the update, then retry. The installer keeps no copy of file contents and has no approval or bypass step. Before the first update attempt it stops once and recommends a current, complete full-site backup (files and database); it cannot verify that one exists, and the retry only acknowledges the note. After a fresh installation it enables the plugin; after an update it leaves the enabled or disabled state alone and points out a disabled plugin. After every installation and update it also checks the scheduled task (see [Scheduled tasks](#scheduled-tasks)).

## Configuration

Help: the Help button of the plugin edit screen and the note at the top of its options open the **Help & tips** page of the DMARC Reports component (`index.php?option=com_dmarcreports&view=help`), which explains the terms, the Action Center entries and the typical tasks.

The plugin has no options of its own besides the note that links to the help page (and, in installed builds, a read-only **System check** tab). Everything it uses is configured in the component options and in the task:

| Setting | Where | Default | Purpose |
|---------|-------|---------|---------|
| Auto-create domains (`auto_create_domains`) | The task's own parameter | `No` | Lets the task create unknown domains; only effective with the two component options below |
| Create unknown domains automatically | Component options | `Off` | General gate for automatic domain creation |
| Allow automatic domain creation from scheduler tasks | Component options | `Off` | Extra gate for scheduled tasks; changing it needs administrator rights on the component |
| IMAP timeout, maximum messages and the run and message limits | Component options | see the [component README](../com_dmarcreports/README.md#configuration) | Applied to each source on its own |
| Execution rule (interval) | **System → Manage → Scheduled Tasks** | every hour | Joomla's standard task fields |

**Permissions.** The plugin has no `access.xml`. Creating or changing the task needs Joomla's Scheduler permissions (the component *Scheduled Tasks*); changing the gates above needs administrator rights on `com_dmarcreports`. The task runs under no person's permissions, so restrict both.

1. Nothing to create: the installation creates the scheduled task **DMARC Reports - Automatic ingestion** (see [Scheduled tasks](#scheduled-tasks)).
2. Adjust its execution rule under System → Manage → Scheduled Tasks if every hour does not suit you.
3. Optionally enable automatic domain creation. This is a privileged action, so it only takes
   effect when **all three** switches are on: the component options
   *Create unknown domains automatically* (`auto_create_unknown_domains`) and
   *Allow automatic domain creation from scheduler tasks* (`task_auto_create`), which require
   administrator rights to change, **and** the task's own *Auto-create domains* parameter. If the
   task parameter is enabled without `task_auto_create`, the run logs a warning and creates no
   domains. It keeps ingesting into the domains that already exist. A created domain is always
   **unpublished**, because anyone can send a report: it is neither monitored nor served an
   MTA-STS policy until an administrator publishes it.

### Scheduled tasks

| Task (routine ID) | Need | What the installer does |
|---|---|---|
| **DMARC Reports - Automatic ingestion** (`plg_task_dmarcreports.ingest`) | **Required** for automatic ingestion, the purpose of this plugin; without a report source it does nothing | Created and switched on when missing |

After every installation and update of this plugin or of the DMARC Reports package, the shared installer (`shared/installer/ScheduledTasks.php`) checks the task:

- **Missing:** created through Joomla's Scheduler, switched on, every hour, with the task form's defaults (`auto_create_domains` off), and named in the message after the installation.
- **Present and switched on:** nothing is changed and nothing is duplicated.
- **Switched off or trashed:** left as it is, because someone switched it off on purpose; the message after the installation and the system check say so and give the menu path to switch it on.
- **Scheduler not started:** if this plugin is switched off, or Joomla's Scheduler has no web trigger and no ingestion ran in the last two days, the message says so.

Check it under **System → Manage → Scheduled Tasks** or in the **System check** tab of this plugin. Uninstalling the plugin deletes the tasks of its routine, because they could no longer run.

### Scheduler trigger — important

The routine ID is `plg_task_dmarcreports.ingest`. Its own `auto_create_domains` parameter defaults to off. Component settings supply the mailbox timeout (default 20 seconds, bounded to 5–120) and message limit (default 200, bounded to 10–2000) **for each source**, not for the whole task. Scheduler execution uses these configured gates rather than an interactive user's `dmarc.ingest` permission. Restrict who can configure component options and Scheduler tasks.

Joomla's Scheduler only runs when something triggers it. Choose one:

- **Lazy Scheduler** (default): Joomla runs due tasks on normal site traffic (a visitor request).
  Simple, but ingestion only happens while the site receives traffic and is therefore irregular.
- **Real cron** (recommended for continuous operation): call the Joomla CLI or the web-cron URL
  from a system cron job at a fixed interval, so ingestion keeps running regardless of traffic.
  See the Joomla documentation on "Scheduled Tasks" for the CLI (`php cli/joomla.php scheduler:run`)
  and web-cron options.

## Usage and Data Handling

Check that the plugin and the task are switched on and that a trigger runs, then inspect **Scheduled Tasks → execution history** and the component's per-source run messages. The plugin reuses component credentials and writes reports through the component; it stores no separate report copy and sends no notification mail. Its logs include source identifiers and ingestion summaries. See the [component's privacy and input limits](../com_dmarcreports/README.md#permissions-and-data-handling) for report storage, network access, export and deletion.

## Updating

Install a newer standalone ZIP over this plugin, or update the DMARC package if installed as part of that package. Existing plugin state is not enabled again on update. Package installations use only the package's update channel; do not update a child independently.

## Uninstall

Remove the standalone plugin through **System → Manage → Extensions**, or uninstall the owning package. The plugin has no report tables or report-deletion routine; component data remains when only the standalone plugin is removed. Uninstalling deletes the Scheduler tasks of this routine (they could no longer run). Removing the plugin does not revoke the configured mailbox credentials.

## Troubleshooting

Missing component or failure to load sources returns Scheduler `KNOCKOUT`. No active sources returns `OK` without importing anything; sources must be enabled and bound to published domains. A source transport error makes the aggregated task fail, but blocked sources and failed report documents are reported in the summary and do not alone force `KNOCKOUT`. A successful task status is therefore not proof that every source/document was imported. Check source run messages, mailbox reachability and report-format/decoder limits.

If due tasks never run, check plugin/task publication and the trigger separately. A configured interval without traffic or cron does not execute work.

## Development

### Structure

```
plg_task_dmarcreports/
├── dmarcreports.xml          # manifest (type=plugin, group=task)
├── script.php                # installer script (enables the plugin on install)
├── services/provider.php     # DI service provider
├── src/Extension/            # DmarcReports task plugin class
├── forms/ingest.xml          # task parameter form
├── language/                 # en-GB, de-DE, fr-FR (.ini + .sys.ini)
└── updates/update.xml        # update server manifest
```

## Automated Testing

`tests/test.env` registers **installation**, **structure**, **graceful-degradation**, **install-messages** and **uninstall** suites. PHPUnit tests under `tests/Unit/` cover summary aggregation. The [package's auto-ingest suite](../pkg_dmarcreports/README.md#test-suites) separately exercises the real Scheduler-to-component ingestion pipeline; standalone structure/degradation checks are not equivalent to that end-to-end coverage.

Follow [local prerequisites](../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites), build this plugin and copy the ZIP to `tests/extension.zip`. From `tests`, run `docker compose up -d`, `./run-tests.sh all`, then `docker compose down -v`. For Joomla 6 use `docker-compose.joomla6.yml` for both Compose calls and `CONTAINER_NAME=plg_task_dmarcreports_j6_test ./run-tests.sh all`. The runner waits for readiness. Unit tests use `composer test:unit` after installing the declared development dependencies. These commands describe the suites, not a claim that the current checkout passed them.

## Multi-Language Support

All extensions and bundled plugins in this repository support exactly **de-DE, en-GB and fr-FR**,
with complete key and placeholder parity. **de-DE uses Swiss High German** (`ss`, never `ß`,
Swiss terminology and `z.B.`). No `de-CH` folders or build-generated copies are shipped.
The shared language lint and package checks enforce this rule, including for future extensions.

Ships with en-GB, de-DE (Swiss High German) and fr-FR, each with `.ini` and `.sys.ini`.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later. See `LICENSE.txt`.
