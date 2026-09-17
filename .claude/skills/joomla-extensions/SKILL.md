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

## Recorded Decisions

- **Minimum platform:** Joomla 5.4 (5.4.x, 6.x) and PHP 8.1 for every extension. Raising the minimum
  to 5.4 ships as a patch release. Details: `references/conventions.md`.
- **CI tracks the newest Joomla:** moving official image tags (newest 5.4.x and 6.x), no pinned
  Joomla patch version; J2Commerce 6 is pinned to commit `7edb6e11`. Details: `references/testing.md`.
- **Deprecations:** the static scan (`shared/tests/deprecated-api-scan.php`) runs in every workflow;
  the production-like lanes fail on deprecations raised directly by extension code
  (`shared-deprecations.php`). Both must stay green.
- **Plugin service providers** create the plugin with the config array only and never pass or set
  the dispatcher (`PluginHelper` sets it when booting the plugin).
- **Releases:** only the latest release per extension exists; the publish workflow deletes older
  releases and tags on purpose. Details: `references/release-workflow.md`.
- **OSMap mixed installation** (J2Store and J2Commerce both enabled): the sitemap has no shop
  entries; this documented limitation stays (issue #182) and is covered by the migration order in
  the OSMap README.
- **Public repository:** no customer data, host names, IP addresses or internal details in code,
  tests, docs, commit messages, pull requests or comments.
- **Review cadence:** fix review findings and push immediately; do not wait for CI. Independent
  reviews use several models; red checks are analysed and fixed at once.

## Workflows

See `references/release-workflow.md` for the full release process.
See `references/testing.md` for running tests locally.
