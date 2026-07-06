# Copilot Instructions

Nutze in diesem Repository die Vorgaben aus `/AGENTS.md` (Repo-Root) als maßgebliche Quelle (Git-Workflow, Commit-Konventionen, Branch-Naming).

Repo-spezifische Skills liegen unter `.claude/skills/` und gelten auch für Copilot. Bei passendem Thema jeweils das `SKILL.md` im passenden Skill-Ordner lesen:
- `.claude/skills/joomla-extensions/` — Entwicklung, Tests, Releases, CI/CD
- `.claude/skills/privacy-plugin/` — plg_privacy_j2commerce Domain-Wissen

Commit-Identität:
- Committe unter deiner **eigenen** Identität (Modell B): dein echter Name und deine persönliche
  GitHub-No-Reply-Adresse. Keine feste gemeinsame Firmen-Identität. Details in `/AGENTS.md`.

Signing-Regeln:
- Repo-Regel: Dieses öffentliche Repository verlangt verifizierte (GPG-signierte) Commits. Signing ist hier Pflicht — mit deinem **eigenen** verifizierten Key, dessen E-Mail zur Commit-E-Mail passt.
- Details und das allgemeine Signing-Verhalten stehen in `/AGENTS.md`.

PR-only-Workflow: Änderungen nur per PR, Squash-Merge, Agenten mergen nie selbst.
