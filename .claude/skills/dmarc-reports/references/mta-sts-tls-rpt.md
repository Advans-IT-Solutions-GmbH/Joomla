# MTA-STS (RFC 8461) & TLS-RPT (RFC 8460)

Two related SMTP-security features built on top of the DMARC solution. MTA-STS lets a domain publish a
policy that requires inbound senders to use TLS with a valid certificate; TLS-RPT lets senders mail
back JSON reports about the TLS outcome. Both are surfaced in `com_dmarcreports`, with MTA-STS policy
hosting delegated to `plg_system_dmarcmtasts`.

## Configuration (component options)

In **Components → DMARC Reports → Options → MTA-STS** (`config.xml` fieldset `mtasts`):

| Key | Meaning |
|-----|---------|
| `mtasts_enable` | Master switch for policy generation and hosting |
| `mtasts_mode` | `testing`, `enforce` or `none` (roll out `testing → enforce`) |
| `mtasts_mx` | The receiving MX hostnames the policy permits (one per line) |
| `mtasts_max_age` | Policy `max_age` in seconds (default `604800`) |
| `tlsrpt_rua` | TLS-RPT reporting address for the `_smtp._tls` record |

All three locales carry the matching `COM_DMARCREPORTS_CONFIG_MTASTS_*` keys.

## Generation — `MtaStsPolicy` (component helper)

`src/Helper/MtaStsPolicy.php` is Joomla-light and static. `generate(Registry $params, string $domain)`
returns everything the UI and the system plugin need:

- `enabled`, `domain`, `mode`, `max_age`, `mx`
- `body` — the policy document text (RFC 8461 §3.2)
- `id` — the policy id (used in the `_mta-sts` record and for drift detection)
- `policy_host`, `well_known_url` — where the policy must be reachable
- `sts_record_host` (`_mta-sts.<domain>`), `sts_record` (`v=STSv1; id=…`)
- `tlsrpt_record_host` (`_smtp._tls.<domain>`), `tlsrpt_record` (`v=TLSRPTv1; rua=…`)

The component **never writes DNS**. It renders the records in the Action Center and on the domain
detail view so the administrator can publish them, and names the one step it cannot do: publish the
`mta-sts.<domain>` host with valid TLS.

## Hosting — `plg_system_dmarcmtasts`

A `system`-group plugin (element `dmarcmtasts`, tag prefix `system-dmarcmtasts-v*`) that serves the
policy dynamically instead of a hand-maintained static file.

- `src/Support/PolicyRequest.php` — pure matcher. `domainFor($host, $path)` returns the base domain
  when the request is for `…/.well-known/mta-sts.txt` on an `mta-sts.` host (suffix match on the path
  so a sub-directory install still works), else `null`. Constants `WELL_KNOWN_PATH`, `HOST_PREFIX`.
- `src/Extension/DmarcMtaSts.php` — subscribes to `onAfterInitialise`. On a **site** request it
  resolves the domain via `PolicyRequest`, reads `com_dmarcreports` params, checks `mtasts_enable`,
  builds the body with `MtaStsPolicy::generate()` and, when enabled and non-empty, sends
  `Content-Type: text/plain` + a one-hour `Cache-Control` and echoes the body, then closes the app.
  It does nothing (no output, no error) for any other request.

The policy therefore always matches the component options — change the mode or MX list in the options
and the served `mta-sts.txt` changes with it.

The web server must hand the exact path to `index.php`. Joomla's generic fallback only works with
`htaccess.txt` active as `.htaccess` and only for paths that are not existing files, so the documented
setup is one dedicated rule placed after `RewriteEngine On`:
`RewriteCond %{QUERY_STRING} ^$` followed by `RewriteRule ^\.well-known/mta-sts\.txt$ index.php [L]` (the condition keeps a query from reaching Joomla past a site's `?option=` rules). It is documented in the plugin README
(section "Web-server rule"); `pkg_dmarcreports/tests/scripts/06-mta-sts.php` installs the same rule,
puts a stale static `mta-sts.txt` in place that must not be served, and requests the exact RFC path
(never an `index.php/…` route), so keep the README and the test identical.

## Validation — `MtaStsDiagnostics` (system check / Action Center)

`src/Helper/MtaStsDiagnostics.php` checks that what is published matches what is generated.
`inspect(array $generated, bool $withNetwork)` returns `{enabled, generated, findings:[…]}`, each
finding `{code, severity, category:'mtasts', params}`. Network lookups are gated on the component
param `dns_diagnostics` and use the existing `DnsResolverInterface` plus a `PolicyFetcherInterface`
(`HttpPolicyFetcher`) — both are interfaces so tests inject fakes.

Finding codes: `mtasts_no_mx`, `mtasts_publish`, `mtasts_lookup_error`, `mtasts_record_drift`,
`mtasts_policy_unreachable`, `mtasts_policy_drift`, `mtasts_mx_lookup_error`, `mtasts_mx_no_answer`,
`mtasts_mx_uncovered`, `mtasts_mode_testing`, `tlsrpt_publish`, `tlsrpt_missing_rua`. These
cover: no MX configured, record/policy not yet published, lookup failure, the `_mta-sts` id drifting
from the generated id, the hosted policy unreachable or inconsistent, the roll-out still in `testing`,
and the TLS-RPT record missing.

With network diagnostics enabled, the resolver also reads the receiving domain's actual MX targets.
`MtaStsPolicy::matchesMx()` applies RFC 8461 section 4.1 exact/single-label wildcard matching.
The shared configured MX list may be a union across monitored domains; coverage is checked for each
domain independently, even before its STS record is published. Uncovered targets warn (severity 3
in enforce, otherwise 2); resolver failure (`null`) and no usable targets (`[]`, including null MX)
are explicit unverified findings. Enforce advice is emitted only after MX coverage and the hosted
policy/record checks pass. SMTP STARTTLS and MX certificates still need separate verification.
Domain detail/its Action Center and System Check reuse this path; the main dashboard/module stay
network-free and their publish guidance points administrators at the live checks.

## TLS-RPT ingestion

TLS-RPT reports arrive the same way as DMARC `rua` — as e-mail (IMAP) or upload — but as JSON,
optionally gzipped. The pipeline routes by content:

- `src/Helper/Ingestor.php` detects DMARC XML vs TLS-RPT JSON and dispatches accordingly.
- `src/Helper/TlsRptParser.php` — Joomla-free, static RFC 8460 parser. `parse()` throws
  `InvalidArgumentException` with codes `ERROR_EMPTY(1)` / `ERROR_JSON(2)` / `ERROR_NOT_REPORT(4)`;
  `fingerprint()` is a length-prefixed sha256 dedupe key; domains via `DomainName::canonicalize`.
- `src/Helper/TlsReportArchive.php` — gzip-aware unpacking of the JSON payload.
- `src/Helper/TlsRptIngestor.php` — idempotent store into the dedicated tables.
- `src/Helper/DomainRegistry.php` — resolves/attaches the domain.

Storage is kept **separate** from the DMARC aggregate data (added by
`sql/updates/mysql/1.1.0.sql` with `CREATE TABLE IF NOT EXISTS`, so updates lose no DMARC data):

| Table | Holds |
|-------|-------|
| `#__dmarc_tlsrpt_reports` | One row per TLS-RPT report; fingerprint (unique) = dedupe key |
| `#__dmarc_tlsrpt_policies` | One policy block per row (policy type, policy domain, success/failure session counts) |
| `#__dmarc_tlsrpt_failures` | One failure detail per row (result type, receiving MX, sending MTA IP, sessions) |

## Surfacing

- `Metrics::tlsRptSummary(int $domainId)` returns `{reports, success, failure, failures:[…]}`,
  degrading to zeroes/empty when there are no reports.
- `DetailModel::getTlsRptSummary()` + a TLS reporting card on the domain detail view
  (`tmpl/detail/default.php`), alongside the MTA-STS records card.
- All strings are language keys (`DETAIL_MTASTS_*`, `DETAIL_TLSRPT_*`, `ACTION_*`) in all three
  locales.

## Tests

- Component PHPUnit `Unit/`: `MtaStsPolicyTest` (static builders), `MtaStsDiagnosticsTest` (injected
  fake resolver + fetcher, all finding codes), `TlsRptParserTest` (valid/invalid JSON, fingerprint).
- `plg_system_dmarcmtasts/tests/`: `PolicyRequest` matching unit tests plus integration suites for
  policy output (correct body for enabled/disabled, not-found for other requests) on J5 and J6.
- Integration: MTA-STS record generation + system-check validation, TLS-RPT parsing + dashboard
  rendering.
