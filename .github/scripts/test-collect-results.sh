#!/usr/bin/env bash
set -euo pipefail

command -v jq >/dev/null
command -v yq >/dev/null
SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/collect-results.sh"
FIXTURE="$(mktemp -d)"
trap 'rm -rf "$FIXTURE"' EXIT
mkdir -p "$FIXTURE/bin" "$FIXTURE/workflows"
cat >"$FIXTURE/bin/gh" <<'EOF'
#!/usr/bin/env bash
set -euo pipefail
case "$*" in
    *"/files?"*) printf '%s' "$FILES" ;;
    *"/jobs?"*) printf '%s' "$JOBS" ;;
    *"/actions/runs?"*) printf '%s' "$RUNS" ;;
    *) echo "Unexpected API call: $*" >&2; exit 2 ;;
esac
EOF
chmod +x "$FIXTURE/bin/gh"
export PATH="$FIXTURE/bin:$PATH"
export GH_TOKEN=fixture REPO=owner/repo PR_NUMBER=1 HEAD_SHA=built
export WORKFLOW_DIR="$FIXTURE/workflows" MISSING_RUN_GRACE_MINUTES=0 MAX_WAIT_MINUTES=0
export FILES='{"filename":"README.md","previous_filename":null}'
export JOBS='[{"jobs":[
  {"name":"Security / Gitleaks (secret scan)","status":"completed","conclusion":"success"},
  {"name":"Security / Semgrep (SAST)","status":"completed","conclusion":"success"},
  {"name":"Security / Actions Security (zizmor + actionlint)","status":"completed","conclusion":"success"}
]}]'

cat >"$WORKFLOW_DIR/security.yml" <<'EOF'
on:
  pull_request:
    branches: [main]
EOF
cat >"$WORKFLOW_DIR/extension.yml" <<'EOF'
on:
  pull_request:
    branches: [main]
    paths: ['extension/**']
EOF

run_json() {
    jq -cn --arg conclusion "$1" --arg status "${2:-completed}" \
        '[{workflow_runs:[{id:1,run_number:1,run_attempt:1,path:".github/workflows/security.yml",
          status:$status,conclusion:$conclusion,html_url:"https://example.invalid/run/1"}]}]'
}
check() {
    local name="$1" expected="$2" result=0
    bash "$SCRIPT" >"$FIXTURE/output" 2>&1 || result=$?
    if { [ "$expected" = pass ] && [ "$result" -ne 0 ]; } ||
       { [ "$expected" = fail ] && [ "$result" -eq 0 ]; }; then
        echo "FAIL: $name (exit $result)"
        cat "$FIXTURE/output"
        exit 1
    fi
    echo "PASS: $name"
}

export RUNS="$(run_json success)"
check 'documentation still requires no-path security workflow' pass
for conclusion in failure cancelled skipped neutral timed_out action_required; do
    export RUNS="$(run_json "$conclusion")"
    check "security conclusion $conclusion fails closed" fail
done
export RUNS='[{"workflow_runs":[]}]'
check 'missing unconditional workflow fails closed' fail
export RUNS="$(run_json '' queued)"
check 'unfinished unconditional workflow cannot pass' fail
export RUNS="$(run_json success)"
SAVED_JOBS="$JOBS"
export JOBS='[{"jobs":[]}]'
check 'missing actual security jobs fails closed' fail
export JOBS="$(jq '.[0].jobs[0].conclusion = "skipped"' <<<"$SAVED_JOBS")"
check 'workflow success with skipped Gitleaks fails closed' fail
export JOBS="$SAVED_JOBS"
export FILES='{"filename":"extension/src.php","previous_filename":null}'
check 'matching extension run is also required' fail
export FILES='{"filename":"README.md","previous_filename":"extension/old.php"}'
check 'missing rename-source-only extension remains optional' pass
export FILES=''
check 'empty changed-file response fails closed' fail
export FILES='{"filename":"README.md","previous_filename":null}'
export RUNS="$(jq '.[0].workflow_runs += [.[0].workflow_runs[0] | .run_number=2 | .conclusion="failure"]' <<<"$(run_json success)")"
check 'newest failed run wins over older success' fail
export RUNS="$(jq '.[0].workflow_runs += [.[0].workflow_runs[0] | .run_attempt=2 | .conclusion="success"] | .[0].workflow_runs[0].conclusion="failure"' <<<"$(run_json success)")"
check 'latest successful attempt supersedes previous failure' pass
