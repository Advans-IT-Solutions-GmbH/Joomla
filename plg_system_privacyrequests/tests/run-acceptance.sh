#!/bin/bash
# Installed browser/mail acceptance (the CJS suites) on the disposable synthetic
# stack from docker-compose.yml + docker-compose.acceptance.yml. Never target a
# live site. Expects the stack to be up and healthy and these variables:
#   CONTAINER_NAME        the Joomla container of that stack
#   PRIVACY_PACKAGES      directory with appointments.zip, j2commerce-privacy.zip,
#                         probe.zip and mailprobe.zip
#   PRIVACY_CAPTURE_ROOT  host directory mounted at /privacy-capture
#   PRIVACY_RESULTS       empty host directory for logs and observations
#   PLAYWRIGHT_MODULE     path of the pinned playwright package
# Every suite runs, then the script fails if any suite did not exit 0.
set -euo pipefail
cd "$(dirname "$0")"
: "${CONTAINER_NAME:?}" "${PRIVACY_PACKAGES:?}" "${PRIVACY_CAPTURE_ROOT:?}" "${PRIVACY_RESULTS:?}" "${PLAYWRIGHT_MODULE:?}"

container="$CONTAINER_NAME"
site=/var/www/html
tests="$PWD"
smtp_port=1025
worker_port=8081

web() { docker exec -u www-data -e HTTP_HOST=localhost "$container" "$@"; }
joomla() { web php "$site/cli/joomla.php" "$@" --no-interaction; }
fixture() { web php "$tests/fixture.php" "$@"; }
wait_port() {
    timeout 60 bash -c "until (exec 3<>/dev/tcp/127.0.0.1/$1) 2>/dev/null; do sleep 1; done"
}

# The test entrypoint installed the generic plugin as root; the web server and
# every later step run as www-data.
docker exec "$container" chown -R www-data:www-data "$site"

# Synthetic probes and the coupled packages, through Joomla's normal installer.
# Disabled Scheduler tasks exist before Appointments, as on an existing site.
for package in probe mailprobe appointments j2commerce-privacy; do
    docker cp "$PRIVACY_PACKAGES/$package.zip" "$container:/tmp/privacy-$package.zip"
done
docker exec "$container" chown www-data:www-data /tmp/privacy-probe.zip /tmp/privacy-mailprobe.zip \
    /tmp/privacy-appointments.zip /tmp/privacy-j2commerce-privacy.zip
joomla extension:install --path=/tmp/privacy-probe.zip
joomla extension:install --path=/tmp/privacy-mailprobe.zip
fixture appointments-prepare
joomla extension:install --path=/tmp/privacy-appointments.zip
joomla extension:install --path=/tmp/privacy-j2commerce-privacy.zip

# Loopback SMTP capture and the second, independent PHP process.
mkdir -p "$PRIVACY_CAPTURE_ROOT/mail" "$PRIVACY_CAPTURE_ROOT/spool"
docker exec -d "$container" php "$tests/mail-capture.php" 127.0.0.1 "$smtp_port" \
    /privacy-capture/mail /privacy-capture/spool
docker exec -d -u www-data "$container" php -S "127.0.0.1:$worker_port" -t "$site"
wait_port "$smtp_port"
wait_port "$worker_port"
joomla config:set mailer=smtp smtphost=127.0.0.1 "smtpport=$smtp_port" smtpauth=false \
    smtpsecure=none mailonline=true
fixture generic-enable
# A fresh administrator gets Joomla's autostart welcome tour, which sends every
# backend page back to the dashboard until it is dismissed. A real operator
# dismisses it once; the suites drive the backend directly, so switch it off.
prefix="$(web php -r 'require "/var/www/html/configuration.php"; echo (new JConfig())->dbprefix;')"
printf "UPDATE \`%sextensions\` SET enabled=0 WHERE type='plugin' AND folder='system' AND element='guidedtours';\n" "$prefix" \
    | docker compose -p "$CONTAINER_NAME" -f docker-compose.yml -f docker-compose.acceptance.yml exec -T mysql \
        sh -c 'mysql -u"$MYSQL_USER" -p"$MYSQL_PASSWORD" "$MYSQL_DATABASE"'

export JOOMLA_URL=http://127.0.0.1
export PRIVACY_CONCURRENT_URL="http://127.0.0.1:$worker_port"
export PRIVACY_MAIL_DIR="$PRIVACY_CAPTURE_ROOT/mail"
export PRIVACY_TEST_ROOT="$site"
export PRIVACY_PHP=docker
export PRIVACY_PHP_ARGS="[\"exec\",\"-i\",\"-u\",\"www-data\",\"$container\",\"php\"]"
JOOMLA_ADMIN_USERNAME="$(docker exec "$container" printenv JOOMLA_ADMIN_USERNAME)"
JOOMLA_ADMIN_PASSWORD="$(docker exec "$container" printenv JOOMLA_ADMIN_PASSWORD)"
export JOOMLA_ADMIN_USERNAME JOOMLA_ADMIN_PASSWORD
curl --fail --silent --output /dev/null "$JOOMLA_URL/"
curl --fail --silent --output /dev/null "$PRIVACY_CONCURRENT_URL/"

summary="$PRIVACY_RESULTS/summary.md"
printf '| Suite | Exit | Passed checks |\n|---|---|---|\n' > "$summary"
failed=0
run_suite() {
    local suite="$1" report="$PRIVACY_RESULTS/$1" code
    mkdir -p "$report"
    echo "::group::$suite.cjs"
    set +e
    # A suite that throws before closing its browser would otherwise hang the job.
    PRIVACY_TEST_REPORT_DIR="$report" timeout --kill-after=30 1200 node "$tests/$suite.cjs" 2>&1 \
        | tee "$report/output.log"
    code="${PIPESTATUS[0]}"
    set -e
    echo "::endgroup::"
    printf '| %s | %s | %s |\n' "$suite" "$code" "$(grep -c '^PASS ' "$report/output.log" || true)" >> "$summary"
    if [ "$code" -ne 0 ]; then
        echo "::error::$suite.cjs exited $code"
        failed=1
    fi
}

# The coupled site runs the existing shop privacy plugins as an upgraded shop
# would: privacy and mail-hook plugins enabled, their Scheduler task plugin off.
# The registered listener-order and legacy/export suites need them active.
fixture legacy-enable > "$PRIVACY_RESULTS/legacy-enable.json"
for suite in browser lifecycle registered admission concurrency operator legacy export appointments; do
    run_suite "$suite"
done

cat "$summary"
if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    cat "$summary" >> "$GITHUB_STEP_SUMMARY"
fi
exit "$failed"
