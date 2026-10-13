# Ingestion: IMAP client, archives, parser

Two input paths produce the same stored result: on-demand IMAP fetch and manual file upload.
Both feed `Ingestor`, which parses and stores idempotently.

## File upload

`IngestController` accepts an uploaded `.xml`, `.gz` or `.zip`. `ReportArchive` detects the
container and yields the inner XML (gzip decode, zip entry extraction, or raw XML passthrough),
then `DmarcParser` runs. Upload is CSRF-protected and works without the IMAP transport. A manual
upload is deliberately **unbound**: the form offers no source selector, so `upload()` never reads a
client-supplied `source_id` (storing it as `0`) and the domain is resolved from the published policy
under the same ACL gate as every other path. Trusting a posted `source_id` would let a crafted
request attach arbitrary or nonexistent provenance to a report.

`ReportArchive::extract()` distinguishes *malformed* from *undecodable*: a recognised gzip or zip
container that cannot be decoded because the host lacks the PHP extension (`gzdecode` / `ZipArchive`)
throws `DecoderUnavailableException`, whereas genuinely corrupt data returns `[]`. The two paths are
handled differently: on IMAP ingest `Ingestor::ingestMessage()` catches the exception and returns
`RESULT_BLOCKED` — a *permanent* condition (unlike a transient `RESULT_RETRY`) so the client advances
past it instead of pinning the cursor, which would let one undecodable container starve every newer
message behind it forever. To avoid the opposite failure — losing the report once the extension is
installed, because its UID now sits below the cursor — the blocked UID is kept in a durable per-source
retry set (`#__dmarc_sources.deferred_uids`, an ascending CSV capped at `MAX_DEFERRED_UIDS`). Each run
re-fetches those below-cursor UIDs explicitly; a UID that now decodes is stored and dropped from the
set, one still blocked stays, and an expunged one (empty fetch) is dropped. The retry set is persisted
only under the same lock/guard as the cursor and reset with it (identity change in `SourceModel::save()`
or a `UIDVALIDITY` renumber). The run is counted (`stats['blocked']`) and recorded with a `blocked`
status plus a `warning` flash (`COM_DMARCREPORTS_INGEST_BLOCKED`) naming the missing extension, so the
operator installs it and re-runs; on upload `ingestFile()` lets the exception propagate so
`IngestController` shows the concrete requirement (`COM_DMARCREPORTS_INGEST_DECODER_UNAVAILABLE`)
rather than a generic failure.

## Operator-facing failure reasons

Ingest and upload failures state a short, localized reason so a non-expert can act, and never leak a
credential or raw technical detail:

- Upload: when nothing is imported, `IngestController::upload()` appends a specific cause —
  `Ingestor` tags the summary `reason` as `empty`, `format` (not a recognised DMARC report),
  `malformed` (corrupt or security-rejected XML, e.g. a declared DOCTYPE / the XXE class) or `bomb`
  (oversized or over-expanding), mapped to `COM_DMARCREPORTS_UPLOAD_REASON_*`. The parser exposes
  `DmarcParser::ERROR_*` codes that `Ingestor::parseReason()` folds into those tokens.
- Archive cap: `ReportArchive::MAX_DOCUMENTS` (256) bounds the `.xml` entries extracted from one
  archive. The surplus is counted (`stats['dropped']`) and surfaced in the run summary
  (`COM_DMARCREPORTS_INGEST_DROPPED`) instead of being dropped silently.
- IMAP: transport/protocol failures throw `ImapException` with a coarse category
  (`connection`/`tls`/`login`/`config`); the controller shows only the fixed localized category text
  (`COM_DMARCREPORTS_INGEST_REASON_*`), never the exception message.

## CSV export

`ExportController` writes a UTF-8 BOM and uses the operator-configured `csv_delimiter` option
(`semicolon` default, `comma`, or `tab`) for both the header and every row, so Excel on a de-CH
locale opens the export into columns with correct umlauts rather than one mojibake column.

## IMAP client (`ImapClient`)

Connects over an encrypted stream socket — it does **not** require the PHP `imap` extension, so it
degrades gracefully where that extension is absent (the system check / `EnvironmentCheck` surfaces
the transport state). Credentials come from `#__dmarc_sources` with the password decrypted via
`CryptoHelper`.

Only encrypted transports are allowed. `security` accepts `ssl` (implicit TLS, `ssl://`) or `tls`
(STARTTLS over `tcp://`); the plaintext option was removed from `source.xml` and `process()` rejects
any value other than `ssl`/`tls` server-side, so a forged submission or a legacy row cannot reopen a
cleartext session that would leak the mailbox credentials and report contents.

Streaming design (do not revert to loading all messages into an array):

- `process(array $config, callable $onMessage): array` — resumes from the persisted per-source
  cursor (`uidvalidity`, `last_uid`) rather than mailbox `\Seen` state: it validates the stored
  `UIDVALIDITY` (and resets the cursor when the mailbox reports a new one), issues
  `UID SEARCH UID <last_uid+1>:*` to collect only messages above the high-water UID oldest-first,
  `array_slice(0, $limit)` by `max_messages_per_run`, then fetches **one** body at a time and
  passes `['uid' => <real IMAP UID>, 'raw' => $raw]` to the callback, `unset($raw)` between
  messages. The returned stats carry the advanced `uidvalidity`/`last_uid` the controller persists;
  a failed run leaves the stored cursor intact so the next run re-examines the same range.
- A `MAX_TOTAL_BYTES` (128 MiB) run budget caps memory for a single run.
- `advance()` flags a processed message `\Seen` with `UID STORE +FLAGS.SILENT (\Seen)`. When
  `delete_after_import` is set it removes it instead: with the UIDPLUS capability (RFC 4315) it
  marks the message `\Deleted` and issues a **targeted `UID EXPUNGE`** so only the named messages
  go; without UIDPLUS it marks them `\Deleted \Seen` and **defers** removal (a plain `EXPUNGE`
  would drop every `\Deleted` message, including other clients', so it is never issued).
- All mailbox operations address messages by **UID** (`UID SEARCH`, `UID FETCH`, `UID STORE`,
  `UID EXPUNGE`, RFC 3501), so flags and expunges reference the exact message even as the mailbox
  changes — do not revert to sequence-number operations. The `uid` field in a message array is a
  real IMAP UID.
- `fetchBody()` reads the `UID FETCH` response **line by line** until the `{NNN}` literal that
  carries the body or the tagged completion arrives, because the FETCH may be preceded by
  unsolicited untagged responses (for example `* 5 EXISTS`). Do not revert to inspecting only the
  first response line — that would miss the literal and silently skip a valid report.
- Only messages the ingestor could safely process are advanced/deleted.
  `Ingestor::ingestMessage()` returns a **`RESULT_*` string**, not a boolean: `RESULT_NEW`
  (stored a report) and `RESULT_SKIP` (duplicate or unparseable) are both advanced, while
  `RESULT_RETRY` (a transient failure, e.g. the DB transaction could not commit) leaves the
  message untouched so the next run retries it. `RESULT_BLOCKED` (a permanent failure, e.g. a
  compressed container the host cannot decode) is **also advanced** — counted as `stats['blocked']`
  and surfaced to the operator — so it cannot pin the cursor and starve newer messages, but its UID
  is added to the durable retry set so a later run re-fetches it once the decoder is available. The
  callback advances on everything except `RESULT_RETRY`.
- The cursor is scoped to one mailbox. `SourceModel::save()` resets it (via `SourceIdentity`) when
  any identity field (`protocol`, `host`, `port`, `security`, `username`, `folder`) changes, and
  `IngestController::recordRun()` re-checks the identity under a `SourceLock` row lock before
  advancing it, so a poll of the old mailbox cannot revive a stale high-water UID after an edit.

IMAP is **not** integration-tested in CI (no IMAP server); correctness rests on review. Keep the
streaming contract intact.

## MIME reader (`MimeMessage`)

`attachments()` walks the multipart tree and decodes the transfer encodings DMARC reporters use.
The `boundary` parameter is read by a dedicated `boundary()` helper that matches the quoted form
(`boundary="report part"`, which may legally contain spaces) and the bare-token form separately, so
a quoted boundary is never truncated at its first space — truncation would split none of the parts
and silently drop the whole report. Both the top-level and nested multipart call sites use it.

Because mailbox content is untrusted, `attachments()` enforces two hard bounds before materialising
any part: a maximum nesting depth (`MAX_DEPTH`) and a single global part budget (`MAX_PARTS`) shared
across the whole recursion. The segment `explode()` is capped by the remaining budget, so a crafted
message that repeats a short boundary millions of times cannot allocate a huge array, and deeply
nested multiparts stop at the depth ceiling instead of recursing without bound. A legitimate report
has a handful of parts and shallow nesting, so the ceilings never reject a real report.

A leaf part is treated as a report when it has an attachment filename, a gzip/zip media type, or an
`application/xml` / `text/xml` media type **combined with** `Content-Disposition: attachment`. The
disposition requirement on bare XML parts is deliberate: some reporters attach the raw aggregate XML
with no filename, which must still be picked up, while inline human-readable XML (no attachment
disposition) is not a report and must be ignored.

## Parser (`DmarcParser`)

Parses the aggregate XML into one report header + N record rows (see `dmarc-domain.md` for the
XML shape). Loads XML safely (no external entity expansion). Maps `policy_published` and each
`record` into the `#__dmarc_reports` / `#__dmarc_records` columns, computing `spf_aligned` /
`dkim_aligned` from the auth results.

The published policy is validated, not just copied: `p` must be `none`, `quarantine` or `reject`
and `pct`, when present, must be a whole number 0–100 (absent defaults to 100). A malformed or
out-of-range value rejects the whole report instead of being coerced, because the latest stored
policy drives the dashboard and the rollout guidance — one bogus report must not display a fake
policy and suppress all guidance. `sp` is optional and validated only when present. The published
alignment modes (`adkim`/`aspf`) default to relaxed (`r`) when absent but reject a present-unknown
value rather than silently coercing it, so a misdeclared alignment policy is never misrepresented.
The published policy domain is also rejected when it exceeds the stored column length
(`MAX_DOMAIN_LENGTH`, 190): a valid-but-overlong auto-detected domain would otherwise overflow the
insert and make the message retry forever.

Report-level and record-level fields are validated the same way. A report with an inverted date
range (`date_end < date_begin`) is rejected before storage, because an out-of-order range would
misplace it on the timeline and could anchor the rolling comparison windows incorrectly. A report
whose `date_end` is more than a small clock-skew allowance (`MAX_CLOCK_SKEW`, 48 h) in the future
is also rejected: metrics anchor the rolling windows and the displayed current policy on the maximum
`date_end`, so one crafted far-future report could otherwise push every legitimate report outside
the Action Center windows and pin the shown policy indefinitely. Every
`record` row must carry a routable `source_ip` (`FILTER_VALIDATE_IP`) and a positive integer
`count`; a missing or malformed value rejects the whole report rather than being coerced to `''`
or `0`. The per-record evaluated fields are validated the same way rather than coerced: the
`disposition` must be `none`/`quarantine`/`reject`, the evaluated `dkim`/`spf` results must be
`pass`/`fail`, and `header_from` must be present and within the stored length. A missing or unknown
value rejects the whole report instead of being stored as a misleading default (`none` / `fail` /
empty) — otherwise the report fingerprint would be committed with inaccurate rows, and a later
corrected copy would be dropped as a duplicate while the dashboard metrics and rollout guidance stay
wrong.
Coercion would commit the report fingerprint while keeping an invalid row, so a corrected
copy of the same report would later be discarded as a duplicate while the bad row skews metrics.
The `count` must also fit the signed integer PHP binds and the `BIGINT UNSIGNED` column stores
(capped at `PHP_INT_MAX`); a digit string beyond that range is rejected up front, because it would
otherwise overflow on insert and make the message retry forever. The **cumulative** per-report
total is guarded the same way with overflow-safe addition: individually valid counts that sum past
`PHP_INT_MAX` are rejected before storage (otherwise `array_sum()` would spill into a float and the
cast back would yield `PHP_INT_MIN`, failing the unsigned insert on every retry). The parser carries
the validated total forward as `message_count`, and the `Ingestor` stores that value rather than
recomputing it.

## Idempotent storage (`Ingestor`)

`report_hash` (policy domain + reporter org + report id + report-window start) is a UNIQUE key on
`#__dmarc_reports`. Including the policy domain keeps a reporter that reuses one report id across
different policy domains as distinct reports in a multi-domain install, and including the window
start keeps a reporter that reuses a report id for a later window as two distinct reports. Re-ingesting the
same report is a no-op: the insert is skipped and no duplicate records are written. `ingestMessages()`
loops `ingestMessage()` over the streamed messages; `newSummary()` builds the per-run result
(counts of new / duplicate / failed / skipped) shown back to the admin and written to `last_run` /
`last_status` / `last_message` on the source. The per-run `records` tally is accumulated locally and
folded into the summary only **after** the report's transaction commits, so a mid-report insert or
commit failure that rolls the whole report back cannot leave an inflated record count.

Auto-registering an unknown domain is gated by ACL. The constructor takes an `allowAutoCreate` flag
that `IngestController` sets from the current user's `core.create` privilege on the component: an
ingest-only operator may import reports for already-configured domains but may not create new
monitored-domain rows. When a report's policy domain is unknown and `allowAutoCreate` is false,
`resolveDomain()` returns `0` and `store()` increments the `skipped` counter and writes nothing,
rather than registering a domain the operator is not entitled to or storing an orphan report
(`domain_id = 0`). IMAP import for a source bound to a domain passes that id as `forcedDomainId`, so
auto-discovery only applies to unbound sources and uploads.
