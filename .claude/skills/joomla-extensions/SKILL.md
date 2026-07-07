---
name: joomla-extensions
description: Development workflow for the advansit/Joomla repository. Use when working on Joomla/J2Commerce extensions, running tests, creating releases, or managing CI/CD workflows.
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
advansit/Joomla
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
├── tests/                          # Top-level test runner
└── .github/workflows/              # CI/CD workflows per plugin
```

## Key Rules

1. **Never set version manually** — the release workflow manages VERSION, j2commerce.xml, and update.xml
2. **Conventional Commits** — prefix all commits: `fix:`, `feat:`, `docs:`, `chore:`, `test:`, `refactor:`
3. **Auto-delete branches** is enabled — branches are deleted automatically after merge
4. **One PR per plugin** — do not mix changes across plugins in a single PR

## Workflows

See `references/release-workflow.md` for the full release process.
See `references/testing.md` for running tests locally.
