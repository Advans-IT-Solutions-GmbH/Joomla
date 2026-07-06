# Agent Instructions — Advans IT Solutions GmbH

## Git-Workflow
- Nie direkt auf `main` committen oder pushen. Jede Änderung über einen Feature-Branch + PR.
- Branch-Namen kurz & beschreibend: `fix/...`, `feat/...`, `docs/...`, `chore/...`.
- Den PR niemals selbst mergen — das macht der Maintainer.
- Squash-Merge; Branch wird nach dem Merge gelöscht.

## Commit-Konventionen
- Commit-Email immer: `89843389+advansit@users.noreply.github.com`
- Commit-Name immer:  `Advans IT Solutions GmbH`
- Niemals `@advans.ch`-Adressen verwenden (GitHub blockiert den Push).
- Diese Adresse ist an den verifizierten GPG-Signing-Key gebunden. Signing aktivieren,
  WENN der Key in der Umgebung vorhanden ist (`git config commit.gpgsign true`);
  andernfalls unsigniert committen — kein Commit darf am fehlenden Key scheitern.
- Conventional Commits: `fix:` → Patch, `feat:` → Minor, `feat!:`/`BREAKING CHANGE:` → Major.
  Scope optional (`fix(scope): ...`).
- Kein `Co-authored-by`-Trailer und keine Agent-Signatur (kein „Ona“, „Copilot“ o. ä.).

## Skills
Detailwissen liegt in `.claude/skills/`. Vor repo-spezifischen Aufgaben den passenden Skill lesen.

## Repo-spezifisch (Joomla)
Dieses öffentliche Repo erzwingt verifizierte GPG-Signaturen — Signing ist hier verpflichtend (nicht optional).
Eine E-Mail, die nicht zum verifizierten Key passt, erzeugt eine nicht-verifizierbare Signatur und der Merge wird abgelehnt.

Verwende deine **eigene** Identität (Modell B) — maßgeblich, nicht hier duplizieren:
das private Org-Repo `Advans-IT-Solutions-GmbH/org`, Datei [`conventions/git-and-commits.md`](https://github.com/Advans-IT-Solutions-GmbH/org/blob/main/conventions/git-and-commits.md):
- `git config user.email "<ID>+<username>@users.noreply.github.com"`  (deine persönliche GitHub-No-Reply)
- `git config user.name  "<Dein Name>"`
- **eigener verifizierter GPG/SSH-Key** — in diesem öffentlichen Repo Pflicht, sonst wird der Merge abgelehnt.

Generische Extensions in diesem Repository:
- `plg_ajax_joomlaajaxforms`
- `plg_osmap_j2commerce`
- J2Commerce-Extensions

Release-CI leitet die Version aus Conventional Commits ab (`fix(...)` = Patch, `feat(...)` = Minor, `feat!`/`BREAKING CHANGE` = Major).
Nicht erkannte Präfixe (z. B. `docs:` oder `chore:`) müssen als `fix(...)` oder `feat(...)` mit passendem Scope formuliert werden.

Skills liegen in:
- `.claude/skills/joomla-extensions`
- `.claude/skills/privacy-plugin`
