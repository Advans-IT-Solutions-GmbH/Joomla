# DMARC Reports Ingestion Task Plugin

## Description

`plg_task_dmarcreports` is a Joomla Scheduler task plugin (group `task`) that runs the
DMARC Reports mailbox import automatically. It reuses the `com_dmarcreports` component's
`SourcePoller` service, so an automatic run and a concurrent on-demand import share one
cursor- and lock-safe path and never process the same message twice.

With this plugin configured the administrator only watches the dashboard; nothing has to be
imported by hand.

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

Install the package like any Joomla extension (Extensions → Manage → Install). The plugin is
enabled automatically on a fresh install. It is also bundled in `pkg_dmarcreports`, which is the
recommended way to install the whole DMARC solution together.

## Configuration

1. Create a scheduled task: System → Manage → Scheduled Tasks → New → **DMARC Reports - Automatic ingestion**.
2. Set the execution rule (for example every 30 minutes).
3. Optionally enable *Auto-create domains* in the task's parameters.

### Scheduler trigger — important

Joomla's Scheduler only runs when something triggers it. Choose one:

- **Lazy Scheduler** (default): Joomla runs due tasks on normal site traffic (a visitor request).
  Simple, but ingestion only happens while the site receives traffic and is therefore irregular.
- **Real cron** (recommended for continuous operation): call the Joomla CLI or the web-cron URL
  from a system cron job at a fixed interval, so ingestion keeps running regardless of traffic.
  See the Joomla documentation on "Scheduled Tasks" for the CLI (`php cli/joomla.php scheduler:run`)
  and web-cron options.

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

Docker-based integration tests run against a real Joomla 5 and Joomla 6 install. See the
repository root README and the `joomla-extensions` skill for how the suites are built and run.

## Multi-Language Support

Ships with en-GB, de-DE (Swiss High German) and fr-FR, each with `.ini` and `.sys.ini`.

## Support & Contact

Advans IT Solutions GmbH — https://advans.ch

## License

GPL-3.0-or-later. See `LICENSE.txt`.
