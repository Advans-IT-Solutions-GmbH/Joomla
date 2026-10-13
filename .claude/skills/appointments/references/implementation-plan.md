# Implementation plan

Eleven stages. Each stage ends with a commit that builds, installs and passes its own tests. The
component is never left in a state where it cannot be installed.

Commit scope is `appointments` throughout, except stage 0 which touches shared infrastructure and
uses the scope of the file it changes.

## Stage 0 — platform override in the shared requirements check

Before anything else, because nothing can pass CI without it.

- Extend `shared/tests/requirements-check.php` with the per-extension override described in
  `references/architecture.md`, section "Consequence for the shared requirements check".
- Prove the existing extensions are unaffected: run the check for every extension directory before
  and after, and compare the output.
- Add `shared/tests/requirements-check.php` to the `pull_request.paths` of the new component
  workflow, so `shared-path-coverage.yml` stays satisfied.

**Done when:** the check passes for all existing extensions unchanged, and accepts a Joomla 6 only
declaration for a directory that declares one.

## Stage 1 — component skeleton

- Directory layout, manifest, `script.php`, `build.env`, `build.sh`, `composer.json`,
  `phpunit.xml`, `LICENSE.txt` in all three required places, `index.html` files, three language
  packs with the keys that exist so far.
- Administrator and site `services/provider.php`, the component `Extension` class, a dashboard view
  that renders an empty state.
- `sql/install.mysql.utf8.sql` with the full schema from `references/data-model.md`, and the
  matching uninstall file.
- The install secret generated in `script.php`.

**Done when:** the package builds, installs on Joomla 6, shows an empty dashboard, uninstalls
cleanly and leaves no table behind. `php shared/tests/requirements-check.php com_appointments`
passes.

## Stage 2 — schedules and the slot engine

- `AvailabilityWindow`, `ScheduleResolver`, `SlotEngine` as pure classes with no Joomla dependency.
- Schedule and schedule-rule management in the administrator, including a weekly editor field.
- Blocked periods management.

**Done when:** the unit suite covers the engine, including both DST cases, the horizon boundary, the
minimum-notice boundary, buffers that overlap a window edge, the daily and weekly caps, and a
window crossing midnight. No database is touched by any engine test.

## Stage 3 — event types

- Event type CRUD with every setting from `references/data-model.md`, Joomla ACL, access level,
  language, ordering, a generated `feed_token`.
- Custom fields CRUD per event type.
- Front-end menu item types for the list and for a single event type.

**Done when:** the reference configuration in `references/scope.md` can be entered entirely through
the administrator interface.

## Stage 4 — public booking page

- Month grid with available days, day selection, slot list, time-zone switcher.
- Booking form with fixed and custom fields, honeypot, timing check, consent checkbox.
- Confirmation page.
- Core path works with JavaScript disabled. All assets local, declared in `joomla.asset.json`.
- Mobile layout first.

**Done when:** a booking can be made end to end on a clean install and appears in the administrator
list, with and without JavaScript.

## Stage 5 — booking integrity

- `BookingGuard`, the transaction, the unique index behaviour, the friendly "just taken" response.
- Approval workflow for event types that require it.
- Rate limiting on the public endpoints.

**Done when:** a concurrency test fires two simultaneous submissions for one slot and exactly one
succeeds, with the other receiving the friendly message and a reloaded slot list.

## Stage 6 — iCalendar and e-mail

- `IcsWriter` for the single-event attachment, with `UID`, `SEQUENCE`, `METHOD` and `STATUS` per
  `references/calendar-and-ics.md`.
- `MailComposer`, the template table, templates for every purpose in all three languages.
- E-mail on new booking, pending, approval, reschedule, cancellation — to the invitee, the guests
  and the host. **Every one with the ICS attached.**

**Done when:** a test asserts that no code path sends an invitee or host e-mail without a
`text/calendar` part, and the RFC 5545 escaping and folding tests pass.

## Stage 7 — reschedule, cancel and the deletion path

- `SignedLink` with the action in the signature.
- Reschedule page, cancel page, erase page, each reachable only from a valid link.
- The anonymisation routine with the exact field set from `references/privacy-and-deletion.md`.
- The audit table.

**Done when:** all deletion tests in `references/privacy-and-deletion.md` pass, including that a
`cancel` link is rejected for `erase` and that a used link stops working.

## Stage 8 — feeds and the scheduler task

- Per-event-type and per-host feeds, token verification, rotation, ETag, `304`, TTL headers,
  `noindex`, rate limiting.
- Joomla Scheduler task plugin for reminders and for retention, registered at install.
- Single-booking `.ics` export in the administrator.

**Done when:** a desktop mail client subscribes to the feed and shows the bookings, a rotated token
invalidates the old URL immediately, and the retention task is proved idempotent.

## Stage 9 — conferencing

- `ConferenceManager`, the provider interface, the three providers from
  `references/conferencing.md`, their settings forms, the failure behaviour and the retry action.

**Done when:** a booking of a `conference` event type carries a working link in the e-mail, the
calendar entry and the confirmation page, and a forced provider failure leaves the booking intact
with the flag set.

## Stage 10 — privacy sub-plugin, administration polish, documentation

- `plg_privacy_appointments`, bundled, with its own `LICENSE.txt` in its own `<files>` list.
- Dashboard counts, booking filters and search, CSV export, system check view.
- Component `README.md` in English: what it does, what it does not do, installation, every setting,
  the three deletion paths, the feed-is-a-password warning, and the read-only-subscription
  explanation from `references/calendar-and-ics.md`.
- Final language file pass: every key present in `de-DE`, `en-GB` and `fr-FR`, German in Swiss High
  German with no `ß`.

**Done when:** `shared/tests/lang-lint.php` passes, the README documents every shipped setting, and
the Copilot definition of done in `AGENTS.md` is met.

## Order of pull requests

One pull request per stage, or group stages 1 to 3 and 4 to 6 if the reviewer prefers fewer. Stage 0
is always its own pull request, because it touches shared infrastructure that every other extension
depends on.

Never merge your own pull request. Never edit `VERSION`, the manifest `<version>` or
`updates/update.xml` by hand.

## Keeping the instruction files free of drift

The repository fails a review on instruction drift. When the component lands, these files must list
it:

- `AGENTS.md` — the list of generic extensions in this repository.
- `CLAUDE.md` — the architecture tree and the skills list.
- `.claude/skills/joomla-extensions/references/repo-structure.md` — the extension table, the
  per-extension structure notes and the workflow table.
- `.claude/skills/joomla-extensions/references/testing.md` — the local test commands for the new
  component.
- `README.md` at the repository root, if it enumerates the extensions.

Do this in the same pull request that adds the thing being described, not afterwards.
