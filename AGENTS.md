# Agent Instructions — Advans IT Solutions GmbH

## Git-Workflow
- Nie direkt auf `main` committen oder pushen. Jede Änderung über einen Feature-Branch + PR.
- Branch-Namen kurz & beschreibend: `fix/...`, `feat/...`, `docs/...`, `chore/...`.
- Den PR niemals selbst mergen — das macht der Maintainer.
- Squash-Merge; Branch wird nach dem Merge gelöscht.
- Write PRs, commit messages, and documentation in **English** (this is a public repository).
- **Definition of Done für Copilot-Code-Reviews:** Ein PR mit Copilot-Code-Review ist erst
  **fertig**, wenn jeder Review-Kommentar behandelt ist (Fix committen/pushen **oder** mit
  begründetem Reply ablehnen, falls der Vorschlag nicht sinnvoll ist), **alle** Review-Threads
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
- **Eigene Identität (Modell B):** Committe unter deinem **eigenen** GitHub-Account — deinem
  echten Namen und deiner persönlichen GitHub-No-Reply-Adresse. Es gibt **keine** feste
  gemeinsame Firmen-Identität, unter der committet wird.
  - `git config user.name "<Dein echter Name>"`
  - `git config user.email "<deine persönliche GitHub-No-Reply>"`
  - Deine No-Reply-Adresse findest du unter GitHub → Settings → Emails (Option „Keep my email
    addresses private“). Beide Formate sind gültig:
    `<username>@users.noreply.github.com` oder `<ID>+<username>@users.noreply.github.com`.
- Niemals `@advans.ch`-Adressen verwenden — nutze deine No-Reply-Adresse. (Eine private E-Mail
  im Commit kann bei aktivierter „Block command line pushes that expose my email“ den Push
  ablehnen; die No-Reply-Adresse vermeidet das.)
- **Signing:** Verwende deinen **eigenen** verifizierten **GPG-Key**
  (`git config commit.gpgsign true`). Die Commit-E-Mail muss zu diesem Key passen, sonst ist
  die Signatur nicht verifizierbar. Für dieses öffentliche Repo ist Signing **verpflichtend**
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
Dieses öffentliche Repo erzwingt per Organization-Ruleset **verifizierte GPG-Signaturen** —
Signing ist hier verpflichtend (nicht optional). Eine Commit-E-Mail, die nicht zu deinem
verifizierten Key passt, erzeugt eine nicht-verifizierbare Signatur und der Merge wird abgelehnt.
Damit gilt für dieses Repo: Der oben unter „Commit-Konventionen“ beschriebene
**eigener verifizierter GPG-Key** (Modell B) ist Pflicht — nicht optional.

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
