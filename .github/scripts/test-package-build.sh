#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE="$(mktemp -d)"
trap 'rm -rf "$FIXTURE"' EXIT
mkdir -p "$FIXTURE/bin" "$FIXTURE/build-tools" "$FIXTURE/extension"
cp "$ROOT/shared/build/build.sh" "$FIXTURE/build-tools/build.sh"
printf '%s\n' '<?php exit(0);' >"$FIXTURE/build-tools/prepare-installer.php"
printf '%s\n' '<?php exit(0);' >"$FIXTURE/build-tools/known-hashes.php"
cat >"$FIXTURE/build-tools/deterministic-zip.sh" <<'EOF'
#!/usr/bin/env bash
touch "$2"
EOF
cat >"$FIXTURE/bin/rsync" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
chmod +x "$FIXTURE/bin/rsync"
export PATH="$FIXTURE/bin:$PATH"
cat >"$FIXTURE/extension/build.env" <<'EOF'
EXTENSION_NAME=fixture
EXTENSION_TYPE=plugin
VERSION=1.0.0
EOF
cat >"$FIXTURE/build-tools/verify-package.sh" <<'EOF'
#!/usr/bin/env bash
touch verification-ran
exit "${VERIFY_EXIT:-0}"
EOF
chmod -x "$FIXTURE/build-tools/verify-package.sh"
cd "$FIXTURE/extension"
bash "$FIXTURE/build-tools/build.sh" >"$FIXTURE/output" 2>&1
test -f verification-ran
echo 'PASS: non-executable verifier is invoked through Bash'
if VERIFY_EXIT=7 bash "$FIXTURE/build-tools/build.sh" >"$FIXTURE/output" 2>&1; then
    echo 'FAIL: rejected package was reported successful'
    exit 1
fi
echo 'PASS: verifier failure fails the build'
rm "$FIXTURE/build-tools/verify-package.sh"
if bash "$FIXTURE/build-tools/build.sh" >"$FIXTURE/output" 2>&1; then
    echo 'FAIL: missing verifier was silently skipped'
    exit 1
fi
echo 'PASS: missing verifier fails the build'

# The same content must give the same archive bytes, whatever the timestamps, modes and the
# order in which the file system lists the entries.
for copy in first second; do
    mkdir -p "$FIXTURE/zip-$copy/b/c"
done
printf 'one' >"$FIXTURE/zip-first/a.txt"
printf 'two' >"$FIXTURE/zip-first/b/c/d.txt"
printf 'two' >"$FIXTURE/zip-second/b/c/d.txt"
printf 'one' >"$FIXTURE/zip-second/a.txt"
touch -d '2020-05-05 10:00:00' "$FIXTURE/zip-first/a.txt"
chmod 600 "$FIXTURE/zip-second/a.txt"
bash "$ROOT/shared/build/deterministic-zip.sh" "$FIXTURE/zip-first" "$FIXTURE/first.zip"
sleep 1
bash "$ROOT/shared/build/deterministic-zip.sh" "$FIXTURE/zip-second" "$FIXTURE/second.zip"
if [ "$(sha256sum <"$FIXTURE/first.zip")" != "$(sha256sum <"$FIXTURE/second.zip")" ]; then
    echo 'FAIL: equal content produced different package bytes'
    exit 1
fi
unzip -Z1 "$FIXTURE/first.zip" | tr '\n' ' ' | grep -qx 'a.txt b/ b/c/ b/c/d.txt '
echo 'PASS: equal content produces identical package bytes'
