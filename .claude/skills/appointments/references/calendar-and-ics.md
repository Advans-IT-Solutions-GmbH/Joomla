# Slots, iCalendar and the subscription feed

## Slot calculation

`SlotEngine` takes, for one event type and one date range:

- the resolved availability windows (from `ScheduleResolver`),
- the existing active bookings of the host,
- the blocked periods of the host,
- the event type's duration, slot step, buffers, minimum notice, horizon and caps,
- "now" as an injected `DateTimeImmutable`, never `new DateTimeImmutable()` inside the engine.

It returns the start times, in UTC, that are bookable. The algorithm:

1. Resolve the weekly schedule for each date in the range, in the schedule's time zone, applying
   date-specific overrides. A date with an `is_unavailable` row yields nothing.
2. Convert each window to UTC. A window crossing midnight stays one window.
3. Walk each window on the slot-step grid, **anchored to the window start**.
4. A start time is bookable when all three hold:
   - the **meeting** `[start, start + duration)` lies inside a free piece of the window, where the
     free pieces are what is left after every occupied period is removed;
   - the meeting **with its own buffers** lies inside the window, which is what moves the first and
     last start of a window inward when buffers are configured;
   - the meeting with its own buffers **does not overlap another meeting**.
5. Drop every start earlier than `now + minimum notice`.
6. Drop every start outside the horizon. The horizon is counted in calendar days in the schedule's
   time zone: with `horizon_days = 30`, the last bookable day is the 30th day after today, through
   its end of day.
7. Drop days that have reached `max_per_day`, and weeks that have reached `max_per_week`. The week
   starts on Monday, in the schedule's time zone.

The engine is pure: same inputs, same output, no clock, no database, no Joomla. That is what makes
the DST and horizon tests possible.

### Why step 4 takes three conditions and not one

Each obstacle is handed to the engine as a `BusyPeriod` carrying **two** windows: the meeting
itself, and the period it occupies with the buffers of **its own** event type. Both are needed:

- Checking only the meeting against the occupied periods would let an event type with a long buffer
  place that buffer on top of somebody else's appointment.
- Checking the occupied periods against each other would add the two buffers up, so two
  neighbouring meetings with ten minutes of buffer each would need twenty minutes between them. A
  buffer is a minimum gap, not a second reservation.

An absence, and time the schedule itself does not offer, is a `BusyPeriod` whose two windows are the
same: neither a meeting nor a buffer may lie inside it.

The engine never applies the searched event type's buffers to somebody else's booking, because one
host can serve several event types with different buffers.

### Two rules about the grid that are easy to break

- **The grid is anchored to the start of the schedule window, never to a cut-down window.** A window
  clipped at `now + minimum notice`, at the horizon edge, at a clock change or next to a
  date-specific entry would move the grid and offer times the schedule never contained, such as
  10:31 for a schedule of 10:00 and 10:30. `ScheduleResolver` therefore returns whole windows and
  reports what has to come out of them separately, and the engine removes those like any other
  occupied period.
- **Windows of two different local dates are never merged.** Merging them makes the anchor depend on
  which date the resolution started from, so a month-long availability query and a one-day re-check
  can offer different times for the same day — and the second one is the booking guard. The price is
  that a meeting cannot span the boundary between two dates' windows; an operator who needs that
  writes one rule crossing midnight, which stays one window.

### Daylight saving time

A schedule's time zone is an **IANA name** such as `Europe/Zurich`, and nothing else. `CET`,
`CEST`, `EST` and a bare `+01:00` are all accepted by `new DateTimeZone()` and are all traps: PHP
gives them a fixed offset and no transitions, so a schedule on `CET` keeps winter time through the
summer and offers every slot an hour out for half the year. Both the table that stores a schedule
and the resolver that reads one check the value against `DateTimeZone::listIdentifiers()`, which
excludes those names. The resolver checks it again rather than trusting the table, because its
constructor is public and also sees imported and hand-edited rows.

A date or a timestamp is also refused outside the years 1000 to 9999, which is the range the
`DATE` and `DATETIME` columns document as supported. `UtcTimestamp::isDate()` and
`UtcTimestamp::parse()` are the one definition of a usable date in the component, and every layer
that validates one uses them, so a value that reaches a column has already been through the same
check.

The two daylight-saving cases that must have tests, with `Europe/Zurich`:

- **Spring forward**, the last Sunday in March. 02:00 to 03:00 local does not exist. A window that
  lies inside the skipped hour yields no slot, and a window spanning it is one hour shorter in real
  time. The engine must not produce a slot at a non-existent local time.
- **Autumn back**, the last Sunday in October. 02:00 to 03:00 local occurs twice. The window is
  resolved once, using the first occurrence, so no duplicate slot is offered and no booking can be
  made at an ambiguous time.

Those two rules are for a **recurring availability rule**, which has to resolve to something: a
weekly 02:00 to 10:00 rule cannot simply disappear twice a year, so the resolver states a
convention and holds to it.

A **wall clock an operator typed once** is the opposite case, and is handled the opposite way. A
blocked period, and later any single timestamp entered by hand, is refused when it does not stand
for exactly one instant in the reader's zone, with a message that says which of the two nights it
is. There is no convention worth guessing there: the operator is present and can be asked, and a
guess would record an absence an hour away from the one they meant.

The question is asked by `UtcTimestamp::localInstants()`, which returns every instant a wall clock
stands for in a zone: none for the skipped hour, one ordinarily, two for the repeated hour.
`new DateTime($value, $zone)` must not be used for this, and neither must a strict reading of the
value as UTC: the first silently shifts the skipped hour and picks one of the repeated ones, and the
second cannot see a zone at all, so it accepts both. The check belongs in the **controller**,
before Joomla's `user_utc` filter runs, because the filter is the thing that converts and after it
the operator's own input no longer exists. The controller checks the request token first, so a
refusal cannot become a way to write a forged submission into the operator's session state.

## iCalendar output

RFC 5545. Two shapes are produced, and they are not the same.

### The attachment in an e-mail

One `VEVENT`, inside a `VCALENDAR` with the matching `METHOD`:

| State change | `METHOD` | `STATUS` | `SEQUENCE` |
|---|---|---|---|
| new confirmed booking | `REQUEST` | `CONFIRMED` | 0 |
| pending approval | `REQUEST` | `TENTATIVE` | 0 |
| approved | `REQUEST` | `CONFIRMED` | +1 |
| rescheduled | `REQUEST` | `CONFIRMED` | +1 |
| cancelled | `CANCEL` | `CANCELLED` | +1 |

The `UID` is the booking's `uid` and **never changes**, including across a reschedule. That is what
makes a mail client replace the existing entry instead of creating a second one. `SEQUENCE`
increments on every change; a client that receives a lower sequence than it holds ignores the
update, so an off-by-one here silently breaks reschedules.

This is why a reschedule updates the booking row instead of inserting a successor: see
`references/data-model.md`, section "Rescheduling updates the row". A second row would either reuse
the `uid`, which the unique index refuses, or introduce a new one, which is the duplicate entry this
rule exists to prevent — and the feed below would then serve two `VEVENT`s with one `UID`, which is
not valid iCalendar.

Required properties: `DTSTAMP`, `DTSTART`, `DTEND`, `SUMMARY`, `DESCRIPTION`, `ORGANIZER`,
`ATTENDEE` for the invitee and each guest, `LOCATION`, `SEQUENCE`, `STATUS`, `UID`. `DTSTART` and
`DTEND` are written in UTC with the `Z` suffix, which removes any need to ship a `VTIMEZONE`.

The attachment is sent as `text/calendar; charset=UTF-8; method=REQUEST` with the file name
`invite.ics`, **and** as a second attachment part of type `application/ics` where the mail client
needs it. Joomla's `Mail` class takes both. Attach it to **every** e-mail to the invitee and to the
host, without exception — that is a stated constraint of the project.

`DESCRIPTION` carries the conference link when there is one, and so does `LOCATION` when the
location type is `conference`. A plain-text `DESCRIPTION` with the link on its own line is what mail
clients turn into a clickable entry; do not bury it in a sentence.

### The subscription feed

One `VCALENDAR` with `METHOD:PUBLISH` and one `VEVENT` per active booking, served at a stable URL.

- `X-WR-CALNAME` — the event type's title, so the calendar has a readable name after subscribing.
- `X-PUBLISHED-TTL:PT15M` and `REFRESH-INTERVAL;VALUE=DURATION:PT15M` — both, because clients
  honour one or the other. Without them a desktop client may refresh only once a day.
- Cancelled bookings are included with `STATUS:CANCELLED` for 30 days and then dropped, so a client
  that already holds the entry learns it is gone.
- The window served is the configured horizon plus 90 days of history. An unbounded feed will
  eventually time out.
- `Content-Type: text/calendar; charset=UTF-8`, `Content-Disposition: inline; filename="…ics"`.
- Respond to `If-None-Match` with `304` when nothing changed. The ETag is a hash over the booking
  ids and their `modified_utc`.

Two feeds exist: one per event type, and one per host covering all their event types. They have
separate tokens, in `#__appointments_eventtypes.feed_token` and `#__appointments_hosts.feed_token`,
because every token has to be rotatable on its own: a host feed that borrowed or derived the token
of one of its event types would either be revoked by that event type's rotation or survive it.

### Feed security

The feed URL is a bearer secret: anyone holding it sees the bookings. Treat it as a credential.

- 32 random bytes, hex encoded, in the path or query. Compared with `hash_equals()`.
- Rotatable from the administrator interface, per feed. Rotation invalidates the old URL at once and
  the interface says so before rotating.
- `X-Robots-Tag: noindex, nofollow` and `Cache-Control: private, no-store` on the response.
- Rate limited per IP. A wrong token returns `404`, not `403`, and takes the same time as a correct
  one, so the endpoint cannot be probed.
- The URL is never written into an e-mail, never logged, and never shown in a view that a
  lower-privileged user can reach.
- The administrator interface states plainly that the link is a password and must not be shared.

### What a desktop client can and cannot do with the feed

Say this in the README, because it is the single most likely support question:

- A subscribed internet calendar is **read-only**. Deleting an entry there does not cancel a
  booking.
- Refresh is on the client's schedule. The `TTL` of 15 minutes is a request, not a guarantee; a
  hosted mailbox may still refresh more slowly.
- The attached `.ics` in the confirmation e-mail is the immediate path: the entry is in the calendar
  the moment it is accepted. The feed is the standing overview. Both are shipped on purpose.

## Testing the calendar output

- A generated `VEVENT` validates against RFC 5545 line folding at 75 octets, with correct
  continuation, including for a UTF-8 character that straddles the fold.
- Escaping: `;`, `,`, `\` and newlines in `SUMMARY` and `DESCRIPTION`.
- A reschedule keeps the `UID`, raises the `SEQUENCE` and leaves exactly one booking row, so the
  feed cannot serve two `VEVENT`s with the same `UID`.
- A cancellation carries `METHOD:CANCEL` and `STATUS:CANCELLED` with the same `UID`.
- The feed returns `304` for an unchanged `If-None-Match`.
- A wrong feed token returns `404` and no body that distinguishes it from an unknown URL.
