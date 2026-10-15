# Manual acceptance test plan for the DMARC Reports package

This reusable plan covers **human functional and acceptance testing of the entire DMARC Reports
solution**: the `com_dmarcreports` component, the `mod_dmarcreports` dashboard module, the
`plg_task_dmarcreports` auto-ingestion task plugin, the `plg_system_dmarcmtasts` MTA-STS system
plugin and the `pkg_dmarcreports` package that bundles the four. It covers positive and negative
cases, boundaries, permissions, failure handling and integration with real IMAP mailboxes and DNS.

**Initial scope reference:** the `pkg_dmarcreports` package and its four bundled extensions as
described in the component, module and plugin READMEs and the `dmarc-reports` skill. This is a
reference for the plan's scope, not an assertion about the version currently under test. Record the
actual tested commit, package and environment in every execution report.

**Execution status:** this document records no completed manual tests. Automated checks do not
replace human acceptance. Installation, database inspection, IMAP ingestion and controlled fault
injection require a technically experienced tester.

The plan contains **194 identified test cases**, plus the end-to-end acceptance scenario in
section 17. Use the [blank report template](report-template.md) for each execution.

## Publishing and storage

`tests/manual/` follows the repository's per-extension test layout. The shared packager excludes
`tests`, so this plan and its blank report template are not installed with any extension.

A generic plan with synthetic fixtures and reproducible expectations belongs in the public
repository. **Completed reports, raw DMARC and TLS-RPT report files, mailbox credentials, the
Joomla secret, screenshots, database dumps and other evidence belong in access-controlled
storage**, not in this directory or public issues. Public bug reports must be redacted and contain
no real domains, mailbox hostnames, IP addresses, credentials or report contents. A blank template
is not permission to publish its completed contents.

## 1. Test rules and recording results

### 1.1 Test installations only

- Never use a production installation.
- Use only synthetic domains, synthetic IP addresses and controlled test mailboxes.
- Fetch reports from a controlled IMAP mailbox you own, never a live reporting mailbox.
- Run privacy and uninstall tests only with disposable test data.
- Treat mailbox passwords, the Joomla secret and the stored `password_enc` value as passwords.
  Never include them in public bug reports or screenshots.
- Create a restorable snapshot before update, deletion and uninstall tests.
- Tests requiring crafted DNS answers, SSRF targets or a controlled clock must run in a
  **dedicated test VM or isolated container**. Never point a shared server at attacker-chosen hosts
  and never change the clock of a shared computer or server.

### 1.2 Test types

Slash-separated codes mean that every listed aspect must be exercised, not that the tester may
choose one.

| Code | Meaning |
|---|---|
| POS | Valid input or permitted operation |
| NEG | Invalid input or prohibited operation |
| GRZ | Boundary or special value case |
| KON | Concurrent operations |
| FEH | Deliberately induced, controlled technical fault |
| INT | Integration with Joomla, an IMAP server, DNS or a web server |

### 1.3 Result for each test case

Record the following for every case:

| Field | Entry |
|---|---|
| Test case | For example, `IN-01` |
| Build | Actual tested commit and installation package |
| Environment | Joomla, PHP, database and, where applicable, mailbox, DNS resolver and browser |
| Initial state | Domains, sources, configuration and synthetic report fixtures used |
| Actual result | Observations, not just "works" |
| Result | **PASS**, **FAIL**, **BLOCKED** or **NOT RUN** |
| Evidence | Screenshot, redacted HTTP excerpt, raw report fixture or database inspection, stored privately |
| Defect reference | For FAIL, an unambiguous link to the defect report |
| Execution | Tester and date |

**Important:** a blocked or unexecuted case has not passed. For a NEG case, a technically correct
refusal is the expected outcome.

## 2. Environments and test data

### 2.1 Environment matrix

Not every combination requires a complete repeat of the test run.

| Environment | Scope |
|---|---|
| Latest Joomla 5.4.x with supported PHP and MySQL | Complete functional run |
| Latest Joomla 6.x with supported PHP and MySQL | Complete functional run |
| Joomla 6 with MariaDB | Installation, migration, parsing, storage, metrics, TLS-RPT, locking, ACL, privacy and uninstall |
| Chromium-based desktop browser | Main administration run |
| Firefox | Forms, upload, CSV download and the Options screen |
| Narrow screen / 200% zoom | Dashboard, detail, Action Center and forms remain usable |
| German, English and French | Inspect every backend view and the module; at least one complete ingest-and-act flow per language |
| Controlled IMAP mailbox (SSL and STARTTLS) | On-demand fetch, auto-ingestion, cursor and deletion behaviour; record the server |
| Isolated DNS / SSRF fixtures | DNS diagnostics, MTA-STS validation and the SSRF negative cases |

Record any version change during acceptance. After a code fix, rerun at least the affected case
and adjacent regression cases.

### 2.1.1 Mandatory coverage and conditional repetitions

The default mandatory set is **all 194 identified cases**, plus all ten steps of section 17, on
both Joomla/MySQL full-run environments above. Apply the narrower browser, screen and language
repetitions specified in the matrix in addition to those full runs.

On Joomla 6/MariaDB, the mandatory repeat set is `IN-01`–`IN-14`, `PA-01`–`PA-14`,
`ME-01`–`ME-12`, `TR-01`–`TR-10`, `AI-05`–`AI-08`, `RE-01`–`RE-10`, `PR-01`–`PR-08` and
section 17. Other cases do not require an additional MariaDB repetition; they remain mandatory in
both MySQL full runs. Cases requiring isolated DNS/SSRF or controlled-clock fixtures use separate
isolated installations, with their results linked to the relevant run.

The **only conditional exception** is the live, public DNS-and-TLS publication validation: the
`MS-11` step that actually publishes the generated `_mta-sts`/`_smtp._tls` TXT records and hosts
`mta-sts.<domain>` with valid TLS, and the parts of `SY-04`–`SY-08` and `MS-10` that validate
against a real public resolver. Activate this only if the tester controls a real test domain they
may publish records on. Record that decision before execution, with the domain used (redacted in
any public report) and the reason; do not change it afterwards to hide a failure. It never exempts
a case from the default mandatory set.

If that live-DNS condition is false, record the live-publication repetitions as **NOT RUN** with
`condition inactive` and the reason, and exercise the same logic against the injected fake resolver
and policy fetcher (the diagnostics accept both through their interfaces) and the isolated SSRF
fixtures — those injected and isolated runs are **mandatory** and are not covered by the exception.
For all other mandatory executions, BLOCKED/NOT RUN also blocks acceptance; a passed CI job or
missing equipment is not a substitute. Report an observed FAIL as FAIL and assess it under
section 18, never as an inactive condition.

Package install/update/uninstall with `blockChildUninstall`, the TLS-RPT table migration, both
databases, both ingestion inputs (IMAP and upload), the SSRF negative cases against isolated
fixtures, the MTA-STS endpoint served through the documented web-server rule, and the auto-ingestion
task driving the component cannot be opted out of for lack of configuration, access or equipment.
Prepare the isolated fixtures or record BLOCKED; no other optional case set is implied.

**IMAP is not exercised by CI** (there is no IMAP server in the pipeline), so the IMAP cases
(`IM-01`–`IM-12`) and the auto-ingestion cases that drive real sources must be run here against a
controlled test mailbox — they are mandatory manual acceptance, not covered elsewhere.

### 2.2 Users

| Identifier | Role |
|---|---|
| `ADMIN` | Joomla Super User |
| `MANAGER` | Full component operator: `core.manage`, `core.create`, `core.edit`, `core.edit.state`, `core.delete`, `dmarc.ingest` |
| `INGEST` | Ingest-only operator: `core.manage` and `dmarc.ingest` but **not** `core.create` |
| `EDITOR` | `core.edit` only; explicitly denied `core.create`, `core.delete` and `core.edit.state` |
| `OPTIONS-DENIED` | Everything except `core.options` |
| `READONLY` | `core.manage` only; may read permitted views but may not change anything |
| `GUEST` | Unauthenticated visitor / non-administrator |

First **configure and verify permissions in Joomla**. Distinguish inherited permissions from
explicit denials.

### 2.3 Baseline configuration

**Component options (`Components → DMARC Reports → Options`):**

- Default policy target: `reject`.
- Quarantine pass threshold: `95`.
- Reject pass threshold: `99`.
- IMAP timeout: `20` seconds.
- Max messages per run: `200`.
- CSV delimiter: `semicolon`.
- DNS diagnostics: `Off`.
- MTA-STS: `Off`; TLS-RPT: `Off`.

**Task parameter (`System → Scheduled Tasks → DMARC Reports: Ingest`):**

- Automatic domain creation on run (`auto_create_domains`): `Off`.

**Monitored domains:**

| Identifier | Purpose |
|---|---|
| `D-A` | `example.org`, expected policy `reject`, published |
| `D-B` | `sub.example.org`, a subdomain used for forced-binding and `sp` tests |
| `D-C` | A second registrable domain, left unpublished |
| `D-PS` | A public suffix such as `co.uk`, used only for negative binding cases |

**Report sources:**

| Identifier | Purpose |
|---|---|
| `S-A` | Bound to `D-A`; SSL IMAP against the controlled test mailbox |
| `S-TLS` | STARTTLS variant against the same mailbox |
| `S-AUTO` | Unbound (`domain_id = 0`); used for auto-discovery and upload-provenance tests |

**Synthetic fixtures (build by hand; never use real report data):**

| Fixture | Content |
|---|---|
| `R-NONE` | Aggregate report, `p=none`, aligned-pass rate below the quarantine threshold |
| `R-QUAR` | `p=none`, aligned-pass rate above the quarantine threshold |
| `R-REJECT` | `p=reject`, `pct=100`, fully aligned |
| `R-PARTIAL` | `p=reject`, `pct<100` |
| `R-FAIL` | Contains an unaligned sender below the top-20 by volume |
| `R-NEW` | Introduces a sending IP absent from every earlier report |
| `R-BAD-*` | Deliberately malformed variants (unknown `p`, out-of-range `pct`, inverted range, far-future `date_end`, missing/invalid `source_ip`, overflowing `count`, DOCTYPE/XXE) |
| `T-OK` / `T-FAIL` | TLS-RPT JSON reports (plain and gzipped) with success-only and failure details |

Choose synthetic domains, reporter orgs and IP addresses only. **Do not reuse an already ingested
fixture** when a case requires an empty domain.

## 3. Installation, update and removal

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| IN-01 | POS | Install the package ZIP built from the tested commit through Joomla's extension installer. | No PHP/SQL error; the component appears in the menu; an empty dashboard is usable. |
| IN-02 | POS | Open extension, module and plugin management. | Component, the `mod_dmarcreports` administrator module, the `task` plugin and the `system` plugin are registered; the task plugin is enabled on install. |
| IN-03 | POS | Inspect installed administrator, module and plugin files. | Required files and each `LICENSE.txt` exist; no development dependencies (`composer.json`, `tests/`, `phpunit.xml`) in the distributed package. |
| IN-04 | POS | Create a domain, a source with an encrypted password and ingest one report. Reinstall the same package over it. | Existing domains, sources, encrypted credentials and reports remain; exactly one registration per bundled extension (no duplicates). |
| IN-05 | POS | Install an older baseline without the TLS-RPT tables, seed DMARC data, then update to the tested version. | `sql/updates/mysql/1.1.0.sql` adds the `#__dmarc_tlsrpt_*` tables with `CREATE TABLE IF NOT EXISTS`; existing DMARC data is intact. |
| IN-06 | NEG | With the package installed, attempt to uninstall the component, module or a bundled plugin individually. | `blockChildUninstall` refuses the child uninstall; removal is only through the package. |
| IN-07 | NEG | Attempt installation on a separate, explicitly unsupported Joomla version. | Understandable refusal instead of a partially installed package. |
| IN-08 | NEG | Attempt installation against a non-MySQL/MariaDB database server. | Understandable refusal; the schema ships for MySQL/MariaDB only. |
| IN-09 | POS | Disable the task and system plugins deliberately, then update. | Update does not unexpectedly re-enable deliberately disabled plugins. |
| IN-10 | POS | Inspect the five `#__dmarc_*` and three `#__dmarc_tlsrpt_*` tables, keys and unique indexes on both MySQL and MariaDB. | All tables and keys exist as declared; an installation success message alone is insufficient. |
| IN-11 | POS | At the end, uninstall the package on a disposable copy. | Component, module and plugin registrations and every `#__dmarc_*`/`#__dmarc_tlsrpt_*` table are removed; other extensions are unchanged. |
| IN-12 | POS | Reinstall after uninstall. | Clean start without previous domains, sources, reports or update-site drift. |
| IN-13 | POS | Install the component standalone (not the package), then install the package over it. | Single registration per extension; afterwards the package keeps exactly its own update site and the standalone update site is gone. |
| IN-14 | INT | Install, reinstall and update through the Joomla CLI in en-GB, de-DE and fr-FR. | No untranslated key, `[ERROR]`, `[WARNING]` or `[CAUTION]`; zero exit code. |

**Technical inspection:** verify the actual tables and unique indexes on both MySQL and MariaDB,
and confirm that an update never drops a previously populated `#__dmarc_*` table.

## 4. Domains and report sources

### 4.1 Domains

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| DO-01 | POS | Create `D-A` with domain, title, expected policy and notes; save, close and reopen. | All values persist unchanged. |
| DO-02 | NEG | Save a second domain with the same name. | Understandable refusal (unique key); the existing domain is not overwritten. |
| DO-03 | NEG | Submit an empty required domain, an over-long note and an invalid `expected_policy` through a manipulated POST. | Server-side validation; submitted values retained for correction; no raw SQL error. |
| DO-04 | POS | Toggle the `published` flag. | State stored; the MTA-STS domain gate (section 9) follows it. |
| DO-05 | POS | Change `expected_policy` through `none`, `quarantine` and `reject`. | The Action Center rollout target for the domain changes accordingly. |
| DO-06 | POS | Delete an unused domain with no reports. | Domain removed; other domains unchanged. |
| DO-07 | GRZ | Delete a domain that has stored reports and a bound source. | Behaviour follows the documented rule (refusal or defined cascade); never an orphan report or source pointing at a nonexistent domain. |
| DO-08 | POS | Create several domains and ingest for each (multi-tenant). | Each domain is isolated; the dashboard lists each separately with its own metrics. |
| DO-09 | NEG | As a user with `core.manage` but not `core.create`, attempt to create a domain, including by direct POST. | Creation refused. |
| DO-10 | GRZ | Create a domain at the 190-character column limit and with an internationalised (Unicode) label. | Stored canonicalised; the dashboard and detail views stay consistent; no overflow. |

### 4.2 Report sources

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| SR-01 | POS | Create `S-A` with host, port, encryption, username, password and folder; save, close and reopen. | All fields persist; the password is stored encrypted in `password_enc`, never in plaintext. |
| SR-02 | POS | Bind `S-A` to `D-A`; leave `S-AUTO` unbound (`domain_id = 0`); toggle `delete_after_import`. | Binding and the delete-after-import switch persist. |
| SR-03 | NEG | Submit port `0`, `70000` and `abc`. | Localized range error; no raw SQL error; no row written. |
| SR-04 | NEG | Submit an empty required host, username or folder. | Server-side refusal. |
| SR-05 | NEG | Submit a `security` value other than `ssl`/`tls` through a manipulated POST. | Rejected server-side; no cleartext session is ever opened. |
| SR-06 | POS | Edit an identity field (`host`, `port`, `security`, `username`, `folder`) on a source that already has a cursor. | The stored cursor (`uidvalidity`, `last_uid`, `deferred_uids`) is reset via `SourceIdentity`, so a stale high-water UID cannot survive the edit. |
| SR-07 | POS | Edit a source leaving the password field blank. | The existing encrypted password is retained, not wiped. |
| SR-08 | POS | Inspect the database, logs and `last_message` after saving and a failed run. | The password never appears in plaintext, in a log or in `last_message`. |
| SR-09 | POS | Unpublish a source, then run an on-demand poll and the task. | The unpublished source is skipped by both paths (`activeSources()`); publishing it again restores processing. |
| SR-10 | POS | Delete a source that has ingested reports. | Source removed; its DMARC and TLS-RPT reports are preserved but their `source_id` is reset to `0` by `ReportProvenance::unbindSources()`, leaving no dangling source reference. |
| SR-11 | NEG | As `READONLY` or `INGEST`, create, edit or delete a source, including by direct POST. | Refused; no row written or changed. |
| SR-12 | GRZ | With forced binding active (ingest as `INGEST` on demand, or through a task whose auto-creation is off, so `SourcePoller::forcedDomainId()` returns the bound domain), feed a source bound to a registrable domain distinct reports for the exact domain, a subdomain, the parent and a sibling; repeat with the source bound to the public suffix `D-PS`. | Only the bound domain or a subdomain of it is accepted (exact-or-child, no PSL, so `D-PS` is matched as an ordinary label); the parent, sibling and unrelated domain are counted skipped, never resolved independently. |

## 5. Report ingestion: upload and IMAP

Two input paths produce the same stored result. Both feed `Ingestor`, which parses and stores
idempotently (dedupe by `report_hash`).

### 5.1 File upload

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| UP-01 | POS | Upload a valid `.xml` aggregate report (`R-REJECT`). | One report plus its record rows stored; the dashboard reflects them; the run summary counts them as new. |
| UP-02 | POS | Upload the same report as `.gz`. | The inner XML is decoded; dedupe makes the second import a no-op (duplicate), no new records. |
| UP-03 | POS | Upload a `.zip` containing several `.xml` entries, including more than `MAX_DOCUMENTS` (256). | Up to the cap parsed; the surplus is counted (`dropped`) and surfaced in the run summary, not silently discarded. |
| UP-04 | NEG | Upload an empty file. | Nothing stored; the summary reason is `empty` (`COM_DMARCREPORTS_UPLOAD_REASON_*`). |
| UP-05 | NEG | Upload a well-formed XML that is not a DMARC aggregate report. | Nothing stored; reason `format`. |
| UP-06 | NEG | Upload corrupt XML and XML with a declared `DOCTYPE` (the XXE class). | Nothing stored; reason `malformed`; no external entity is expanded. |
| UP-07 | NEG | Upload an oversized or over-expanding archive (gzip/zip bomb). | Bounded; nothing stored; reason `bomb`. |
| UP-08 | FEH | On a host lacking `gzdecode`/`ZipArchive`, upload a `.gz`/`.zip`. | `COM_DMARCREPORTS_INGEST_DECODER_UNAVAILABLE` names the concrete missing extension; nothing is silently lost. |
| UP-09 | NEG | Upload with a crafted `source_id` in the POST. | Ignored; upload is unbound and stores `source_id = 0`; provenance cannot be attacker-set. |
| UP-10 | NEG | Upload without a valid CSRF token, or as a user lacking `dmarc.ingest`. | Refused; no report stored. `IngestController::authorise()` requires only `dmarc.ingest`; `core.create` governs unknown-domain creation (UP-11), not upload permission. |
| UP-11 | GRZ | As `INGEST` (no `core.create`), upload a report whose policy domain is not yet monitored. | The report is counted skipped; no domain is auto-created; nothing is stored under `domain_id = 0`. |
| UP-12 | POS | Re-upload a previously stored report. | Idempotent no-op; no duplicate report or records. |

### 5.2 IMAP fetch

Run against a controlled test mailbox. IMAP is not exercised by CI.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| IM-01 | POS | Trigger an on-demand fetch from `S-A` (SSL) with several unread reports. | Messages are fetched oldest-first above the cursor, stored, and the cursor (`uidvalidity`, `last_uid`) is advanced. |
| IM-02 | POS | Fetch from `S-TLS` (STARTTLS). | TLS is negotiated after the greeting; the fetch works. |
| IM-03 | FEH | Point a source at a wrong host/port. | `ImapException` category `connection`; the localized `COM_DMARCREPORTS_INGEST_REASON_*` is shown; the raw exception message is not. |
| IM-04 | FEH | Force a TLS/certificate failure. | A distinct `tls` category is reported, not a generic connection error. |
| IM-05 | FEH | Use wrong credentials. | `login` category; the password is never echoed in the message or a log. |
| IM-06 | POS | Run a second fetch immediately. | Only messages above the high-water UID are fetched; already-seen messages are not reprocessed. |
| IM-07 | POS | With `delete_after_import` on and a UIDPLUS server, process a message. | The processed message is removed by a targeted `UID EXPUNGE`; other messages are untouched. |
| IM-08 | GRZ | With `delete_after_import` on and a non-UIDPLUS server, process a message. | The message is marked `\Deleted \Seen` and expunge is deferred; a blind `EXPUNGE` is never issued. |
| IM-09 | GRZ | Change the mailbox `UIDVALIDITY`. | The cursor is reset and the range is re-examined safely, not skipped. |
| IM-10 | FEH | Place an undecodable compressed container in the mailbox (host lacks the decoder). | It returns `RESULT_BLOCKED`: counted in `stats['blocked']`, a `warning` flash names the missing extension, the cursor advances past it, and its UID is added to the durable `deferred_uids` retry set. |
| IM-11 | POS | Install the missing decoder and re-run. | The below-cursor deferred UID is re-fetched, decoded, stored and dropped from the retry set. |
| IM-12 | GRZ | Fill the mailbox beyond `max_messages_per_run`; also force a mid-run failure. | Each run is bounded (`max_messages_per_run`, `MAX_TOTAL_BYTES`); the backlog clears over several runs; a failed run leaves the stored cursor intact for a retry. |

## 6. Continuous auto-ingestion (task plugin and scheduler)

The `plg_task_dmarcreports` plugin drives the component's `SourcePoller` so ingestion runs
unattended. It must not re-implement IMAP or parsing.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| AI-01 | POS | Create **System → Scheduled Tasks → DMARC Reports: Ingest**, set the interval, enable it and run it manually. | The routine is available; its execution and per-source result are traceable; the task's own schedule holds the interval. |
| AI-02 | POS | Rely on the lazy scheduler and generate ordinary administrator/site traffic. | A due task runs on the back of that traffic without any manual fetch. |
| AI-03 | POS | Configure a real system/web cron calling the Scheduler endpoint. | Ingestion runs continuously and independently of visitor traffic. |
| AI-04 | POS | With several active sources, let the task run. | It polls every active source through `SourcePoller`/`Ingestor` and stores the same result as an on-demand run. |
| AI-05 | FEH | Make one of several sources unreachable. | Per-source error isolation: the run reports `Status::KNOCKOUT`, the other sources are still processed. |
| AI-06 | KON | Run the task concurrently with a manual on-demand import of the same source. | Parallel-safe under `DomainLock`/`SourceLock`/`RowLock`: each message is processed exactly once, never twice. |
| AI-07 | POS | On a separate installation carrying only the standalone task plugin (the package blocks child uninstall, and disabling the component alone does not remove its classes), let the task run. | The guard `class_exists(SourcePoller::class)` is false, so the routine no-ops with `Status::KNOCKOUT` instead of fatalling. |
| AI-08 | POS | Run the task with no active sources. | A clean `Status::OK`; nothing stored. |
| AI-09 | POS/NEG | Across all four combinations of the task parameter `auto_create_domains` and the component option `task_auto_create`, run the task with a published source bound to a published domain plus a fresh unknown-domain report each run. | A new monitored domain is created only when both switches are on (`$allowAutoCreate`); in the other three combinations the unknown-domain report is skipped, never stored under `domain_id = 0`. The scheduled path consults no triggering-user ACL. |
| AI-10 | POS | Disable the task. | Ingestion stops; the dashboard shows no new data; nothing claims continuous ingestion is still guaranteed. |

## 7. Parser and aggregate evaluation

### 7.1 Parser

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| PA-01 | POS | Parse a well-formed report with mixed auth results. | One report header and N record rows; `spf_aligned`/`dkim_aligned` computed from the auth results. |
| PA-02 | POS | Parse records with `disposition` `none`, `quarantine` and `reject`. | Each disposition is stored verbatim. |
| PA-03 | NEG | Parse a report whose published `p` is an unknown value. | The whole report is rejected; no fake policy is displayed. |
| PA-04 | NEG | Parse a report whose `pct` is not a whole number 0–100. | The whole report is rejected, not coerced. |
| PA-05 | GRZ | Parse reports with `pct` absent, `sp` absent, `adkim`/`aspf` absent, and with a present-unknown `adkim`/`aspf`. | Absent `pct` defaults to 100, absent `sp` is accepted, absent alignment defaults to relaxed `r`; a present-unknown alignment value is rejected. |
| PA-06 | NEG | Parse a report with an inverted date range (`date_end < date_begin`). | Rejected before storage. |
| PA-07 | NEG | Parse a report whose `date_end` is more than 48 h (`MAX_CLOCK_SKEW`) in the future. | Rejected; a far-future report cannot pin the rolling windows or the displayed policy. |
| PA-08 | NEG | Parse a record with a missing or malformed `source_ip`. | The whole report is rejected (not coerced to an empty IP). |
| PA-09 | NEG | Parse records with a missing, non-positive or over-`PHP_INT_MAX` `count`, and a set whose valid counts sum past `PHP_INT_MAX`. | Each is rejected up front with overflow-safe addition; nothing is stored. |
| PA-10 | NEG | Parse records whose evaluated `disposition`, `dkim`, `spf` or `header_from` is missing or an unknown value. | The whole report is rejected rather than stored with a misleading default. |
| PA-11 | GRZ | Parse a policy domain and `header_from` at and beyond `MAX_DOMAIN_LENGTH` (190). | The overlong value is rejected, never truncated or overflowed into the column. |
| PA-12 | NEG | Parse XML carrying an external entity / `DOCTYPE`. | Loaded safely; no external entity expansion (no XXE). |
| PA-13 | POS | Parse a report for a monitored subdomain published at its organizational parent. | `effectivePolicy()` uses the subdomain policy `sp` per RFC 7489, falling back to `p` when `sp` is empty. |
| PA-14 | POS | Compare the stored `message_count` with the validated per-report total. | The `Ingestor` stores the parser's validated total rather than recomputing it. |

### 7.2 Metrics, dashboard and detail

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| ME-01 | POS | Open the dashboard with several domains ingested. | Each domain shows volume, SPF/DKIM pass rate and would-be-rejected share. |
| ME-02 | POS | Open a domain's detail view. | Top sending IPs, a pass/fail breakdown and a timeline are shown. |
| ME-03 | GRZ | Seed one historical failure, then a clean current window. | The rollout decision reads the current window (`window_total`, `pass_rate_window`, `window_reject_under_p`); the historical failure does not block the next step forever. |
| ME-04 | POS | Seed a would-be-rejected share at and above 2%. | The share is shown and raised to severity 3 at ≥2%. |
| ME-05 | POS | Seed a heavy failing sender ranked below the top-20 by total volume. | `unalignedSources()` still surfaces it for an alignment recommendation. |
| ME-06 | POS | Seed an IP first seen only in a second, contemporaneous report from another reporter in the current window. | `newSources()` flags it across the whole window, not just the single newest report id. |
| ME-07 | GRZ | Seed a drop in aligned-pass rate versus the previous period. | `pass_rate_drop` is reported. |
| ME-08 | POS | Compare `latestPolicy()` (single domain) and `latestPolicyAll()` (bulk dashboard) for a subdomain already covered by `sp=reject`. | Both resolve the effective policy; the subdomain is not told to start its rollout from `none`. |
| ME-09 | GRZ | Open a domain with no reports. | `no_data`; the metrics degrade to zeroes with no error. |
| ME-10 | POS | Seed several overlapping reports across windows. | The timeline renders correctly with no double counting. |
| ME-11 | POS | With several tenants ingested, inspect each domain's metrics. | One domain's metrics never include another domain's records. |
| ME-12 | POS | Ingest a TLS-RPT report, then open the dashboard and detail. | The TLS-RPT delivery-results card is shown alongside the DMARC metrics (ties to section 10). |

## 8. Action Center, system check and DNS diagnostics

### 8.1 Action Center

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| AC-01 | POS | Open the Action Center for a domain with no reports. | A `no_data` item. |
| AC-02 | POS | Seed `p=none` with a pass rate below the quarantine threshold. | `stay_at_none` — not safe to tighten yet. |
| AC-03 | POS | Seed `p=none` with a pass rate at or above the quarantine threshold. | `move_to_quarantine`. |
| AC-04 | POS | Seed `p=quarantine` below and then at or above the reject threshold. | `stay_at_quarantine`, then `move_to_reject`. |
| AC-05 | POS | Seed the configured target reached, a `p=reject` with `pct<100` and a `p=quarantine` with `pct<100`. | `at_target`/`enforced`; `partial_enforcement`; `partial_quarantine` — a partial policy is never reported as the reached target or treated as safe to advance. |
| AC-06 | POS | Seed a would-be-rejected share at ≥2%. | `would_be_rejected` at severity 3. |
| AC-07 | POS | Seed an unaligned sender at a small and a large share. | `align_source` with severity scaled by share. |
| AC-08 | POS | Seed a sending IP not seen before. | `new_source` — review it. |
| AC-09 | POS | Inspect ordering and badges across mixed findings. | Sorted by severity descending then `action_key`; the presenter maps 3→danger, 2→warning, 1→info, else success. |
| AC-10 | POS | Mark an item done and snooze another, then re-ingest. | The status and `snooze_until` survive the next ingestion; `first_seen`/`last_seen` track recurrence. |
| AC-11 | NEG | Mark an item done or snooze it without a CSRF token or without rights, including by direct POST. | No change. |
| AC-12 | POS | Vary `expected_policy`/`default_policy_target` and the two thresholds. | The `none<quarantine<reject` rank comparison drives the correct branch each time. |

### 8.2 System check and DNS diagnostics

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| SY-01 | POS | Open the System Check. | It reports the runtime capabilities (`EnvironmentCheck`, including the socket-function check) and the MTA-STS/TLS-RPT diagnostics; it does not claim to show plugin or task status, the last run, or mailbox reachability. Inspect task and source status in their own screens. |
| SY-02 | POS | In an isolated runtime, disable the `stream_socket_client` function so `EnvironmentCheck::sockets()` reports a warning. | The capability warning is surfaced; manual upload still works. Removing ext-imap or blocking outbound access alone does not change this check. |
| SY-03 | POS | Leave DNS diagnostics off (default) and open a domain detail view. | No DNS lookup is performed. |
| SY-04 | POS | Enable DNS diagnostics and open a domain detail view. | Published SPF/DMARC records are checked; only the domain name is disclosed to the resolver, never report contents. |
| SY-05 | NEG | Point a domain at a missing SPF/DMARC record. | A `dns_*` finding suggests the record to publish. |
| SY-06 | NEG | Force a DNS lookup error (null) versus a genuinely absent record (`[]`). | A null result emits `dns_lookup_error` and suppresses the publish suggestion; a transient outage is not reported as a missing record. |
| SY-07 | NEG | Publish a duplicate DMARC/SPF record, a malformed `p`/`pct`, and a lookalike version token (`v=DMARC10`, `v=spf10`). | The duplicate and malformed record are reported (`dns_dmarc_invalid`); the lookalike version is treated as missing via the word-boundary match. |
| SY-08 | POS | Simulate a domain-wide outage where the parent zone also fails to resolve. | Kept as null (ambiguous), not reported as a missing record. |

## 9. MTA-STS policy hosting and records

The `plg_system_dmarcmtasts` plugin serves the generated policy; the component generates the body
and records and validates what is published. The component never writes DNS.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| MS-01 | POS | Enable MTA-STS, mode `testing`, set the MX list and `max_age`. | `MtaStsPolicy::generate()` surfaces the body and the `_mta-sts`/`_smtp._tls` records in the Action Center and on the domain detail view. |
| MS-02 | INT | With the documented Apache rewrite rule in place, request `/.well-known/mta-sts.txt` on `mta-sts.<domain>`. | The policy is served with `version: STSv1` and the configured `mode`, `mx` and `max_age`. |
| MS-03 | POS | Change the mode from `testing` to `enforce`. | The served policy changes dynamically to match the component option. |
| MS-04 | NEG | Request the path on a non-`mta-sts.` host (for example `www.<domain>`). | The policy is not served; the plugin stays silent and emits no error. |
| MS-05 | NEG | Disable the feature (`mtasts_enable = 0`) and request the path. | The policy is not served. |
| MS-06 | NEG | Place a stale static `mta-sts.txt` at the path and request the exact RFC path. | The dedicated rule routes to Joomla; the stale static file is not served; the request never uses an `index.php/…` route. |
| MS-07 | NEG | Request the policy for a monitored domain whose `published` flag is off. | The domain gate refuses; the policy is not served. |
| MS-08 | NEG | Request the policy for a domain absent from the monitored table. | The policy is not served. |
| MS-09 | POS | Inspect the generated DNS records. | `_mta-sts.<domain>` TXT (`v=STSv1; id=…`) and `_smtp._tls.<domain>` TXT (`v=TLSRPTv1; rua=…`) are shown for the operator to publish. |
| MS-10 | POS/INT | Run `MtaStsDiagnostics::inspect()` across the states with an injected fake resolver and fetcher. | The finding codes appear as appropriate: `mtasts_no_mx`, `mtasts_publish`, `mtasts_lookup_error`, `mtasts_record_drift` (id drift), `mtasts_policy_unreachable`, `mtasts_policy_drift`, `mtasts_mode_testing`, `tlsrpt_publish`. |
| MS-11 | INT | The one manual DNS/TLS step: publish the records and host `mta-sts.<domain>` with valid TLS, then re-run the System Check validation. | The drift/publish findings clear; the component itself never writes DNS. *(Conditional — see section 2.1.1.)* |
| MS-12 | GRZ | Set `mtasts_mode = none` and leave the MX list empty. | `mtasts_no_mx` is reported; no unsafe policy is served; the documented body/record behaviour holds. |

## 10. TLS-RPT ingestion and display

TLS-RPT reports arrive the same way as DMARC `rua` — IMAP or upload — but as JSON, optionally
gzipped, and are routed by content to a dedicated parser and separate tables.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| TR-01 | POS | Upload a valid TLS-RPT JSON report (`T-OK`). | It is routed by content to `TlsRptParser` and stored in `#__dmarc_tlsrpt_*`, never mixed into the DMARC aggregate tables. |
| TR-02 | POS | Upload the same report gzipped. | `TlsReportArchive` decodes it; it is stored. |
| TR-03 | NEG | Feed empty JSON, invalid JSON and valid JSON that is not a TLS-RPT report to `TlsRptParser` directly, and separately upload the same through the endpoint. | The parser raises `ERROR_EMPTY(1)`, `ERROR_JSON(2)` and `ERROR_NOT_REPORT(4)` respectively; `TlsRptIngestor::ingestDocuments()` converts each into a summary reason that `IngestController::upload()` shows as a localized message. Nothing is stored either way. |
| TR-04 | POS | Re-ingest the same TLS-RPT report. | Idempotent no-op; the unique fingerprint prevents a duplicate. |
| TR-05 | POS | Ingest a report with several policy blocks. | One row per block in `#__dmarc_tlsrpt_policies` (type, policy domain, success/failure session counts). |
| TR-06 | POS | Ingest `T-FAIL` with failure details. | One row per detail in `#__dmarc_tlsrpt_failures` (result type, receiving MX, sending MTA IP, session count). |
| TR-07 | POS | Call `Metrics::tlsRptSummary()` for a domain with and without reports. | It returns reports/success/failure/failures and degrades to zeroes/empty when there are none. |
| TR-08 | POS | Open the domain detail view after ingesting a TLS-RPT report. | The TLS reporting card (`DETAIL_TLSRPT_*`) renders alongside the MTA-STS records card. |
| TR-09 | POS | With forced binding active (ingest as `INGEST` on demand, or through a task whose auto-creation is off), ingest a TLS-RPT report for a bound source and for a parent/sibling domain. | The report is routed to the bound domain or a subdomain of it (`DomainRegistry`/`DomainBinding`, exact-or-child); a parent, sibling or unrelated domain is skipped. |
| TR-10 | GRZ | Feed the pipeline a DMARC XML and a TLS-RPT JSON in turn. | Each is routed to its own parser and tables with no cross-contamination. |

## 11. Security: SSRF hardening of the MTA-STS policy fetcher

The policy-drift check resolves `mta-sts.<domain>`, where `<domain>` can be auto-created from an
ingested report, so the fetched host is attacker-influenced. Exercise `HttpPolicyFetcher` and
`PrivateAddressFilter` on an **isolated environment** with crafted DNS answers and local targets.
These cases do not replace a separate, complete security audit.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| SE-01 | NEG | Point the policy URL at a non-`https` scheme (`http`, `file`, `gopher`). | Not fetched; the fetcher returns null. |
| SE-02 | NEG | Use an `https` URL on a non-443 port. | Refused. |
| SE-03 | NEG | Put credentials in the authority (`user:pass@host`). | Refused. |
| SE-04 | NEG | Make the host answer a 3xx redirect toward an internal destination. | Not followed; redirects are disabled entirely. |
| SE-05 | NEG | Resolve the host to loopback (`127.0.0.0/8`, `::1`). | Refused. |
| SE-06 | NEG | Resolve the host to RFC 1918 (`10/8`, `172.16/12`, `192.168/16`). | Refused. |
| SE-07 | NEG | Resolve to link-local (`169.254/16`, `fe80::/10`) and CGNAT (`100.64/10`). | Refused. |
| SE-08 | NEG | Resolve to ULA (`fc00::/7`) and deprecated site-local (`fec0::/10`). | Refused (the IPv6 global-unicast gate fails closed). |
| SE-09 | NEG | Resolve to 6to4 (`2002::/16`, any embedded value); and separately to IPv4-mapped (`::ffff:10.0.0.1`), IPv4-compatible (`::10.0.0.1`) and NAT64 (`64:ff9b::10.0.0.1`) forms carrying a private embedded IPv4. | 6to4 is rejected unconditionally; the mapped, compatible and NAT64 forms are unwrapped and refused because their embedded IPv4 is private (a public embedded IPv4 would be allowed). |
| SE-10 | NEG | Resolve to TEST-NET `/24` (`192.0.2`, `198.51.100`, `203.0.113`), benchmarking (`198.18/15`), and documentation (`2001:db8::/32`, `3fff::/20`). | Refused. |
| SE-11 | NEG | Rebind the host: public at validation, private at connect (DNS rebinding / TOCTOU). | The connection is pinned to the validated IP via `CURLOPT_RESOLVE`; the rebinding cannot take effect. |
| SE-12 | NEG | Serve an oversized policy response (over the 64 KiB `MAX_POLICY_BYTES`). | The transfer is aborted; no memory exhaustion. |
| SE-13 | FEH | Remove the curl extension, and separately present an invalid TLS certificate. | Fails closed (null) and is reported as unreachable, never reached through an unchecked path. |
| SE-14 | NEG | Return a mixed resolution answer (one public and one private address for the host). | The whole fetch fails closed; it does not connect to the public subset of a rebinding answer. |

## 12. Privacy, self-hosting and retention

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| PR-01 | INT | Block outbound Internet for the isolated environment except the configured mailbox; run ingestion and open the dashboard. | Ingestion and the dashboard work; no report data is sent anywhere. |
| PR-02 | INT | Inspect network activity through a full workflow. | The only outbound connections are the configured IMAP mailbox, the optional DNS lookups (domain name only), Joomla's own update check (version metadata only) and, when MTA-STS diagnostics are enabled, the HTTPS MTA-STS policy fetch to the policy host (`MtaStsDiagnostics::inspectHostedPolicy()` via `HttpPolicyFetcher`). No report contents or mailbox credentials are ever sent, and there is no third-party analysis service. |
| PR-03 | POS | After ingestion, inspect where report data lives. | Parsed report data is stored only in the local `#__dmarc_*`/`#__dmarc_tlsrpt_*` tables and is never transmitted. |
| PR-04 | POS | Confirm DNS diagnostics default state and its disclosure. | Off by default; when enabled, only the domain name is disclosed to the resolver, never report contents. |
| PR-05 | POS | Inspect the stored mailbox password everywhere it could appear. | Encrypted at rest in `password_enc`; never logged and never in `last_message`. |
| PR-06 | POS | Delete a domain, then uninstall the package. | Deleting a domain removes its data per the documented behaviour; uninstall drops every `#__dmarc_*`/`#__dmarc_tlsrpt_*` table and all collected data. |
| PR-07 | INT | Export a domain's records as CSV and inspect the file. | Only the authorised domain's records appear; no credentials, secrets or tokens are present. |
| PR-08 | POS | As `GUEST`, request component data through the front end. | No DMARC data appears on the public site; the component is administrator-only. |

## 13. ACL and permissions

Test every visible action through a **direct URL/POST request** too. A hidden button alone is not
access control.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| RE-01 | NEG | As `GUEST`, request administration pages, ingestion, CSV export and the System Check. | No access to internal data. |
| RE-02 | NEG | As `READONLY`, save, delete, ingest, export or change state. | Only explicitly permitted read operations are possible. |
| RE-03 | POS/NEG | As `INGEST`, import a report for an already-monitored domain, then for an unknown domain. | The known-domain import succeeds; the unknown domain is not auto-created (no `core.create`). |
| RE-04 | NEG | As `OPTIONS-DENIED`, open the component Options screen. | Access refused (`core.options`). |
| RE-05 | NEG | Without `core.create`, create a domain or a source, including by direct POST. | Refused. |
| RE-06 | NEG | With `core.edit` but not `core.edit.state`/`core.delete`, change the published state or delete, including by direct POST. | Each denied action is refused. |
| RE-07 | KON | User 1 opens a domain or source for editing; user 2 attempts to edit/save the same record. | Joomla checkout is respected; no unnoticed loss of user 1's changes. |
| RE-08 | NEG | Request a CSV export without `core.manage` or without a valid token. | No export. |
| RE-09 | NEG | As a user without `core.manage` on the component, view the dashboard module. | The module shows the `unauthorised` state with no figures. |
| RE-10 | NEG | Enable the task parameter `auto_create_domains` while the component option `task_auto_create` is off, then run the task. | The component option vetoes it: `$allowAutoCreate` is false, a `warning` is logged, and unknown-domain reports are still skipped. The scheduled path never consults the triggering user's `core.create`. |

## 14. Languages and presentation

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| UI-01 | POS | Open every backend view (dashboard, detail, domains, sources, system check, Options) and the module in German, English and French, including error/empty states. | No missing translations or visible `COM_DMARCREPORTS_...`/`MOD_DMARCREPORTS_...` keys. |
| UI-02 | POS | Inspect the de-DE and fr-FR strings. | de-DE uses real umlauts and no `ß` (Swiss High German); fr-FR uses accented forms. |
| UI-03 | POS | Compare the `.ini` and `.sys.ini` key sets across en-GB/de-DE/fr-FR for the component, module, both plugins and the package. | The key sets stay aligned; none is missing in a locale. |
| UI-04 | POS | Inspect every Action Center and MTA-STS/TLS-RPT finding. | Each key has aligned `_TITLE`/`_TEXT` in all three locales; placeholders fill correctly; no admin-visible text is assembled at runtime. |
| UI-05 | POS | Inspect long French labels and long domain names on a narrow screen and at 200% zoom. | No clipped actions or obscured fields. |
| UI-06 | POS | Export CSV under each `csv_delimiter` option and open it in a de-CH spreadsheet. | The configured delimiter is used for header and rows; the UTF-8 BOM is present; umlauts open correctly in columns. |
| UI-07 | POS | Switch language and re-render the dashboard and Action Center. | No raw keys; the wording matches the selected language. |
| UI-08 | POS | Install/update the package and inspect the installer text in all three locales. | The package `.sys.ini` description and name keys render translated. |

## 15. Backend module and admin views

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| MO-01 | POS | Place `mod_dmarcreports` on the administrator `cpanel` dashboard with data ingested. | It shows monitored domains, total messages, SPF/DKIM pass rate and would-be-rejected share, plus the top Action Center items with severity label/class and the owning domain. |
| MO-02 | POS | Vary the `show_summary` and `action_limit` parameters. | The summary is gated by `show_summary`; items are capped by `action_limit`; a "more actions" count and an "Open dashboard" button into the component are shown. |
| MO-03 | POS | Compare the module figures with the component dashboard. | They match exactly — the module reuses `DashboardModel`/`ActionPresenter` and duplicates no query logic. |
| MO-04 | POS | Disable or remove the component, then view the module. | The `unavailable` notice renders; no raw language key leaks; no summary cards appear. |
| MO-05 | NEG | View the module as a user without `core.manage`. | The `unauthorised` state; no figures. |
| MO-06 | FEH | With the component present, force a model/method error. | The `error` state renders a short translated notice; no fatal. |
| MO-07 | POS | Render every admin page of the component. | HTTP 200, no PHP/Joomla error page and no untranslated key on any page. |
| MO-08 | POS | Open the domain detail view after configuring MTA-STS and ingesting TLS-RPT. | The MTA-STS records card and the TLS reporting card render with the correct values. |

## 16. Input, request and robustness

These cases exercise the tester's own isolated installation. They do not replace a separate,
complete security audit.

| ID | Type | Procedure | Expected result |
|---|---|---|---|
| RB-01 | NEG | Use `<`, `>`, `&`, `'` and `"` in domain titles/notes, source names and searches. | Correct output; no broken page, unauthorised HTML or SQL error. |
| RB-02 | NEG | Remove the CSRF token from every writing action (domain/source save and delete, ingest, export, action status). | No state change for any action. |
| RB-03 | NEG | Substitute another record's id in domain, source, detail and export requests. | No unauthorised record is readable or changeable. |
| RB-04 | NEG | Seed report fields with `=1+1`, `+1+1`, `-1+1` and `@TEST`, then export CSV and open it in a spreadsheet. | Protected as text; no formula execution. |
| RB-05 | FEH | Trigger an IMAP, parser and validation error in turn; inspect the visible error. | No credential, token or raw stack trace is leaked. |
| RB-06 | NEG | Submit a manipulated `source_id`/`domain_id` in an upload POST, a nonexistent record id in a source-edit POST, and an unsupported `security` value in a source POST. | The upload ignores posted `source_id`/`domain_id` (stored as `0`, per UP-09) and still succeeds; the nonexistent reference and the unsupported `security` value are refused server-side. |
| RB-07 | GRZ | Upload a very large file and a report with many records in an isolated environment. | Bounded by `MAX_TOTAL_BYTES`, the archive cap and the parser count caps; no 500 error or unbounded processing. |
| RB-08 | INT | Inspect the component tables and logs after the induced errors. | No raw credential, source password or confidential token is stored or logged. |

## 17. Complete functional acceptance scenario

Configure this scenario **only through Joomla administration**, without file or database edits.

### Setup

1. Install the package and open **Components → DMARC Reports**.
2. Add a monitored domain with expected policy `reject`.
3. Add a report source bound to that domain with IMAP settings against the controlled test mailbox,
   password entered through the form.
4. Configure the Options: thresholds, CSV delimiter and DNS diagnostics off.
5. Enable MTA-STS in `testing` mode with an MX list, and enable TLS-RPT.
6. Create and enable the **DMARC Reports: Ingest** scheduled task.
7. Place the `mod_dmarcreports` module on the administrator dashboard.

### Procedure

| Step | Action | Expected result |
|---|---|---|
| 1 | Upload a valid aggregate report by hand. | Exactly one report stored; the dashboard shows volume and pass rates. |
| 2 | Re-upload the same report. | Idempotent no-op; no duplicate. |
| 3 | Trigger an on-demand IMAP fetch from the source. | New reports stored; the cursor advances; already-seen messages are not reprocessed. |
| 4 | Let the scheduled task run. | It ingests through the shared poller; a concurrent manual import processes each message once. |
| 5 | Open the Action Center. | Prioritised, plain-language steps appear for the domain's current policy and failing sources. |
| 6 | Drill into the domain detail. | Top IPs, pass/fail breakdown, timeline and the MTA-STS records card render. |
| 7 | Upload a TLS-RPT JSON report. | It is stored separately; the TLS reporting card appears. |
| 8 | Request `/.well-known/mta-sts.txt` on the `mta-sts.` host through the documented rule. | The generated policy is served; changing the mode changes it. |
| 9 | Enable DNS diagnostics, then open the domain detail page and the System Check. | The domain detail page shows the SPF/DMARC findings (`DetailModel::getDnsDiagnostics()`); the System Check shows the runtime capabilities and the MTA-STS/TLS-RPT diagnostics (which may make the optional HTTPS policy request). Only the domain name is disclosed to the resolver. |
| 10 | Export the records as CSV, then uninstall the package on a disposable copy. | The export contains only authorised records; uninstall drops every table and all data. |

Then repeat the core ingest-and-read flow in a second language.

## 18. Defect assessment and acceptance criteria

### Defect classes

| Priority | Examples | Consequence |
|---|---|---|
| **P0 - critical** | Report data or a credential exposed to an unauthorised user or the public site; the SSRF guard reaching an internal host; the mailbox password readable in plaintext or a log; data still present after uninstall | No acceptance |
| **P1 - high** | A malformed report accepted and skewing metrics; a duplicate stored under a reused report id; an unknown domain auto-created without permission; the MTA-STS policy served for an unpublished/unmonitored domain; a lost report on update | No acceptance |
| **P2 - medium** | Limited functional defect with a safe workaround; incorrect filtering or metric; an important missing translation | Fix before acceptance or explicitly document acceptance of the limitation |
| **P3 - low** | Cosmetic/minor wording/layout issue without functional loss | Document and assess |

### Minimum conditions for human acceptance

- Package install, update (with the TLS-RPT migration) and uninstall pass on Joomla 5.4 and Joomla 6.
- MySQL/MariaDB-relevant cases pass.
- No open P0/P1 defects.
- Both ingestion inputs (IMAP and upload) and the idempotent dedupe actually exercised.
- The auto-ingestion task driving the component, with per-source error isolation and lock-based
  parallel safety, exercised.
- The parser's rejection of every malformed-report class exercised.
- The MTA-STS endpoint served (and refused) through the documented web-server rule, including the
  domain gate.
- TLS-RPT ingestion into its separate tables exercised.
- The SSRF negative cases exercised against isolated fixtures.
- Self-hosting confirmed: no report data sent to any third party.
- All three languages inspected.
- The mandatory executions defined in section 2.1.1 have no BLOCKED or NOT RUN results; only the
  explicitly inactive live-DNS publication repetitions are exempt. Observed failures retain FAIL
  status and require resolution or the explicit P2/P3 assessment above, never an applicability
  waiver.
- Actual tested commit recorded unambiguously.
- After required code fixes, affected cases and regression pass again, and CI is green on the new
  commit.

**Human acceptance and the required green Copilot recommendation are separate gates.**
A human approval does not automatically turn "Needs a closer look" into "Approval recommended".
Record each decision and the commit it covers separately; neither is asserted by this plan.

## 19. Final execution report

Copy the [blank report template](report-template.md) into private, access-controlled storage.
Record actual observations and case-level evidence using section 1.3. Leave unexecuted cases as
**NOT RUN**; do not infer manual passes from CI results.
