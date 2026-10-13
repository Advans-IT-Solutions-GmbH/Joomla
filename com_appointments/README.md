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
- **Event types and custom fields.** Configure the duration, slot grid, buffers, notice, horizon,
  limits, location, host, schedule, publication, access, content language, ordering and retention
  through the administrator. Custom fields support every form type listed below. Each event type
  has its own Joomla permission asset and independently generated feed credential.
- **Menu item types.** Publish the list of event types or one event type through Joomla's menu
  manager. Direct links enforce publication, access and content language too.
- **Public booking.** A server-rendered month grid, available days, slot choices and an explicit
  time-zone selector work with or without JavaScript. The form collects contact details, optional
  guests and configured custom fields. Consent, a honeypot, a session-bound signed render time
  and a short-lived keyed rate limit protect submission without an external CAPTCHA.
- **Booking integrity and approval.** Pending and confirmed appointments both hold their slot.
  Submission serialises on the host row, rechecks the exact slot against current availability,
  and retains the database's unique active-slot constraint. The administrator can approve a
  pending appointment or cancel an appointment.

- **Email and calendar invitations.** Confirmations, pending notices, approval and cancellation
  notifications are sent to the invitee, each additional guest and the host through Joomla's
  configured mail transport. Every message carries both the `text/calendar` and `application/ics`
  attachments. UTC calendar entries retain their UID and increase their sequence on changes.
  Notification failure never discards a saved booking: the page explicitly reports the mail problem.

- **Signed appointment management and erasure.** The email links open a no-login confirmation
  page. Rescheduling updates the existing booking and UID under the host lock; it cannot take an
  overlapping slot from another event type. Cancellation releases the slot. Erasure clears all
  contact data, guests, answers, free text and conference metadata in one transaction, optionally
  deleting the row entirely. A future appointment triggers only a host cancellation with no former
  invitee data. The administrator can erase selected bookings with event-specific permission.

Still to come: calendar subscription feeds, reminders, automatic retention, conferencing
integration and the Joomla privacy-request plugin.

## Configuration

Open **Components → Appointments → Options → Permissions** to configure the component's Joomla
ACL. Event types, custom fields, schedules and blocked periods are configured from the dashboard.

### Booking information

In **Options**, configure the booking data information text and the URL of your site's privacy
notice. The information text explains what you collect and why; it is not a legal privacy notice.
The consent checkbox is mandatory. The timing check requires at least two seconds after rendering
and expires after one hour; reload an expired form.
Additional guests are optional, deduplicated and limited to ten valid email addresses.
Telephone fields use an international calling-code selector and a national number.

The displayed zone never changes the stored UTC appointment. Public times always come from the
schedule, including its clock-change conventions. If someone else takes a time while the form is
open, submission returns to the same day with a friendly message and refreshed slot choices.
Confirmation pages require the submitting browser's session and are not public booking lookups.
Public requests use the directly connected address for rate limiting, not untrusted forwarding
headers. Only its keyed hash is stored; no raw address is added to a booking.

### Email templates

The installer creates eight editable site-wide templates in each shipped language: confirmation,
pending, approved, reschedule, cancellation, reminder, host notice and erasure notice.
An event-specific template takes precedence over the published site-wide template for that purpose
and language. If neither is available, the corresponding English template is used. Updating the
component preserves existing templates rather than overwriting operator changes.

Templates are plain text. Common placeholders include `{event_title}`, `{invitee_name}`,
`{invitee_email}`, `{invitee_phone}`, `{guest_emails}`, `{host_name}`, `{host_email}`, `{location}`,
`{conference_url}` and `{custom_fields.ID}`. `{start}`, `{end}` and `{timezone}` show the appointment
in the recipient's zone, with the invitee's stored locale or the host's Joomla profile settings;
`{start_utc}` and `{end_utc}` retain the original UTC values. `{state_label}` is localized.
Use only single-line values in subjects.

The invitee and guest messages always append the action-specific reschedule, cancellation and
erasure links, even if a customized template omits their placeholders. Feed credentials are never included in mail.

### Managing an appointment and deleting data

Every invitee message contains separate, action-specific signed reschedule, cancellation and
erasure links. A link is invalidated when the booking changes. The confirmation requires the
browser's normal Joomla CSRF token and one explicit confirmation, never a login or another email
round trip. A completed action has a generic same-browser receipt; repeating it does not repeat
notifications or audit entries.

Rescheduling keeps the booking ID and calendar UID, advances `SEQUENCE`, checks the new slot under
the same host lock as creation, excludes the current booking from its availability/cap checks and
resets reminder delivery markers. Pending bookings remain pending. A cancellation may include an
optional reason of at most 2,000 characters.

**Options → On erasure** selects an anonymised statistical slot (the default) or complete deletion
of the booking row. Both remove the name, email, phone, invitee zone/language, guest addresses,
custom answers, cancellation text and conference metadata. A future appointment is cancelled
first. Its host-only cancellation contains just the slot and calendar identifiers, not the former
invitee details or the erasure reason. Conference teardown is being wired in the conferencing
stage.

The deletion audit stores only booking ID, action, path, timestamp and, for administrator actions,
the acting operator's Joomla user ID. It contains no invitee data. Automatic audit retention and
formal privacy requests are still being implemented.

### Event types

| Setting | Purpose |
|---------|---------|
| Title, alias, description | Public title, unique URL alias and rich-text description |
| Schedule, host | Existing availability schedule and Joomla user hosting the appointment |
| Duration, slot step | Meeting length and independent grid spacing, in minutes |
| Buffers before/after | Minimum gaps around the meeting, in minutes |
| Minimum notice | Earliest bookable start relative to now, in minutes |
| Horizon days | Rolling Gregorian calendar days in the schedule's zone; zero selects a fixed range |
| Fixed start/end | Explicit UTC timestamps, `YYYY-MM-DD HH:MM:SS`; start inclusive, end exclusive |
| Daily/weekly limits | Per-event-type caps, with weeks starting Monday; zero means unlimited |
| Location and instructions | Host-calls telephone, invitee-calls telephone, in-person, custom text or conference |
| Conference provider | Jitsi, generic room URL or fixed room; public integration is in progress |
| Require approval | Holds a pending request's slot until approval or cancellation |
| Retention days | Per-event override; empty inherits the component setting, zero expires after the meeting ends |
| Colour | Six-digit hexadecimal colour |
| Published, access, language | Visibility controls for the public list and direct event links |
| Ordering | Order in the public list |
| Permissions | Joomla per-event-type edit, state and deletion rules |

An event type with bookings cannot be deleted; unpublish it instead. Feed credentials cannot be
edited through the ordinary event form.

### Custom booking fields

Each field belongs to an event type and has a label, description, required flag, publication state
and ordering. Types are single-line text, multiline text, number, telephone with country selector,
email, URL, single choice, multiple choice, checkbox and date. Enter choices one per non-empty line
for the two choice types. Editing or deleting a field inherits its event type's permissions.

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
Their own intervals define that date's slot grid; an overlapping rule from the previous night
cannot add starts on another grid. A meeting starting before midnight may still extend into the
override's open time.
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

4. Create an event type, assign the schedule and host, and configure its meeting rules.
5. Add its custom booking fields and create a Joomla menu item for the list or the individual type.

6. Open the public menu item, choose a displayed zone, day and time, complete the form and consent.
7. View bookings from the dashboard. Pending requests can be approved; cancellation releases the slot.

Notification, calendar and self-service lifecycle integration is still in progress.

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
