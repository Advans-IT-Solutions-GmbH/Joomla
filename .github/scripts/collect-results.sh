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
#   1. Read the pull_request trigger of every workflow in .github/workflows that
#      filters by `paths` (the extension build & test workflows).
#   2. Match those patterns against the files changed by the pull request.
#   3. Wait for every matching workflow run on the pull request head commit and
#      fail unless each of them concluded with `success`.
#
# A pull request that matches no extension workflow (for example a pure
# documentation change outside the extension directories) passes immediately.
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
mapfile -t CHANGED < <(printf '%s\n' "$changed_json" | jq -r '.filename' | sort -u)
mapfile -t PREVIOUS < <(printf '%s\n' "$changed_json" | jq -r '.previous_filename // empty' | sort -u)

echo "Changed files: ${#CHANGED[@]}"
if [ "${#CHANGED[@]}" -ge 3000 ]; then
    echo "::warning::The pull request lists 3000 or more files; the GitHub API truncates the list."
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
    # Workflows without a paths filter (for example the security scan) are not
    # extension test workflows and are not part of this gate.
    [ -n "$paths" ] || continue

    branches="$(yq -r '(.on.pull_request.branches // []) | .[]' "$wf")"
    if [ -n "$branches" ] && ! grep -qx 'main' <<< "$branches"; then
        continue
    fi

    if any_file_matches "$paths" "${CHANGED[@]}"; then
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
    # Prints: status<TAB>conclusion<TAB>html_url for the newest pull_request run
    # of the given workflow file on HEAD_SHA, or nothing if no run exists yet.
    local file="$1"
    printf '%s' "$RUNS_JSON" | jq -r --arg path ".github/workflows/${file}" '
        [ .workflow_runs[] | select(.path == $path) ]
        | sort_by(.created_at) | last
        | if . == null then empty else [.status, (.conclusion // ""), .html_url] | @tsv end'
}

while :; do
    RUNS_JSON="$(gh api "repos/${REPO}/actions/runs?event=pull_request&head_sha=${HEAD_SHA}&per_page=100")"
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

        IFS=$'\t' read -r status conclusion url <<< "$line"
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

echo "All triggered extension workflows succeeded."
