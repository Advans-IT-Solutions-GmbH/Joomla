---
name: dmarc-reports
description: Deep knowledge of the DMARC Reports solution — the com_dmarcreports administrator component, the mod_dmarcreports dashboard module, the plg_task_dmarcreports auto-ingestion scheduler task, the plg_system_dmarcmtasts MTA-STS policy system plugin and the pkg_dmarcreports package that bundles them. Use when working on DMARC aggregate-report ingestion (IMAP or file upload), the RFC 7489 XML parser, TLS-RPT (RFC 8460) ingestion, MTA-STS (RFC 8461) policy hosting and validation, automatic continuous ingestion, the data model, the dashboard metrics, the Action Center recommendation logic, the backend cpanel dashboard module, or the package build/release.
triggers:
  - dmarc
  - com_dmarcreports
  - mod_dmarcreports
  - plg_task_dmarcreports
  - plg_system_dmarcmtasts
  - pkg_dmarcreports
  - dashboard module
  - cpanel module
  - aggregate report
  - RFC 7489
  - SPF
  - DKIM
  - alignment
  - p=none
  - p=quarantine
  - p=reject
  - action center
  - DMARC policy
  - report source
  - IMAP ingestion
  - auto ingestion
  - scheduler task
  - onExecuteTask
  - lazy scheduler
  - MTA-STS
  - RFC 8461
  - mta-sts.txt
  - well-known
  - TLS-RPT
  - RFC 8460
  - SMTP TLS reporting
references:
  - references/architecture.md
  - references/dmarc-domain.md
  - references/ingestion.md
  - references/action-center.md
  - references/testing.md
  - references/module.md
  - references/auto-ingestion.md
  - references/mta-sts-tls-rpt.md
---

# DMARC Reports — Domain Knowledge

## Location

The DMARC Reports solution is five sibling extensions at the repository root (same level as
`plg_ajax_joomlaajaxforms`):

| Directory | Extension | Tag prefix |
|-----------|-----------|------------|
| `com_dmarcreports/` | component (administrator) | `dmarcreports-v*` |
| `mod_dmarcreports/` | dashboard module (administrator) | `moddmarcreports-v*` |
| `plg_task_dmarcreports/` | scheduler task plugin (`task`) | `task-dmarcreports-v*` |
| `plg_system_dmarcmtasts/` | MTA-STS system plugin (`system`) | `system-dmarcmtasts-v*` |
| `pkg_dmarcreports/` | package bundling the four above | `pkgdmarcreports-v*` |

The component, module and both plugins stay individually buildable, testable and releasable; the
package bundles their built archives so the administrator gets one install and one update stream.
The already-merged `mod_dmarcreports` is folded into the package rather than shipped twice — it keeps
its own Build & Test workflow for CI but is delivered through the package. See
`references/auto-ingestion.md` (task plugin + scheduler) and `references/mta-sts-tls-rpt.md`
(MTA-STS policy hosting/validation and TLS-RPT ingestion). The package build/release wiring is in the
`joomla-extensions` skill and `pkg_dmarcreports/README.md`.

## What This Component Does

A redistributable, administrator-only Joomla component that turns raw DMARC aggregate
reports (RFC 7489) into a readable dashboard and actionable guidance. Everything runs
inside the Joomla install — no external service, Python, Elasticsearch or cloud; no
report data leaves the host.

- Multi-domain / multi-tenant: several monitored domains, each with its own report source
- Ingests reports on demand over IMAP, or by manual upload of `.xml`, `.gz` or `.zip`
- Native PHP RFC 7489 parser; idempotent storage de-duplicated by policy domain + reporter org + report id + report-window start
- **TLS-RPT (RFC 8460)**: ingests SMTP TLS reporting JSON (optionally gzipped) over the same IMAP/upload pipeline, routed by content to a dedicated parser and its own tables (no mixing with DMARC aggregate data)
- **MTA-STS (RFC 8461)**: generates the policy body, the `_mta-sts` TXT record and the `_smtp._tls` TLS-RPT TXT record, surfaces them in the Action Center and the domain detail view, and validates the published records and hosted policy in the system check (never writes DNS itself)
- Dashboard: per-domain volume, SPF/DKIM pass rates, would-be-rejected share, top IPs, timeline, TLS-RPT delivery results
- **Action Center**: prioritised, data-driven next steps along `none → quarantine → reject`, plus MTA-STS/TLS-RPT publish and drift findings
- DNS diagnostics for SPF/DMARC/MTA-STS records; CSV export; system check for the IMAP transport
- Mailbox password encrypted at rest (Joomla secret); CSRF tokens, ACL, prepared statements

## Data Model

Five core `#__dmarc_*` tables plus three TLS-RPT tables (install/uninstall SQL in
`administrator/components/com_dmarcreports/sql/`; the TLS-RPT tables are added by
`sql/updates/mysql/1.1.0.sql` with `CREATE TABLE IF NOT EXISTS`, so updating an existing
install never drops collected DMARC data):

| Table | Holds |
|-------|-------|
| `#__dmarc_domains` | Monitored domains (`domain`, `expected_policy`, notes) |
| `#__dmarc_sources` | Mailbox config per domain; password in `password_enc` |
| `#__dmarc_reports` | One row per aggregate report; `report_hash` (unique) = dedupe key |
| `#__dmarc_records` | One row per report record (source IP, count, disposition, SPF/DKIM result + alignment, header_from) |
| `#__dmarc_actions` | Persisted Action Center items (`action_key`, severity, status, snooze) |
| `#__dmarc_tlsrpt_reports` | One row per TLS-RPT report; fingerprint (unique) = dedupe key |
| `#__dmarc_tlsrpt_policies` | One row per policy block in a report (policy type, policy domain, success/failure session counts) |
| `#__dmarc_tlsrpt_failures` | One row per TLS failure detail (result type, receiving MX, sending MTA IP, session count) |

## Key Classes

| Class (`src/Helper/` unless noted) | Purpose |
|------|---------|
| `DmarcParser` | Parse aggregate-report XML into report + record rows |
| `ReportArchive` / `MimeMessage` | Unpack `.gz`/`.zip`, extract report attachments from a raw message |
| `ImapClient` | Streaming IMAP transport (`process()` callback, one message at a time) |
| `Ingestor` | Orchestrates parse + idempotent store; `ingestMessage()` / `ingestMessages()`; routes DMARC XML vs TLS-RPT JSON by content |
| `TlsRptParser` / `TlsReportArchive` / `TlsRptIngestor` | RFC 8460 JSON parsing (gzip-aware), de-dup fingerprint, idempotent store into the TLS-RPT tables |
| `MtaStsPolicy` | RFC 8461 policy body + `_mta-sts`/`_smtp._tls` DNS record generation (`generate()`, static) |
| `MtaStsDiagnostics` / `PolicyFetcherInterface` / `HttpPolicyFetcher` | Validate published `_mta-sts` record and hosted policy for drift (`inspect()`), gated on `dns_diagnostics` |
| `DomainRegistry` | Resolve/attach domains during ingestion |
| `CryptoHelper` | Encrypt/decrypt the mailbox password with the Joomla secret |
| `ActionCenter` / `ActionStatus` / `ActionPresenter` | Derive, persist and render recommendations |
| `Metrics` | Per-domain volume / pass-rate / would-be-rejected calculations; `tlsRptSummary()` |
| `DnsDiagnostics` / `SystemDnsResolver` / `DnsResolverInterface` | SPF/DMARC/MTA-STS record checks |
| `SourcePoller` (`src/Service/`) | Polls every active source over IMAP under the shared locks; reused by the on-demand controller and the task plugin |
| `EnvironmentCheck` | Whether the IMAP transport is available (system check view) |

Controllers (`src/Controller/`): `Display`, `Ingest`, `Export`, `Actions`, `Domain(s)`, `Source(s)`.

## Companion Module (`mod_dmarcreports`)

A separate top-level administrator module (`mod_dmarcreports/`, tag prefix `moddmarcreports-v*`)
renders the DMARC summary and top Action Center items on the Joomla backend `cpanel` dashboard.
It is a thin presenter over this component: its helper boots the `DashboardModel`
(`getTotals()` / `getActions()`) and reuses `ActionPresenter`, so no query logic is duplicated.
It respects `com_dmarcreports` ACL and degrades gracefully (states `ok` / `unavailable` /
`unauthorised` / `error`) when the component is absent, disabled or unreadable. Full detail:
`references/module.md`.

## Auto-Ingestion Task Plugin (`plg_task_dmarcreports`)

A `task`-group scheduler plugin (`onExecuteTask`) that runs continuous, unattended ingestion so the
administrator only watches the dashboard. It does not duplicate ingestion logic: it loads the
component's `SourcePoller`, which polls every active source over the native IMAP client and stores
through the shared `Ingestor`, under the component's `DomainLock` / `SourceLock` / `RowLock`. A manual
on-demand run (`IngestController`) and an automatic task run are therefore parallel-safe — no double
processing. The interval is configurable on the task; Joomla's scheduler needs a trigger (lazy
scheduler by default, or a real system/web cron for truly continuous operation). Classes:
`src/Extension/DmarcReports.php`, `src/Support/IngestSummary.php`; form `forms/ingest.xml`;
`script.php` enables the plugin on install. Full detail: `references/auto-ingestion.md`.

## MTA-STS System Plugin (`plg_system_dmarcmtasts`)

A `system`-group plugin that serves the generated MTA-STS policy (RFC 8461) dynamically at
`/.well-known/mta-sts.txt`, so the published policy always matches the component options instead of a
hand-maintained static file. It reads the component configuration and renders the body via the
component's `MtaStsPolicy` helper. The one step it cannot do is DNS/host-level: the policy must be
reachable at `https://mta-sts.<domain>/.well-known/mta-sts.txt` with valid TLS, which the component
spells out to the administrator. Classes: `src/Extension/DmarcMtaSts.php`,
`src/Support/PolicyRequest.php`. Full detail: `references/mta-sts-tls-rpt.md`.

## Package (`pkg_dmarcreports`)

A `type="package"` manifest that bundles the component, module, task plugin and system plugin into one
install/update/uninstall (`pkgdmarcreports-v*`). It holds no code or tables; each bundled extension
migrates its own tables, so package updates never lose collected data. Its `build.sh` builds every
sub-extension with that extension's own `build.sh`, stages the archives under `packages/` with the
stable names the manifest declares, then zips and verifies the tree. Full detail:
`pkg_dmarcreports/README.md` and the `joomla-extensions` skill.

## Conventions

Follows the repo-wide rules — see the `joomla-extensions` skill (build/test/release, manifest,
`update.xml`, language-file lint, GPL headers). Repo-wide Git/commit/license rules live in
`/AGENTS.md`. Do not duplicate those here. In particular: **no `Co-authored-by` trailer and no agent
signature (no Copilot/Ona/etc.) in commits, PR titles or bodies.**

- Joomla 5.4+ / PHP 8.1+; Joomla 6 DB via `DatabaseInterface` + a `createQuery()`/`getQuery(true)` helper
- No J2Commerce dependency; admin-only, behind ACL
- Native socket IMAP client is kept on purpose (`ext-imap` is removed in PHP 8.4 / Joomla 6 CI)
- The task and system plugins reuse the component's helpers — never fork or duplicate the ingestion, locking or policy logic
- Versions (`VERSION`, manifest, `update.xml`) are owned by the release workflow — never set by hand

See `references/architecture.md` for the MVC wiring and data model, `references/dmarc-domain.md`
for the RFC 7489 / policy-rollout background, `references/ingestion.md` for the IMAP client and
parser, `references/action-center.md` for the recommendation logic, `references/testing.md`
for the test suites and validation gates, `references/module.md` for the companion backend
dashboard module `mod_dmarcreports`, `references/auto-ingestion.md` for the scheduler task plugin
and trigger model, and `references/mta-sts-tls-rpt.md` for MTA-STS policy hosting/validation and
TLS-RPT ingestion.
