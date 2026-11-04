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
set -u

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
# with COM_APPOINTMENTS_TEAM_NOT_READY; A additionally bundles sabre/dav in lib/.
stubbed() {
    local missing=() service
    for service in "$@"; do
        if [ "$service" = "lib" ]; then
            [ -d "$LIB" ] || missing+=("A")
        elif grep -q COM_APPOINTMENTS_TEAM_NOT_READY "$SERVICES/${service%%:*}.php"; then
            missing+=("${service##*:}")
        fi
    done
    [ ${#missing[@]} -eq 0 ] && return 1
    printf '%s\n' "${missing[@]}" | sort -u | paste -sd, - | sed 's/,/, /g'
}

summary() {
    echo ""
    echo "=== CalDAV client test summary ==="
    echo "Failed: ${failed}, Pending: ${pending}"
    [ "$failed" -eq 0 ]
    exit $?
}

CORE=(lib CalendarObjectStore:A KeyService:B CalendarService:C GrantService:C DeviceService:D TeamMailer:D AuditService:F LockoutService:F CleanupService:F)
BOOKING=(BookingCalendarBridge:E TimingIndex:E)

# The client image is built and its versions printed even while phases are
# pending, so the image itself is always tested.
if ! docker build -q -t "$IMAGE" "$HERE" >/dev/null; then
    echo "✗ The client image does not build"
    failed=$((failed + 1))
    summary
fi
docker run --rm --entrypoint python "$IMAGE" -I -c 'import caldav, importlib.metadata as m; print("Clients: caldav", caldav.__version__, "vdirsyncer", m.version("vdirsyncer"))'

if missing="$(stubbed "${CORE[@]}")"; then
    pending_line "CalDAV clients: discovery, sync, roles, vdirsyncer, revocation and clean-up" "$missing"
    summary
fi

if ! docker ps --format '{{.Names}}' | grep -qxF "$CONTAINER"; then
    echo "✗ Joomla container ${CONTAINER} is not running"
    failed=$((failed + 1))
    summary
fi

WORK="$(mktemp -d)"
NETWORK="$(docker inspect -f '{{range $name, $_ := .NetworkSettings.Networks}}{{$name}}{{end}}' "$CONTAINER")"

fixture() {
    docker exec "$CONTAINER" php /var/www/html/tests/caldav/fixture.php "$@"
}

teardown() {
    fixture teardown >/dev/null || echo "  (fixture teardown failed)"
    rm -rf "$WORK"
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

docker exec "$CONTAINER" mkdir -p /var/www/html/tests/scripts /var/www/html/tests/caldav
docker cp "$TESTS/scripts/." "$CONTAINER:/var/www/html/tests/scripts/" >/dev/null
docker cp "$HERE/fixture.php" "$CONTAINER:/var/www/html/tests/caldav/fixture.php" >/dev/null
docker cp "$CONTAINER:/etc/ssl/appointments-test/ca.pem" "$WORK/ca.pem" >/dev/null

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

step revoke && client revoked

# Clean-up: empty, then hidden, then revoked. Each phase is delivered by the
# client's fetch and advanced by the Scheduler routine.
step cleanup-start && client cleanup-empty
step cleanup-advance && client cleanup-hidden
step cleanup-advance && client cleanup-revoked

summary
