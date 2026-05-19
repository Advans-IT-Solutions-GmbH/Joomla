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
| [Joomla! AJAX Forms](plg_ajax_joomlaajaxforms/) | AJAX login, registration, MFA, profile editing, password reset, username reminder, J2Store cart operations | 5.x – 7.x |

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

Releases are created **manually** via the GitHub Actions UI (`workflow_dispatch`). Each extension has its own release workflow, tag prefix and independent version.

### How to create a release

1. Go to **Actions** → select the release workflow for the extension
2. Click **Run workflow**
3. Choose a bump level or leave empty for auto-detect from commits

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

All releases and tags are preserved, so users can track changes between versions.

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
**Location:** Settings → Branches  
**Documentation:** https://docs.github.com/en/repositories/configuring-branches-and-merges-in-your-repository/managing-protected-branches/about-protected-branches

**Enabled rules:**
- ✅ Require a pull request before merging
- ✅ Dismiss stale pull request approvals when new commits are pushed
- ✅ Require status checks to pass before merging — required check: `Collect Results`
- ✅ Require branches to be up to date before merging
- ✅ Require conversation resolution before merging
- ✅ Require signed commits
- ✅ Require linear history
- ☐ Do not allow bypassing the above settings — **disabled** to allow `github-actions[bot]` to push release commits directly to `main`

**Disabled rules:**
- ☐ Require approvals
- ☐ Require review from Code Owners
- ☐ Require approval of the most recent reviewable push
- ☐ Require deployments to succeed before merging
- ☐ Lock branch
- ☐ Allow force pushes
- ☐ Allow deletions

**GPG signing setup (dev environment):**
A GPG key is configured in this Gitpod environment (`BBB1295FE1391E99`, `pascal.raphael@advans.ch`).
The corresponding public key is registered on GitHub under Settings → SSH and GPG keys.
Git is configured to sign all commits automatically (`commit.gpgsign=true`).
If the environment is recreated, a new GPG key must be generated, added to GitHub, and configured via:
```bash
gpg --batch --gen-key ...   # generate key
git config --global user.signingkey <KEY_ID>
git config --global commit.gpgsign true
```

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

**File:** `SECURITY.md`  
**Documentation:** https://docs.github.com/en/code-security/getting-started/adding-a-security-policy-to-your-repository

Defines how security vulnerabilities should be reported. Directs users to GitHub's private vulnerability reporting system and provides contact information for urgent issues.

---

**Advans IT Solutions GmbH**  
Karl-Barth-Platz 9  
4052 Basel  
Switzerland  
CHE-316.407.165

https://advans.ch

Copyright (C) 2026 Advans IT Solutions GmbH. All rights reserved.
