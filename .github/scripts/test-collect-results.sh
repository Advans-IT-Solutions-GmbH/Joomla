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
    *"/actions/runs?"*) if [ -n "${RUNS_FILE:-}" ]; then cat "$RUNS_FILE"; else printf '%s' "$RUNS"; fi ;;
    *"/commits/${MERGE_SHA}/check-runs?"*) [ "$CHECK_RUNS" = FAIL ] && { echo 'HTTP 502' >&2; exit 1; }; printf '%s' "$CHECK_RUNS" ;;
    *"/check-runs?"*) echo "Wrong commit used for check-run lookup: $*" >&2; exit 2 ;;
    *"/actions/runs/"*) [ "$SINGLE_RUN" = FAIL ] && { echo 'HTTP 502' >&2; exit 1; }; printf '%s' "$SINGLE_RUN" ;;
    *) echo "Unexpected API call: $*" >&2; exit 2 ;;
esac
EOF
chmod +x "$FIXTURE/bin/gh"
export PATH="$FIXTURE/bin:$PATH"
export GH_TOKEN=fixture REPO=owner/repo PR_NUMBER=1 BASE_REF=main
export BASE_SHA=1111111111111111111111111111111111111111
export HEAD_SHA=2222222222222222222222222222222222222222
export MERGE_SHA=3333333333333333333333333333333333333333
export GITHUB_STEP_SUMMARY="$FIXTURE/summary.md"
export WORKFLOW_DIR="$FIXTURE/workflows" MISSING_RUN_GRACE_MINUTES=0 MAX_WAIT_MINUTES=0
export FILES='{"filename":"README.md","previous_filename":null}'
export CHECK_RUNS='[{"check_runs":[]}]' SINGLE_RUN='{}'
export JOBS='[{"jobs":[
  {"name":"Security / Gitleaks (secret scan)","status":"completed","conclusion":"success"},
  {"name":"Security / Semgrep (SAST)","status":"completed","conclusion":"success"},
  {"name":"Security / Actions Security (zizmor + actionlint)","status":"completed","conclusion":"success"}
]}]'

cat >"$WORKFLOW_DIR/security.yml" <<'EOF'
on:
  pull_request:
    branches: [main, advansit-superuser-calendar-administration, advansit-guest-privacy-requests]
EOF
cat >"$WORKFLOW_DIR/extension.yml" <<'EOF'
on:
  pull_request:
    branches: [main, advansit-superuser-calendar-administration, advansit-guest-privacy-requests]
    paths: ['extension/**']
EOF

run_json() {
    jq -cn --arg conclusion "$1" --arg status "${2:-completed}" --arg base "$BASE_REF" \
        --arg base_sha "$BASE_SHA" --arg head_sha "$HEAD_SHA" \
        '[{workflow_runs:[{id:1,run_number:1,run_attempt:1,path:".github/workflows/security.yml",
          event:"pull_request",pull_requests:[{number:1,base:{ref:$base,sha:$base_sha},head:{sha:$head_sha}}],
          status:$status,conclusion:$conclusion,html_url:"https://example.invalid/run/1"}]}]'
}
run_json_with_extension() {
    jq '.[0].workflow_runs += [ (.[0].workflow_runs[0] | .id=2 | .run_number=2 |
        .path=".github/workflows/extension.yml" | .html_url="https://example.invalid/run/2")]' \
        <<<"$(run_json success)"
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

for base in main advansit-superuser-calendar-administration advansit-guest-privacy-requests; do
    export BASE_REF="$base"
    export RUNS="$(run_json success)"
    check "documentation requires Security Scan for base $base" pass
done
export BASE_REF=main
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
for base in main advansit-superuser-calendar-administration advansit-guest-privacy-requests; do
    export BASE_REF="$base"
    export RUNS="$(run_json_with_extension)"
    check "matching extension run succeeds for base $base" pass
done
export BASE_REF=main
for conclusion in failure cancelled skipped; do
    export RUNS="$(run_json_with_extension | jq --arg conclusion "$conclusion" \
        '.[0].workflow_runs[1].conclusion = $conclusion')"
    check "required extension conclusion $conclusion fails closed" fail
done
export RUNS="$(run_json success | jq '.[0].workflow_runs += [ (.[0].workflow_runs[0] | .id=2 | .run_number=2 |
    .path=".github/workflows/extension.yml" | .pull_requests[0].number=2)]')"
check 'same head on another pull request cannot satisfy this pull request' fail
export RUNS="$(run_json success | jq '.[0].workflow_runs += [ (.[0].workflow_runs[0] | .id=2 | .run_number=2 |
    .path=".github/workflows/extension.yml" | .pull_requests[0].base.sha="4444444444444444444444444444444444444444")]')"
check 'success on an older base commit cannot satisfy this merge' fail
export FILES='{"filename":"README.md","previous_filename":"extension/old.php"}'
check 'missing rename-source-only extension remains optional' pass
export FILES=''
check 'empty changed-file response fails closed' fail
export FILES='{"filename":"README.md","previous_filename":null}'
export RUNS="$(jq '.[0].workflow_runs += [.[0].workflow_runs[0] | .run_number=2 | .conclusion="failure"]' <<<"$(run_json success)")"
check 'newest failed run wins over older success' fail
export RUNS="$(jq '.[0].workflow_runs += [.[0].workflow_runs[0] | .run_attempt=2 | .conclusion="success"] | .[0].workflow_runs[0].conclusion="failure"' <<<"$(run_json success)")"
check 'latest successful attempt supersedes previous failure' pass

# A pull request that runs every workflow lists more than the 128 KiB Linux allows for one
# argument. The list comes from a file here, because an environment variable has that limit too.
jq -c '.[0].workflow_runs[0].padding = ("x" * 200000)' <<<"$(run_json success)" >"$FIXTURE/runs.json"
export RUNS_FILE="$FIXTURE/runs.json"
check 'a run list above the 128 KiB argument limit is read' pass
unset RUNS_FILE

# The run list lags behind under congested runners; the commit's check runs do not.
export RUNS='[{"workflow_runs":[]}]'
export CHECK_RUNS='[{"check_runs":[{"details_url":"https://github.com/owner/repo/actions/runs/7/job/70"},
  {"details_url":"https://example.invalid/other-app"},{"details_url":null}]}]'
single_run() {
    jq -cn --arg status "$1" --arg conclusion "$2" --arg event "${3:-pull_request}" --arg head_sha "${4:-$HEAD_SHA}" \
        --arg pr "${5:-$PR_NUMBER}" --arg base "${6:-$BASE_REF}" --arg base_sha "${7:-$BASE_SHA}" \
        '{id:7,run_number:1,run_attempt:1,path:".github/workflows/security.yml",event:$event,head_sha:$head_sha,
          pull_requests:[{number:($pr|tonumber),base:{ref:$base,sha:$base_sha},head:{sha:$head_sha}}],
          status:$status,conclusion:(if $conclusion == "" then null else $conclusion end),html_url:"https://example.invalid/run/7"}'
}
export SINGLE_RUN="$(single_run completed success)"
check 'run missing from the lagging list is found through the check runs' pass
export SINGLE_RUN="$(single_run queued '')"
check 'queued run found through the check runs is awaited, not reported missing' fail
if grep -q 'no run exists' "$FIXTURE/output" || ! grep -q 'did not finish' "$FIXTURE/output"; then
    echo 'FAIL: a queued run must count as present'
    cat "$FIXTURE/output"
    exit 1
fi
echo 'PASS: a queued run counts as present'
export SINGLE_RUN=FAIL
check 'a failed lookup of a run found through the check runs fails closed' fail
export RUNS="$(run_json success)" CHECK_RUNS=FAIL
check 'failed check-run discovery cannot pass on an older success in the lagging list' fail
grep -q 'could not be read completely' "$FIXTURE/output" || { echo 'FAIL: discovery failure must be reported'; cat "$FIXTURE/output"; exit 1; }
echo 'PASS: discovery failure is reported'
export RUNS='[{"workflow_runs":[]}]'
export CHECK_RUNS='[{"check_runs":[{"details_url":"https://github.com/owner/repo/actions/runs/7/job/70"}]}]'
export SINGLE_RUN="$(single_run completed success push)"
check 'a run of another event is not taken from the check runs' fail
export SINGLE_RUN="$(single_run completed success pull_request other)"
check 'a run of another commit is not taken from the check runs' fail

if ! grep -q "$BASE_REF" "$GITHUB_STEP_SUMMARY" ||
   ! grep -q "$BASE_SHA" "$GITHUB_STEP_SUMMARY" ||
   ! grep -q "$HEAD_SHA" "$GITHUB_STEP_SUMMARY" ||
   ! grep -q "$MERGE_SHA" "$GITHUB_STEP_SUMMARY"; then
    echo 'FAIL: the tested base, head and merge SHAs must be reported'
    cat "$GITHUB_STEP_SUMMARY"
    exit 1
fi
echo 'PASS: base, head and tested merge provenance is reported'

export BASE_REF=unsupported
check 'unsupported pull request base fails closed' fail
export BASE_REF=main
cp "$WORKFLOW_DIR/security.yml" "$FIXTURE/security.yml"
cat >"$WORKFLOW_DIR/security.yml" <<'EOF'
on:
  pull_request:
    paths: ['**']
EOF
check 'missing pull_request.branches filter fails closed' fail
cat >"$WORKFLOW_DIR/security.yml" <<'EOF'
on:
  pull_request:
    branches: [main, '*']
EOF
check 'wildcard pull_request.branches filter fails closed' fail
cat >"$WORKFLOW_DIR/security.yml" <<'EOF'
on:
  pull_request:
    branches: [main]
EOF
export BASE_REF=advansit-guest-privacy-requests
check 'filter omitting the actual dependent base fails closed' fail
export BASE_REF=main
cp "$FIXTURE/security.yml" "$WORKFLOW_DIR/security.yml"
