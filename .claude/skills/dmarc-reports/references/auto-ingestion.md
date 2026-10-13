# Auto-Ingestion — `plg_task_dmarcreports`

The task plugin turns the component's on-demand import into unattended, continuous ingestion so the
administrator only watches the dashboard and never clicks "fetch now".

## What it is

A Joomla **Scheduled Tasks** plugin in the `task` group (`plg_task_dmarcreports`, element
`dmarcreports`, tag prefix `task-dmarcreports-v*`). It registers one task routine,
`plg_task_dmarcreports.ingest`, and runs it through the standard scheduler handler.

- `src/Extension/DmarcReports.php` — the plugin. Subscribes to `onTaskOptionsList`,
  `onExecuteTask` and `onContentPrepareForm` via `TaskPluginTrait`; the routine is `runIngest()`.
- `src/Support/IngestSummary.php` — pure aggregator that folds the per-source result arrays into
  `imported / duplicates / failed / blocked / errors` totals (unit-tested without Joomla).
- `forms/ingest.xml` — the task's parameter form (e.g. `auto_create_domains`).
- `services/provider.php` — DI registration (`task` plugin, `dmarcreports`).
- `script.php` — enables the plugin on install (same path as
  `j2commerce/plg_privacy_j2commerce/plugins/task/j2commerceprivacy`).

## Reuse, do not duplicate

`runIngest()` **must not** re-implement IMAP or parsing. It loads the component's
`Advans\Component\DmarcReports\Administrator\Service\SourcePoller`, calls `activeSources()` and then
`poll($source, $allowAutoCreate)` per source. `SourcePoller` is the single shared path used by both:

- the on-demand `IngestController` in the component, and
- this task plugin.

Both go through the same `Ingestor` and the component's row-level locks
(`DomainLock` / `SourceLock` / `RowLock`), so an automatic run and a concurrent manual import are
**parallel-safe**: a message is claimed under a lock and processed once, never twice. If the component
is absent (`class_exists(SourcePoller::class)` is false), the routine logs and returns
`Status::KNOCKOUT` instead of fataling.

Return mapping: any source error ⇒ `Status::KNOCKOUT`; otherwise `Status::OK`. No active sources is a
clean `Status::OK`.

## Scheduler trigger — document this clearly

Installing and enabling the plugin is not enough; Joomla's scheduler needs something to *trigger* it.
The component README and this plugin's README both state the options:

1. **Lazy scheduler (default).** Joomla runs due tasks on the back of ordinary site/administrator
   traffic. Good enough for low-traffic monitoring; cadence depends on visits.
2. **Real cron (recommended for continuous operation).** A system cron or a web cron calling the
   Scheduler CLI/endpoint on a fixed interval, independent of visitor traffic. This is the right
   choice when reports must be pulled promptly and regularly.

Create the task under **System → Manage → Scheduled Tasks → New → DMARC Reports: Ingest**, set the
interval (the task's own schedule) and enable it. The configurable interval lives on the task, not in
the component options.

## Tests

`plg_task_dmarcreports/tests/`:

- PHPUnit `Unit/` — `IngestSummary::aggregate()` totals and edge cases.
- Integration `scripts/` — installation, structure, an automatic task run and its parallel/lock
  behaviour (a second run while a lock is held does not double-process), installer messages,
  uninstall. Runs on both the Joomla 5 and Joomla 6 stacks (`CONTAINER_NAME=…_j6_test`).

The plugin ships no tables of its own; all storage is the component's.
