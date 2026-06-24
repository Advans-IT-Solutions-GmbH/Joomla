# Joomla Extensions Repository

Extensions for [Joomla](https://github.com/joomla/joomla-cms) and [J2Commerce](https://github.com/joomla-projects/j2commerce) developed and maintained by Advans IT Solutions GmbH.

## Repository Structure

```
Joomla/
├── plg_ajax_joomlaajaxforms/ # Joomla AJAX Forms Plugin
├── j2commerce/               # J2Commerce Extensions
│   ├── com_j2commerce_importexport/
│   ├── com_j2store_cleanup/
│   ├── plg_osmap_j2commerce/
│   ├── plg_j2commerce_productcompare/
│   └── plg_privacy_j2commerce/
├── shared/                   # Shared build and test scripts
└── .github/                  # CI/CD workflows
```

## Available Extensions

### Joomla Core Extensions

| Extension | Description | Joomla |
|-----------|-------------|--------|
| [Joomla! AJAX Forms](plg_ajax_joomlaajaxforms/) | AJAX login, registration, MFA, profile editing, password reset, username reminder, J2Store cart operations | 5.x – 6.x |

### J2Commerce Extensions

| Extension | Description |
|-----------|-------------|
| [Import/Export](j2commerce/com_j2commerce_importexport/) | Bulk data import/export for J2Commerce |
| [J2Store Cleanup](j2commerce/com_j2store_cleanup/) | Detect incompatible extensions after J2Store-to-J2Commerce migration |
| [OSMap J2Commerce](j2commerce/plg_osmap_j2commerce/) | Adds J2Commerce products to the OSMap sitemap automatically |
| [Product Compare](j2commerce/plg_j2commerce_productcompare/) | Compare products side-by-side |
| [Privacy](j2commerce/plg_privacy_j2commerce/) | GDPR compliance for J2Commerce |

## Testing

Each extension has automated tests that run via GitHub Actions when files in the respective directory are modified.

| Workflow | Extension | Trigger |
|----------|-----------|---------|
| `joomla-ajax-forms.yml` | Joomla AJAX Forms | `plg_ajax_joomlaajaxforms/**` |
| `j2commerce-import-export.yml` | Import/Export | `j2commerce/com_j2commerce_importexport/**` |
| `j2store-cleanup.yml` | J2Store Cleanup | `j2commerce/com_j2store_cleanup/**` |
| `osmap-j2commerce.yml` | OSMap J2Commerce | `j2commerce/plg_osmap_j2commerce/**` |
| `j2commerce-product-compare.yml` | Product Compare | `j2commerce/plg_j2commerce_productcompare/**` |
| `j2commerce-privacy.yml` | Privacy | `j2commerce/plg_privacy_j2commerce/**` |

View test results: https://github.com/advansit/Joomla/actions

## Releases

Releases use a **two-stage, PR-based flow** so that no commit reaches `main` without review (the `main` branch is protected by organization rulesets — see [Branch Protection](#branch-protection)). Each extension has its own release and publish workflow, tag prefix and independent version.

### How to create a release

1. Go to **Actions** → select the **Release - …** workflow for the extension
2. Click **Run workflow** and choose a bump level (or leave empty for auto-detect from commits)
3. The workflow bumps the version files on a `release/<prefix>-v<version>` branch and opens a **pull request** titled `release: <prefix> v<version>` — it does **not** push to `main`
4. **Review and merge** that pull request into `main` (squash)
5. On merge, the matching **Publish - …** workflow triggers automatically: it builds the package, creates and pushes the tag, generates the changelog and creates the GitHub release

Run release workflows one at a time. For planned compatibility releases, set the bump explicitly instead of relying on auto-detect.

Auto-detect uses [Conventional Commits](https://www.conventionalcommits.org/) since the last tag:

| Commit Prefix | Version Bump | Example |
|---------------|-------------|---------|
| `fix:` | Patch (1.0.0 → 1.0.1) | `fix: correct cart count query` |
| `feat:` | Minor (1.0.0 → 1.1.0) | `feat: add product export filter` |
| `feat!:` or `BREAKING CHANGE:` | Major (1.0.0 → 2.0.0) | `feat!: require Joomla 5+` |

Commits without a conventional prefix are ignored — no release is created.

### Release workflows

| Workflow | Tag Pattern |
|----------|-------------|
| `release-joomla-ajax-forms.yml` | `ajaxforms-v*` |
| `release-importexport.yml` | `importexport-v*` |
| `release-cleanup.yml` | `cleanup-v*` |
| `release-productcompare.yml` | `productcompare-v*` |
| `release-privacy.yml` | `privacy-v*` |
| `release-osmap-j2commerce.yml` | `osmap-j2commerce-v*` |

Release workflows replace older releases and tags for the same extension prefix after the new release is created.

View all releases: https://github.com/advansit/Joomla/releases

## Repository Configuration

This repository is configured with the following GitHub settings for security, automation, and collaboration.

### Security Settings

All security features are configured at: **Settings → Security → Code security and analysis**

#### Private Vulnerability Reporting
**Status:** ✅ Enabled  
**Documentation:** https://docs.github.com/en/code-security/security-advisories/working-with-repository-security-advisories/configuring-private-vulnerability-reporting-for-a-repository

Allows security researchers to privately report potential security vulnerabilities directly to repository maintainers. Reports are submitted via GitHub's security advisory system and remain private until disclosed.

#### Dependency Graph
**Status:** ✅ Enabled  
**Documentation:** https://docs.github.com/en/code-security/supply-chain-security/understanding-your-software-supply-chain/about-the-dependency-graph

Automatically detects and displays all dependencies (Composer packages, GitHub Actions) used in this repository. Provides visibility into the software supply chain.

#### Dependabot Alerts
**Status:** ✅ Enabled  
**Documentation:** https://docs.github.com/en/code-security/dependabot/dependabot-alerts/about-dependabot-alerts

Automatically notifies maintainers when dependencies have known security vulnerabilities. Alerts appear in the Security tab and via notifications.

#### Dependabot Security Updates
**Status:** ✅ Enabled (with grouped updates)  
**Documentation:** https://docs.github.com/en/code-security/dependabot/dependabot-security-updates/about-dependabot-security-updates

Automatically creates pull requests to update dependencies with known security vulnerabilities. Grouped updates combine multiple security updates into a single pull request to reduce noise.

#### Dependabot Version Updates
**Status:** ✅ Enabled  
**Configuration:** `.github/dependabot.yml`  
**Documentation:** https://docs.github.com/en/code-security/dependabot/dependabot-version-updates/about-dependabot-version-updates

Automatically creates pull requests to keep dependencies up-to-date (not just security updates). Configured to check weekly for:
- Composer dependencies in plugin directories
- GitHub Actions workflows

#### Code Scanning (CodeQL)
**Status:** ✅ Enabled (default setup)  
**Documentation:** https://docs.github.com/en/code-security/code-scanning/introduction-to-code-scanning/about-code-scanning

Automatically analyzes code for security vulnerabilities and coding errors using GitHub's CodeQL engine. Runs on every push and pull request. Detects issues like SQL injection, XSS, and other common vulnerabilities.

#### Copilot Autofix
**Status:** ✅ Enabled  
**Documentation:** https://docs.github.com/en/code-security/code-scanning/managing-code-scanning-alerts/about-autofix-for-codeql-code-scanning

Automatically suggests fixes for code scanning alerts using AI. Provides code suggestions to remediate security vulnerabilities detected by CodeQL.

#### Secret Scanning
**Status:** ✅ Enabled  
**Documentation:** https://docs.github.com/en/code-security/secret-scanning/about-secret-scanning

Scans the entire repository history for accidentally committed secrets (API keys, tokens, passwords). Alerts maintainers when secrets are detected.

#### Push Protection
**Status:** ✅ Enabled  
**Documentation:** https://docs.github.com/en/code-security/secret-scanning/push-protection-for-repositories-and-organizations

Prevents commits containing secrets from being pushed to the repository. Blocks the push and alerts the developer before secrets are committed.

### Access Control

#### Code Review Limits
**Status:** ✅ Enabled - Collaborators only  
**Location:** Settings → Moderation → Code review limits  
**Documentation:** https://docs.github.com/en/repositories/managing-your-repositorys-settings-and-features/managing-repository-settings/managing-pull-request-reviews-in-your-repository

Only users with write access (collaborators) can approve or request changes on pull requests. All users can still comment and create pull requests.

#### Branch Protection
**Branch:** `main`  
**Enforced via:** Organization **rulesets** (Settings → Rules → Rulesets at the organization level), not classic branch protection. Two rulesets apply to this repository: the org-wide *Protect default branch* baseline plus the public-repo *Public repos - signed, PR-reviewed, checks* ruleset.  
**Documentation:** https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-rulesets/about-rulesets

**Enabled rules:**
- ✅ Require a pull request before merging
- ✅ Require **1** approving review
- ✅ Dismiss stale pull request approvals when new commits are pushed
- ✅ Require approval of the most recent reviewable push
- ✅ Require conversation resolution before merging
- ✅ Require status checks to pass before merging — required check: `Collect Results`
- ✅ Require branches to be up to date before merging (strict)
- ✅ Require signed commits
- ✅ Block force pushes (non-fast-forward)
- ✅ Block branch deletion
- ✅ Allowed merge method: **squash only**

**Bypass:**
- Only **organization administrators** may bypass these rules. `github-actions[bot]` has **no** bypass and can no longer push directly to `main` — releases now go through the two-stage PR flow described in [Releases](#releases).



### Repository Features

**Location:** Settings → General → Features

#### Enabled Features
- ✅ **Issues** - Bug reports and feature requests from the community
- ✅ **Discussions** - Community support and Q&A
- ✅ **Projects** - Public roadmap and project management

#### Disabled Features
- ❌ **Wikis** - Documentation is maintained in README files
- ❌ **Sponsorships** - Not accepting sponsorships

### Security Policy

**Source:** org-wide policy in [`Advans-IT-Solutions-GmbH/.github`](https://github.com/Advans-IT-Solutions-GmbH/.github/blob/main/SECURITY.md)  
**Documentation:** https://docs.github.com/en/code-security/getting-started/adding-a-security-policy-to-your-repository

This repository inherits the organization-wide security policy (no repo-level `SECURITY.md`). It defines how security vulnerabilities should be reported, directs users to GitHub's private vulnerability reporting system, and provides contact information for urgent issues.

---

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.

