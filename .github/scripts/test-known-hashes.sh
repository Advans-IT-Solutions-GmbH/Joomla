#!/usr/bin/env bash
# Offline test of shared/build/known-hashes.php against a disposable git history.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GENERATOR="$ROOT/shared/build/known-hashes.php"
FIXTURE="$(mktemp -d)"
trap 'rm -rf "$FIXTURE"' EXIT
REPO="$FIXTURE/repo"
mkdir -p "$REPO/ext"
cd "$REPO"
git init -q
git config user.email test@example.invalid
git config user.name test
git config commit.gpgsign false

cat >ext/build.sh <<'EOF'
#!/bin/bash
set -e
cd "$(dirname "$0")"
source build.env
mkdir -p stage && cp payload.txt stage/ && printf 'stamped %s' "$VERSION" >stage/manifest.xml
(cd stage && zip -q -r "../${EXTENSION_NAME}_${VERSION}.zip" .)
rm -rf stage
EOF
release() {
    printf 'EXTENSION_NAME="fixture"\nVERSION="%s"\nRELEASE_PREFIX="fixture"\n' "$1" >ext/build.env
    printf '%s' "$2" >ext/payload.txt
    git add -A && git commit -q -m "$3"
}
sha() { printf '%s' "$1" | sha256sum | cut -d ' ' -f 1; }
catalog() { tail -n +2 "$1"; }

release 1.0.0 'first payload' 'release: fixture v1.0.0 (#1)'
release 1.1.0 'second payload' 'release: fixture v1.1.0 [skip ci]'
release 1.1.0 'work in progress' 'feat: unreleased work'
git tag fixture-v1.1.0 HEAD~1

php "$GENERATOR" ext "$FIXTURE/catalog.php" >/dev/null
catalog "$FIXTURE/catalog.php" | grep -q '"versions":\["1.0.0","1.1.0"\]'
for content in 'first payload' 'second payload' 'stamped 1.0.0' 'stamped 1.1.0'; do
    catalog "$FIXTURE/catalog.php" | grep -q "$(sha "$content")"
done
if catalog "$FIXTURE/catalog.php" | grep -q "$(sha 'work in progress')"; then
    echo 'FAIL: unreleased work was catalogued'
    exit 1
fi
catalog "$FIXTURE/catalog.php" | grep -q '"complete":true'
head -n 1 "$FIXTURE/catalog.php" | grep -qx '<?php exit; ?>'
echo 'PASS: every release commit is rebuilt and its built files (stamped ones included) are hashed'

git checkout -q HEAD~1
php "$GENERATOR" ext "$FIXTURE/at-release.php" >/dev/null
catalog "$FIXTURE/at-release.php" | grep -q '"versions":\["1.0.0"\]'
echo 'PASS: the release commit being built is not its own earlier release'
git checkout -q -

ADVANS_KNOWN_HASHES=skip php "$GENERATOR" ext "$FIXTURE/skipped.php" >/dev/null
catalog "$FIXTURE/skipped.php" | grep -q '"complete":false'
echo 'PASS: a skipped catalog is marked incomplete'

git clone -q --depth 1 "file://$REPO" "$FIXTURE/shallow"
php "$GENERATOR" "$FIXTURE/shallow/ext" "$FIXTURE/shallow.php" >/dev/null
catalog "$FIXTURE/shallow.php" | grep -q '"complete":false'
if ADVANS_KNOWN_HASHES_STRICT=1 php "$GENERATOR" "$FIXTURE/shallow/ext" "$FIXTURE/strict.php" >/dev/null 2>&1; then
    echo 'FAIL: a strict build accepted a shallow history'
    exit 1
fi
echo 'PASS: a shallow history is incomplete and fails a strict build'

printf 'exit 1\n' >>ext/build.sh
git add -A && git commit -q -m 'release: fixture v1.2.0'
printf 'EXTENSION_NAME="fixture"\nVERSION="1.3.0"\nRELEASE_PREFIX="fixture"\n' >ext/build.env
if ADVANS_KNOWN_HASHES_STRICT=1 php "$GENERATOR" ext "$FIXTURE/failed.php" >/dev/null 2>&1; then
    echo 'FAIL: a release that cannot be rebuilt was accepted by a strict build'
    exit 1
fi
echo 'PASS: a release that cannot be rebuilt fails a strict build'
