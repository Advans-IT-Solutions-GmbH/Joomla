---
name: joomla-extensions
description: Development workflow for the Advans-IT-Solutions-GmbH/Joomla repository. Use when working on Joomla/J2Commerce extensions, running tests, creating releases, or managing CI/CD workflows.
triggers:
  - release
  - workflow
  - plugin
  - extension
  - version bump
  - test
  - build
  - ZIP
  - branch
  - PR
  - conventional commits
  - j2commerce
  - joomla extension
references:
  - references/repo-structure.md
  - references/release-workflow.md
  - references/conventions.md
  - references/testing.md
---

# Joomla Extensions — Development Workflow

## Repository Structure

```
Advans-IT-Solutions-GmbH/Joomla
├── j2commerce/
│   ├── plg_privacy_j2commerce/         # Privacy plugin (main)
│   ├── com_j2commerce_importexport/    # Import/Export component
│   ├── plg_j2commerce_productcompare/  # Product Compare plugin
│   ├── plg_osmap_j2commerce/           # OSMap sitemap plugin
│   └── com_j2store_cleanup/            # J2Store cleanup component
├── plg_ajax_joomlaajaxforms/       # Joomla AJAX Forms plugin
├── shared/
│   ├── build/build.sh              # Shared packager (per-extension build.sh delegates here)
│   └── tests/                      # Shared test infrastructure
└── .github/workflows/              # CI/CD workflows per plugin
```

## Key Rules

1. **Never set version manually** — the release workflow manages `VERSION`, `build.env`, the extension manifest and `updates/update.xml`
2. **Conventional Commits** — prefix all commits: `fix:`, `feat:`, `docs:`, `chore:`, `test:`, `refactor:`
3. **Auto-delete branches** is enabled — branches are deleted automatically after merge
4. **One PR per plugin** — do not mix changes across plugins in a single PR

## Workflows

See `references/release-workflow.md` for the full release process.
See `references/testing.md` for running tests locally.

- **Fork-PR workflow approval (public repo):** `Joomla` is public, so GitHub gates a first-time contributor's first workflow run behind manual approval (the run sits in `action_required`, shown as "N workflows awaiting approval"). The **Copilot cloud coding agent** (actor `Copilot`) counts as first-time, so this repo's `actions/permissions/fork-pr-contributor-approval` `approval_policy` is set to `first_time_contributors_new_to_github` (least strict) so agent runs start automatically. Check/set: `gh api repos/Advans-IT-Solutions-GmbH/Joomla/actions/permissions/fork-pr-contributor-approval`. Full rationale, values, the accepted security trade-off and the org-wide policy (private repos have no such setting; `.github` deliberately stays strict) live in the org handbook README, Section 7 "Fork-PR Contributor Approval".
