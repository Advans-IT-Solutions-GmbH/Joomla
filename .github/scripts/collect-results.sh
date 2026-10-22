#!/usr/bin/env bash
#
# Single source of the required "Collect Results" status check.
#
# GitHub path filters can only express "run if at least one changed file
# matches". The complement ("run only if no changed file belongs to an
# extension") cannot be expressed, and several extension workflows can run on
# the same pull request. Therefore exactly one workflow reports the required
# check name and evaluates the extension workflows itself:
#
#   1. Read every pull_request workflow in .github/workflows, including
#      unconditional workflows such as Security Scan.
#   2. Match those patterns against the files changed by the pull request.
#   3. Wait for every matching workflow run on the pull request head commit and
#      fail unless each of them concluded with `success`. A run counts as present
#      as soon as the run list or the commit's check runs show it, queued or not.
#
# Documentation-only pull requests still have to pass unconditional workflows.
#
# Required environment: GH_TOKEN, REPO, PR_NUMBER, HEAD_SHA.
# Optional: POLL_SECONDS (default 60), MISSING_RUN_GRACE_MINUTES (default 20),
#           MAX_WAIT_MINUTES (default 330), WORKFLOW_DIR (default .github/workflows).

set -euo pipefail

: "${GH_TOKEN:?GH_TOKEN is required}"
: "${REPO:?REPO is required}"
: "${PR_NUMBER:?PR_NUMBER is required}"
: "${HEAD_SHA:?HEAD_SHA is required}"

POLL_SECONDS="${POLL_SECONDS:-60}"
MISSING_RUN_GRACE_MINUTES="${MISSING_RUN_GRACE_MINUTES:-20}"
MAX_WAIT_MINUTES="${MAX_WAIT_MINUTES:-330}"
WORKFLOW_DIR="${WORKFLOW_DIR:-.github/workflows}"
SELF_WORKFLOW="${SELF_WORKFLOW:-collect-results.yml}"

if ! command -v yq >/dev/null 2>&1; then
    echo "::error::yq is required to read workflow triggers but is not installed"
    exit 1
fi

# --- Changed files of the pull request ---------------------------------------
# GitHub evaluates path filters against the file list of the pull request.
# `filename` is the current path; `previous_filename` is set for renames.
changed_json="$(gh api --paginate "repos/${REPO}/pulls/${PR_NUMBER}/files?per_page=100" \
    --jq '.[] | {filename, previous_filename}')"

# The evaluation runs in a COMMAND substitution, not a process substitution:
# `mapfile -t X < <(... | jq ...)` hides a jq failure, because the exit status of a process
# substitution never reaches the caller and `pipefail` does not cross that boundary. mapfile would
# then exit 0 with an empty array, and an empty array means "no extension workflow is affected" to
# this script, so the required check would report success without evaluating a single workflow. In
# the assignments below, `set -e` aborts on exactly that failure.
changed_list="$(printf '%s\n' "$changed_json" | jq -r '.filename' | sort -u)"
previous_list="$(printf '%s\n' "$changed_json" | jq -r '.previous_filename // empty' | sort -u)"

if [ -z "$changed_list" ]; then
    # A pull request without a single file does not exist. An empty list therefore means the API
    # request or its evaluation failed, and this required check must not report success on that.
    echo "::error::The pull request reports no changed files at all; the API request or its evaluation failed, so the required extension workflows cannot be determined. Failing closed."
    exit 1
fi

mapfile -t CHANGED <<<"$changed_list"

# Renames are normally absent. A here-string built from an empty string would yield an array with
# one empty element, so the array is left explicitly empty in that case.
PREVIOUS=()
if [ -n "$previous_list" ]; then
    mapfile -t PREVIOUS <<<"$previous_list"
fi

echo "Changed files: ${#CHANGED[@]}"
if [ "${#CHANGED[@]}" -ge 3000 ]; then
    # The "List pull requests files" API returns at most 3000 files, so the
    # changed-file list is truncated here. Evaluating the incomplete list could
    # drop an extension whose file is missing from REQUIRED and let this
    # required check pass without validating that extension's workflow. Fail
    # closed instead of gating on an incomplete change set.
    echo "::error::The pull request lists 3000 or more files; the GitHub API truncates the file list, so the required extension workflows cannot be determined reliably. Failing closed."
    exit 1
fi

# --- Pattern matching ----------------------------------------------------------
# Supported pattern forms (all patterns used in this repository):
#   exact/path/file.ext      exact file
#   some/directory/**        everything below the directory
# Anything else fails loudly so that the evaluation can never silently drift
# from the real trigger configuration.
pattern_matches() {
    local pattern="$1" file="$2"
    case "$pattern" in
        '!'*)
            echo "::error::Negated path patterns are not supported by collect-results.sh: ${pattern}" >&2
            exit 2
            ;;
        *'/**')
            local prefix="${pattern%/**}"
            case "$prefix" in
                *'*'*|*'?'*|*'['*)
                    echo "::error::Unsupported path pattern: ${pattern}" >&2
                    exit 2
                    ;;
            esac
            [[ "$file" == "$prefix/"* ]]
            return
            ;;
        *'*'*|*'?'*|*'['*)
            echo "::error::Unsupported path pattern: ${pattern}" >&2
            exit 2
            ;;
        *)
            [[ "$file" == "$pattern" ]]
            return
            ;;
    esac
}

any_file_matches() {
    # $1 = newline-separated patterns, remaining args = files
    local patterns="$1"
    shift
    local file pattern
    for file in "$@"; do
        while IFS= read -r pattern; do
            [ -z "$pattern" ] && continue
            if pattern_matches "$pattern" "$file"; then
                return 0
            fi
        done <<< "$patterns"
    done
    return 1
}

# --- Determine the expected workflows ------------------------------------------
declare -a REQUIRED=()
declare -a OPTIONAL=()

for wf in "$WORKFLOW_DIR"/*.yml "$WORKFLOW_DIR"/*.yaml; do
    [ -f "$wf" ] || continue
    name="$(basename "$wf")"
    [ "$name" = "$SELF_WORKFLOW" ] && continue

    # Only map-style `on:` blocks with a pull_request trigger are relevant.
    on_tag="$(yq -r '.on | tag' "$wf")"
    [ "$on_tag" = "!!map" ] || continue
    has_pr="$(yq -r '.on | has("pull_request")' "$wf")"
    [ "$has_pr" = "true" ] || continue

    if [ "$(yq -r '.on.pull_request | has("paths-ignore")' "$wf" 2>/dev/null || echo false)" = "true" ]; then
        echo "::error::${name} uses pull_request.paths-ignore, which collect-results.sh does not evaluate"
        exit 2
    fi

    paths="$(yq -r '(.on.pull_request.paths // []) | .[]' "$wf")"
    branches="$(yq -r '(.on.pull_request.branches // []) | .[]' "$wf")"
    if [ -n "$branches" ] && ! grep -qx 'main' <<< "$branches"; then
        continue
    fi

    if [ -z "$paths" ] || any_file_matches "$paths" "${CHANGED[@]}"; then
        REQUIRED+=("$name")
    elif [ "${#PREVIOUS[@]}" -gt 0 ] && any_file_matches "$paths" "${PREVIOUS[@]}"; then
        OPTIONAL+=("$name")
    fi
done

if [ "${#REQUIRED[@]}" -eq 0 ] && [ "${#OPTIONAL[@]}" -eq 0 ]; then
    echo "No extension workflow is triggered by this pull request."
    exit 0
fi

echo "Required extension workflows: ${REQUIRED[*]:-none}"
[ "${#OPTIONAL[@]}" -gt 0 ] && echo "Evaluated if present (rename sources only): ${OPTIONAL[*]}"

# --- Wait for and evaluate the runs --------------------------------------------
start_epoch="$(date +%s)"
declare -A FINAL=()

latest_run() {
    # Prints: status<TAB>conclusion<TAB>html_url<TAB>id for the newest pull_request run
    # of the given workflow file on HEAD_SHA, or nothing if no run exists yet.
    local file="$1"
    printf '%s' "$RUNS_JSON" | jq -r --arg path ".github/workflows/${file}" '
        [ .workflow_runs[] | select(.path == $path) ]
        | sort_by(.run_number, .run_attempt) | last
        | if . == null then empty else [.status, (.conclusion // "pending"), .html_url, .id] | @tsv end'
}

fetch_runs() {
    # The run list is eventually consistent. While the runners are congested it can
    # omit a queued or even running run on HEAD_SHA for longer than the grace period,
    # which used to fail this check with "no run exists". The check runs of the commit
    # are current, so every run they reference and the list lacks is read directly by
    # its id. A queued or in-progress run therefore counts as present and is awaited.
    # The function runs in a command substitution, where errexit does not apply, so
    # every lookup returns its failure explicitly: if the check runs could not be read,
    # an older success in the lagging list must not stand in for a newer run.
    local listed run_ids run_id extra='[]'
    listed="$(gh api --paginate --slurp "repos/${REPO}/actions/runs?event=pull_request&head_sha=${HEAD_SHA}&per_page=100" \
        | jq -c '[.[].workflow_runs[]]')" || return 1
    run_ids="$(gh api --paginate --slurp "repos/${REPO}/commits/${HEAD_SHA}/check-runs?per_page=100" \
        | jq -r '[.[].check_runs[].details_url // empty | select(test("/actions/runs/[0-9]+"))
            | capture("/actions/runs/(?<id>[0-9]+)").id] | unique | .[]')" || return 1
    for run_id in $run_ids; do
        if ! jq -e --argjson id "$run_id" 'any(.[]; .id == $id)' <<<"$listed" >/dev/null; then
            extra="$(gh api "repos/${REPO}/actions/runs/${run_id}" \
                | jq -c --argjson extra "$extra" --arg sha "$HEAD_SHA" \
                    'if .event == "pull_request" and .head_sha == $sha then $extra + [.] else $extra end')" || return 1
        fi
    done
    jq -n --argjson listed "$listed" --argjson extra "$extra" '{workflow_runs: ($listed + $extra)}' || return 1
}

while :; do
    if ! RUNS_JSON="$(fetch_runs)"; then
        echo "::error::The workflow runs of ${HEAD_SHA} could not be read completely. Failing closed."
        exit 1
    fi
    elapsed_min=$(( ( $(date +%s) - start_epoch ) / 60 ))
    pending=0

    for file in "${REQUIRED[@]}" "${OPTIONAL[@]}"; do
        [ -n "$file" ] || continue
        [ -n "${FINAL[$file]:-}" ] && continue

        line="$(latest_run "$file")"
        if [ -z "$line" ]; then
            if printf '%s\n' "${OPTIONAL[@]}" | grep -qx "$file"; then
                if [ "$elapsed_min" -ge "$MISSING_RUN_GRACE_MINUTES" ]; then
                    echo "No run of ${file} appeared (rename source only); not evaluated."
                    FINAL[$file]="not-run"
                else
                    pending=1
                fi
                continue
            fi
            if [ "$elapsed_min" -ge "$MISSING_RUN_GRACE_MINUTES" ]; then
                echo "::error::${file} matches the changed files but no run exists for ${HEAD_SHA} after ${elapsed_min} minutes"
                exit 1
            fi
            pending=1
            continue
        fi

        IFS=$'\t' read -r status conclusion url run_id <<< "$line"
        if [ "$status" != "completed" ]; then
            pending=1
            continue
        fi

        FINAL[$file]="$conclusion"
        echo "${file}: ${conclusion} (${url})"
        if [ "$conclusion" != "success" ]; then
            echo "::error::${file} concluded with '${conclusion}': ${url}"
            echo "After re-running that workflow, re-run this check as well."
            exit 1
        fi
        if [ "$file" = "security.yml" ]; then
            jobs_json="$(gh api --paginate --slurp "repos/${REPO}/actions/runs/${run_id}/jobs?filter=latest&per_page=100")"
            # A successful workflow can contain skipped jobs. The blocking secret scan
            # must actually have run, as must the report-only scanners (tool errors fail).
            if ! printf '%s' "$jobs_json" | jq -e '
                [.[].jobs[]] as $jobs
                | ($jobs | length > 0)
                and all($jobs[]; .status == "completed" and .conclusion == "success")
                and all(["Gitleaks (secret scan)", "Semgrep (SAST)", "Actions Security (zizmor + actionlint)"][];
                    . as $expected | any($jobs[]; .name | endswith($expected)))' >/dev/null; then
                echo "::error::Security Scan did not successfully execute every expected scanner: ${url}"
                exit 1
            fi
        fi
    done

    if [ "$pending" -eq 0 ]; then
        break
    fi

    if [ "$elapsed_min" -ge "$MAX_WAIT_MINUTES" ]; then
        echo "::error::Extension workflows did not finish within ${MAX_WAIT_MINUTES} minutes"
        exit 1
    fi

    sleep "$POLL_SECONDS"
done

echo "All required pull request workflows succeeded."
