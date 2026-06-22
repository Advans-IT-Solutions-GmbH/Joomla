#!/usr/bin/env bash
# Setup script for GPG commit-signing and Git/gh authentication in Codespaces.
# Fehlertolerant: fehlende Secrets oder Fehler stoppen den Container-Start NICHT.
# Keine Secret-Werte werden geloggt.

GPG_OK=false
GH_OK=false

# ---------------------------------------------------------------------------
# 1. Git-Identität (immer setzen)
# ---------------------------------------------------------------------------
git config --global user.name  "Advans IT Solutions GmbH"
git config --global user.email "89843389+advansit@users.noreply.github.com"

# ---------------------------------------------------------------------------
# 2. GPG-Import und Signing-Konfiguration
# ---------------------------------------------------------------------------
if [ -n "${GPG_PRIVATE_KEY}" ]; then
    echo "${GPG_PRIVATE_KEY}" | gpg --batch --import 2>/dev/null || true

    # Key-ID dynamisch aus dem importierten Schlüssel ermitteln (erste sec-Zeile)
    KEY_ID=$(gpg --list-secret-keys --with-colons 2>/dev/null \
        | awk -F: '/^sec/{print $5; exit}')

    if [ -n "${KEY_ID}" ]; then
        git config --global user.signingkey  "${KEY_ID}"
        git config --global commit.gpgsign   true
        git config --global tag.gpgsign      true
        git config --global gpg.program      gpg

        # Nicht-interaktives Signieren (kein Passphrase-Dialog)
        mkdir -p ~/.gnupg
        chmod 700 ~/.gnupg
        grep -qxF 'pinentry-mode loopback' ~/.gnupg/gpg.conf 2>/dev/null \
            || echo 'pinentry-mode loopback' >> ~/.gnupg/gpg.conf
        chmod 600 ~/.gnupg/gpg.conf

        export GPG_TTY=$(tty 2>/dev/null || true)

        GPG_OK=true
        echo "[setup-git-signing] GPG signing aktiviert (Key-ID: ${KEY_ID})."
    else
        echo "[setup-git-signing] WARNUNG: GPG_PRIVATE_KEY gesetzt, aber kein Key-ID ermittelt – kein Signing."
    fi
else
    echo "[setup-git-signing] GPG_PRIVATE_KEY nicht gesetzt – unsignierte Commits."
fi

# ---------------------------------------------------------------------------
# 3. GH_PAT / gh-Authentifizierung
# ---------------------------------------------------------------------------
if [ -n "${GH_PAT}" ]; then
    if command -v gh >/dev/null 2>&1; then
        echo "${GH_PAT}" | gh auth login --with-token 2>/dev/null || true
        gh auth setup-git 2>/dev/null || true
        GH_OK=true
        echo "[setup-git-signing] gh auth konfiguriert."
    else
        # gh nicht vorhanden – store-basierten Credential-Helper für github.com setzen
        git config --global credential.https://github.com.helper \
            "!f(){ echo username=x-access-token; echo ******; }; f" 2>/dev/null || true
        GH_OK=true
        echo "[setup-git-signing] gh nicht gefunden – Git Credential Helper für github.com gesetzt."
    fi
else
    echo "[setup-git-signing] GH_PAT nicht gesetzt – keine automatische gh-Authentifizierung."
fi

# ---------------------------------------------------------------------------
# 4. Status-Zusammenfassung (keine Secret-Werte)
# ---------------------------------------------------------------------------
echo ""
echo "=== setup-git-signing: Zusammenfassung ==="
echo "  Git-Identität : $(git config --global user.name) <$(git config --global user.email)>"
echo "  GPG-Signing   : ${GPG_OK}"
echo "  gh-Auth       : ${GH_OK}"
echo "==========================================="
