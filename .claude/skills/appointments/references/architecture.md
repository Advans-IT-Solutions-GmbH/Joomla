# Architecture

## Self-hosted by design

The component must work on a Joomla server whose outbound internet access is blocked. That rules
out, for the booking core: hosted scheduling backends, cloud calendar APIs, remote fonts, CDN-hosted
scripts, map tiles, avatar services, error reporting services and analytics. Every asset ships in
the package.

Two deliberate exceptions exist, both opt-in and both inert until an operator configures them:

- A conferencing adapter that has to call a provider (see `references/conferencing.md`).
- Joomla's own update server lookup, which is Joomla's behaviour and not the component's.

If a feature cannot be built without a third-party call, it does not go into version 1. Record it in
`references/scope.md` under "Explicitly out of scope" instead of building it.

## Platform

- **Joomla 6 only.** `script.php` declares `$minimumJoomla = '6.0'`, the manifest and
  `updates/update.xml` declare `targetplatform` `6\.[0-9]`.
- **PHP 8.2 minimum**, matching Joomla 6.
- The database layer uses `$db->createQuery()` (Joomla 6). The Joomla 5 fallback described in
  `.claude/skills/joomla-extensions/references/conventions.md` is not needed here and must not be
  added — it would only be dead code.

### Consequence for the shared requirements check

`shared/tests/requirements-check.php` currently enforces one platform rule for every extension:
`MIN_JOOMLA = '5.4'`, `MIN_PHP = '8.1'` and `targetplatform (5\.[4-9]|6\.[0-9])`. A Joomla 6 only
component fails that check.

The check must therefore gain a **per-extension override** before `com_appointments` can pass CI.
Required properties of that change:

- Default behaviour for every existing extension is unchanged: same constants, same failures.
- The override is declared by the extension, not hardcoded in the checker as a list of names.
  Put it in the extension's `build.env` (for example `MIN_JOOMLA="6.0"`, `MIN_PHP="8.2"`,
  `TARGET_REGEX="(6\.[0-9])"`), which the checker already has to locate anyway.
- The sample-version evaluation at the end of the checker runs against the extension's own regex:
  for `com_appointments`, `6.0.0` and `6.1.3` must be accepted and `5.4.0`, `5.4.8` and `4.4.0`
  rejected.
- The release workflow's `targetplatform` is validated against the same source.
- `shared-path-coverage.yml`: `shared/tests/requirements-check.php` is already watched by other
  workflows, but the new component's workflow must list it too, because the component uses it.

This change touches shared infrastructure used by every extension. It goes in its own commit, with
its own test, and the existing extensions' checks are run to prove nothing moved.

## Component layout

Mirror `com_dmarcreports`, with the addition of a site part.

```
com_appointments/
├── README.md                      # user-facing documentation, English
├── LICENSE.txt                    # unmodified GPL-3.0 text, identical to the repository root
├── VERSION                        # release workflow owns this
├── build.env                      # EXTENSION_NAME, VERSION, EXTENSION_TYPE, platform overrides
├── build.sh                       # thin wrapper delegating to shared/build/build.sh
├── script.php                     # install / update / uninstall
├── com_appointments.xml           # manifest
├── composer.json
├── phpunit.xml
├── administrator/
│   ├── components/com_appointments/
│   │   ├── access.xml
│   │   ├── LICENSE.txt
│   │   ├── forms/                 # eventtype.xml, schedule.xml, booking.xml, blockedperiod.xml, field.xml
│   │   ├── services/provider.php
│   │   ├── sql/                   # install / uninstall / updates per version
│   │   ├── src/
│   │   │   ├── Controller/
│   │   │   ├── Extension/AppointmentsComponent.php
│   │   │   ├── Field/             # custom form fields (weekly schedule editor, timezone)
│   │   │   ├── Helper/            # the domain logic, see below
│   │   │   ├── Model/
│   │   │   ├── Table/
│   │   │   └── View/
│   │   └── tmpl/
│   └── language/{de-DE,en-GB,fr-FR}/
├── components/com_appointments/   # site part
│   ├── LICENSE.txt
│   ├── services/provider.php
│   ├── src/
│   │   ├── Controller/            # BookingController, FeedController, IcsController
│   │   ├── Helper/                # RouteHelper
│   │   ├── Model/                 # EventtypeModel, EventtypesModel, BookingModel, SlotsModel
│   │   └── View/
│   └── tmpl/
├── language/{de-DE,en-GB,fr-FR}/  # site language files
├── media/com_appointments/        # css, js, joomla.asset.json — all local
├── updates/update.xml             # release workflow owns this
└── tests/
```

Namespace: `Advans\Component\Appointments` with the `Administrator` and `Site` sub-namespaces, as
Joomla expects.

## Domain logic lives in helpers, not in models

Models talk to the database and to Joomla. The rules live in plain, injectable classes under
`administrator/.../src/Helper/`, with no Joomla dependency beyond value objects, so they can be unit
tested without a Joomla bootstrap. This is the single most important structural decision: the slot
engine is where the bugs will be, and it has to be testable in isolation.

| Class | Responsibility |
|---|---|
| `SlotEngine` | Given a schedule, overrides, blocked periods, existing bookings, event-type rules and a date range, return the bookable start times. Pure function of its inputs. No database access. |
| `AvailabilityWindow` | A half-open interval `[start, end)` in UTC, with the operations the engine needs: overlap, subtract, merge. |
| `ScheduleResolver` | Turn a weekly schedule plus date-specific overrides into concrete windows for a date range, in the schedule's time zone, applying DST correctly. |
| `BookingGuard` | Re-validate a slot at submission time inside a transaction: still in the future, still within the horizon, still free, still within the daily and weekly caps. |
| `IcsWriter` | Build RFC 5545 output for a booking and for a feed. See `references/calendar-and-ics.md`. |
| `FeedToken` | Create, verify and rotate the per-feed token; constant-time comparison. |
| `SignedLink` | Create and verify the reschedule and cancel links. |
| `MailComposer` | Render a template with booking placeholders, in the recipient's language, and attach the ICS. |
| `ConferenceManager` | Resolve the configured provider and ask it for a link. See `references/conferencing.md`. |

## Time handling

This is the second place bugs will come from. The rules are absolute:

- **Everything is stored in UTC**, in `DATETIME` columns, with no exception.
- Every conversion goes through `DateTimeImmutable` with an explicit `DateTimeZone`. Never
  `date()`, never `strtotime()`, never the server's default zone.
- The schedule's time zone is the authority for what "Tuesday 14:00" means. The visitor's displayed
  zone is presentation only and never changes what is stored.
- DST: a weekly window is resolved per concrete date in the schedule's zone, so the day a country
  changes clocks produces the right windows automatically. A window that falls into a skipped hour
  produces no slot; a window in a repeated hour produces the slots once, using the first occurrence.
  Both cases need a unit test with a fixed, named zone (`Europe/Zurich`).
- The booking horizon counts calendar days in the schedule's zone, not 24-hour periods.

## Concurrency

Two visitors can submit the same slot at the same moment. The guarantee is at the database level,
not in PHP:

- A unique index on `(eventtype_id, start_utc)` for confirmed and pending bookings, so the second
  insert fails.
- The insert happens inside a transaction that first re-checks availability through `BookingGuard`.
- A failed unique insert is caught and presented as "this time was just taken", with the slot list
  reloaded. It is never presented as a server error.

## Security

- Every state-changing request validates `Session::checkToken()`. The public booking form included.
- Reschedule and cancel links carry an HMAC over booking id, action and the booking's current
  `modified` timestamp, keyed by a secret generated at install. Changing the booking invalidates
  older links automatically.
- Feed URLs carry a 32-byte random token, compared in constant time, rotatable per feed from the
  administrator interface. A rotated token invalidates the old URL immediately.
- All output escaped. All queries through `quoteName()` and `quote()` or bound parameters.
- No secret is ever written to a log, a URL in an e-mail body, or an error message.
- Rate limiting on the public booking endpoint and on the feed endpoint, counted per IP in a small
  table with a short retention, so a blocked feed URL cannot be brute forced.

## What the first deployment adds on top

Nothing in the component. Site-specific text, branding, the conference server address and the
schedule are all settings. If something cannot be expressed as a setting, that is a defect in the
component, not a reason to hardcode it.
