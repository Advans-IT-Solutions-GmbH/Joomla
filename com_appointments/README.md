# Appointments (com_appointments)

[![Build & Test](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/appointments.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/appointments.yml)
[![Release](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-appointments.yml/badge.svg)](https://github.com/Advans-IT-Solutions-GmbH/Joomla/actions/workflows/release-appointments.yml)
[![Joomla 5.4+](https://img.shields.io/badge/Joomla-5.4%2B-blue.svg)](https://www.joomla.org/)
[![Joomla 6](https://img.shields.io/badge/Joomla-6.x-blue.svg)](https://www.joomla.org/)
[![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-purple.svg)](https://www.php.net/)

Self-hosted appointment scheduling for Joomla. The site operator publishes bookable time slots, a
visitor picks a date and a time, and the confirmation e-mail carries the calendar entry as an
attachment so it lands in a locally installed mail client.

## Description

Everything runs on the operator's own server: availability rules, slot calculation, booking storage,
e-mail delivery, iCalendar generation and the conference link. **No third-party scheduling service
is required or contacted**, and the component works on a server whose outbound internet access is
blocked.

> **Status: in development.** The component is being built in stages. This file grows with the
> implemented feature set and will document every shipped setting before the first release. The
> sections below say what is implemented today and name what is still to come, so nothing here
> should be read as a feature you can already use.

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or later
- MySQL or MariaDB. The installer refuses any other database server, because the booking table uses
  a generated column to guarantee that one time slot can be held by exactly one booking.
- Administrator access for configuration

## Joomla 6 Compatibility

The component resolves its database through `DatabaseInterface`. Queries use `createQuery()` when
the driver provides it and fall back to `getQuery(true)` on Joomla 5.4. No platform-specific
configuration is required.

## Installation

1. Download the package `com_appointments_<version>.zip` from the repository releases.
2. Install it in Joomla through **System → Install → Extensions**.
3. Open **Components → Appointments**.

## Updating

The component registers its own update site during installation, so later versions appear under
**System → Update → Extensions**. You can also install a newer ZIP over the existing installation.

## Features

### What is implemented so far

- The component skeleton with its administrator and site part, the full database schema, Joomla
  access control on the component, and the three shipped languages (`de-DE`, `en-GB`, `fr-FR`).
- An administrator dashboard that shows the number of event types and bookings and an empty state
  while nothing is configured yet.
- A public page that lists the bookable event types a visitor is allowed to see.
- **Schedules.** A named weekly pattern with any number of intervals per weekday, in one time zone,
  plus date-specific entries that replace the weekly times of a single date or close it entirely.
  A schedule that an event type still uses cannot be deleted, even during a concurrent assignment:
  a database foreign key enforces the reference. Creating a schedule without
  `core.edit.state` leaves it unpublished; editing its content without that permission preserves
  its existing publication state.
- **Blocked periods.** Time a host is not available, independent of any event type. Entered in the
  operator's own time zone and stored in universal time.
- **The slot engine.** The calculation behind the booking page: it turns a schedule, its overrides,
  the blocked periods and the existing bookings into the bookable start times, honouring duration,
  slot step, buffers, minimum notice, booking horizon and the daily and weekly caps. It handles both
  days a year on which the clocks change: a time that never happened is never offered, and an hour
  that happens twice is offered once. The engine has no clock and no database of its own, which is
  why all of that is covered by unit tests.

Still to come: the public booking form itself, e-mail notifications with the calendar attachment,
calendar subscription feeds, the reschedule, cancel and erasure links, and the conferencing links.

## Configuration

Open **Components → Appointments → Options → Permissions** to configure the component's Joomla
ACL. General booking settings arrive in later stages; the current screens configure schedules and
blocked periods.

### Schedules

| Setting | Purpose |
|---------|---------|
| Title | Name shown in the administrator list |
| Time zone | IANA identifier used to interpret the weekly times and override dates |
| Owner | Joomla user who owns the schedule; defaults to the current operator when omitted |
| Weekly intervals | Weekday and start/end times in `HH:MM`; multiple intervals per day are allowed |
| Date-specific overrides | Date in `YYYY-MM-DD` and replacement intervals, or a whole-day closure |
| Published | Enables or disables the schedule; changing it requires `core.edit.state` |

An interval whose end precedes its start crosses midnight. A date-specific override replaces the
weekly intervals of that date, and a closed date cannot also contain open override intervals.
Overrides are dates in the schedule's zone, not instants converted into the reader's zone.
The time zone selector includes UTC explicitly and defaults new schedules to the site's zone.
Closed dates reload with empty time fields; the database's midnight sentinels are not editable
availability. A date skipped entirely by a time-zone change offers no windows, even for overnight
rules, and is never shifted onto the following date.

### Blocked periods

| Setting | Purpose |
|---------|---------|
| Host | Joomla user whose time is blocked |
| Title | Internal note; never shown publicly |
| Start and end | Entered in the operator's Joomla time zone and stored in UTC |

Calendar fields use `YYYY-MM-DD HH:MM:SS` in every language so submitted values can be validated
before conversion. Nonexistent or ambiguous local times at a clock change are refused instead of
silently shifted. A blocked period has no published state.
After such a refusal, the bounds temporarily render as plain text so the original input is not
normalised or converted again. Correcting and saving restores the normal calendar fields.

## Usage

1. Open **Components → Appointments → Schedules** and create a named schedule with a time zone.
2. Add weekly intervals and any date-specific replacements or closures, then save.
3. Open **Blocked periods** to record a host's absences independently of an event type.

The dashboard links to both lists. Event-type administration and the public booking form are not
implemented yet; these screens prepare the availability the later booking flow will use.

## Signing keys

Key derivation is implemented; the features that will use it are not. `KeyProvider` derives one key
per purpose from the Joomla site secret of the installation, and the two purposes it defines are the
signed links in the component's e-mails and the hashing of IP addresses in the rate-limit counters.
Both of those arrive in later stages.

The property that matters is already in place and covered by the unit suite: no key is stored in the
database, printed into a page or written to a log, and no key changes when the component is updated,
so a link that is already in someone's mailbox will keep working.

## Uninstall

Uninstall through **System → Manage → Extensions**. This removes the component files and drops
every table it created, including all stored bookings. Export what you still need first.

## Development

The directory layout follows `com_dmarcreports`, with an additional site part:

```text
com_appointments/
├── com_appointments.xml                 # Manifest; version managed by release workflow
├── script.php                          # Install/update/uninstall
├── build.env, build.sh                  # Wrapper for the shared packager
├── composer.json, phpunit.xml           # Development-only PHPUnit
├── administrator/
│   ├── components/com_appointments/
│   │   ├── access.xml, LICENSE.txt
│   │   ├── forms/, services/, sql/
│   │   ├── src/                        # Controllers, pure helpers, models, tables and views
│   │   └── tmpl/
│   └── language/{de-DE,en-GB,fr-FR}/
├── components/com_appointments/         # Site services, models, views and LICENSE.txt
├── language/{de-DE,en-GB,fr-FR}/
├── media/com_appointments/              # Local assets only
├── updates/update.xml                   # Managed by release workflow
└── tests/                              # Unit tests and Docker integration harness
```

The binding [implementation plan](../.claude/skills/appointments/references/implementation-plan.md)
defines the remaining stages. Versions are managed by the repository's two-stage
[release workflow](../README.md#releases), never set manually.

## Automated Testing

[`appointments.yml`](../.github/workflows/appointments.yml) follows the repository's standard
Build & Test workflow: package build, PHP 8.1 syntax check, language lint, compatibility
requirements check, deprecated-API scan, PHPUnit, and real Joomla integration suites. It runs on
relevant pushes and pull requests to `main`, and by manual dispatch. **Collect Results** is the
required pull request check.

The full integration matrix runs on the newest Joomla 5.4 and Joomla 6 images with MySQL. A third
lane runs the schema-sensitive suites on Joomla 6 with MariaDB 10.6, because the booking table
relies on an indexed `STORED` generated column. No J2Commerce installation is needed.

### Test Suites

The authoritative list and order is [`tests/test.env`](tests/test.env):

| Suite | Coverage |
|-------|----------|
| Installation | Registration, installed files and database schema |
| Structure | Namespaces, forms, calendar-field contracts, languages and license deployment |
| Site page | Real HTTP responses for the public list, visibility filters and escaped output |
| Schedules | Form submission, stored intervals, validation, publication permissions and deletion |
| Blocked periods | Form submission, UTC conversion and invalid or ambiguous local-time rejection |
| Backend views | Shared HTTP rendering checks for every administrator view |
| Installer messages | Shared install/update checks in en-GB, de-DE and fr-FR |
| Uninstall | Component removal and table cleanup |

PHPUnit covers the pure helpers without Joomla or a database, including DST, buffers, the slot
grid, minimum notice, booking horizons and caps. CI treats skipped integration assertions as
failures.

### Running Tests Locally

Use Bash (WSL or Git Bash) with Docker available. Build the package before starting the containers;
full prerequisites are in the shared
[testing guide](../.claude/skills/joomla-extensions/references/testing.md#local-prerequisites).

```bash
# From the repository root
(cd com_appointments && ./build.sh && cp *.zip tests/extension.zip)
cd com_appointments/tests

# Joomla 5.4; the runner waits for readiness
docker compose up -d
TEST_STRICT_SKIP=1 ./run-tests.sh all
docker compose down -v

# Joomla 6
docker compose -f docker-compose.joomla6.yml up -d
TEST_STRICT_SKIP=1 CONTAINER_NAME=com_appointments_j6_test ./run-tests.sh all
docker compose -f docker-compose.joomla6.yml down -v

# MariaDB; schema-sensitive suites only
docker compose -f docker-compose.mariadb.yml up -d
for suite in installation structure uninstall; do
  TEST_STRICT_SKIP=1 CONTAINER_NAME=com_appointments_mariadb_test ./run-tests.sh "$suite"
done
docker compose -f docker-compose.mariadb.yml down -v
```

Run the unit suite separately:

```bash
cd com_appointments
composer install
composer test:unit
```

Integration results are saved in `tests/test-results/`. If Bash or Docker is unavailable locally,
the package build and integration suites must be verified in CI, not reported as local passes.

## Troubleshooting

**Cannot access the component:** check Joomla administrator access and the component's permissions.

**A new schedule stays unpublished:** its creator needs `core.edit.state` to publish it; permission
to create a schedule alone does not grant permission to enable it.

**A schedule cannot be deleted:** remove its event-type assignments first.

**An update reports missing schedules:** an older installation contains event types pointing to
missing schedules (including the former zero default). Reassign those event types to existing
schedules before retrying the update. The installer refuses the upgrade rather than deleting or
silently remapping data; the registered SQL migration then enforces the same reference as a fresh
installation.

**A blocked time is rejected:** verify the start precedes the end and neither bound falls in a
skipped or repeated local hour during a clock change.

## Multi-Language Support

Like every extension in this repository, the component ships **English (`en-GB`)**, **German
(`de-DE`, Swiss High German)** and **French (`fr-FR`)**, for both the administrator and site part.
Visible text uses language keys; generic labels use Joomla core keys.

To add another language, translate these files and declare each in the corresponding manifest
`<languages>` block:

```text
administrator/language/{language-tag}/com_appointments.ini
administrator/language/{language-tag}/com_appointments.sys.ini
language/{language-tag}/com_appointments.ini
```

Keep keys and placeholders aligned with en-GB and run the shared language lint. See the repository's
[language guide](../README.md#languages) for the complete checklist.

## Support & Contact

Report defects or feature requests through this repository's
[issue tracker](https://github.com/Advans-IT-Solutions-GmbH/Joomla/issues).

## License

Copyright (C) 2026 Advans IT Solutions GmbH

GNU General Public License version 3 or later; see [LICENSE.txt](LICENSE.txt).

SPDX-License-Identifier: `GPL-3.0-or-later`
