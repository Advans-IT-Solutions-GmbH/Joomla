# Testing

Two levels, as everywhere in this repository. Read
`.claude/skills/joomla-extensions/references/testing.md` first; only the component-specific parts
are here.

## Manual acceptance

The complete, reusable [manual plan](../../../../com_appointments/tests/manual/README.md)
contains 228 identified cases and the end-to-end acceptance scenario. It preserves positive,
negative, boundary, concurrency, controlled-fault and integration coverage, including real
calendar clients, conferencing and the three erasure paths. Use the
[blank report template](../../../../com_appointments/tests/manual/report-template.md) in private
storage; record the actual tested commit and never publish credentials, real data or raw evidence.
The plan itself claims no executed manual tests. Human acceptance and the required exact-head
Copilot recommendation remain separate gates.
For Help navigation, UI-03 also checks all eight native fragments, direct entry and browser
Back/Forward against the actual sticky-toolbar geometry, including wrapped mobile actions.
DOM visibility and full-page captures do not establish unobscured native viewport text.
At 375 px, exercise consecutive Help contents clicks in both browsers/themes without added delays;
native scrolling must not move the pointer target between press and release.

## PHPUnit — the domain logic

The slot engine, the ICS writer, the signed links, the feed token and the conference providers are
plain classes with no Joomla dependency. They are tested with PHPUnit and no Joomla bootstrap, the
same way `com_dmarcreports` tests its parser.

```
com_appointments/tests/Unit/
├── KeyProviderTest.php
├── AvailabilityWindowTest.php
├── ScheduleResolverTest.php
├── SlotEngineTest.php            # the largest suite by far
├── SlotRulesTest.php
├── UtcTimestampTest.php
├── IcsWriterTest.php
├── SignedLinkTest.php
├── FeedTokenTest.php
├── MailComposerTest.php
├── AnonymiserTest.php
└── Conference/
    ├── JitsiProviderTest.php
    ├── GenericRoomProviderTest.php
    └── FixedRoomProviderTest.php
```

```bash
cd com_appointments
composer install
composer test
vendor/bin/phpunit --testsuite=Unit
```

The clock is always injected. A test that calls `new DateTimeImmutable()` without an explicit time
is a defect in the test.

### Cases the slot engine suite must contain

| Case | Expectation |
|---|---|
| Spring-forward Sunday, `Europe/Zurich` | no slot at a local time that does not exist |
| Autumn-back Sunday, `Europe/Zurich` | the repeated hour is offered once |
| 20-minute meeting on a 30-minute grid in a 10:00–12:00 window | 10:00, 10:30, 11:00, 11:30 |
| Buffers overlapping the window edge | the first and last slot shift inward |
| `min_notice_minutes` boundary | the slot exactly at the boundary is offered; one minute earlier is not |
| `horizon_days = 30` | the 30th day is fully bookable, the 31st is not |
| `max_per_day` reached | the whole day disappears, not only the next slot |
| `max_per_week` reached, week starting Monday in the schedule's zone | the rest of the week disappears |
| Window crossing midnight | treated as one window, slots on both sides |
| Date-specific override | replaces the weekday rules entirely |
| `is_unavailable` date | the day yields nothing |

## Integration — Docker against a real Joomla

**Two Joomla lanes, exactly as every other extension.** `tests/Dockerfile` builds on
`joomla:5.4-php8.3-apache` and is used by `docker-compose.yml`; `tests/Dockerfile.joomla6` builds on
`joomla:6-php8.4-apache` and is used by `docker-compose.joomla6.yml`. Both tags are moving tags, so
no Joomla patch version is pinned.

**Plus a MariaDB lane**, `docker-compose.mariadb.yml`, reusing `Dockerfile.joomla6` against
`mariadb:10.6`. The component advertises MySQL or MariaDB, and the booking table relies on an
indexed `STORED` generated column that has that form precisely because the two engines disagree
about indexing a `VIRTUAL` one. Testing the schema on MySQL alone would leave the claim unproven.
Only the schema-sensitive suites run there — installation, structure, uninstall — because the rest
is engine independent and already covered.

No J2Commerce is involved, so no `j2commerce6.zip` is needed. Follow the shape of
`com_dmarcreports`, which is also J2Commerce-free and has both lanes.

**HTTPS in every lane.** CalDAV answers over HTTPS only, so both Dockerfiles run
`tests/caldav/tls/setup-tls.sh` at build time: a throw-away test CA signs a server certificate for
`localhost`, `joomla` (the compose service name) and `127.0.0.1`, and Apache serves the site on
port 443 next to the unchanged port 80. The CA is at `/etc/ssl/appointments-test/ca.pem` in the
image and in the image's system trust store. The images set `TEAM_BASE_URL=https://localhost`,
which `21-teamcalendars.php` reads; every other suite keeps using `http://localhost`. Host ports:
8443 (J5), 8444 (J6), 8445 (MariaDB).

Certificates are verified, never switched off: the workflow's `curl -f --cacert` probe right after
each container is ready, so a broken TLS set-up fails before any suite runs, and `TeamSuite::http()`
in `21-teamcalendars.php`, which sets `CURLOPT_CAINFO` to `ca.pem`. A client outside the container
verifies against `ca.pem` too (copy it out with `docker cp`).

**Real CalDAV clients** (job `caldav-clients`, J6 lane): `tests/caldav/run-clients.sh` builds a
client container (`tests/caldav/Dockerfile`, pinned Python `caldav` and `vdirsyncer`) on the compose
network, which reaches the site as `https://joomla` and verifies the certificate against `ca.pem`.
`tests/caldav/fixture.php` prepares and changes the server between the phases of
`tests/caldav/clients.py` through the component's own services: seed, discovery, sync, roles,
vdirsyncer, booking, revocation and the three clean-up phases. Automated assertions cover real
initial and incremental CalDAV sync with `disable_fallback=True`, preservation of all-day and
recurring events, and vdirsyncer create/change/delete in both directions. Negative assertions
cover anonymous and mismatched credentials, case-insensitive names, MFA removal/restoration,
and stale DELETE rejection with contents retained. Sync tombstones remove local entries during
clean-up, booking cancellation removes the local event, and the final lockout assertion requires
HTTP 429 with `Retry-After`. Clean-up uses independent devices for the sync tombstones, empty
collection listing, vdirsyncer's deletion guard and explicitly forced local deletion: fetching the
empty collection already advances that device to hidden, and listing its hidden home revokes it.
A separate idle device checks both Scheduler timeout transitions without consuming those deliveries.
The devices that prove the clean-up choose «Clean up» explicitly, every other one keeps the
recommendation of its client. Before the clean-up, a removed Outlook device («Block only») must get
401 for PROPFIND and REPORT, report no deletions, and its vdirsyncer copy must stay complete. The
revoked calendar of WRITER («Block only») answers 404 to REPORT as well.
The same job also runs `authenticated.py`: a synthetic account starts without a key or device,
signs in through Joomla's real password and TOTP forms (codes generated locally), and releases
its first key only after MFA. The fixture then grants calendar access; the browser submits
the normal device form twice (with «Clean up» chosen) and both actual clients use the returned one-time app passwords
to read, create, change and delete entries. Wrong app passwords and Joomla passwords are
refused; blocking permits only empty cleanup delivery, and final revocation refuses both clients.
Credentials, TOTP seeds and browser cookies stay in the private work directory, never in artifacts.
These are automated assertions, not a claim that end-to-end execution
succeeded on a partial branch. Phases whose work package still ships its stub print PENDING, as
`TeamSuite::pending()` does (a failure with `TEAM_NO_PENDING=1`).
The job also verifies the Joomla runtime's required PHP extensions before starting the clients.
Its output and compose diagnostics are uploaded even on failure; Bash's pipeline failure handling
keeps a failed runner from being hidden by `tee`.

On PRs to `main`, pushes to `main`, and integrated manual dispatches, the client job sets
`TEAM_NO_PENDING=1` and first runs `21-teamcalendars.php` with that flag explicitly passed through
`docker exec`. The shared test runner does not forward this flag. Any unfinished work package
therefore fails the integrated gate, rather than producing a misleading success.
Manual dispatches on a partial branch retain explicit PENDING dependencies by default;
that lane is not evidence of complete calendar-client support. Select `integrated: true`
when dispatching an assembled integration branch to require the complete gate. Dispatches on
`main` are always strict, regardless of that input. The run summary records which mode ran.

Locally, with the Joomla 6 stack running: `bash caldav/run-clients.sh` from `tests/`.
Use `TEAM_NO_PENDING=1 bash caldav/run-clients.sh` for the integrated client gate; successful static
checks alone do not prove that either real client ran.

For a disposable native installation without Docker, `tests/caldav/run-authenticated.py`
executes the same authenticated phases and seed fixture. It accepts only a loopback HTTPS origin,
requires the test CA and an installed candidate, and never installs a test endpoint into Joomla.
Use a private work directory, the pinned dependencies in `tests/caldav/requirements.txt`, a PHP
configuration with the required extensions and the CLI name of the synthetic administrator:

```text
python tests/caldav/run-authenticated.py --root <disposable-joomla-root> --base <loopback-https-origin> --ca <test-ca.pem> --php-ini <test-php.ini> --admin-username <synthetic-admin> --work <new-private-directory>
```

The PHP fixture reads `APPOINTMENTS_TEST_ROOT`, `APPOINTMENTS_TEST_DAV_URL` and
`APPOINTMENTS_FIXTURE_STATE` only in this test process; the installed runtime is unchanged.
Successful teardown removes accounts, device secrets and cookies. Failed setup/teardown retains
private checkpoints for retry; do not upload the work directory or point it at a real site.

```
com_appointments/tests/scripts/
├── 01-installation.php      # tables created, task plugin registered, both Scheduler tasks created once (never re-enabled), no signing secret stored
├── 02-structure.php         # manifest files installed, LICENSE.txt present in both parts, name columns and their 1.3.0 migration
├── 03-site-page.php         # the public pages really render over HTTP, empty and with seeded rows
├── 04-schedules.php         # a schedule is really submitted, stored, reloaded and validated
├── 05-blocked.php           # a blocked period is submitted from a non-UTC zone, stored as UTC, and the clock-change refusals hold
├── 06-eventtypes.php        # CRUD, ACL, feed token generated and unique
├── 07-slots.php             # the site slot endpoint returns what the engine returns
├── 08-booking.php           # end-to-end booking, both concurrency cases, first/last name, max_guests
├── 09-mail.php              # every e-mail carries a text/calendar part, guests get the guest template and link
├── 10-lifecycle.php         # reschedule and cancel, UID stable, SEQUENCE raised
├── 11-erasure.php           # the three deletion paths, the audit table, guest links (valid, tampered, replayed)
├── 12-feed.php              # token, 304, rotation, 404 on a wrong token
├── 12-scheduler.php         # real Scheduler CLI, installed tasks, reminders, batches and retention
├── 13-conference.php        # link in mail, ICS and page; provider failure handled
├── 14-privacy.php           # typed Joomla events, Unicode ownership, guest export/removal and legal blocking
├── 15-administration.php    # scoped filters/exports, details/reschedule and system checks
├── 16-menu-routing.php      # menu item form, multilingual SEF routing ("All" and one language), mail links
├── 21-teamcalendars.php     # internet calendars: one runner, one part file team-<letter>-*.php per work package
├── 20-uninstall.php         # final installed-suite run: tables dropped, task plugin removed, no orphan rows
└── upgrade-from-v1.3.0.php  # separate CI-only published v1.3.0 upgrade fixture
```

Build the package once, then run either lane. `tests/test.env` names the Joomla 5 container, so the
Joomla 6 lane gets its container name on the command line:

```bash
cd com_appointments
./build.sh && mkdir -p tests && cp *.zip tests/extension.zip
cd tests

# Joomla 5.4 lane
docker compose up -d
./run-tests.sh all
docker compose down -v

# Joomla 6 lane
docker compose -f docker-compose.joomla6.yml up -d
CONTAINER_NAME=com_appointments_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v

# MariaDB lane, schema-sensitive suites only
docker compose -f docker-compose.mariadb.yml up -d
for suite in installation structure uninstall; do
  CONTAINER_NAME=com_appointments_mariadb_test ./run-tests.sh "$suite"
done
docker compose -f docker-compose.mariadb.yml down -v
```

CI sets `TEST_STRICT_SKIP=1`, so a skipped test fails. Reproduce that locally before pushing.

### The site page assertion

`03-site-page.php` requests the public pages over HTTP, because `file_exists()` never opens a page
and the shared `backend-views` suite covers the administrator only. It runs twice: once against the
empty table, which is the empty-state branch, and once with four seeded event types — one visible
and three that each have to be filtered out for a different reason, namely unpublished, an access
level a guest does not hold, and a different content language. The visible one carries `<`, `&` and
`"` in its title, so the layout's escaping is asserted and not only the model's filters.
It then walks the booking page in steps without JavaScript: the calendar alone, the times of a
chosen day with one **Next** button each, the GET form of a time to step 2 (`start=`) and its
**Back** link, both information-column layouts with markup in name and role, an unknown layout
(falls back to the logo layout), a time no longer offered (times of its day with the reason) and
a malformed `start` (400). `16-menu-routing.php` sends the same GET form on a SEF menu address.

### The schedule permission assertions

`04-schedules.php` submits the real administrator form as both an administrator and a restricted
operator. A creator without `core.edit.state` must create an unpublished schedule whether the
request omits `published` or tries to set it to `1`; the database's default must not publish it.
An editor without that permission must still be able to change the schedule's content while its
existing publication state stays unchanged. An administrator with the permission is the positive
control and can create a published schedule.
Direct edit views, including mixed-case names, must also refuse an existing record until the edit
task has checked it out for that session. Direct save and cancel POSTs require the same held edit ID, before
any raw blocked-period validation or form-state mutation. The edit task must not acquire another
operator's checkout. The schedule suite also submits the fields rendered by an unchanged edit form
containing a closed day, and verifies successful save and checkout release, not merely unchanged
database rows after a possible validation failure.
Both administration suites also request list ordering by the ambiguous bare `id` and by the
qualified table alias, and require the stored fixture to render without a joined-query failure.
Pure-helper regressions cover both supported calendar-date edges, synthetic adjacent dates,
overnight windows on the maximum date, and fixed horizons supplied as non-UTC instants.
Rolling-horizon tests include Apia's skipped date and Havana's repeated midnight, using the
resolver's convention for the exclusive boundary.
An overlapping weekly or date-specific overnight rule must not add its grid to the following
date's override. Tests compare wide and narrow queries, retain meetings starting before midnight,
and cover override grid phases across repeated time.
The schedule form explicitly includes Joomla's translated UTC option; HTTP assertions verify the
site-zone default and the unchanged save of an existing UTC schedule from rendered fields.
The structure suite verifies the mandatory schedule foreign key, the legacy schema migration and
two-connection assignment/deletion races on both MySQL and MariaDB. Installation asserts the
registered schema baseline, so later migrations are not silently skipped.

### The blocked-period redisplay assertions

`05-blocked.php` checks the actual input values, not only the error message, after an impossible
date and each DST refusal. Both bounds must remain exactly as submitted. A corrected submission
must update the same row using the normal UTC conversion, and a subsequent edit must display it in
the operator's zone again.

A record the edit task has checked out is not removed by the list's delete task. A suite that
deletes a record it edited first releases the checkout (`05-blocked.php` posts `blocked.cancel`
before `blockeds.delete`), or deletes before any edit attempt.

### The mail assertion

`09-mail.php` is the test that enforces a stated constraint of the project. It must not check one
happy path; it must enumerate every e-mail-sending code path and assert a `text/calendar` part on
each. Implement it by capturing outgoing mail in the test environment, not by inspecting the
composer's return value, so a path that bypasses `MailComposer` is caught too.

## CI workflows

Three new files, mirroring the DMARC set:

| File | Trigger | Purpose |
|---|---|---|
| `.github/workflows/appointments.yml` | push/PR to `main` for `com_appointments/**`, the shared files the component actually uses, and the workflow file; plus manual | Build & Test, both lanes in the matrix and real CalDAV clients on J6 |
| `.github/workflows/release-appointments.yml` | `workflow_dispatch` | Stage 1: bump and open the release pull request |
| `.github/workflows/publish-appointments.yml` | release pull request merged | Stage 2: package, tag, GitHub Release |

Tag prefix: `appointments-v`.

`appointments.yml` lists the shared files by name, never `shared/**` as a whole. At minimum:
`shared/build/build.sh`, `shared/build/verify-package.sh`, `shared/tests/run-tests.sh`,
`shared/tests/requirements-check.php`, `shared/tests/lang-lint.php`,
`shared/tests/deprecated-api-scan.php` and the shared scripts the component's `test.env` actually
runs. `shared-path-coverage.yml` fails if a file under `shared/` is matched by no workflow, so check
it after adding the component.

The `upgrade-v130` matrix starts isolated Joomla 5.4 and 6 installations from the actual published
`appointments-v1.3.0` ZIP, verifies its asset digest, seeds representative 1.3.0 schedules,
event types and plaintext bookings, then installs the exact candidate ZIP through Joomla's CLI.
It checks the 1.4.0 schema migration and legacy-feed compatibility, then verifies that the same
plaintext bookings remain readable during the authenticated seven-day migration window. The
fixture expires that token and uses the authenticated administrator action on those bookings,
requiring `v2:` ciphertext and exactly one audit row per booking. The upgraded installations also
run the booking suite and strict team-calendar suite, with real CalDAV clients on Joomla 6.
The release workflow intentionally retires older assets, so this transition-specific job reports
an explicit skip after the v1.3.0 source asset is removed.

Concurrency: cancel a superseded run of the same branch or pull request, never on `main`, never for
`workflow_dispatch`, never in the release or publish workflows.

The required check on a pull request is `Collect Results` alone. After re-running a failed component
workflow, re-run `Collect Results` too.
This component workflow, the collector and the other repository-wide PR workflows target `main`;
they do not automatically run for the partial base `feat/appointments-teamkalender`. A manual
dispatch is separate execution evidence, not proof that the PR-triggered required gate ran.
An empty `statusCheckRollup` is never a successful gate. Preserve the partial PR base and use a
manual dispatch; do not retarget the PR or weaken branch protections to obtain checks.
