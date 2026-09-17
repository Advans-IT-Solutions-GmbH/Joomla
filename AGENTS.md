# Agent Instructions — Advans IT Solutions GmbH

## Git-Workflow
- Nie direkt auf `main` committen oder pushen. Jede Änderung über einen Feature-Branch + PR.
- Branch-Namen kurz & beschreibend: `fix/...`, `feat/...`, `docs/...`, `chore/...`.
- Den PR niemals selbst mergen — das macht der Maintainer.
  - Solange der Maintainer allein arbeitet, mergt er bewusst per Admin-Bypass der Review-Pflicht;
    vorher müssen alle Checks grün sein.
  - Vor dem Merge prüft der Maintainer die Änderung auf seiner Staging-Umgebung. Dort liegen echte
    Kundendaten: nichts davon in Commits, PRs, Kommentare, Tests oder Doku übernehmen.
- Squash-Merge; Branch wird nach dem Merge gelöscht.
- **Review-Takt:** Review-Befunde sofort beheben und pushen, nicht auf die CI warten; rote Checks
  sofort auswerten und beheben. Unabhängige Reviews laufen mit mehreren Modellen; die
  Copilot-Runde unten bleibt Teil der Definition of Done.
- **Öffentliches Repository:** keine Kundendaten, Bestellnummern, Hostnamen, IP-Adressen,
  Site-spezifischen IDs, internen Prozessdetails oder Verweise auf private Repositories — weder in
  Code, Tests und Doku noch in Commit-Nachrichten, PR-Texten und Kommentaren.
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
  Namen und deiner eigenen GitHub-No-Reply-Adresse (signiert, falls ein Schlüssel vorhanden ist;
  für Organisationsmitglieder gemäss internem Onboarding).
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
- **Signing:** Ist ein verifizierter Signierschlüssel vorhanden, signiere damit
  (`git config commit.gpgsign true`); die Commit-E-Mail muss zu diesem Key passen, sonst ist
  die Signatur nicht verifizierbar. Ohne Key committest du unsigniert — kein Commit darf an
  einem fehlenden Key scheitern
  (siehe „Repo-spezifisch (Joomla)“). Startet GPG in der Umgebung nicht, nicht reparieren, sondern
  unsigniert committen und pushen.
- Conventional Commits: `fix:` → Patch, `feat:` → Minor, `feat!:`/`BREAKING CHANGE:` → Major.
  Scope optional (`fix(scope): ...`).
- Kein `Co-authored-by`-Trailer und keine Agent-Signatur (kein „Ona“, „Copilot“ o. ä.).

> **Im Codespace (Cloud):** GitHub konfiguriert Git-Identität und `gh`-Auth automatisch aus
> **deinem eigenen** Account — du committest ohnehin als du selbst. Beim lokal gestarteten
> Devcontainer (Reopen in Container) richtest du deine Identität selbst ein (wie oben). Der
> Container setzt in keinem Fall eine feste Identität oder ein Signing-Skript — das frühere
> `setup-git-signing.sh`, das eine feste Commit-Identität im Container erzwang, ist entfernt.

## Lizenz
- Alle Extensions stehen unter **GPL-3.0-or-later**; jede Extension liefert den unveränderten GPL-3.0-Text als `LICENSE.txt` mit.
- Neue Dateien tragen den Lizenzkopf mit `@copyright`, `@license` und `SPDX-License-Identifier: GPL-3.0-or-later` (Vorlage: `.claude/skills/joomla-extensions/references/conventions.md`).
- Fremdcode nur mit GPL-3.0-kompatibler Lizenz übernehmen (nie GPL-2.0-only); fremde Copyright-Hinweise nie entfernen, Quelle in `THIRD-PARTY-NOTICES.txt` der Extension festhalten.

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
Nicht erkannte Präfixe (z. B. `docs:` oder `chore:`) lösen keinen Release aus. Nur wenn ein Versionssprung gewollt ist, wird die Änderung als `fix(...)` oder `feat(...)` mit passendem Scope formuliert.

Festgehaltene Entscheidungen (Details im Skill `joomla-extensions`):
- Alle Extensions verlangen Joomla 5.4 oder neuer; die Anhebung der Mindestversion wird als Patch-Release ausgeliefert.
- Die CI testet immer die neuesten Joomla-5.4.x- und 6.x-Releases; J2Commerce 6 ist auf einen Commit gepinnt.
- Deprecation-Gate in den produktionsnahen Lanes und statischer Scan nach veralteten Joomla-APIs in jeder Workflow-Datei; beide müssen grün sein.
- Plugin-Service-Provider übergeben den Dispatcher weder im Konstruktor noch per `setDispatcher()`.
- Pro Extension existiert nur das neueste GitHub-Release; ältere Releases und Tags löscht der Publish-Workflow bewusst.
- OSMap: Sind J2Store und J2Commerce gleichzeitig aktiv, bleibt die Sitemap ohne Shop-Einträge (dokumentierte Einschränkung, Issue #182); abgedeckt durch die Reihenfolge der Migration.
- Lizenz: Umstellung auf GPL-3.0-or-later mit Regeln für fremden Code in einem eigenen PR (#189).

Skills liegen in:
- `.claude/skills/joomla-extensions`
- `.claude/skills/privacy-plugin`
