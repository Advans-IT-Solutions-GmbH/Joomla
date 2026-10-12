---
name: dmarc-reports
description: Deep knowledge of the com_dmarcreports administrator component. Use when working on DMARC aggregate-report ingestion (IMAP or file upload), the RFC 7489 XML parser, the data model, the dashboard metrics, or the Action Center recommendation logic.
triggers:
  - dmarc
  - com_dmarcreports
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
references:
  - references/architecture.md
  - references/dmarc-domain.md
  - references/ingestion.md
  - references/action-center.md
  - references/testing.md
---

# DMARC Reports — Domain Knowledge

## Location

`com_dmarcreports/` (repository root, same level as `plg_ajax_joomlaajaxforms`).

## What This Component Does

A redistributable, administrator-only Joomla component that turns raw DMARC aggregate
reports (RFC 7489) into a readable dashboard and actionable guidance. Everything runs
inside the Joomla install — no external service, Python, Elasticsearch or cloud; no
report data leaves the host.

- Multi-domain / multi-tenant: several monitored domains, each with its own report source
- Ingests reports on demand over IMAP, or by manual upload of `.xml`, `.gz` or `.zip`
- Native PHP RFC 7489 parser; idempotent storage de-duplicated by reporter org + report id
- Dashboard: per-domain volume, SPF/DKIM pass rates, would-be-rejected share, top IPs, timeline
- **Action Center**: prioritised, data-driven next steps along `none → quarantine → reject`
- DNS diagnostics for SPF/DMARC records; CSV export; system check for the IMAP transport
- Mailbox password encrypted at rest (Joomla secret); CSRF tokens, ACL, prepared statements

## Data Model

Five `#__dmarc_*` tables (install/uninstall SQL in `administrator/components/com_dmarcreports/sql/`):

| Table | Holds |
|-------|-------|
| `#__dmarc_domains` | Monitored domains (`domain`, `expected_policy`, notes) |
| `#__dmarc_sources` | Mailbox config per domain; password in `password_enc` |
| `#__dmarc_reports` | One row per aggregate report; `report_hash` (unique) = dedupe key |
| `#__dmarc_records` | One row per report record (source IP, count, disposition, SPF/DKIM result + alignment, header_from) |
| `#__dmarc_actions` | Persisted Action Center items (`action_key`, severity, status, snooze) |

## Key Classes

| Class (`src/Helper/` unless noted) | Purpose |
|------|---------|
| `DmarcParser` | Parse aggregate-report XML into report + record rows |
| `ReportArchive` / `MimeMessage` | Unpack `.gz`/`.zip`, extract report attachments from a raw message |
| `ImapClient` | Streaming IMAP transport (`process()` callback, one message at a time) |
| `Ingestor` | Orchestrates parse + idempotent store; `ingestMessage()` / `ingestMessages()` |
| `CryptoHelper` | Encrypt/decrypt the mailbox password with the Joomla secret |
| `ActionCenter` / `ActionStatus` / `ActionPresenter` | Derive, persist and render recommendations |
| `Metrics` | Per-domain volume / pass-rate / would-be-rejected calculations |
| `DnsDiagnostics` / `SystemDnsResolver` / `DnsResolverInterface` | SPF/DMARC record checks |
| `EnvironmentCheck` | Whether the IMAP transport is available (system check view) |

Controllers (`src/Controller/`): `Display`, `Ingest`, `Export`, `Actions`, `Domain(s)`, `Source(s)`.

## Conventions

Follows the repo-wide rules — see the `joomla-extensions` skill (build/test/release, manifest,
`update.xml`, language-file lint, GPL headers). Repo-wide Git/commit/license rules live in
`/AGENTS.md`. Do not duplicate those here.

- Joomla 5.4+ / PHP 8.1+; Joomla 6 DB via `DatabaseInterface` + a `createQuery()`/`getQuery(true)` helper
- No J2Commerce dependency; admin-only, behind ACL
- Versions (`VERSION`, manifest, `update.xml`) are owned by the release workflow — never set by hand

See `references/architecture.md` for the MVC wiring and data model, `references/dmarc-domain.md`
for the RFC 7489 / policy-rollout background, `references/ingestion.md` for the IMAP client and
parser, `references/action-center.md` for the recommendation logic, and `references/testing.md`
for the test suites and validation gates.
