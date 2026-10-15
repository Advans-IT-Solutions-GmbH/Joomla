#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
FIXTURE="$(mktemp -d)"
trap 'rm -rf "$FIXTURE"' EXIT
mkdir -p "$FIXTURE/bin" "$FIXTURE/build-tools" "$FIXTURE/extension"
cp "$ROOT/shared/build/build.sh" "$FIXTURE/build-tools/build.sh"
cat >"$FIXTURE/bin/rsync" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat >"$FIXTURE/bin/zip" <<'EOF'
#!/usr/bin/env bash
touch "$2"
EOF
chmod +x "$FIXTURE/bin/rsync" "$FIXTURE/bin/zip"
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
