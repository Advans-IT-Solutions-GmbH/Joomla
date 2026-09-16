#!/bin/bash
#
# Generic Test Runner for Joomla/J2Commerce Extensions
# Usage: Run from extension/tests directory
#
# Environment variables passed through to the test scripts in the container
# (only when set on the host):
#   TEST_STRICT_SKIP=1   a test that would SKIP fails instead (used by CI)
#   J2COMMERCE_STACK     "j6" for the Joomla 6 + J2Commerce 6 stack
#   PREVIOUS_PACKAGE     container path of the previous release package
#                        (update-from-previous suite)
#   INSTALLED_PLUGIN_FOLDERS  comma-separated plugin groups the extension may
#                        be registered in (may also be set in test.env)
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

SHARED_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Load test configuration
if [ ! -f "test.env" ]; then
    echo -e "${RED}Error: test.env not found${NC}"
    echo "Please create test.env with:"
    echo "  CONTAINER_NAME=\"extension_test\""
    echo "  TEST_SCRIPTS=(\"Installation:01-installation.php\" ...)"
    exit 1
fi

# Preserve caller-supplied overrides before sourcing test.env defaults
_CONTAINER_NAME_OVERRIDE="${CONTAINER_NAME:-}"

source test.env

# Re-apply caller overrides (test.env must not clobber them)
[ -n "$_CONTAINER_NAME_OVERRIDE" ] && CONTAINER_NAME="$_CONTAINER_NAME_OVERRIDE"

RESULTS_DIR="${RESULTS_DIR:-./test-results}"
mkdir -p "$RESULTS_DIR"

EXEC_ENV=()
for var in TEST_STRICT_SKIP J2COMMERCE_STACK PREVIOUS_PACKAGE INSTALLED_PLUGIN_FOLDERS; do
    if [ -n "${!var:-}" ]; then
        EXEC_ENV+=(-e "${var}=${!var}")
    fi
done

print_header() {
    echo -e "\n${BLUE}========================================${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}========================================${NC}\n"
}

print_success() { echo -e "${GREEN}✅ $1${NC}"; }
print_error() { echo -e "${RED}❌ $1${NC}"; }

run_test() {
    local test_name=$1
    local test_script=$2
    local result_file="$RESULTS_DIR/${test_name}.txt"

    print_header "Running: $test_name"

    if docker exec "${EXEC_ENV[@]}" "$CONTAINER_NAME" php "/var/www/html/tests/scripts/${test_script}" > "$result_file" 2>&1; then
        cat "$result_file"
        print_success "$test_name PASSED"
        return 0
    else
        cat "$result_file"
        print_error "$test_name FAILED"
        return 1
    fi
}

copy_test_scripts() {
    print_header "Copying test scripts to container"
    docker exec "$CONTAINER_NAME" mkdir -p /var/www/html/tests/scripts
    for script in scripts/*.php; do
        if [ -f "$script" ]; then
            docker cp "$script" "$CONTAINER_NAME:/var/www/html/tests/scripts/"
            echo "  Copied: $(basename $script)"
        fi
    done
    # Shared, extension-independent suites (shared-*.php) are available to
    # every extension; test.env decides whether they run.
    for script in "$SHARED_DIR"/scripts/shared-*.php; do
        if [ -f "$script" ]; then
            docker cp "$script" "$CONTAINER_NAME:/var/www/html/tests/scripts/"
            echo "  Copied (shared): $(basename $script)"
        fi
    done
    print_success "Test scripts copied"
}

main() {
    local test_suite=${1:-"all"}
    local failed_tests=0
    local total_tests=0

    print_header "Extension Test Suite"

    if ! docker ps | grep -q "$CONTAINER_NAME"; then
        print_error "Container $CONTAINER_NAME is not running"
        exit 1
    fi

    print_header "Waiting for Joomla to be ready"
    timeout 180 bash -c "until docker exec $CONTAINER_NAME test -f /var/www/html/health.txt 2>/dev/null; do sleep 3; done" || {
        print_error "Joomla did not become ready in time"
        docker exec $CONTAINER_NAME ls -la /var/www/html/ 2>/dev/null || true
        docker logs $CONTAINER_NAME 2>&1 | tail -50 || true
        exit 1
    }
    print_success "Joomla is ready"

    # The CI uses moving image tags (newest Joomla 5.4.x / 6.x); record which
    # versions were actually tested.
    local versions
    versions=$(docker exec "$CONTAINER_NAME" php -r '
        $v = @file_get_contents("/var/www/html/libraries/src/Version.php");
        preg_match_all("/const (MAJOR|MINOR|PATCH)_VERSION = (\d+);/", (string) $v, $m);
        $parts = array_combine($m[1] ?: [], $m[2] ?: []);
        echo "Joomla " . ($parts ? ($parts["MAJOR"] . "." . $parts["MINOR"] . "." . $parts["PATCH"]) : "unknown") . ", PHP " . PHP_VERSION;
    ' 2>/dev/null || echo "Joomla unknown, PHP unknown")
    echo "Tested versions: ${versions}"
    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        echo "- \`${CONTAINER_NAME}\`: ${versions}" >> "$GITHUB_STEP_SUMMARY"
    fi

    copy_test_scripts

    # Run tests based on TEST_SCRIPTS array from test.env
    for test_entry in "${TEST_SCRIPTS[@]}"; do
        IFS=':' read -r test_name test_script <<< "$test_entry"

        if [ "$test_suite" = "all" ] || [ "$test_suite" = "$(echo $test_name | tr '[:upper:]' '[:lower:]')" ]; then
            total_tests=$((total_tests + 1))
            if ! run_test "$test_name" "$test_script"; then
                failed_tests=$((failed_tests + 1))
            fi
        fi
    done

    if [ "$total_tests" -eq 0 ]; then
        print_error "No test suite named '$test_suite' in test.env"
        exit 1
    fi

    print_header "Test Summary"
    echo ""
    echo "Total Tests: $total_tests"
    echo "Passed: $((total_tests - failed_tests))"
    echo "Failed: $failed_tests"
    echo ""

    if [ $failed_tests -eq 0 ]; then
        print_success "All tests passed!"
        exit 0
    else
        print_error "$failed_tests test(s) failed"
        exit 1
    fi
}

main "$@"
