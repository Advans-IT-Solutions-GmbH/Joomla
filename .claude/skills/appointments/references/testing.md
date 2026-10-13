# Testing

Two levels, as everywhere in this repository. Read
`.claude/skills/joomla-extensions/references/testing.md` first; only the component-specific parts
are here.

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

```
com_appointments/tests/scripts/
├── 01-installation.php      # tables created, task plugin registered, no signing secret stored
├── 02-structure.php         # manifest files installed, LICENSE.txt present in both parts
├── 03-site-page.php         # the public pages really render over HTTP, empty and with seeded rows
├── 04-schedules.php         # a schedule is really submitted, stored, reloaded and validated
├── 05-eventtypes.php        # CRUD, ACL, feed token generated and unique
├── 06-slots.php             # the site slot endpoint returns what the engine returns
├── 07-booking.php           # end-to-end booking, both concurrency cases
├── 08-mail.php              # every e-mail carries a text/calendar part
├── 09-lifecycle.php         # reschedule and cancel, UID stable, SEQUENCE raised
├── 10-erasure.php           # the three deletion paths and the audit table
├── 11-feed.php              # token, 304, rotation, 404 on a wrong token
├── 12-conference.php        # link in mail, ICS and page; provider failure handled
└── 20-uninstall.php         # tables dropped, task plugin removed, no orphan rows
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

### The mail assertion

`08-mail.php` is the test that enforces a stated constraint of the project. It must not check one
happy path; it must enumerate every e-mail-sending code path and assert a `text/calendar` part on
each. Implement it by capturing outgoing mail in the test environment, not by inspecting the
composer's return value, so a path that bypasses `MailComposer` is caught too.

## CI workflows

Three new files, mirroring the DMARC set:

| File | Trigger | Purpose |
|---|---|---|
| `.github/workflows/appointments.yml` | push/PR to `main` for `com_appointments/**`, the shared files the component actually uses, and the workflow file; plus manual | Build & Test, both lanes in the matrix |
| `.github/workflows/release-appointments.yml` | `workflow_dispatch` | Stage 1: bump and open the release pull request |
| `.github/workflows/publish-appointments.yml` | release pull request merged | Stage 2: package, tag, GitHub Release |

Tag prefix: `appointments-v`.

`appointments.yml` lists the shared files by name, never `shared/**` as a whole. At minimum:
`shared/build/build.sh`, `shared/build/verify-package.sh`, `shared/tests/run-tests.sh`,
`shared/tests/requirements-check.php`, `shared/tests/lang-lint.php`,
`shared/tests/deprecated-api-scan.php` and the shared scripts the component's `test.env` actually
runs. `shared-path-coverage.yml` fails if a file under `shared/` is matched by no workflow, so check
it after adding the component.

Concurrency: cancel a superseded run of the same branch or pull request, never on `main`, never for
`workflow_dispatch`, never in the release or publish workflows.

The required check on a pull request is `Collect Results` alone. After re-running a failed component
workflow, re-run `Collect Results` too.
