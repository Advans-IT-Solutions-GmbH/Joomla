#!/usr/bin/env bash
#
# Guard for the narrowed `shared/**` trigger paths.
#
# The extension workflows no longer list `shared/**` as a whole: each of them
# lists only the files under `shared/` that it really uses, so a change to a
# shared file starts only the extension workflows that consume it. The price of
# that is a new failure mode: a file added under `shared/` could end up in no
# workflow's `paths` at all and silently be tested by nothing.
#
# This script closes that gap. Every file under `shared/` must either
#   * be matched by the `pull_request.paths` of at least one workflow, or
#   * be listed in UNCOVERED_ON_PURPOSE below, with a reason.
#
# Optional environment:
#   WORKFLOW_DIR    default `.github/workflows`
#   SHARED_DIR      default `shared`
#   SELF_WORKFLOW   the workflow running this script, excluded from the search
#                   because it watches `shared/**` itself and would otherwise
#                   report every file as covered

set -euo pipefail

WORKFLOW_DIR="${WORKFLOW_DIR:-.github/workflows}"
SHARED_DIR="${SHARED_DIR:-shared}"
SELF_WORKFLOW="${SELF_WORKFLOW:-shared-path-coverage.yml}"

# Files under `shared/` that deliberately trigger no extension workflow. They
# are templates that a new extension copies into its own `tests/` tree; no lane
# reads them from `shared/`, so running six workflows for a change to them would
# be pure waste. Each extension has its own copy under `<extension>/tests/`,
# which is covered by that extension's own path filter.
UNCOVERED_ON_PURPOSE=(
    "shared/tests/Dockerfile.template"
    "shared/tests/scripts/docker-entrypoint.sh"
    "shared/tests/scripts/install-extension.php"
)

if ! command -v yq >/dev/null 2>&1; then
    echo "::error::yq is required to read workflow triggers but is not installed"
    exit 1
fi

# Same pattern forms as .github/scripts/collect-results.sh: an exact path or a
# `directory/**` prefix. Anything else fails loudly instead of drifting.
pattern_matches() {
    local pattern="$1" file="$2"
    case "$pattern" in
        '!'*)
            echo "::error::Negated path patterns are not supported: ${pattern}" >&2
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

# --- Read every workflow's pull_request paths once -----------------------------
declare -a WF_NAMES=()
declare -a WF_PATHS=()

for wf in "$WORKFLOW_DIR"/*.yml "$WORKFLOW_DIR"/*.yaml; do
    [ -f "$wf" ] || continue
    name="$(basename "$wf")"
    [ "$name" = "$SELF_WORKFLOW" ] && continue

    # Only map-style `on:` blocks with a pull_request trigger are relevant.
    [ "$(yq -r '.on | tag' "$wf")" = "!!map" ] || continue
    [ "$(yq -r '.on | has("pull_request")' "$wf")" = "true" ] || continue

    paths="$(yq -r '(.on.pull_request.paths // []) | .[]' "$wf")"
    # Workflows without a paths filter (for example the security scan) are not
    # extension test workflows and are not part of this guard.
    [ -n "$paths" ] || continue

    WF_NAMES+=("$name")
    WF_PATHS+=("$paths")
done

if [ "${#WF_NAMES[@]}" -eq 0 ]; then
    echo "::error::No workflow with pull_request paths found in ${WORKFLOW_DIR}/"
    exit 1
fi

echo "Workflows with pull_request paths: ${#WF_NAMES[@]}"

# --- Check every file under shared/ --------------------------------------------
mapfile -t SHARED_FILES < <(find "$SHARED_DIR" -type f | sort)

if [ "${#SHARED_FILES[@]}" -eq 0 ]; then
    echo "::error::No files found under ${SHARED_DIR}/"
    exit 1
fi

echo "Files under ${SHARED_DIR}/: ${#SHARED_FILES[@]}"

uncovered=0
for file in "${SHARED_FILES[@]}"; do
    covering=""

    for i in "${!WF_NAMES[@]}"; do
        while IFS= read -r pattern; do
            [ -n "$pattern" ] || continue
            if pattern_matches "$pattern" "$file"; then
                covering+=" ${WF_NAMES[$i]}"
                break
            fi
        done <<< "${WF_PATHS[$i]}"
    done

    if [ -n "$covering" ]; then
        echo "  ${file} ->${covering}"
        continue
    fi

    allowed=0
    for allow in "${UNCOVERED_ON_PURPOSE[@]}"; do
        if [ "$allow" = "$file" ]; then
            allowed=1
            break
        fi
    done

    if [ "$allowed" -eq 1 ]; then
        echo "  ${file} -> no workflow (listed as uncovered on purpose)"
        continue
    fi

    echo "::error file=${file}::${file} is not matched by the pull_request paths of any workflow. Add it to the workflows of the extensions that use it, or to UNCOVERED_ON_PURPOSE in .github/scripts/check-shared-path-coverage.sh with a reason."
    uncovered=1
done

# A file that was removed must not stay in the allow list.
for allow in "${UNCOVERED_ON_PURPOSE[@]}"; do
    if [ ! -f "$allow" ]; then
        echo "::error::${allow} is listed in UNCOVERED_ON_PURPOSE but does not exist any more. Remove the entry."
        uncovered=1
    fi
done

if [ "$uncovered" -ne 0 ]; then
    exit 1
fi

echo "Every file under ${SHARED_DIR}/ is covered by a workflow or listed on purpose."
