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
| `spf_only_source` | source | A current-window source with mail that passed DMARC only through SPF (no aligned DKIM), sev 3, params `[ip, host, spf_only, dkim, total]` |
| `align_source` | alignment | A current-window source with failing mail that was still delivered (disposition `none`), sev 1, params `[ip, host, delivered, share]` |
| `pass_rate_drop` | alignment | Aligned-pass rate fell vs the previous period |
| `dns_*` | dns | From `DnsDiagnostics` — missing/broken SPF or DMARC record (see below) |

Policy decisions use a `rank = none<quarantine<reject` comparison against the domain's
`expected_policy` / the `default_policy_target` option and the two threshold options. Partial
enforcement (`pct<100`) is handled before the target/advance branches for both `p=reject`
(`partial_enforcement`) and `p=quarantine` (`partial_quarantine`), so a policy that applies to only
part of the mail stream is never reported as the reached target or treated as safe to advance.

The rollout decision (pass rate, would-be-rejected count and its share) reflects the **current
reporting window**, not accumulated lifetime totals. `Metrics::domainMetrics()` supplies
`window_total`, `pass_rate_window` and `window_reject_under_p`; when a window is present the Action
Center uses them, otherwise it falls back to the lifetime `total` / pass rate / `reject_under_p`.
This prevents a single historical failure — which keeps the lifetime `reject_under_p` above zero
forever — from permanently blocking the next policy step once the current window is clean.

The **current enforcement level** that the rollout decision reads is the *effective* policy, not
simply the report's `p`. `Metrics::effectivePolicy()` resolves it from the monitored domain and the
report's `policy_domain`: when a report for a monitored subdomain was published at its
organizational parent (`policy_domain` is a parent of the monitored name), the subdomain policy
`sp` applies per RFC 7489 and is used in preference to `p` (falling back to `p` when `sp` is empty).
Both the single-domain (`latestPolicy()`) and bulk dashboard (`latestPolicyAll()`) paths apply this
so a subdomain already covered by `sp=reject` is not told to start its rollout from `none`.

## Source classification

`SourceClassifier` judges every sending IP itself from the stored `dkim_aligned` / `spf_aligned`
(the receivers' `policy_evaluated` results, which already apply `adkim`/`aspf`) and `disposition`:
`dkim` (aligned DKIM, confirmed automatically, no item), `spf_only` (passed only through SPF, act
now), `rejected` (failed and quarantined/rejected, statistics only) and `unauthenticated` (failed
and delivered, informational). Mixed records take the most urgent class (spf_only >
unauthenticated > dkim > rejected). SPF alone is never trusted: shared provider gateways included
in SPF pass for every customer of the provider. `SourceClassifier::current()` judges the current
window when the source sent mail there, otherwise its lifetime.

`Metrics::flaggedSources()` / `flaggedSourcesAll()` (detail and dashboard paths, identical
results) return only current-window sources with SPF-only or delivered failing mail, ranked by
SPF-only then delivered failing volume, top 20. `Metrics::sourceRows()` feeds the domain-view
table (lifetime figures, `w_`-prefixed window figures, `last_seen`, `class`). The table lists the
top 20 by volume plus every flagged source, so each item can link to its row
(`ActionPresenter::sourceLink()`, anchor `SourceClassifier::anchor()`).

Host names: `SourceHostNames` shows only forward-confirmed PTR names, caches them in Joomla's cache
(`JoomlaHostNameStore`, 7 days confirmed / 1 day otherwise), limits new lookups per call (10) and
checks a shared elapsed-time deadline. `SystemReverseDnsResolver` adds a one-second alarm where
POSIX `pcntl` controls are available; platforms without `pcntl` cannot guarantee a hard in-flight
timeout. It never runs during ingestion. The detail view looks names up only with `dns_diagnostics` on,
the dashboard reads the cache only. Names never influence the classification.
Detail metrics return every flagged source in the current reporting window. Dashboard/all-domain
metrics (FlaggedSourceLimit) return per domain the top 50 open sources in the detail ranking, then up to
200 handled ones (exact action key done/ignored/auto_done or running snooze) in the same ranking;
`flagged_sources_total` carries the full count. Incomplete shared-deadline DNS outcomes are not cached, so later
page views can retry them.

`new_source` is retired (no longer generated). `ActionStatus::resolveConfirmedNewSources()` closes
stored `new_source:*` rows (status open/snoozed) whose source is now DKIM-confirmed with status
`auto_done` and `modified_by = 0`. `autoResolved()` lists them among the handled items. Ignored rows
stay.

## DNS diagnostics

`DnsDiagnostics` checks the published SPF and DMARC records via `DnsResolverInterface`
(`SystemDnsResolver` wraps `dns_get_record`). `txt()` returns `?array`: **null = lookup error**,
**`[]` = no records**. A null result emits a `dns_lookup_error` finding and suppresses the
"publish this record" suggestion (we cannot know what is missing when the lookup itself failed) —
distinct from a genuinely missing record. Keep that distinction when editing. Because
`dns_get_record()` returns `false` for both NXDOMAIN and a transport failure without exposing the
response code, `SystemDnsResolver` only concludes `[]` (absent) when the root zone answers **and**
the host's parent zone still resolves; a failure that also takes out the parent is kept as `null`
(ambiguous) so a domain-wide outage is not reported as a missing record.

A present record is validated before it is read as healthy: more than one DMARC (or SPF) record is
reported as a duplicate, and a malformed required tag — an unknown `p` value or a `pct` that is not
a whole number 0–100 — is reported as invalid (`dns_dmarc_invalid`) rather than silently treated as
a working policy. The version token is matched on a word boundary: `v=DMARC1` / `v=spf1` must end
at the prefix or be followed by a separator, so a lookalike such as `v=DMARC10` or `v=spf10` is not
mistaken for a valid version and the record is treated as missing.

## Help coverage

Every code the engine, `DnsDiagnostics` and `MtaStsDiagnostics` can emit has an entry in
`HelpTopics::ACTION_GROUPS` with a `COM_DMARCREPORTS_HELP_ACTION_*` text in all three locales.
`HelpPageTest` reads the codes from those three sources and fails when one has no help entry, so a new
finding key also needs its help text.

## Persistence

Findings are stored in `#__dmarc_actions` keyed by `(domain_id, action_key)` so an admin can mark
an item done or snooze it (`snooze_until`) and the state survives the next ingestion; `first_seen`
/ `last_seen` track recurrence. `ActionStatus` reconciles freshly-derived findings with stored rows.

## Language

Every title and body is a language key with placeholders (`%s`), written out in full in en-GB,
de-DE and fr-FR — no runtime assembly of admin-visible text. Adding a finding key means adding the
aligned `_TITLE` / `_TEXT` keys to all three locales (lang-lint enforces alignment).
