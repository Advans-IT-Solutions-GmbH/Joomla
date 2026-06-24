# Release Workflow

**Canonical source: [README → Releases](../../../../README.md#releases).**
Do not restate the full process here — it lives in the README so it cannot drift.

In short: releases are **two-stage and PR-based**:

```
Release - … (manual)  →  release PR  →  human merge  →  Publish - … (automatic)
prepare version + PR       review          squash         tag + GitHub release
```

## Agent-relevant notes (intentionally not in the README)

- **Never self-merge** the `release: …` PR — a human reviews and merges it.
- **Never set `VERSION`, plugin XML or `update.xml` manually** — the release
  workflow owns version bumps.
- **Run one release workflow at a time.**
- Auto-detect scans Conventional Commits since the last `{prefix}-v*` tag:
  `fix:` → patch, `feat:` → minor, `!` / `BREAKING CHANGE:` → major.
  **Anything else (`docs:`, `chore:`, `refactor:`, `test:`, no prefix) → `none`,
  and no release PR is opened.**
- Each extension has a paired `release-*.yml` / `publish-*.yml` with its own
  `{prefix}-v*` tag prefix.

## Branch Strategy

- `main` — production, always releasable
- Feature/fix branches — short-lived, deleted automatically after merge
- Branch naming: `fix/`, `feat/`, `chore/`, `docs/` prefix
