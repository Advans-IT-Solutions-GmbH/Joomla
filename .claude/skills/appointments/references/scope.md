# Scope

## How the scope was chosen

The component replaces a hosted scheduling service for a single-host operator. Two design
judgements shaped the scope more than anything else:

1. **The component owns its calendar.** A hosted service can connect to Google, Outlook or
   Exchange, but a scheduling component that computes availability from its own rules, its own
   bookings and its own blocked periods needs no such connection. Reading an external calendar is a
   later, opt-in extension, not a prerequisite, and leaving it out is what makes the component work
   on a server without outbound internet access.
2. **Only the features of a single-host plan are in version 1.** Team scheduling, round robin,
   routing forms, payment collection, meeting polls and approval workflows beyond a simple host
   approval belong to the paid tiers of hosted services and to later versions here.

## Acceptance scenario

Version 1 is accepted when the following scenario can be set up through the administrator interface
alone, with no file edit and no database change. It is a synthetic test case for the settings that
have to exist, not a configuration anyone has to use:

| Property | Value |
|---|---|
| Event types | 1 |
| Duration | 20 minutes |
| Slot step | 30 minutes |
| Location type | telephone — the host calls the invitee on the number given at booking |
| Weekly availability | three windows on three weekdays, two of them in the afternoon |
| Time zone | one IANA zone for the schedule, invitee may switch the displayed zone |
| Booking horizon | 30 calendar days ahead, rolling |
| Booking form | name*, e-mail*, additional guests, telephone* with a country selector, two short-answer fields*, one free-text field |
| Confirmation | e-mail to invitee and host, calendar entry attached |

`*` = required. Every one of these values is a setting, never a default compiled into the code.

## In scope for version 1

### Event types
- Unlimited event types, each with: title, URL alias, description (rich text), duration, colour,
  active/inactive, access level, language.
- Location type per event type: `phone_host_calls`, `phone_invitee_calls`, `in_person`,
  `custom_text`, `conference` (see `references/conferencing.md`).
- Slot step independent of duration (e.g. a 20-minute meeting offered on a 30-minute grid).
- Buffer before and buffer after a booking, in minutes.
- Minimum notice before the earliest bookable slot, in minutes.
- Booking horizon: rolling number of days, or a fixed date range.
- Maximum bookings per day and per week for the event type.
- Per-event-type override of the weekly availability schedule.

### Availability
- Named weekly schedules, assignable to one or more event types, with any number of intervals per
  weekday.
- Date-specific overrides: a different set of intervals, or fully unavailable, for a given date or
  date range (holidays, absences).
- One time zone per schedule. The public page converts to the visitor's zone and lets them change it.

### Booking form
- Fixed fields: name, e-mail, optional additional guest e-mail addresses.
- Configurable custom fields per event type: single line, multi line, number, phone (with country
  selector), e-mail, URL, single choice, multiple choice, checkbox, date. Each with label,
  description, required flag and ordering.
- Honeypot plus a timing check against bots. No external CAPTCHA.
- Consent checkbox with a configurable text and a link to the site's own privacy notice.

### Booking lifecycle
- Confirm immediately, or require the host's approval before the booking counts as confirmed
  (configurable per event type).
- **A slot is held from the moment it is requested, approval or not.** `pending` and `confirmed` are
  both slot-holding states, which is what `state_active` in `references/data-model.md` expresses.
  Leaving a slot free while it waits for approval would let a second visitor book it and then force
  the host to turn one of them away after the fact, and it would make the invitee's own confirmation
  mail a promise the component cannot keep. A pending booking is therefore released only by a
  refusal or a cancellation, both of which set `cancelled` and free the slot again.
- Reschedule by the invitee through a signed link, subject to the same availability rules.
- Cancel by the invitee through a signed link, with an optional reason.
- Cancel and reschedule by the host from the administrator interface.
- Automatic no-show and completed marking is **not** in version 1.

### Notifications
- E-mail to the invitee and to the host on: new booking, pending approval, approval, reschedule,
  cancellation.
- Reminder e-mails at configurable offsets before the start, delivered by a Joomla Scheduler task.
- Every one of these e-mails carries the iCalendar entry as an attachment. No exception.
- Templates per event type with a site-wide fallback, in all three shipped languages, with
  placeholders for every booking field.

### Calendars
- One iCalendar subscription feed per event type, plus one combined feed per host, reachable at a
  stable URL with an unguessable token. Details and the security model in
  `references/calendar-and-ics.md`.
- Export of a single booking as an `.ics` file from the administrator interface.

### Video conferencing
- A conference link generated per booking, written into the e-mail, the calendar entry and the
  confirmation page. Providers and the adapter interface in `references/conferencing.md`.

### Administration
- Dashboard: upcoming bookings, today's bookings, counts per event type.
- Booking list with filters by event type, state, date range and free-text search; detail view.
- Blocked periods, independent of event types.
- CSV export of bookings.
- Joomla ACL on the component and per event type, the latter through the event type's `asset_id`.
  **A booking has no asset of its own and inherits the permissions of its event type.** That is a
  deliberate narrowing, not an omission: a per-booking asset would add one `#__assets` row to every
  booking insert, grow that table without bound on a busy site, and leave an orphan row behind every
  erasure — while expressing nothing that a rule on the event type cannot. Who may read, edit or
  delete bookings is decided per event type and at component level.
- A system check view that reports: PHP version, Joomla version, time zone, mail configuration,
  whether the Scheduler task is registered and when it last ran, and whether the feed token is set.

### Front end
- A menu item type for the list of event types and one for a single event type.
- The booking page: month grid, available days marked, day selection, slot list, time-zone switcher,
  form, confirmation page.
- Works without JavaScript for the core path. Progressive enhancement only.
- Mobile first. No external assets: no remote fonts, no CDN, no tracking.
- Reschedule and cancel pages reachable from the signed link in the e-mail.

### Privacy and deletion
Full specification in `references/privacy-and-deletion.md`. In summary, three independent paths,
all three in version 1:
- A signed "delete my data" link in every e-mail to the invitee. One click, one confirmation page,
  no account.
- A bundled `plg_privacy_appointments` sub-plugin, so Joomla's own data subject request workflow
  answers export and removal requests for bookings.
- A retention task that anonymises or deletes bookings a configurable number of days after they
  ended.

No IP address is stored at all. The anti-bot timing check persists nothing, and the rate limiter
keeps only a keyed hash with a one-day retention.

## Explicitly out of scope for version 1

These are recorded so that they are not silently added. Each is a candidate for a later version.

| Not in version 1 | Why |
|---|---|
| Reading an external calendar (Microsoft Graph, Google, CalDAV, ICS subscription) for conflicts | The component owns the calendar, and every one of these needs an outbound connection the self-hosted constraint rules out. |
| Payment collection | Belongs to a shop component, not here. |
| Round robin, collective and group events | Single-host scheduling is the requirement. |
| Routing forms | A separate problem: form logic, not scheduling. |
| SMS reminders | Requires an external gateway, which contradicts the self-hosted constraint. |
| Meeting polls | A different interaction model with its own data and its own pages. |
| An embeddable widget for third-party sites | A Joomla menu item covers the requirement. |
| Analytics beyond the dashboard counts | Not required, and a privacy liability. |

## Definition of done for version 1

- Every item under "In scope" is implemented, covered by a test and documented in the component
  README.
- `php shared/tests/requirements-check.php com_appointments` passes.
- The CI workflow for the component is green on both lanes, Joomla 5.4 and Joomla 6.
- The acceptance scenario above can be reproduced through the administrator interface alone, with
  no file edit and no database change.
- The repository's Copilot review definition of done in `AGENTS.md` is met.
