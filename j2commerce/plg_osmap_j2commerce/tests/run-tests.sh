#!/bin/bash
#
# Generic Test Runner for Joomla/J2Commerce Extensions
# Usage: Run from extension/tests directory
#

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

# Load test configuration
if [ ! -f "test.env" ]; then
    echo -e "${RED}Error: test.env not found${NC}"
    echo "Please create test.env with:"
    echo "  CONTAINER_NAME=\"extension_test\""
    echo "  TEST_SCRIPTS=(\"Installation:01-installation.php\" ...)"
    exit 1
fi

source test.env

RESULTS_DIR="${RESULTS_DIR:-./test-results}"
mkdir -p "$RESULTS_DIR"

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
    
    if docker exec "$CONTAINER_NAME" php "/var/www/html/tests/scripts/${test_script}" > "$result_file" 2>&1; then
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
    # Wait for ready.txt — written after Joomla init + OSMap + J2Commerce + plugin install + fixtures
    timeout 600 bash -c "until docker exec $CONTAINER_NAME test -f /var/www/html/ready.txt 2>/dev/null; do sleep 5; done" || {
        print_error "Test environment did not become ready in time"
        echo "--- Container logs (last 80 lines) ---"
        docker logs $CONTAINER_NAME 2>&1 | tail -80 || true
        exit 1
    }
    print_success "Joomla is ready"
    
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
