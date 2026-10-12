# DMARC domain knowledge

Background needed to work on the parser, metrics and Action Center. Authoritative spec: RFC 7489.

## Aggregate reports (RFC 7489)

Mailbox providers send **aggregate (rua) reports** — not forensic/failure (ruf) reports — as a
compressed XML attachment, typically once per day per reporter. Only aggregate reports are parsed.

Structure:

- `report_metadata` — reporter `org_name`, `email`, `report_id`, and a `date_range` (`begin`/`end` as Unix timestamps).
- `policy_published` — the domain's published policy at report time: `p` (domain policy),
  `sp` (subdomain policy), `pct`, `adkim`/`aspf` (alignment mode `r` relaxed / `s` strict).
- one or more `record` rows — per sending source: `source_ip`, `count`, evaluated
  `disposition` (`none`/`quarantine`/`reject`), and the `dkim`/`spf` auth results plus the
  DMARC-evaluated alignment.

## SPF, DKIM, alignment

A message **passes DMARC** when at least one of SPF or DKIM both *passes* and is *aligned*
with the header-from domain. Alignment can be relaxed (organizational domain matches) or strict
(exact match). A source can pass SPF yet fail alignment (e.g. a third-party sender using its own
envelope domain) — those are the senders that break under a strict policy.

Stored per record: `spf_result`, `dkim_result`, `spf_aligned`, `dkim_aligned`, `disposition`,
`header_from`. Metrics derive the **aligned-pass rate** = share of messages (weighted by `count`)
where SPF-aligned OR DKIM-aligned.

## Policy rollout: none → quarantine → reject

The DMARC policy is tightened in stages so legitimate mail is never lost:

| Policy | Effect | Move on when |
|--------|--------|--------------|
| `p=none` | Monitor only, nothing blocked | Aligned-pass rate above the quarantine threshold (default 95%) and no unhandled legitimate sender |
| `p=quarantine` | Failing mail to spam | Aligned-pass rate above the reject threshold (default 99%) and stable |
| `p=reject` | Failing mail rejected | — (goal state) |

The thresholds are the component options `quarantine_pass_threshold` / `reject_pass_threshold`.
The "would-be-rejected share" metric is the fraction of mail that would be blocked **today** if
the domain were at `p=reject` — the key number for deciding whether tightening is safe.

## Why this matters for the Action Center

The whole point is to tell a non-expert admin *when it is safe to tighten* and *which senders to
fix first*. See `action-center.md`. Help text in the UI explains the rollout in the site language;
all admin-visible wording comes from language keys (no runtime string assembly).
