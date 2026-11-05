#!/bin/bash
#
# CalDAV client job: real clients (Python caldav, vdirsyncer) in their own
# container against the internet calendars of a running Joomla test container,
# over HTTPS with the certificate verified against the image's test CA.
#
# Usage, from com_appointments/tests with the Joomla 6 stack running:
#   bash caldav/run-clients.sh [joomla-container]   (default com_appointments_j6_test)
#
# The server side is prepared and changed between the client phases by
# caldav/fixture.php inside the Joomla container (users, calendars, grants,
# devices, a booking, a revoked grant, the clean-up). While a work package the
# phases need still ships its stub, those phases are reported as PENDING, the
# same rule as TeamSuite::pending(): never a pass, and a failure with
# TEAM_NO_PENDING=1.
#
set -uo pipefail

CONTAINER="${1:-com_appointments_j6_test}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TESTS="$(dirname "$HERE")"
SERVICES="$TESTS/../administrator/components/com_appointments/src/Service/Team"
LIB="$TESTS/../administrator/components/com_appointments/lib"
RESULTS="${RESULTS_DIR:-$TESTS/test-results}"
IMAGE="appointments-caldav-clients"
mkdir -p "$RESULTS"
exec > >(tee "$RESULTS/caldav-clients.txt") 2>&1

failed=0
pending=0
WORK=""

echo "=== CalDAV client tests ==="

pending_line() {
    if [ "${TEAM_NO_PENDING:-}" = "1" ]; then
        echo "✗ $1 - PENDING $2 not allowed here"
        failed=$((failed + 1))
    else
        echo "… $1 - PENDING $2"
        pending=$((pending + 1))
    fi
}

# Work packages whose stub a group of phases waits for. A stub still refuses
# with COM_APPOINTMENTS_TEAM_NOT_READY and knows no other team error key; a
# delivered service may keep NOT_READY as a fail-closed guard next to its own
# keys. A additionally bundles sabre/dav in lib/.
is_stub_file() {
    [ -f "$1" ] || return 0
    grep -q 'COM_APPOINTMENTS_TEAM_NOT_READY' "$1" || return 1
    ! grep -o 'COM_APPOINTMENTS_TEAM_[A-Z0-9_]*' "$1" | grep -qv '^COM_APPOINTMENTS_TEAM_NOT_READY$'
}

stubbed() {
    local missing=() service
    for service in "$@"; do
        if [ "$service" = "lib" ]; then
            [ -f "$LIB/sabre/dav/lib/DAV/Server.php" ] || missing+=("A")
        elif is_stub_file "$SERVICES/${service%%:*}.php"; then
            missing+=("${service##*:}")
        fi
    done
    [ ${#missing[@]} -eq 0 ] && return 1
    printf '%s\n' "${missing[@]}" | sort -u | paste -sd, - | sed 's/,/, /g'
}

summary() {
    if [ -n "${WORK:-}" ]; then
        teardown
        trap - EXIT
    fi
    echo ""
    echo "=== CalDAV client test summary ==="
    echo "Failed: ${failed}, Pending: ${pending}"
    [ "$failed" -eq 0 ]
    exit $?
}

# TimingIndex is core: every PUT extracts the time skeleton, and the free/busy view needs it.
CORE=(lib CalendarObjectStore:A KeyService:B CalendarService:C GrantService:C DeviceService:D TeamMailer:D TimingIndex:E AuditService:F LockoutService:F CleanupService:F)
BOOKING=(BookingCalendarBridge:E)

# The client image is built and its versions printed even while phases are
# pending, so the image itself is always tested.
if ! docker build -q -t "$IMAGE" "$HERE" >/dev/null; then
    echo "✗ The client image does not build"
    failed=$((failed + 1))
    summary
fi
if ! docker run --rm --entrypoint python "$IMAGE" -I -c 'import caldav, vdirsyncer, importlib.metadata as m; print("Clients: caldav", caldav.__version__, "vdirsyncer", m.version("vdirsyncer"))'; then
    echo "✗ The client image does not start its clients"
    failed=$((failed + 1))
    summary
fi

if missing="$(stubbed "${CORE[@]}")"; then
    pending_line "CalDAV clients: discovery, sync, roles, vdirsyncer, revocation and clean-up" "$missing"
    summary
fi

if ! docker ps --format '{{.Names}}' | grep -qxF "$CONTAINER"; then
    echo "✗ Joomla container ${CONTAINER} is not running"
    failed=$((failed + 1))
    summary
fi

WORK="$(mktemp -d)" || { failed=$((failed + 1)); summary; }
NETWORK="$(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{"\n"}}{{end}}' "$CONTAINER" | head -n 1)"
if [ -z "$NETWORK" ]; then
    echo "✗ Joomla container has no accessible compose network"
    failed=$((failed + 1))
    rm -rf "$WORK"
    unset WORK
    summary
fi

fixture() {
    # As the web server's user: files the services create (the signed epoch anchor of
    # specification 5.11, Joomla's cache and logs) must stay writable for CalDAV requests.
    docker exec -u www-data "$CONTAINER" php /var/www/html/tests/caldav/fixture.php "$@"
}

teardown() {
    if ! fixture teardown >/dev/null; then
        echo "✗ Fixture teardown failed"
        failed=$((failed + 1))
    fi
    rm -rf "$WORK"
    unset WORK
}
trap teardown EXIT

client() {
    if docker run --rm --network "$NETWORK" -v "$WORK:/work" "$IMAGE" "$1"; then
        return 0
    fi
    failed=$((failed + 1))
    return 1
}

step() {
    # One fixture command; its JSON goes to the work directory when a file is named.
    local output
    if ! output="$(fixture "$1")"; then
        echo "✗ fixture $1 failed"
        echo "$output" | sed 's/^/  /'
        failed=$((failed + 1))
        return 1
    fi
    [ -n "${2:-}" ] && printf '%s\n' "$output" > "$WORK/$2"
    return 0
}

if ! {
    docker exec "$CONTAINER" mkdir -p /var/www/html/tests/scripts /var/www/html/tests/caldav &&
    docker cp "$TESTS/scripts/." "$CONTAINER:/var/www/html/tests/scripts/" >/dev/null &&
    docker cp "$HERE/fixture.php" "$CONTAINER:/var/www/html/tests/caldav/fixture.php" >/dev/null &&
    docker cp "$CONTAINER:/etc/ssl/appointments-test/ca.pem" "$WORK/ca.pem" >/dev/null
}; then
    echo "✗ Preparing the fixture and trusted test CA failed"
    failed=$((failed + 1))
    summary
fi

step setup state.json || summary

for phase in seed discovery sync roles vdirsyncer; do
    client "$phase"
done

if missing="$(stubbed "${BOOKING[@]}")"; then
    pending_line "A booking appears in the client and disappears on cancellation" "$missing"
else
    step book booking.json && client booking
    step cancel && client booking-cancelled
fi

step mfa-disable && client mfa-denied
step mfa-enable && client mfa-restored

step revoke && client revoked

# Clean-up: empty, then hidden, then revoked. Each phase is delivered by the
# client's fetch and advanced by the Scheduler routine.
step cleanup-start && client cleanup-empty
step cleanup-advance && client cleanup-hidden
step cleanup-advance && client cleanup-revoked
client lockout

summary
