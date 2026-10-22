# DMARC Reports Component

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/dmarc-reports.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/dmarc-reports.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-dmarcreports.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-dmarcreports.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Administrator component that turns raw DMARC aggregate reports into a readable dashboard and tells you exactly what to do next.

## Description

Mailbox providers send DMARC aggregate reports (RFC 7489) as compressed XML attachments that are unreadable by hand. The DMARC Reports Component collects those reports directly inside your [Joomla](https://github.com/joomla/joomla-cms) installation, parses them in native PHP, and presents the result as per-domain statistics: mail volume, SPF and DKIM pass rates, alignment, top sending sources, and how much legitimate mail would be rejected under `p=reject`.

Report parsing and storage run on your own server — there is no external analysis service, Python, Elasticsearch or cloud dependency. Reports can be fetched on demand from configured mailboxes over outbound IMAP, uploaded by hand, or imported automatically by the optional [Scheduler task plugin](../plg_task_dmarcreports). Automatic ingestion requires a configured task and a working Joomla Scheduler trigger (site traffic for the lazy scheduler, or system/web cron for regular unattended operation); installing the plugin does not start a daemon. Report contents are not sent to an external analysis service. Exports and uploads remain administrator-controlled.

**Network and privacy:** IMAP connects to the mailbox hosts and ports you configure. Live diagnostics are **off by default** (`dns_diagnostics`): enabling them permits DNS queries for SPF/DMARC and, for separately enabled MTA-STS/TLS-RPT features, their TXT records and the receiving domain's actual MX targets. DNS discloses queried names to the configured resolver and upstream DNS servers. MTA-STS validation also makes an outbound **HTTPS** request to `https://mta-sts.<domain>/.well-known/mta-sts.txt` when the published TXT id matches; that host sees the requested path and server connection metadata, not report contents. These live checks run in the domain detail and System Check, not the main dashboard or dashboard module. Separately, Joomla's extension updater checks GitHub for version metadata and downloads update packages when requested; it does not upload reports.

Beyond showing the numbers, the component includes an **Action Center** that reads the parsed data and produces prioritised, plain-language recommendations: when it is safe to move a domain from `p=none` to `quarantine` to `reject`, which unaligned senders need SPF or DKIM before you tighten policy, and which new or failing sources need attention first. A non-expert administrator can see exactly where action is required.

## Features

- Multi-domain, multi-tenant: monitor several domains, each with its own report source
- On-demand IMAP ingestion (host, port, SSL/TLS, folder) with the password encrypted at rest; optional scheduled ingestion through the companion task plugin
- Manual upload of `.xml`, `.gz` and `.zip` aggregate reports as an alternative input
- Native RFC 7489 aggregate-report XML parser (no external tools)
- Idempotent storage: reports are de-duplicated by policy domain, reporter org, report id and report-window start date, so a reporter that reuses a report id for a later window — or for a different policy domain — still stores each report separately
- Dashboard with per-domain volume, SPF/DKIM pass rates and would-be-rejected share
- Per-domain drill-down: top sending IPs, pass/fail breakdown and a timeline
- **Action Center**: prioritised, data-driven next steps along the `none → quarantine → reject` rollout
- Live diagnostics (optional, off by default): SPF/DMARC records, MTA-STS/TLS-RPT publication and policy drift, and actual receiving-MX coverage before MTA-STS enforce advice
- CSV export of the per-domain record data
- System check view that reports whether the IMAP transport is available
- Administrator-only, behind Joomla ACL — the data never appears on the public site

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or higher
- A MySQL or MariaDB database (the schema is shipped for the MySQL/MariaDB driver only; installation is refused on other database servers)
- Administrator access
- A mailbox that receives DMARC aggregate reports (for IMAP ingestion); manual upload works without one
- Outbound IMAP connectivity from the web server for mailbox ingestion (manual or scheduled)
- Outbound DNS and HTTPS connectivity if live MTA-STS diagnostics are enabled

## Joomla 6 Compatibility

On Joomla 6, `Factory::getContainer()->get('DatabaseDriver')` was removed. The component resolves the database through `DatabaseInterface` and builds queries with a small helper that calls `$db->getQuery(true)` on Joomla 5 (Joomla 5.4 has no `createQuery()` on `DatabaseInterface`) and `$db->createQuery()` on Joomla 6. No configuration required — the correct API is selected at runtime.

## Installation

**Availability:** No component release or `dmarcreports-v*` tag has been published yet. Build from source for now. The component is individually buildable; the [DMARC Reports package](../pkg_dmarcreports) bundles it with the module and both plugins for a single installation and update channel.

From a repository checkout with Bash, rsync and zip available, run `cd com_dmarcreports` followed by `./build.sh`.

1. Use the generated `com_dmarcreports_<version>.zip`
2. **System → Install → Extensions**
3. Upload and install
4. Access via **Components → DMARC Reports**

## Updating

The standalone manifest registers this repository's `updates/update.xml` as update server (`<updateservers>`), and the install script makes sure the update site is present after every install or update. Published versions will appear under **System → Update → Extensions** once releases exist; source availability does not imply a downloadable release. You can also install a newer built ZIP over the existing installation. Package installations use the package's update channel instead. Releases retain only the newest release in each extension's tag family, not one global latest release for this monorepo.

## Uninstall

Uninstall via **System → Manage → Extensions**. The component's uninstall SQL drops its own tables (monitored domains, report sources, reports and records) and Joomla removes the component files and language files. All collected report data is deleted with it; export anything you want to keep first.

## Configuration

Open **Components → DMARC Reports**, then **Options** (top right) to set the defaults:

| Option | Default | Purpose |
|--------|---------|---------|
| Default policy target | `reject` | The policy the rollout guidance works towards |
| Quarantine pass threshold | `95` | Aligned-pass rate (%) at which moving to `p=quarantine` is suggested |
| Reject pass threshold | `99` | Aligned-pass rate (%) at which moving to `p=reject` is suggested |
| IMAP timeout | `20` | Seconds to wait for the mailbox connection |
| Max messages per run | `200` | Upper bound of messages fetched per ingestion run |
| Create unknown domains automatically (`auto_create_unknown_domains`) | `Off` | Lets an import create a domain that a report names but that is not yet listed. Such a domain is always created **unpublished**: anyone can send a report, so it is neither monitored nor served an MTA-STS policy until an administrator reviews and publishes it. Applies to the manual upload, the mailbox fetch (both also need `core.create`) and the scheduled task (which also needs *Allow automatic domain creation from scheduler tasks* and its own task parameter). While off, reports for unknown domains are skipped. |
| DNS diagnostics | `Off` | Enables live DNS checks in domain detail/System Check; separately enabled MTA-STS also checks MX coverage and fetches the hosted policy over HTTPS. Queried names and connection metadata are disclosed as described above; report contents are not sent |

Per-domain report sources (mailbox host, port, encryption, username, password and folder) are managed inside the component UI under **Sources**, not in the global options. The mailbox password is encrypted at rest using Joomla's secret and is never written to a log.

## Usage

### Access

Navigate to **Components → DMARC Reports** (`administrator/index.php?option=com_dmarcreports`).

### Workflow

1. **Add a domain** under **Domains** — the domain you want to monitor (for example `example.org`)
2. **Add a report source** under **Sources** — the mailbox that receives the aggregate reports for that domain, with its IMAP settings. Unless automatic domain creation is enabled (see `auto_create_unknown_domains` above), a source only stores reports for its own domain or a subdomain of it (for example `mail.example.org`); a report for a parent, sibling or unrelated domain is skipped. Without a bundled Public Suffix List this is an exact-or-child rule, so bind the source to the registrable domain you own, never to a public suffix such as `co.uk`
3. **Ingest reports** — trigger an on-demand IMAP fetch, upload a report file, or configure the companion task plugin and a Scheduler trigger for automatic imports
   The import summary names the stored records (rows) and the messages they describe apart: a report with three rows can stand for six messages.
4. **Read the Dashboard** — per-domain volume, SPF/DKIM pass rates and would-be-rejected share
5. **Act on the Action Center** — follow the prioritised recommendations before tightening policy
6. **Drill down** per domain for top sending IPs, pass/fail detail and the timeline
7. **Export** the per-domain records as CSV when you need the raw data

### Action Center

The Action Center converts the parsed reports into concrete, prioritised tasks, for example:

- A domain is still at `p=none` while the aligned-pass rate is already above the quarantine threshold → it is safe to move to `p=quarantine`
- An unaligned sender sends a meaningful share of mail → add it to SPF or set up DKIM before tightening policy
- A new or unknown sending source appeared → review it before it affects a stricter policy
- An SPF or DMARC DNS record is missing → the suggested record to publish is shown
- A DNS lookup failed → the records could not be checked, so none is suggested and a transient outage is not mistaken for a missing record

Each item explains what to change, why, and when it is safe, ordered so the most important action comes first.

### Policy rollout guidance

DMARC policy is rolled out in stages: `p=none` (monitor only) → `p=quarantine` (suspicious mail to spam) → `p=reject` (fail hard). The component shows this as help text and only recommends the next step once the data says the step is safe, so legitimate mail is not lost.

### MTA-STS receiving-MX coverage

The MTA-STS MX list in component options is shared across monitored domains; a union of all their receiving hosts is valid. With live diagnostics enabled, the domain detail Action Center and System Check compare **each domain's actual DNS MX targets** with that policy. Exact matches are case-insensitive; `*.example.org` matches `mail.example.org`, but neither `example.org` nor `mail.eu.example.org` (RFC 8461 section 4.1).

Uncovered targets produce an actionable warning even before policy publication, with higher urgency in `enforce`. DNS failures and answers without usable MX targets (including null MX) remain explicit, unverified states and never produce enforce advice. Correct the MX list, publish the updated policy and TXT id, and recheck every affected domain before enforcing. A mismatch in an enforcing policy can defer delivery and eventually cause bounces. Coverage alone does not test SMTP STARTTLS or MX certificates: verify those and review TLS-RPT reports as well. The main dashboard/module remain network-free and direct you to these checks through their publish guidance.

## Development

### Structure

```
com_dmarcreports/
├── README.md
├── VERSION                                   # Managed by release workflow
├── LICENSE.txt
├── com_dmarcreports.xml                       # Joomla manifest
├── script.php                                 # Install/update/uninstall script
├── composer.json                              # Dev-only (PHPUnit)
├── phpunit.xml
├── administrator/
│   ├── components/
│   │   └── com_dmarcreports/
│       │       ├── access.xml                     # ACL rules
│       │       ├── services/provider.php          # DI service provider
│       │       ├── sql/                            # install/uninstall SQL
│       │       ├── forms/                          # domain.xml, source.xml
│       │       ├── src/
│       │       │   ├── Controller/                # Display, Ingest, Export, Actions, Domain(s), Source(s)
│   │       │   ├── Extension/                  # Component entry point
│   │       │   ├── Helper/                     # Parser, IMAP client, Action Center, DNS, crypto
│   │       │   ├── Model/                      # Dashboard, Detail, Domain(s), Source(s), Systemcheck
│   │       │   ├── Table/                      # DomainTable, SourceTable
│   │       │   └── View/                       # Dashboard, Detail, Domain(s), Source(s), Systemcheck
│   │       └── tmpl/                           # Layouts per view
│   └── language/
│       ├── en-GB/
│       ├── de-DE/
│       └── fr-FR/
├── updates/
│   └── update.xml                             # Joomla update server
└── tests/
    ├── scripts/                               # Integration test scripts (01–05, 10)
    ├── Unit/                                   # PHPUnit unit tests
    ├── docker-compose.yml
    ├── docker-compose.joomla6.yml
    ├── run-tests.sh
    └── test.env
```

## Automated Testing

This component has automated tests that run via GitHub Actions (`dmarc-reports.yml`) on pushes and pull requests to `main` that change this directory, the shared harness files it uses or the workflow file. CI also runs a PHP syntax check, the language file lint, the requirements check (Joomla 5.4+, PHP 8.1+), the deprecated-API scan and a PHPUnit unit suite. Details: [testing.md](../.claude/skills/joomla-extensions/references/testing.md).

The integration suite runs against **two real stacks** — Joomla 5 (`test-j5`) and Joomla 6 (`test-j6`) — so the parser, storage, metrics and Action Center logic are exercised inside an actual Joomla install. The component does not depend on J2Commerce.

### Test Suites

Order as in `tests/test.env`:

1. **Installation** - Component registration, file deployment, table creation
2. **Structure** - Namespaces, services, forms and SQL are present and well-formed
3. **Parser** - RFC 7489 aggregate-report XML is parsed into the expected records
4. **Storage** - Reports and records are stored idempotently (dedupe by policy domain, reporter org, report id and report-window start date)
5. **Action Center** - Recommendations are derived correctly from the parsed data
6. **Metrics** - The `Metrics` aggregator is driven against a seeded database and returns the expected lifetime totals, rolling-window pass rates, new sources and timeline
7. **Export** - The CSV export's keyset pagination is driven against a seeded record set and returns every record exactly once, in order, without duplicating or skipping rows as it pages; the snapshot id ceiling excludes rows ingested mid-export; and the export controller keeps its CSRF-token and `core.manage` guards
8. **Backend Views** - shared suite (`shared-backend-views.php`): logs into `/administrator` and really renders the component pages. It asserts HTTP 200, no PHP error and no Joomla error page, no untranslated language key, and at least one string of the component's own language file on the page
9. **Installer Messages** - shared suite: removes and reinstalls the package through the Joomla CLI in en-GB, de-DE and fr-FR, then updates once; fails on untranslated language keys, `[ERROR]`/`[WARNING]`/`[CAUTION]` output, PHP warnings or a non-zero exit code
10. **Uninstall** - Component removal, table drop, verification

A separate **PHPUnit (Unit)** job runs the parser, DNS-diagnostics and Action-Center unit tests from `tests/Unit/` without Docker. The database-backed `Metrics` aggregation is covered by the **Metrics** integration suite instead, because it needs a real database.

### Running Tests Locally

Prerequisites: the package as `tests/extension.zip`.
Full commands: [Local Prerequisites](../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites).

```bash
# from the repository root
(cd com_dmarcreports && ./build.sh && cp *.zip tests/extension.zip)
```

Joomla 5:

```bash
cd com_dmarcreports/tests
docker compose up -d
# Wait for container readiness (health.txt written by docker-entrypoint.sh)
timeout 300 bash -c 'until docker exec com_dmarcreports_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
./run-tests.sh all
docker compose down -v
```

Joomla 6:

```bash
cd com_dmarcreports/tests
docker compose -f docker-compose.joomla6.yml up -d
timeout 300 bash -c 'until docker exec com_dmarcreports_j6_test test -f /var/www/html/health.txt 2>/dev/null; do sleep 5; done'
CONTAINER_NAME=com_dmarcreports_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v
```

PHPUnit unit suite (needs `composer install` once):

```bash
cd com_dmarcreports
composer install
composer test
```

Test results are saved in `tests/test-results/`.

## Troubleshooting

### IMAP ingestion reports the transport is unavailable
**Problem:** The System Check or an ingestion run states that IMAP is not available  
**Solution:** The component connects over an encrypted stream socket and does not require the PHP `imap` extension, but it does need outbound network access to the mailbox host and port. Check the server firewall and the host/port/encryption settings on the source. Manual upload works regardless.

### A report file is rejected on upload
**Problem:** Uploading an aggregate report does nothing or reports a parse error  
**Solution:** Only RFC 7489 aggregate reports are supported, as `.xml`, `.gz` or `.zip`. Forensic/failure reports are a different format and are not parsed. Confirm the file is an aggregate report and not already corrupted.

### The same report seems to be missing after a second ingestion
**Problem:** Re-running ingestion does not add rows  
**Solution:** This is expected. Reports are de-duplicated by policy domain, reporter org, report id and report-window start date, so importing the same report twice does not create duplicates.

### Cannot access the component after installation
**Problem:** Menu item missing or permission denied  
**Solution:** Verify administrator permissions under **System → Global Configuration → Permissions** and that the `com_dmarcreports` ACL allows your group.

### A sending source is flagged as unaligned
**Problem:** A legitimate sender appears in the Action Center as failing alignment  
**Solution:** This is the point of the tool. Add the sender to the domain's SPF record or configure DKIM signing for it, then re-ingest. Only tighten the DMARC policy once the Action Center no longer flags it.

## Multi-Language Support

This extension supports the following languages:
- **English (en-GB)** - Default
- **German (de-DE)**
- **French (fr-FR)**

Every visible string — headings, table columns, status badges, Action Center recommendations, help text and the copyright/licence line — comes from a language key; no admin-visible text is assembled at runtime. Generic words use Joomla core keys. A further language needs these files, plus one `<language>` line per file in the manifest (see [Languages](../README.md#languages)):

```
administrator/language/{language-tag}/com_dmarcreports.ini
administrator/language/{language-tag}/com_dmarcreports.sys.ini
```

## Support & Contact

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

## License

Copyright (C) 2026 Advans IT Solutions GmbH

This program is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version. It is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See [LICENSE.txt](LICENSE.txt) for the full license text.

SPDX-License-Identifier: `GPL-3.0-or-later`
