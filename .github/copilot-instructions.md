# Copilot Instructions

Nutze in diesem Repository die Vorgaben aus `/AGENTS.md` (Repo-Root) als maßgebliche Quelle (Git-Workflow, Commit-Konventionen, Branch-Naming).

Repo-spezifische Skills liegen unter `.claude/skills/` und gelten auch für Copilot. Bei passendem Thema jeweils das `SKILL.md` im passenden Skill-Ordner lesen:
- `.claude/skills/joomla-extensions/` — Entwicklung, Tests, Releases, CI/CD
- `.claude/skills/privacy-plugin/` — plg_privacy_j2commerce Domain-Wissen

Commit-Identität:
- Entwickler committen unter ihrer **eigenen** Identität (eigener Account, eigene GitHub-No-Reply-Adresse, eigener Signierschlüssel).
- Der Maintainer und KI-Agenten in seinen Sitzungen committen als `Advans IT Solutions GmbH <89843389+advansit@users.noreply.github.com>`. Details in `/AGENTS.md`.

Signing-Regeln:
- Auf `main` verlangen das Organization-Ruleset und der Branch-Schutz verifizierte Signaturen.
- Konvention dieses öffentlichen Repos: jeder Commit, auch im Feature-Branch, ist mit einem verifizierten Schlüssel signiert, dessen E-Mail zur Commit-E-Mail passt. Details in `/AGENTS.md`.

PR-only-Workflow: Änderungen nur per PR, Squash-Merge, Agenten mergen nie selbst.
