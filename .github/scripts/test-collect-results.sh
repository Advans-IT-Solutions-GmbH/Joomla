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
    *"/commits/${HEAD_SHA}/check-runs?"*) [ "$CHECK_RUNS" = FAIL ] && { echo 'HTTP 502' >&2; exit 1; }; printf '%s' "$CHECK_RUNS" ;;
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

run_title() {
    printf 'PR #%s base=%s base-sha=%s head-sha=%s merge-sha=%s' \
        "${1:-$PR_NUMBER}" "${2:-$BASE_REF}" "${3:-$BASE_SHA}" "${4:-$HEAD_SHA}" "${5:-$MERGE_SHA}"
}

RUN_NAME="\${{ github.event_name == 'pull_request' && format('PR #{0} base={1} base-sha={2} head-sha={3} merge-sha={4}', github.event.pull_request.number, github.event.pull_request.base.ref, github.event.pull_request.base.sha, github.event.pull_request.head.sha, github.sha) || (github.event_name == 'push' && github.event.head_commit.message) || github.workflow }}"
write_security_workflow() {
    cat >"$WORKFLOW_DIR/security.yml" <<EOF
run-name: "$RUN_NAME"
on:
  pull_request:
    branches: [$1]
EOF
}
write_extension_workflow() {
    cat >"$WORKFLOW_DIR/extension.yml" <<EOF
run-name: "$RUN_NAME"
on:
  pull_request:
    branches: [$1]
    paths: ['extension/**']
EOF
}
write_security_workflow 'main, advansit-superuser-calendar-administration, advansit-guest-privacy-requests'
write_extension_workflow 'main, advansit-superuser-calendar-administration, advansit-guest-privacy-requests'

run_json() {
    jq -cn --arg conclusion "$1" --arg status "${2:-completed}" --arg base "$BASE_REF" \
        --arg base_sha "$BASE_SHA" --arg head_sha "$HEAD_SHA" --arg merge_sha "$MERGE_SHA" \
        --arg pr "$PR_NUMBER" --arg display_title "$(run_title)" \
        '[{workflow_runs:[{id:1,run_number:1,run_attempt:1,path:".github/workflows/security.yml",
          event:"pull_request",head_sha:$head_sha,display_title:$display_title,
          pull_requests:[{number:($pr|tonumber),base:{ref:$base,sha:$base_sha},head:{sha:$head_sha}}],
          status:$status,conclusion:$conclusion,html_url:"https://example.invalid/run/1"}]}]'
}
run_json_with_extension() {
    jq '.[0].workflow_runs += [ (.[0].workflow_runs[0] | .id=2 | .run_number=2 |
        .path=".github/workflows/extension.yml" | .html_url="https://example.invalid/run/2")]' \
        <<<"$(run_json success)"
}
set_security_run() {
    export BASE_REF="$1"
    export RUNS="$(run_json "${2-success}" "${3-completed}")"
}
set_extension_run() {
    export BASE_REF="$1"
    export RUNS="$(run_json_with_extension)"
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
    set_security_run "$base"
    check "documentation requires Security Scan for base $base" pass
done
for base in main advansit-superuser-calendar-administration advansit-guest-privacy-requests; do
    set_security_run "$base"
    export RUNS="$(jq '.[0].workflow_runs[0].pull_requests=[]' <<<"$RUNS")"
    check "exact event-derived run title accepts empty PR association for base $base" pass
done
set_security_run main
export RUNS="$(jq '.[0].workflow_runs[0].pull_requests=[] |
    .[0].workflow_runs[0].display_title="PR #2 base=main base-sha=" + $ENV.BASE_SHA +
        " head-sha=" + $ENV.HEAD_SHA + " merge-sha=" + $ENV.MERGE_SHA' <<<"$RUNS")"
check 'empty association with another PR number cannot satisfy this pull request' fail
set_security_run main
export RUNS="$(jq '.[0].workflow_runs[0].display_title |= sub("base-sha=" + $ENV.BASE_SHA; "base-sha=4444444444444444444444444444444444444444")' <<<"$RUNS")"
check 'a valid association with a stale event-derived base SHA is rejected' fail
for conclusion in failure cancelled skipped neutral timed_out action_required; do
    set_security_run main "$conclusion"
    check "security conclusion $conclusion fails closed" fail
done
export BASE_REF=main
export RUNS='[{"workflow_runs":[]}]'
check 'missing unconditional workflow fails closed' fail
set_security_run main '' queued
check 'unfinished unconditional workflow cannot pass' fail
set_security_run main
SAVED_JOBS="$JOBS"
export JOBS='[{"jobs":[]}]'
check 'missing actual security jobs fails closed' fail
export JOBS="$(jq '.[0].jobs[0].conclusion = "skipped"' <<<"$SAVED_JOBS")"
check 'workflow success with skipped Gitleaks fails closed' fail
export JOBS="$SAVED_JOBS"
export FILES='{"filename":"extension/src.php","previous_filename":null}'
set_security_run main
check 'matching extension run is also required' fail
for base in main advansit-superuser-calendar-administration advansit-guest-privacy-requests; do
    set_extension_run "$base"
    check "matching extension run succeeds for base $base" pass
done
for conclusion in failure cancelled skipped; do
    set_extension_run main
    export RUNS="$(jq --arg conclusion "$conclusion" \
        '.[0].workflow_runs[1].conclusion = $conclusion' <<<"$RUNS")"
    check "required extension conclusion $conclusion fails closed" fail
done
set_security_run main
export RUNS="$(jq '.[0].workflow_runs += [ (.[0].workflow_runs[0] | .id=2 | .run_number=2 |
    .path=".github/workflows/extension.yml" | .pull_requests[0].number=2)]' <<<"$RUNS")"
check 'same head on another pull request cannot satisfy this pull request' fail
set_security_run main
export RUNS="$(jq '.[0].workflow_runs += [ (.[0].workflow_runs[0] | .id=2 | .run_number=2 |
    .path=".github/workflows/extension.yml" | .pull_requests[0].base.sha="4444444444444444444444444444444444444444")]' <<<"$RUNS")"
check 'success on an older base commit cannot satisfy this merge' fail
set_security_run main
export FILES='{"filename":"README.md","previous_filename":"extension/old.php"}'
check 'missing rename-source-only extension remains optional' pass
export FILES=''
check 'empty changed-file response fails closed' fail
export FILES='{"filename":"README.md","previous_filename":null}'
set_security_run main
export RUNS="$(jq '.[0].workflow_runs += [.[0].workflow_runs[0] | .run_number=2 | .conclusion="failure"]' <<<"$RUNS")"
check 'newest failed run wins over older success' fail
set_security_run main
export RUNS="$(jq '.[0].workflow_runs += [.[0].workflow_runs[0] | .run_attempt=2 | .conclusion="success"] | .[0].workflow_runs[0].conclusion="failure"' <<<"$RUNS")"
check 'latest successful attempt supersedes previous failure' pass

# A pull request that runs every workflow lists more than the 128 KiB Linux allows for one
# argument. The list comes from a file here, because an environment variable has that limit too.
set_security_run main
jq -c '.[0].workflow_runs[0].padding = ("x" * 200000)' <<<"$RUNS" >"$FIXTURE/runs.json"
export RUNS_FILE="$FIXTURE/runs.json"
check 'a run list above the 128 KiB argument limit is read' pass
unset RUNS_FILE

# The run list lags behind under congested runners; the commit's check runs do not.
export BASE_REF=main
export RUNS='[{"workflow_runs":[]}]'
export CHECK_RUNS='[{"check_runs":[{"details_url":"https://github.com/owner/repo/actions/runs/7/job/70"},
  {"details_url":"https://example.invalid/other-app"},{"details_url":null}]}]'
single_run() {
    local event="${3:-pull_request}" head_sha="${4:-$HEAD_SHA}" pr="${5:-$PR_NUMBER}"
    local base="${6:-$BASE_REF}" base_sha="${7:-$BASE_SHA}" association="${8:-exact}" title
    title="$(run_title "$pr" "$base" "$base_sha" "$head_sha" "$MERGE_SHA")"
    jq -cn --arg status "$1" --arg conclusion "$2" --arg event "$event" --arg head_sha "$head_sha" \
        --arg pr "$pr" --arg base "$base" --arg base_sha "$base_sha" \
        --arg association "$association" --arg display_title "$title" \
        '{id:7,run_number:1,run_attempt:1,path:".github/workflows/security.yml",event:$event,head_sha:$head_sha,
          display_title:$display_title,
          pull_requests:(if $association == "empty" then [] else
            [{number:($pr|tonumber),base:{ref:$base,sha:$base_sha},head:{sha:$head_sha}}] end),
          status:$status,conclusion:(if $conclusion == "" then null else $conclusion end),html_url:"https://example.invalid/run/7"}'
}
export SINGLE_RUN="$(single_run completed success)"
check 'run missing from the lagging list is found through the check runs' pass
export SINGLE_RUN="$(single_run completed success pull_request "$HEAD_SHA" "$PR_NUMBER" "$BASE_REF" "$BASE_SHA" empty)"
check 'run found on HEAD_SHA with empty PR association and exact title is accepted' pass
export SINGLE_RUN="$(single_run completed success pull_request "$HEAD_SHA" "$PR_NUMBER" "$BASE_REF" "$BASE_SHA" empty)"
export SINGLE_RUN="$(jq '.display_title="PR #2 base=main base-sha=" + $ENV.BASE_SHA +
    " head-sha=" + $ENV.HEAD_SHA + " merge-sha=" + $ENV.MERGE_SHA' <<<"$SINGLE_RUN")"
check 'empty run association with foreign PR title is rejected' fail
export SINGLE_RUN="$(single_run completed success pull_request "$HEAD_SHA" "$PR_NUMBER" "$BASE_REF" "$BASE_SHA")"
export SINGLE_RUN="$(jq '.display_title |= sub("base-sha=" + $ENV.BASE_SHA; "base-sha=4444444444444444444444444444444444444444")' <<<"$SINGLE_RUN")"
check 'valid run association with stale event title is rejected' fail
export SINGLE_RUN="$(single_run completed success pull_request "$HEAD_SHA" "$PR_NUMBER" "$BASE_REF" "$BASE_SHA" empty)"
export SINGLE_RUN="$(jq 'del(.pull_requests)' <<<"$SINGLE_RUN")"
check 'missing PR association cannot use the title fallback' fail
export SINGLE_RUN="$(single_run completed success pull_request "$HEAD_SHA" "$PR_NUMBER" "$BASE_REF" "$BASE_SHA" empty)"
export SINGLE_RUN="$(jq '.display_title=""' <<<"$SINGLE_RUN")"
check 'empty display title cannot use the PR association fallback' fail
export SINGLE_RUN="$(single_run completed success pull_request "$HEAD_SHA" 2 main "$BASE_SHA")"
export SINGLE_RUN="$(jq --arg title "$(run_title)" '.display_title=$title' <<<"$SINGLE_RUN")"
check 'foreign non-empty association is not overridden by a matching title' fail
export SINGLE_RUN="$(single_run completed success pull_request "$HEAD_SHA" "$PR_NUMBER" "$BASE_REF" "$BASE_SHA" empty)"
export SINGLE_RUN="$(jq '.display_title="PR #1 base=main base-sha=4444444444444444444444444444444444444444 head-sha=" + $ENV.HEAD_SHA +
    " merge-sha=" + $ENV.MERGE_SHA' <<<"$SINGLE_RUN")"
check 'empty association with stale base SHA title is rejected' fail
export SINGLE_RUN="$(single_run completed success pull_request "$HEAD_SHA" "$PR_NUMBER" "$BASE_REF" "$BASE_SHA" empty)"
export SINGLE_RUN="$(jq '.display_title |= .[0:40]' <<<"$SINGLE_RUN")"
check 'truncated display title fails exact provenance matching' fail
export SINGLE_RUN="$(single_run completed success pull_request 4444444444444444444444444444444444444444)"
check 'a run on another HEAD_SHA cannot satisfy this pull request' fail
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

SAVED_BASE_REF="$BASE_REF"
unset BASE_REF
check 'missing BASE_REF fails closed' fail
export BASE_REF=''
check 'empty BASE_REF fails closed' fail
export BASE_REF="$SAVED_BASE_REF"
for sha_name in BASE_SHA HEAD_SHA MERGE_SHA; do
    original_sha="${!sha_name}"
    export "$sha_name=invalid"
    check "invalid $sha_name fails closed" fail
    export "$sha_name=$original_sha"
done
export BASE_REF=unsupported
check 'unsupported pull request base fails closed' fail
export BASE_REF=main
cp "$WORKFLOW_DIR/security.yml" "$FIXTURE/security.yml"
cat >"$WORKFLOW_DIR/security.yml" <<'EOF'
run-name: "${{ github.event_name == 'pull_request' && format('PR #{0} base={1} base-sha={2} head-sha={3} merge-sha={4}', github.event.pull_request.number, github.event.pull_request.base.ref, github.event.pull_request.base.sha, github.event.pull_request.head.sha, github.sha) || (github.event_name == 'push' && github.event.head_commit.message) || github.workflow }}"
on:
  pull_request:
    paths: ['**']
EOF
check 'missing pull_request.branches filter fails closed' fail
cat >"$WORKFLOW_DIR/security.yml" <<'EOF'
run-name: "${{ github.event_name == 'pull_request' && format('PR #{0} base={1} base-sha={2} head-sha={3} merge-sha={4}', github.event.pull_request.number, github.event.pull_request.base.ref, github.event.pull_request.base.sha, github.event.pull_request.head.sha, github.sha) || (github.event_name == 'push' && github.event.head_commit.message) || github.workflow }}"
on:
  pull_request:
    branches: [main, '*']
EOF
check 'wildcard pull_request.branches filter fails closed' fail
cat >"$WORKFLOW_DIR/security.yml" <<'EOF'
run-name: "${{ github.event_name == 'pull_request' && format('PR #{0} base={1} base-sha={2} head-sha={3} merge-sha={4}', github.event.pull_request.number, github.event.pull_request.base.ref, github.event.pull_request.base.sha, github.event.pull_request.head.sha, github.sha) || (github.event_name == 'push' && github.event.head_commit.message) || github.workflow }}"
on:
  pull_request:
    branches: [main]
EOF
export BASE_REF=advansit-guest-privacy-requests
check 'filter omitting the actual dependent base fails closed' fail
write_security_workflow 'main, advansit-superuser-calendar-administration, advansit-guest-privacy-requests'
sed -i '1d' "$WORKFLOW_DIR/security.yml"
check 'missing event-derived run-name stamp fails closed' fail
export BASE_REF=main
cp "$FIXTURE/security.yml" "$WORKFLOW_DIR/security.yml"
