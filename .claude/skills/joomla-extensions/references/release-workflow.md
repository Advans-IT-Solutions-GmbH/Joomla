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
- **No release before the human-like test.** Merging to `main` follows the Definition of Done.
  A `Release - …` workflow only prepares the release PR and may run before the test. The
  `release: …` PR is merged only after the CI package of its head has been tested on a test
  installation (never a production site): every form, function and layout changed since the last
  release, positive and negative, at 1600 and 375 px. The report goes into the release PR as a
  comment, free of host names and customer data. Rule and negative-test scope: `AGENTS.md`.
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
- Raising the minimum Joomla version (currently 5.4) is released as a patch;
  a higher bump is only chosen when the maintainer wants it.
- Each extension has a paired `release-*.yml` / `publish-*.yml` with its own
  `{prefix}-v*` tag prefix.
- **Only the latest release per extension exists — intentionally.** After
  verified publication, `.github/scripts/publish-release.py` deletes strictly
  all older GitHub releases and tags of the same prefix. Do not "fix" this.
  Consequence: an older version cannot be downloaded from GitHub, so the CI job
  `Update from previous release (J6)` rebuilds the previous version with
  `build.sh` from the last `release: <prefix> v` commit on `main`.
- **Every build rebuilds the release history.** `shared/build/known-hashes.php` rebuilds every
  merged `release: <prefix> vX.Y.Z` commit (prefix = `RELEASE_PREFIX` in `build.env`) with that
  commit's own build scripts and ships the hashes of the built files as
  `advansinstaller/known-hashes.php`; updates use it to recognise untouched files. Builds need the
  full history (`fetch-depth: 0`); CI, release and publish builds set
  `ADVANS_KNOWN_HASHES_STRICT=1`, so a shallow clone or a release that no longer builds fails.
  `ADVANS_KNOWN_HASHES=skip` skips it for a quick local build (the package then reports every
  differing file as changed). Details: README, "Installation safety".
- **`update.xml` carries `<sha256>`.** The release workflow writes the SHA-256 of its reproducible
  build into update.xml in the release PR; the publish workflow rebuilds the merged release commit
  and stops before uploading when the hash differs (then run the release workflow again). Packages
  are zipped by `shared/build/deterministic-zip.sh`; `updates/` is not part of the package.
- Release preparation keeps `GITHUB_TOKEN` authentication. Owner permission and
  manual approval of the generated PR's CI are documented in
  [README → Release permissions and CI approval](../../../../README.md#release-permissions-and-ci-approval).

## Branch Strategy

- `main` — production, always releasable
- Feature/fix branches — short-lived, deleted automatically after merge
- Branch naming: `fix/`, `feat/`, `chore/`, `docs/` prefix
