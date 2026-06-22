# Devcontainer – GPG Signing & Auth Setup

Dieses Verzeichnis enthält die Konfiguration für den GitHub Codespaces-Container dieses Repos.
Beim Start wird automatisch `setup-git-signing.sh` ausgeführt, das GPG-Commit-Signing und
gh-Authentifizierung einrichtet.

## Vorausgesetzte Codespaces-Secrets

Die folgenden Secrets werden als **organisationsweite Codespaces-Secrets** erwartet
(Einstellungen unter *Organisation → Settings → Secrets and variables → Codespaces*):

| Secret | Beschreibung | Pflicht |
|---|---|---|
| `GPG_PRIVATE_KEY` | ASCII-armored privater GPG-Schlüssel | Nein |
| `GH_PAT` | GitHub Personal Access Token für git/gh | Nein |

## Automatisches GPG-Signing

Wenn `GPG_PRIVATE_KEY` gesetzt ist:
- wird der Schlüssel per `gpg --batch --import` importiert,
- die Key-ID **dynamisch** aus dem importierten Schlüssel ermittelt (nicht hardcodiert),
- `user.signingkey`, `commit.gpgsign true`, `tag.gpgsign true` und `gpg.program gpg` gesetzt,
- `pinentry-mode loopback` in `~/.gnupg/gpg.conf` eingetragen (kein Passphrase-Dialog).

Die committende Identität wird immer auf die Org-Identität gesetzt:

```
Name : Advans IT Solutions GmbH
Email: 89843389+advansit@users.noreply.github.com
```

## gh-Authentifizierung

Wenn `GH_PAT` gesetzt ist, wird `gh auth login --with-token` und `gh auth setup-git`
ausgeführt. Ist `gh` nicht installiert, wird stattdessen ein Git Credential Helper für
`github.com` konfiguriert.

## Fehlertoleranz

Das Skript verwendet **kein `set -e`**. Fehlende Secrets oder fehlschlagende Schritte
stoppen den Container-Start **nie**:

- Kein `GPG_PRIVATE_KEY` → unsignierte Commits (Commits scheitern nie nur am fehlenden Key).
- Kein `GH_PAT` → keine automatische gh-Authentifizierung; interaktiver Login via `gh auth login` weiterhin möglich.
- Keine Secret-Werte werden in Logs ausgegeben.

Am Ende des Starts gibt das Skript eine kurze Status-Zusammenfassung aus (ohne Secrets).
