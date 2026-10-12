# Ingestion: IMAP client, archives, parser

Two input paths produce the same stored result: on-demand IMAP fetch and manual file upload.
Both feed `Ingestor`, which parses and stores idempotently.

## File upload

`IngestController` accepts an uploaded `.xml`, `.gz` or `.zip`. `ReportArchive` detects the
container and yields the inner XML (gzip decode, zip entry extraction, or raw XML passthrough),
then `DmarcParser` runs. Upload is CSRF-protected and works without the IMAP transport.

## IMAP client (`ImapClient`)

Connects over an encrypted stream socket — it does **not** require the PHP `imap` extension, so it
degrades gracefully where that extension is absent (the system check / `EnvironmentCheck` surfaces
the transport state). Credentials come from `#__dmarc_sources` with the password decrypted via
`CryptoHelper`.

Streaming design (do not revert to loading all messages into an array):

- `process(array $config, callable $onMessage): array` — searches `UNSEEN` oldest-first,
  `array_slice(0, $limit)` by `max_messages_per_run`, then fetches **one** body at a time and
  passes `['uid' => seq, 'raw' => $raw]` to the callback, `unset($raw)` between messages.
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
- Only successfully-ingested messages are advanced/deleted — `Ingestor::ingestMessage()` returns a
  success bool that the callback checks.

IMAP is **not** integration-tested in CI (no IMAP server); correctness rests on review. Keep the
streaming contract intact.

## Parser (`DmarcParser`)

Parses the aggregate XML into one report header + N record rows (see `dmarc-domain.md` for the
XML shape). Loads XML safely (no external entity expansion). Maps `policy_published` and each
`record` into the `#__dmarc_reports` / `#__dmarc_records` columns, computing `spf_aligned` /
`dkim_aligned` from the auth results.

The published policy is validated, not just copied: `p` must be `none`, `quarantine` or `reject`
and `pct`, when present, must be a whole number 0–100 (absent defaults to 100). A malformed or
out-of-range value rejects the whole report instead of being coerced, because the latest stored
policy drives the dashboard and the rollout guidance — one bogus report must not display a fake
policy and suppress all guidance. `sp` is optional and validated only when present.

## Idempotent storage (`Ingestor`)

`report_hash` (reporter org + report id) is a UNIQUE key on `#__dmarc_reports`. Re-ingesting the
same report is a no-op: the insert is skipped and no duplicate records are written. `ingestMessages()`
loops `ingestMessage()` over the streamed messages; `newSummary()` builds the per-run result
(counts of new / duplicate / failed) shown back to the admin and written to `last_run` /
`last_status` / `last_message` on the source.
