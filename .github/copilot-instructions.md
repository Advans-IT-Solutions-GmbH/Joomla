# Copilot Instructions

Nutze in diesem Repository die Vorgaben aus `/AGENTS.md` (Repo-Root) als maßgebliche Quelle (Git-Workflow, Commit-Konventionen, Branch-Naming).

Repo-spezifische Skills liegen unter `.claude/skills/` und gelten auch für Copilot. Bei passendem Thema jeweils das `SKILL.md` im passenden Skill-Ordner lesen.

Commit-Identität:
- `Advans IT Solutions GmbH <89843389+advansit@users.noreply.github.com>`

Signing-Regeln:
- Repo-Regel: Dieses öffentliche Repository verlangt verifizierte (GPG-signierte) Commits. Signing ist hier Pflicht und im Devcontainer automatisch aktiviert.
- AGENTS-Grundsatz (allgemein): Signing aktivieren, wenn der Key verfügbar ist; ein Commit-Workflow darf nie nur am fehlenden Key scheitern. Dieser Grundsatz hebt die Repo-Pflicht nicht auf.

PR-only-Workflow: Änderungen nur per PR, Squash-Merge, Agenten mergen nie selbst.
