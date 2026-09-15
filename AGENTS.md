# Agent Instructions — Advans IT Solutions GmbH

## Git-Workflow
- Nie direkt auf `main` committen oder pushen. Jede Änderung über einen Feature-Branch + PR.
- Branch-Namen kurz & beschreibend: `fix/...`, `feat/...`, `docs/...`, `chore/...`.
- Den PR niemals selbst mergen — das macht der Maintainer.
- Squash-Merge; Branch wird nach dem Merge gelöscht.
- Write PRs, commit messages, and documentation (READMEs, skills) in **English** (this is a public repository); the agent instruction files `AGENTS.md`, `CLAUDE.md` and `copilot-instructions.md` are maintained in German.
- **Definition of Done für Copilot-Code-Reviews:** Ein PR mit Copilot-Code-Review ist erst
  **fertig**, wenn jeder Review-Kommentar behandelt ist (Fix committen/pushen **oder** mit
  begründetem Reply ablehnen, falls der Vorschlag nicht sinnvoll ist) — **inklusive** des
  eingeklappten **`Suppressed comments`**-Blocks im Review-**Body** (erscheint **nicht** in
  `reviewThreads`) — **alle** Review-Threads
  geschlossen (resolved) sind und eine erneut angeforderte Copilot-Runde keine neuen Punkte mehr
  findet. Nach jedem Fix-Push Copilot erneut anfordern. Gilt für Entwickler und Agenten. Ablauf:
  Review + Threads via `gh api repos/{owner}/{repo}/pulls/{pull_number}/reviews` und die GraphQL-Query `reviewThreads` holen; jeden offenen
  Thread behandeln; Fixes pushen; Threads mit der GraphQL-Mutation `resolveReviewThread`
  schließen; Copilot per
  `gh api --method POST repos/{owner}/{repo}/pulls/{pull_number}/requested_reviewers -f 'reviewers[]=copilot-pull-request-reviewer[bot]'`
  erneut anfordern; wiederholen, bis nichts mehr kommt.
- **Kein Skill-/Instruktions-Drift.** `AGENTS.md`, `CLAUDE.md` und die intern gepflegte
  SSOT (Single Source of Truth) müssen konsistent bleiben und nur das Minimum
  tragen (kurze Zusammenfassungen plus Verweise, kein duplizierter voller Regelsatz). Jede AI,
  jeder Agent und jeder Entwickler hält diese Dateien schon während der Arbeit driftfrei —
  proaktiv, damit Drift gar nicht erst ins Review gelangt. Das Code-Review (inkl. Copilot) prüft
  die Instruktionsdateien im PR zusätzlich als Absicherung auf Drift.

## Commit-Konventionen
- **Identität von Entwicklern:** Committe unter deinem **eigenen** GitHub-Account — mit deinem
  Namen, deiner eigenen GitHub-No-Reply-Adresse und deinem eigenen Signierschlüssel (für
  Organisationsmitglieder gemäss internem Onboarding).
  - `git config user.name "<Dein Name>"`
  - `git config user.email "<deine eigene GitHub-No-Reply>"`
  - Deine No-Reply-Adresse findest du unter GitHub → Settings → Emails (Option „Keep my email
    addresses private“). Beide Formate sind gültig:
    `<username>@users.noreply.github.com` oder `<ID>+<username>@users.noreply.github.com`.
- **Maintainer und KI-Agenten in seinen Sitzungen** committen als
  `Advans IT Solutions GmbH <89843389+advansit@users.noreply.github.com>`.
- Niemals `@advans.ch`-Adressen verwenden — nutze deine No-Reply-Adresse. (Eine private E-Mail
  im Commit kann bei aktivierter „Block command line pushes that expose my email“ den Push
  ablehnen; die No-Reply-Adresse vermeidet das.)
- **Signing:** Ist ein verifizierter **GPG-Key** vorhanden, signiere damit
  (`git config commit.gpgsign true`); die Commit-E-Mail muss zu diesem Key passen, sonst ist
  die Signatur nicht verifizierbar. Ohne Key committest du unsigniert — kein Commit darf an
  einem fehlenden Key scheitern
  (siehe „Repo-spezifisch (Joomla)“).
- Conventional Commits: `fix:` → Patch, `feat:` → Minor, `feat!:`/`BREAKING CHANGE:` → Major.
  Scope optional (`fix(scope): ...`).
- Kein `Co-authored-by`-Trailer und keine Agent-Signatur (kein „Ona“, „Copilot“ o. ä.).

> **Im Codespace (Cloud):** GitHub konfiguriert Git-Identität und `gh`-Auth automatisch aus
> **deinem eigenen** Account — du committest ohnehin als du selbst. Beim lokal gestarteten
> Devcontainer (Reopen in Container) richtest du deine Identität selbst ein (wie oben). Der
> Container setzt in keinem Fall eine feste Identität oder ein Signing-Skript — das frühere
> `setup-git-signing.sh`, das eine feste Commit-Identität im Container erzwang, ist entfernt.

## Skills
Detailwissen liegt in `.claude/skills/`. Vor repo-spezifischen Aufgaben den passenden Skill lesen.

## Repo-spezifisch (Joomla)
Auf `main` verlangen das Organization-Ruleset und der Branch-Schutz dieses Repos verifizierte
Signaturen. Das erfüllt der Squash-Merge, den GitHub signiert. Commits im Feature-Branch
signierst du, wenn ein verifizierter Schlüssel vorhanden ist; ohne Schlüssel dürfen sie
unsigniert sein.

Die vollständigen Identitäts- und Signing-Konventionen in diesem Abschnitt und unter
„Commit-Konventionen“ sind eigenständig und für externe Contributor ausreichend.

Organization members: the authoritative conventions are maintained internally (single source of truth); consult your internal onboarding.

Generische Extensions in diesem Repository:
- `plg_ajax_joomlaajaxforms`
- `plg_osmap_j2commerce`
- J2Commerce-Extensions

Release-CI leitet die Version aus Conventional Commits ab (`fix(...)` = Patch, `feat(...)` = Minor, `feat!`/`BREAKING CHANGE` = Major).
Nicht erkannte Präfixe (z. B. `docs:` oder `chore:`) müssen als `fix(...)` oder `feat(...)` mit passendem Scope formuliert werden.

Skills liegen in:
- `.claude/skills/joomla-extensions`
- `.claude/skills/privacy-plugin`
