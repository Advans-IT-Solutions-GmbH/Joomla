# Action Center

The Action Center (`ActionCenter`, rendered via `ActionPresenter`, persisted via `ActionStatus`)
is what makes the component actionable: it reads the stored metrics and emits prioritised,
plain-language next steps so a non-expert admin knows what to do first.

## Severity scale

`3` = act now (danger) · `2` = plan soon (warning) · `1` = review (info) · `0` = informational (success).
Items are sorted by severity descending, then by `action_key`. The presenter maps severity to a
Bootstrap badge class in `ActionPresenter` (3→danger, 2→warning, 1→info, else success).

## Finding keys

Each finding is `self::item(key, category, severity, domainId, params[, dedupeSuffix])`. `params`
are positional and fed into the matching language key by `ActionPresenter::title()`/`text()`.

| Key | Category | When |
|-----|----------|------|
| `no_data` | data | No reports stored for the domain yet |
| `stay_at_none` | policy | At `p=none` but pass rate below the quarantine threshold — not safe yet |
| `move_to_quarantine` | policy | At `p=none` and pass rate ≥ quarantine threshold — safe to tighten |
| `stay_at_quarantine` | policy | At `p=quarantine` but pass rate below the reject threshold |
| `move_to_reject` | policy | At `p=quarantine` and pass rate ≥ reject threshold |
| `at_target` / `enforced` | policy | Already at the configured target / at `p=reject` with `pct=100` |
| `partial_enforcement` | policy | At `p=reject` but `pct<100`, so only that share of mail is enforced — not full enforcement |
| `partial_quarantine` | policy | At `p=quarantine` but `pct<100`, so quarantine is only partly exercised — raise `pct` to 100 before advancing or calling quarantine the reached target |
| `would_be_rejected` | policy | Share of mail that would be blocked today under `p=reject` (sev 3 if ≥2%) |
| `align_source` | alignment | An unaligned sender (IP) — fix SPF/DKIM before tightening (sev by share) |
| `new_source` | source | A sending IP not seen before — review it |
| `pass_rate_drop` | alignment | Aligned-pass rate fell vs the previous period |
| `dns_*` | dns | From `DnsDiagnostics` — missing/broken SPF or DMARC record (see below) |

Policy decisions use a `rank = none<quarantine<reject` comparison against the domain's
`expected_policy` / the `default_policy_target` option and the two threshold options. Partial
enforcement (`pct<100`) is handled before the target/advance branches for both `p=reject`
(`partial_enforcement`) and `p=quarantine` (`partial_quarantine`), so a policy that applies to only
part of the mail stream is never reported as the reached target or treated as safe to advance.

`align_source` is driven by `Metrics::unalignedSources()`, which queries failing senders
independently of the display-oriented `topSources()` top-N list (`GROUP BY source_ip HAVING
SUM(fail) > 0 ORDER BY fail DESC`). A heavy failing sender ranked below the top 20 by total volume
therefore still reaches the Action Center and earns an alignment recommendation.

## DNS diagnostics

`DnsDiagnostics` checks the published SPF and DMARC records via `DnsResolverInterface`
(`SystemDnsResolver` wraps `dns_get_record`). `txt()` returns `?array`: **null = lookup error**,
**`[]` = no records**. A null result emits a `dns_lookup_error` finding and suppresses the
"publish this record" suggestion (we cannot know what is missing when the lookup itself failed) —
distinct from a genuinely missing record. Keep that distinction when editing.

A present record is validated before it is read as healthy: more than one DMARC (or SPF) record is
reported as a duplicate, and a malformed required tag — an unknown `p` value or a `pct` that is not
a whole number 0–100 — is reported as invalid (`dns_dmarc_invalid`) rather than silently treated as
a working policy. The version token is matched on a word boundary: `v=DMARC1` / `v=spf1` must end
at the prefix or be followed by a separator, so a lookalike such as `v=DMARC10` or `v=spf10` is not
mistaken for a valid version and the record is treated as missing.

## New-source detection

`Metrics::newSources()` flags sending IPs that appear in the **current reporting window** (every
report whose range begins in the latest rolling window) but never in any earlier report. Detection
spans the whole window rather than the single newest report id, because a domain commonly receives
several overlapping reports from different organisations for the same window; a source first seen in
a contemporaneous report from another reporter would otherwise be missed.

## Persistence

Findings are stored in `#__dmarc_actions` keyed by `(domain_id, action_key)` so an admin can mark
an item done or snooze it (`snooze_until`) and the state survives the next ingestion; `first_seen`
/ `last_seen` track recurrence. `ActionStatus` reconciles freshly-derived findings with stored rows.

## Language

Every title and body is a language key with placeholders (`%s`), written out in full in en-GB,
de-DE and fr-FR — no runtime assembly of admin-visible text. Adding a finding key means adding the
aligned `_TITLE` / `_TEXT` keys to all three locales (lang-lint enforces alignment).
