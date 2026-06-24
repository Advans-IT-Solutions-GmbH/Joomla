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
- Auto-detect only looks at commits that **touch that extension's path**, since
  the last `{prefix}-v*` tag (or the last 20 such commits if no tag exists yet).
  Conventional-commit **type** is read from the subject, a `BREAKING CHANGE:` /
  `BREAKING-CHANGE:` footer from the body:
  `fix:` → patch, `feat:` → minor, **any type with a `!` before the colon**
  (`feat!:`, `fix!:`, …) **or a breaking-change footer** → major. **Anything else
  (`docs:`, `chore:`, `refactor:`, `test:`, no prefix) → `none`, and no release
  PR is opened.**
- Each extension has a paired `release-*.yml` / `publish-*.yml` with its own
  `{prefix}-v*` tag prefix.

## Branch Strategy

- `main` — production, always releasable
- Feature/fix branches — short-lived, deleted automatically after merge
- Branch naming: `fix/`, `feat/`, `chore/`, `docs/` prefix
