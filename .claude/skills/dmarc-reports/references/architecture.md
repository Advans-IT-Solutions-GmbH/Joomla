# Architecture

Standard Joomla 5/6 administrator MVC component, namespace `Advans\Component\DmarcReports`
(`administrator/components/com_dmarcreports/`). Admin-only; no site part is shipped.

## Wiring

- `services/provider.php` — DI service provider (registers the component dispatcher factory and
  the MVC factory; no router, as an admin-only component needs none).
  Does not pass a dispatcher in constructors or call `setDispatcher()` (Joomla sets it on load).
- `src/Extension/` — the component entry/boot class.
- `forms/domain.xml`, `forms/source.xml` — JForm definitions for the domain and source editors.
- `access.xml` — ACL actions; component options live inline in `com_dmarcreports.xml` `<config>`.
- `src/Rule/RangeRule.php` — a server-side `FormRule` (`validate="range"`, prefix declared with
  `addruleprefix` on `config.xml` and `forms/source.xml`) that re-checks numeric bounds the HTML5
  `min`/`max` only enforce in the browser. It backs the config thresholds/timeouts/limits and the
  source port, so an out-of-range or non-numeric value posted directly is rejected before storage.
- Table `check()` methods add the data-level guards a form rule cannot express: `DomainTable`
  rejects an over-long note and a duplicate domain with a localized message instead of a raw SQL
  error; `SourceTable` keeps a port backstop.
- `src/Helper/ImapException.php` — carries a coarse category (`connection`/`tls`/`login`/`config`)
  that `IngestController` maps to a localized reason. Only the fixed category text is shown; the
  exception message (which could hold connection detail) is never surfaced, so a credential can
  never leak into a flash message, run status or log.

## Views

| View | Shows |
|------|-------|
| `Dashboard` | Overview of all domains + the Action Center |
| `Detail` | Per-domain drill-down: top IPs, pass/fail breakdown, timeline |
| `Domains` / `Domain` | List + edit of monitored domains |
| `Sources` / `Source` | List + edit of mailbox report sources |
| `Systemcheck` | Whether the IMAP transport is available (`EnvironmentCheck`) |

Each view has a model in `src/Model/` and a layout folder in `tmpl/`. `DashboardModel` and
`DetailModel` aggregate via `Metrics`; `SourceModel` handles the encrypted password field.

## Data model

Five `#__dmarc_*` tables (see the SKILL table). Created by the manifest install SQL and dropped
by the uninstall SQL — `administrator/components/com_dmarcreports/sql/{install,uninstall}.mysql.utf8.sql`.
Both are declared in the manifest `<install>`/`<uninstall>` `<sql>` blocks. Tables use the
`#__` prefix placeholder and `utf8mb4`.

- `report_hash` on `#__dmarc_reports` is the unique dedupe key (policy domain + reporter org +
  report id + report-window start). The policy domain keeps reports that a reporter sends for
  different domains under one reused report id distinct across a multi-domain install.
- `#__dmarc_records.count` is the message count for that row; metrics weight by it. Message counts
  are `BIGINT UNSIGNED` and the report date range (`date_begin`/`date_end`, Unix timestamps) is
  `BIGINT`, so high-volume rows do not overflow and the timeline is not capped at the 2038 limit of
  a signed 32-bit `INT`. The dashboard timeline groups by UTC calendar day
  (`FLOOR(date_begin / 86400)`, portable across MySQL and PostgreSQL), so several reporters covering
  the same day collapse into one point and the 90-row limit means 90 distinct days, not 90 reports.
- `#__dmarc_actions` persists Action Center items so status (open/done/snoozed) survives re-ingestion.

The manifest also declares an `<update><schemas>` path (`sql/updates/mysql`) with a baseline
`1.0.0.sql` (no-op), so `#__schemas` gets a row for the extension and future schema migrations have
a baseline to run from. The schema version sequence is independent of the extension version that
the release CI bumps.

## Database API (Joomla 5 vs 6)

Resolve the driver through `DatabaseInterface`, not `get('DatabaseDriver')`. Build queries with
the helper that calls `$db->createQuery()` on Joomla 6 and `$db->getQuery(true)` on Joomla 5.4
(which has no `createQuery()` on the interface). Use `$query->setLimit()` / `$query->bind()` —
the deprecated-API scan rejects the removed `setQuery($q, $offset, $limit)` and `getQuery(true)`
call shapes. Prepared statements / bound parameters only.

## Security

- Mailbox password encrypted at rest via `CryptoHelper` (Joomla secret); never logged, never
  round-tripped to the browser. `SourceModel`/`SourceController` strip it from re-displayed form state.
- Every admin write action checks a CSRF token and the component ACL.
- The default `DisplayController` also authorises the guessable `view=domain`/`view=source` edit
  views so a direct URL cannot reach an editable form and read mailbox configuration or domain
  notes, bypassing the task controllers' own ACL checks. The check is split by record state: an
  existing record (`id > 0`) exposes stored configuration and requires `core.edit`, while only a
  blank/new form (no `id`) is reachable with `core.create` alone — a single OR of both privileges
  would otherwise let a create-only operator open an existing source and read its mailbox settings.
  Any failure returns HTTP 403.
- The component options expose a standard `permissions` fieldset (a `rules` field over `access.xml`)
  so the custom `dmarc.ingest` action — and the core actions — can be granted to non-Super-Users
  through Joomla's component permissions UI.
- Manual upload and IMAP data are treated as untrusted input; the parser uses safe XML loading.
