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

# A fixture build that stamps its manifest and ships a catalog of its own, like the real build.
cat >ext/build.sh <<'EOF'
#!/bin/bash
set -e
cd "$(dirname "$0")"
source build.env
rm -rf stage && mkdir -p stage
cp payload.txt stage/ && printf 'stamped %s' "$VERSION" >stage/manifest.xml
printf 'catalog of %s at %s' "$VERSION" "$RANDOM" >stage/advans-known-hashes.php
(cd stage && zip -q -r "../${EXTENSION_NAME}_${VERSION}.zip" .)
rm -rf stage
EOF
release() {
    printf 'EXTENSION_NAME="fixture"\nVERSION="%s"\nRELEASE_PREFIX="fixture"\n' "$1" >ext/build.env
    printf '%s' "$2" >ext/payload.txt
    git add -A && git commit -q -m "$3"
}
# The build in progress: what the real build stages before it writes the catalog.
stage() {
    rm -rf "$FIXTURE/stage" && mkdir -p "$FIXTURE/stage"
    cp ext/payload.txt "$FIXTURE/stage/"
    printf 'stamped %s' "$1" >"$FIXTURE/stage/manifest.xml"
}
sha() { printf '%s' "$1" | sha256sum | cut -d ' ' -f 1; }
catalog() { tail -n +2 "$1"; }

release 1.0.0 'first payload' 'release: fixture v1.0.0 (#1)'
release 1.1.0 'second payload' 'release: fixture v1.1.0 [skip ci]'
git tag fixture-v1.1.0
release 1.1.0 'third payload' 'feat: work for 1.2.0'

# Release preparation: the version is raised in the working tree, its release commit does not exist yet.
sed -i 's/VERSION="1.1.0"/VERSION="1.2.0"/' ext/build.env
stage 1.2.0
php "$GENERATOR" ext "$FIXTURE/prepared.php" "$FIXTURE/stage" >/dev/null
for content in 'first payload' 'second payload' 'third payload' 'stamped 1.0.0' 'stamped 1.1.0' 'stamped 1.2.0'; do
    catalog "$FIXTURE/prepared.php" | grep -q "$(sha "$content")"
done
if catalog "$FIXTURE/prepared.php" | grep -q 'catalog of'; then
    echo 'FAIL: a catalog listed catalog contents'
    exit 1
fi
catalog "$FIXTURE/prepared.php" | grep -q '"complete":true'
head -n 1 "$FIXTURE/prepared.php" | grep -qx '<?php exit; ?>'
echo 'PASS: every release commit is rebuilt and its built files (stamped ones included) are hashed'

# Publication: the release commit exists now and is HEAD.
git add -A && git commit -q -m 'release: fixture v1.2.0 (#3)'
RELEASE_120="$(git rev-parse HEAD)"
stage 1.2.0
php "$GENERATOR" ext "$FIXTURE/published.php" "$FIXTURE/stage" >/dev/null
cmp "$FIXTURE/prepared.php" "$FIXTURE/published.php"
# A later build of another extension (a package) that still contains this unchanged release.
git commit -q --allow-empty -m 'release: other v2.0.0'
php "$GENERATOR" ext "$FIXTURE/later.php" "$FIXTURE/stage" >/dev/null
cmp "$FIXTURE/prepared.php" "$FIXTURE/later.php"
echo 'PASS: preparation, publication and later builds produce byte-identical catalogs'

# A recovery build of 1.2.0 after a newer release and its tag exist: only the history of the
# commit being built counts, so the catalog (and with it the package checksum) never changes.
release 1.2.1 'fourth payload' 'release: fixture v1.2.1 (#4)'
git tag fixture-v1.2.1
git checkout -q "$RELEASE_120"
stage 1.2.0
php "$GENERATOR" ext "$FIXTURE/recovered.php" "$FIXTURE/stage" >/dev/null
cmp "$FIXTURE/published.php" "$FIXTURE/recovered.php"
if catalog "$FIXTURE/recovered.php" | grep -q "$(sha 'fourth payload')"; then
    echo 'FAIL: a newer release entered the catalog of an older commit'
    exit 1
fi
git checkout -q -
echo 'PASS: a historical rebuild after a newer release and its tag is byte-identical'

ADVANS_KNOWN_HASHES=skip php "$GENERATOR" ext "$FIXTURE/skipped.php" "$FIXTURE/stage" >/dev/null
catalog "$FIXTURE/skipped.php" | grep -q '"complete":false'
catalog "$FIXTURE/skipped.php" | grep -q "$(sha 'stamped 1.2.0')"
echo 'PASS: a skipped catalog is marked incomplete and still lists the build in progress'
if ADVANS_KNOWN_HASHES=skip ADVANS_KNOWN_HASHES_STRICT=1 php "$GENERATOR" ext "$FIXTURE/strict-skipped.php" "$FIXTURE/stage" >/dev/null 2>&1; then
    echo 'FAIL: a strict build accepted skipped earlier releases'
    exit 1
fi
echo 'PASS: skipped earlier releases fail a strict build'

git clone -q --depth 1 "file://$REPO" "$FIXTURE/shallow"
php "$GENERATOR" "$FIXTURE/shallow/ext" "$FIXTURE/shallow.php" "$FIXTURE/stage" >/dev/null
catalog "$FIXTURE/shallow.php" | grep -q '"complete":false'
if ADVANS_KNOWN_HASHES_STRICT=1 php "$GENERATOR" "$FIXTURE/shallow/ext" "$FIXTURE/strict.php" "$FIXTURE/stage" >/dev/null 2>&1; then
    echo 'FAIL: a strict build accepted a shallow history'
    exit 1
fi
echo 'PASS: a shallow history is incomplete and fails a strict build'

printf 'exit 1\n' >>ext/build.sh
git add -A && git commit -q -m 'release: fixture v1.3.0'
if ADVANS_KNOWN_HASHES_STRICT=1 php "$GENERATOR" ext "$FIXTURE/failed.php" "$FIXTURE/stage" >/dev/null 2>&1; then
    echo 'FAIL: a release that cannot be rebuilt was accepted by a strict build'
    exit 1
fi
echo 'PASS: a release that cannot be rebuilt fails a strict build'
