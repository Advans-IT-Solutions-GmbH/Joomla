# Agent Instructions — Advans IT Solutions GmbH

## Git-Workflow
- Nie direkt auf `main` committen oder pushen. Jede Änderung über einen Feature-Branch + PR.
- Branch-Namen kurz & beschreibend: `fix/...`, `feat/...`, `docs/...`, `chore/...`.
- Den PR niemals selbst mergen — das macht der Maintainer.
- Squash-Merge; Branch wird nach dem Merge gelöscht.

## Commit-Konventionen
- **Eigene Identität (Modell B):** Committe unter deinem **eigenen** GitHub-Account — deinem
  echten Namen und deiner persönlichen GitHub-No-Reply-Adresse. Es gibt **keine** feste
  gemeinsame Firmen-Identität, unter der committet wird.
  - `git config user.name  "<Dein echter Name>"`
  - `git config user.email "<deine persönliche GitHub-No-Reply>"`
  - Deine No-Reply-Adresse findest du unter GitHub → Settings → Emails („Keep my email
    addresses private"). Beide Formate sind gültig:
    `<username>@users.noreply.github.com` oder `<ID>+<username>@users.noreply.github.com`.
- Niemals `@advans.ch`-Adressen verwenden (GitHub blockiert den Push wegen E-Mail-Privacy).
- **Signing:** Verwende deinen **eigenen** verifizierten GPG-/SSH-Key
  (`git config commit.gpgsign true`). Die Commit-E-Mail muss zu diesem Key passen, sonst ist
  die Signatur nicht verifizierbar. Für dieses öffentliche Repo ist Signing **verpflichtend**
  (siehe „Repo-spezifisch (Joomla)").
- Conventional Commits: `fix:` → Patch, `feat:` → Minor, `feat!:`/`BREAKING CHANGE:` → Major.
  Scope optional (`fix(scope): ...`).
- Kein `Co-authored-by`-Trailer und keine Agent-Signatur (kein „Ona“, „Copilot“ o. ä.).

## Skills
Detailwissen liegt in `.claude/skills/`. Vor repo-spezifischen Aufgaben den passenden Skill lesen.

## Repo-spezifisch (Joomla)
Dieses öffentliche Repo erzwingt per Organization-Ruleset **verifizierte GPG-Signaturen** —
Signing ist hier verpflichtend (nicht optional). Eine Commit-E-Mail, die nicht zu deinem
verifizierten Key passt, erzeugt eine nicht-verifizierbare Signatur und der Merge wird abgelehnt.
Damit gilt für dieses Repo: Der oben unter „Commit-Konventionen" beschriebene
**eigene verifizierte GPG-/SSH-Key** (Modell B) ist Pflicht — nicht optional.

Die vollständigen Identitäts- und Signing-Konventionen in diesem Abschnitt und unter
„Commit-Konventionen" sind eigenständig und für externe Contributor ausreichend. Org-Mitglieder
finden die ausführliche Fassung (SSOT) im **separaten, privaten** Org-Repo
`Advans-IT-Solutions-GmbH/org`, Datei `conventions/git-and-commits.md` (nur für Org-Mitglieder
zugänglich — kein Bestandteil dieses Repos).

Generische Extensions in diesem Repository:
- `plg_ajax_joomlaajaxforms`
- `plg_osmap_j2commerce`
- J2Commerce-Extensions

Release-CI leitet die Version aus Conventional Commits ab (`fix(...)` = Patch, `feat(...)` = Minor, `feat!`/`BREAKING CHANGE` = Major).
Nicht erkannte Präfixe (z. B. `docs:` oder `chore:`) müssen als `fix(...)` oder `feat(...)` mit passendem Scope formuliert werden.

Skills liegen in:
- `.claude/skills/joomla-extensions`
- `.claude/skills/privacy-plugin`
