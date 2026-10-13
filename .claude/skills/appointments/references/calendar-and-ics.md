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
3. Subtract every blocked period, and every active booking **extended by the buffers**: a booking
   from 10:00 to 10:20 with a 10-minute buffer on each side removes 09:50 to 10:30.
4. Walk each remaining window on the slot-step grid, anchored to the window start. A start time is
   bookable when `start + duration <= window end`.
5. Drop every start earlier than `now + minimum notice`.
6. Drop every start outside the horizon. The horizon is counted in calendar days in the schedule's
   time zone: with `horizon_days = 30`, the last bookable day is the 30th day after today, through
   its end of day.
7. Drop days that have reached `max_per_day`, and weeks that have reached `max_per_week`. The week
   starts on Monday, in the schedule's time zone.

The engine is pure: same inputs, same output, no clock, no database, no Joomla. That is what makes
the DST and horizon tests possible.

### Daylight saving time

The two cases that must have tests, with `Europe/Zurich`:

- **Spring forward**, the last Sunday in March. 02:00 to 03:00 local does not exist. A window that
  lies inside the skipped hour yields no slot, and a window spanning it is one hour shorter in real
  time. The engine must not produce a slot at a non-existent local time.
- **Autumn back**, the last Sunday in October. 02:00 to 03:00 local occurs twice. The window is
  resolved once, using the first occurrence, so no duplicate slot is offered and no booking can be
  made at an ambiguous time.

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

Two feeds exist: one per event type, and one per host covering all their event types.

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
- A reschedule keeps the `UID` and raises the `SEQUENCE`.
- A cancellation carries `METHOD:CANCEL` and `STATUS:CANCELLED` with the same `UID`.
- The feed returns `304` for an unchanged `If-None-Match`.
- A wrong feed token returns `404` and no body that distinguishes it from an unknown URL.
