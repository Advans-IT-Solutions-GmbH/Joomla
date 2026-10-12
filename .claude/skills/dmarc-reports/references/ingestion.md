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
- `advance()` flags a message `\Seen`, or `\Deleted` + `EXPUNGE` when `delete_after_import` is set.
  Flags are STOREd **before** any EXPUNGE because EXPUNGE renumbers sequence numbers.
- Plain `SEARCH` returns **sequence numbers**, not UIDs; STORE/FETCH/EXPUNGE all use them. The
  `uid` field in a message array is really a seq number (pre-existing naming).
- Only successfully-ingested messages are advanced/deleted — `Ingestor::ingestMessage()` returns a
  success bool that the callback checks.

IMAP is **not** integration-tested in CI (no IMAP server); correctness rests on review. Keep the
streaming contract intact.

## Parser (`DmarcParser`)

Parses the aggregate XML into one report header + N record rows (see `dmarc-domain.md` for the
XML shape). Loads XML safely (no external entity expansion). Maps `policy_published` and each
`record` into the `#__dmarc_reports` / `#__dmarc_records` columns, computing `spf_aligned` /
`dkim_aligned` from the auth results.

## Idempotent storage (`Ingestor`)

`report_hash` (reporter org + report id) is a UNIQUE key on `#__dmarc_reports`. Re-ingesting the
same report is a no-op: the insert is skipped and no duplicate records are written. `ingestMessages()`
loops `ingestMessage()` over the streamed messages; `newSummary()` builds the per-run result
(counts of new / duplicate / failed) shown back to the admin and written to `last_run` /
`last_status` / `last_message` on the source.
