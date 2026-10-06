# Copilot Instructions

Nutze in diesem Repository die Vorgaben aus `/AGENTS.md` (Repo-Root) als maßgebliche Quelle (Git-Workflow, Commit-Konventionen, Branch-Naming).

Repo-spezifische Skills liegen unter `.claude/skills/` und gelten auch für Copilot. Bei passendem Thema jeweils das `SKILL.md` im passenden Skill-Ordner lesen:
- `.claude/skills/joomla-extensions/` — Entwicklung, Tests, Releases, CI/CD
- `.claude/skills/privacy-plugin/` — plg_privacy_j2commerce Domain-Wissen

Commit-Identität:
- Entwickler committen unter ihrer **eigenen** Identität (eigener Account, eigene GitHub-No-Reply-Adresse; signiert, falls ein Schlüssel vorhanden ist).
- Der Maintainer und KI-Agenten in seinen Sitzungen committen als `Advans IT Solutions GmbH <89843389+advansit@users.noreply.github.com>`. Details in `/AGENTS.md`.

Signing-Regeln:
- Auf `main` verlangen das Organization-Ruleset und der Branch-Schutz verifizierte Signaturen.
- Das erfüllt der Squash-Merge, den GitHub signiert. Commits im Feature-Branch signierst du, wenn ein Schlüssel vorhanden ist, der auf GitHub hinterlegt ist und zur Commit-E-Mail passt; ohne Schlüssel dürfen sie unsigniert sein. Details in `/AGENTS.md`.

Lizenz: Alle Extensions stehen unter GPL-3.0-or-later; Kopfvorlage und Regeln für Fremdcode in `/AGENTS.md` (Abschnitt „Lizenz“).

PR-only-Workflow: Änderungen nur per PR, Squash-Merge, Agenten mergen nie selbst.
