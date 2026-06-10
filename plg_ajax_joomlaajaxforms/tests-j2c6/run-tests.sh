#!/usr/bin/env bash
# Full-install test runner for J2C6 (Joomla 6 + J2Commerce 6).
#
# The shared runner copies scripts/*.php from the CWD into the container.
# Populate scripts/ with the base suite first, then apply the J2C6 override
# (11-j2c6-cart.php -> 11-j2store-cart.php) before delegating.
set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_SCRIPTS="$SCRIPT_DIR/../tests/scripts"
LOCAL_SCRIPTS="$SCRIPT_DIR/scripts"

# Copy base suite into local scripts/ (creates a merged view)
cp "$BASE_SCRIPTS"/*.php "$LOCAL_SCRIPTS/"

# Apply J2C6 override: replace the generic cart test with the full-install version
cp "$LOCAL_SCRIPTS/11-j2c6-cart.php" "$LOCAL_SCRIPTS/11-j2store-cart.php"

exec "$SCRIPT_DIR/../../shared/tests/run-tests.sh" "$@"
