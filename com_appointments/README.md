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

> **Status: unreleased.** The features below are implemented in the component source, but no
> Appointments release or downloadable installation package has been published yet. For evaluation,
> build a package from source as described below. Official packages and version changes will be
> published only through the repository's reviewed release workflow.

## Requirements

- [Joomla](https://github.com/joomla/joomla-cms) 5.4 or later (5.4.x, 6.x)
- PHP 8.1 or later
- MySQL or MariaDB only. The component ships its schema only for these servers; the installer
  refuses other database servers. The booking table uses a generated column to guarantee that one
  time slot can be held by exactly one booking. PostgreSQL is not supported by this component,
  even though Joomla itself supports it.
- Administrator access for configuration

## Joomla 6 Compatibility

The component resolves its database through `DatabaseInterface`. Queries use `createQuery()` when
the driver provides it and fall back to `getQuery(true)` on Joomla 5.4. No platform-specific
configuration is required.

## Installation

### Currently: build from source

Use a checkout of this repository and Bash (for example, WSL on Windows) with `rsync`, `zip` and
`unzip` available. From the repository root:

```bash
cd com_appointments
bash ./build.sh
```

The shared packager reads `build.env` and creates `com_appointments_<version>.zip` in that
directory. This is a locally built pre-release package, not a published release; do not change
version files manually. Docker is needed for the integration tests, not for this package build.

1. Use the ZIP produced by the build.
2. Install it in Joomla through **System → Install → Extensions**.
3. Open **Components → Appointments**.

### Once a release is published

Download `com_appointments_<version>.zip` from the repository's
[releases](https://github.com/Advans-IT-Solutions-GmbH/Joomla/releases) under the matching
`appointments-v<version>` tag, then follow the installation steps above. Only the newest release
in the Appointments tag family is retained; releases of other extensions are not Appointments
packages.

## Updating

The component registers its own update site during installation. Once releases are published,
later versions will appear under **System → Update → Extensions**. You can also install a newer
ZIP over the existing installation. The update-site registration does not mean that a release is
currently available.

## Features

### What is implemented so far

- The component skeleton with its administrator and site part, the full database schema, Joomla
  access control on the component, and the three shipped languages (`de-DE`, `en-GB`, `fr-FR`).
- An administrator dashboard with today's and upcoming active appointments, pending approvals,
  per-event counts and an empty state. Statistics include only the operator's authorised events.
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
  has its own Joomla permission asset and independently generated feed credential. An event type's
  host cannot change while any booking is retained; create a new event type for a different host.
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
  The mails follow the site's mail templates setting (com_mails): with «Mail Format» set to HTML or
  «HTML and plain text», the text is sent as HTML in the site's mail layout, including a template
  override such as `mytemplate:mailtemplate`, with links clickable and the text as alternative part.
  The reschedule, cancel, erase and meeting links show their label as link text in the HTML part, so a
  long signed URL cannot widen the mail. In the plain-text part the links the component adds itself stand
  as full URLs, each on a line of its own.
  A site with plain-text mails keeps plain-text appointment mails.

- **Signed appointment management and erasure.** Only the primary invitee receives the no-login
  reschedule, cancellation and erasure capabilities, including in reminders. Guests and the host
  receive calendar invitations without these bearer links. Rescheduling updates the existing
  booking and UID under the host lock; it cannot take an
  overlapping slot from another event type. Cancellation releases the slot. Erasure clears all
  contact data, guests, answers, free text and conference metadata in one transaction, optionally
  deleting the row entirely. A future appointment triggers only a host cancellation with no former
  invitee data. The administrator can erase selected bookings with event-specific permission.

- **Scheduled reminders and retention.** The bundled task plugin offers separate reminder and
  retention routines. Confirmed appointments receive calendar reminders at configurable offsets;
  completed offsets are recorded without changing the booking's signed-link revision. Booking,
  audit and keyed rate-limit retention are bounded and can be rerun safely.

- **Calendar subscriptions and export.** Each host and event type has an independently rotatable
  bearer credential. Subscription responses support conditional requests, private caching and
  bounded history; booking-list ICS exports respect the event type's edit permission.

- **Opt-in video conferencing.** Self-hosted Jitsi, a generic room URL and a fixed room work without
  remote API requests. Event-specific overrides, separate host/guest JWT links, persistent failure
  diagnostics and an administrator retry action preserve a saved booking even if provisioning fails.

- **Booking administration.** Filter by event, status and UTC date range, search invitee names/email,
  export a filtered CSV, inspect details and custom answers, and reschedule using the same locked
  lifecycle. System checks report platform, mail configuration, Scheduler and audit health.

- **Joomla privacy integration.** The bundled privacy plugin exports readable booking records and
  removes matching records through Joomla's formal data-subject workflow, including guest invitees.
  Email ownership is exact after Unicode case normalisation; accent-insensitive database collations
  cannot expose another person's booking. The minimal accountability audit is excluded from exports.

## Configuration

Open **Components → Appointments → Options → Permissions** to configure the component's Joomla
ACL. Event types, custom fields, schedules and blocked periods are configured from the dashboard.

### Links and web-server rules

The component has no SEF router. Its guest links are non-SEF on purpose:

- **Links in e-mails** (reschedule, cancel, erase) are built as
  `index.php?option=com_appointments&view=manage&id=…&action=…&signature=…` from the public site URL.
  They are signed, are also sent by the reminder task from the command line, where no site router is
  available, and must stay valid for as long as the booking exists.
- **Calendar subscriptions** use `index.php?option=com_appointments&task=feed.download&…`.
- **Booking pages** are reached through a Joomla menu item of type *Event types* or *Event type*.
  Redirects inside the booking flow use the menu item when one matches, otherwise Joomla's
  `/component/appointments/…` form.

A site that blocks direct component addresses in its `.htaccess` therefore needs two exceptions,
otherwise guests cannot open the links of their e-mails or subscribe to a feed. Each line goes
into the existing rule as one more negated condition next to its other exceptions:

```apache
# Rule that redirects or blocks ?option=com_<x> on the frontend (non-POST). Exempt only a query
# whose one and only option parameter is com_appointments: a second option parameter with another
# value or without a value (PHP uses the last one, an empty one lets Joomla fall back to the menu
# item), an embedded "option=com_appointments" in another value, or a parameter name
# with any character other than letters, digits, "_" and "-" keep the request under the rule.
# PHP rewrites such names: it decodes "%6F" and "+" (a space), strips leading spaces, turns
# "." and " " into "_" and reads "[" as an array, so "+option" or "opti%6Fn" would also select
# a component. The option must be the first parameter, as in every link the component builds:
# PHP reads at most max_input_vars parameters, so a later one could be dropped unread.
RewriteCond %{QUERY_STRING} !^(?=option=com_appointments(?:&|$))(?!.*(?:^|&)option(?:&|$|=(?!com_appointments(?:&|$))))(?!.*(?:^|&)[^=&]*[^A-Za-z0-9_=&-])

# Rule that redirects or blocks /component/<x>. A query on such an address stays under the
# ?option= rule above, so another component cannot be reached through this path.
RewriteCond %{REQUEST_URI} !^(/[a-z]{2})?/component/appointments(/|$)
```

A plain `RewriteCond %{QUERY_STRING} !option=com_appointments` is not enough: it is a substring
match, so `?option=com_users&x=option=com_appointments` would also be exempted.

No exception is needed for `tmpl=component` or `format=feed`: the component uses neither. The
feed is served by the task `feed.download`.

A router would not remove these exceptions. The signed mail links and feed URLs have to be
stable and buildable without a site router, and without a menu item a routed address is still
`/component/appointments/…`. A router can be added later for nicer addresses of the booking pages,
in which case the existing non-SEF links must keep working.

### Video conferencing
Select a provider in **Options → Video conferencing**, then set the event's location to **Video
conference**. An event can inherit the provider, disable it, choose another installed provider,
override the fixed-room URL or override the room prefix. Saved event overrides are independent of
the global settings.

| Setting | Behaviour |
|---|---|
| Provider | None by default; Jitsi, generic URL and fixed room are bundled. |
| Jitsi base URL | Absolute HTTPS URL of the operator's Jitsi server. |
| Room pattern | `{prefix}`, `{alias}`, and mandatory `{random}`; default `{prefix}-{alias}-{random}`. |
| Room prefix / entropy | Default `appointment` and 24 keyed random characters; at least 16. No invitee data. |
| Authentication | None or JWT. Configure the signing secret, application ID, audience, issuer and subject to match the Jitsi server. |
| Token timing | Guests enter from 15 minutes before the start. Moderator lead time defaults to 30 minutes; end grace defaults to 60 minutes. |
| Generic URL | An absolute HTTPS template containing `{room}`; no API request. |
| Fixed room | One permanent HTTPS link. Every booking reuses it, so close or overlapping meetings can collide. Use different event overrides or avoid overlaps. |
| Outbound adapters | Disabled by default. Only explicitly selected, installed third-party adapters can make requests after opt-in; none are bundled. |

The JWT secret is operator configuration, not invitee credentials. Restrict component options and
database backups; never log JWT-bearing join URLs. Room names use an HMAC of the booking identity,
not the public calendar UID. Only the host's message/calendar receives the moderator URL; guests
and subscription feeds receive guest links. Calendar attachments and a mandatory joining footer
are retained even with customised email templates.

The provider of an event follows the event's saved settings, including an explicit choice to
inherit the component option. Only an event type without any saved settings (for example one
written directly to the database) uses its stored provider before the component option. An event whose location is **Video conference** but for which no
provider resolves is not a silent success: the booking is saved and flagged with
`No conference provider is selected for this event type.`

Provisioning runs after the booking transaction commits. Every failure leaves the appointment saved,
marks the missing link and stores a short redacted diagnostic, also when the conference status
itself cannot be saved (then as a separate best-effort update). **Bookings → Retry conference link**
retries selected authorised bookings; success raises the calendar sequence and sends updates.
Rescheduling replaces time-bound tokens while preserving the booking UID. Cancellation and
erasure remove conference metadata and ask the provider to release the previous room.

Third-party plugins can register a provider with the component's DI-backed `ConferenceManager`,
available through `bootComponent('com_appointments')->getConferenceManager()`. Use `register()`;
provider names automatically appear in the selectors. Plugins can add their configuration using
Joomla's `onContentPrepareForm` event and their provider's `getConfigForm()` fragment. Outbound
providers must implement the bounded adapter contract: a five-second timeout and exactly one retry.
`MeetingRequest` contains only a keyed room seed, event title, duration and UTC start/end, never
invitee contact information.

### Formal privacy requests

Installation enables **Privacy - Appointments**; updates retain its enabled state and uninstall
removes it. Joomla's confirmed export request includes all matching primary-invitee booking
columns, decoded answers and their current field labels. Additional guests do not gain access to
the primary invitee's contact details by appearing in a booking.

Formal removal uses the same transaction, anonymisation/deletion mode and host-only future
cancellation notice as the signed erase link. A nonempty **Legal reason to block formal removal**
blocks matching Joomla removal requests and explains the reason to the administrator. This option
does not alter signed self-service erasure or automatic retention; operators must configure those
policies consistently with their legal obligations. Accent differences never establish ownership,
and the request's email remains authoritative even if another privacy plugin has already changed
the associated Joomla user object.

### Scheduler, reminders and retention

The component installs and enables **Task - Appointments**. Updates preserve its enabled/disabled
state; uninstalling the component removes the plugin and its Scheduler tasks. Create two tasks in
**System → Scheduled Tasks**: **Appointments: reminders**, running every five minutes, and
**Appointments: retention**, running daily. Configure Joomla's Scheduler execution mechanism or
invoke its CLI from the server's cron; a quiet website must not rely solely on visitor-triggered
execution for timely reminders.

In the component options, set **Public site URL** to the canonical HTTP(S) site root, including a
Joomla subdirectory if applicable. CLI reminders require this setting unless Joomla's `live_site`
is configured. It controls the signed links in reminder messages and avoids deriving a public link
from a CLI script path. Use HTTPS in production.

**Reminder offsets** is a comma-separated list of minutes before a confirmed appointment. The
default `1440,60` sends reminders one day and one hour beforehand; each value must be 1–525600.
An empty list disables reminders. Pending approval, cancelled, anonymised and already-started
appointments are not reminded. A delayed Scheduler run sends unsent due offsets while the
appointment is still in the future. Each successful offset receives a timestamp marker; rescheduling
resets the markers. Delivery is serialized with lifecycle changes. Mail failures mark the task
failed and leave that offset eligible for retry. SMTP cannot guarantee crash-proof exactly-once
delivery: a transport failure after some recipients accepted a message can repeat those deliveries
on retry.

**Booking retention** defaults to 365 days after the appointment ended. A nullable per-event-type
value inherits this default; zero removes completed data on the next run. The component's
**Erasure mode** chooses anonymisation or outright deletion. Eligibility is rechecked under the
host and booking locks, so a concurrently rescheduled appointment is not erased from a stale
candidate list. Retention uses the same erasure transaction and audit contract as self-service.

**Audit retention** defaults to three years, counted from the audit entry, and is independent of booking retention.
Expired keyed rate-limit windows become eligible for cleanup after one day. Each task's **Batch
size** defaults to 100 (1–1000); the retention routine processes at most that many bookings, audit
rows and rate-limit windows per invocation. Run additional batches or increase the frequency when
an existing backlog exceeds one daily batch. Monitor Scheduler results: disabling or failing the
task prevents both personal-data retention and metadata cleanup from being enforced.

### Calendar subscriptions

Open **Components → Appointments → Calendar feeds** with component management and options
permission. Copy an event-type URL or a host URL into your calendar client's subscription settings.
Event credentials also require edit permission for that event. A combined host credential is
available and rotatable only when you may edit every event type included in that host's feed.
Each credential controls only that feed; rotating a host URL does not rotate its event-type URLs,
and vice versa. Rotation requires explicit confirmation and revokes the old URL immediately.
Replace the URL in the calendar client afterwards. Normal event-type edits cannot restore an old
credential.

**Treat the full URL as a password.** Anyone who obtains it can read the calendar's appointment
details. Never put it in an invitee email, a public page, an error report or application logs.
Configure web-server and reverse-proxy access logs to redact the `token` query parameter or omit
query strings on the feed endpoint; the component cannot control infrastructure access logs.
Use HTTPS and private calendar clients. Feed responses are `noindex` and private/no-store, enforce
a keyed request limit and return the same generic 404 for missing calendars and wrong credentials.
Fixed-length credentials are compared in constant time before loading bookings or generating ICS;
unauthenticated requests cannot trigger calendar-sized database or serialization work.

Subscriptions are **read-only**: deleting a calendar entry does not cancel its booking. A
15-minute refresh is requested, but the client controls when it actually refreshes. ETags allow
an unchanged response to return 304. Feeds bound future entries by the booking horizon, include
90 days of history and retain cancellation tombstones for 30 days. Anonymised entries expose only
their anonymous slot metadata, never former contact, custom-answer or conference data.

For an individual booking, use **Export ICS** in the booking list. The download requires edit
permission on that booking's event type; credentials for subscription feeds are not included.

### Booking information

In **Options**, configure the booking data information text and the URL of your site's privacy
notice. The information text explains what you collect and why; it is not a legal privacy notice.
The consent checkbox is mandatory. The timing check requires at least two seconds after rendering
and expires after one hour; reload an expired form.
Additional guests are optional, deduplicated and limited to ten valid email addresses.
Telephone fields use an international calling-code selector and a national number.

Invalid input is reported at the field it concerns, all fields in one round trip: the control gets
`aria-invalid="true"` and `aria-describedby` pointing to its message, and the first invalid control
receives the focus. A missing consent and a missing confirmation on the reschedule, cancel and
erase pages each have their own message. Only problems the visitor cannot fix in a field (an
expired or tampered form, too many attempts, a time just taken) appear as a message above the form.

Dates and times follow the site language: the month and the day are written in words with the
weekday and month names of the installed Joomla language, times read `09:00`, and the time zone is
stated once per page. The patterns are the `COM_APPOINTMENTS_DATE_FORMAT_*` keys of the component
language file and can be changed with a language override. The reschedule, cancel and erase pages
show the appointment in the invitee's time zone, like the booking page.

The displayed zone never changes the stored UTC appointment. Public times always come from the
schedule, including its clock-change conventions. If someone else takes a time while the form is
open, submission returns to the same day with a friendly message and refreshed slot choices.
Confirmation pages require the submitting browser's session and are not public booking lookups.
Public requests use the directly connected address for rate limiting, not untrusted forwarding
headers. Only its keyed hash is stored; no raw address is added to a booking.

### Page markup and styling

Each public page sets its own browser title and main heading when the active menu item does not
point at it, for example on `/component/appointments/` without a menu item: "Book an appointment"
for the list, the event type's title for its booking page, the confirmation heading and the
heading of the reschedule, cancel or erase page. When a menu item points at the page (for one
event type: at that event type), its browser page title (or else its menu title) applies and its
page heading replaces the default heading when the menu item shows the page heading. In both
cases Joomla's **Site Name in Page Titles** setting applies. Summaries name the end date of an
appointment that crosses midnight in the displayed zone.

The public pages are template-neutral. They use Joomla's standard form classes and add
`com-appointments-*` hooks for a site template. The bundled stylesheet only provides the content
column, the calendar grid, touch targets of at least 44 px and the error state; typography and
colours stay with the template. The custom properties on `.com-appointments`
(`--appointments-max-width`, `--appointments-target`, `--appointments-radius`,
`--appointments-error`, `--appointments-gap`, `--appointments-border`) can be overridden.

| Part | Structure and classes |
|---|---|
| Page | `.com-appointments` plus the view (`-eventtypes`, `-eventtype`, `-confirmation`, `-manage`), inside it `.com-appointments-container` (centred column, `max-width: var(--appointments-max-width)`) |
| Header | `.com-appointments-header` with `h1.com-appointments-heading`, `.com-appointments-description`, `p.com-appointments-duration`, `p.com-appointments-location` |
| Time zone | `form.com-appointments-zone` with `label.form-label`, `.com-appointments-zone-row` (`select.form-select`, `button.btn.btn-secondary.com-appointments-zone-submit`) and `p.form-text.com-appointments-zone-note` |
| Calendar | `section.com-appointments-calendar` with `nav.com-appointments-calendar-nav` (`a.btn.btn-outline-secondary.com-appointments-calendar-prev` / `-next`, `h2.com-appointments-calendar-title`) and `table.com-appointments-calendar-grid`: a `thead` row of `th.com-appointments-weekday > abbr[title]` from Monday, `td.com-appointments-day-cell` (empty cells add `.com-appointments-day-empty`) holding `a.com-appointments-day.com-appointments-day-available` (selected day: `.com-appointments-day-selected`, `aria-current="date"`) or `span.com-appointments-day.com-appointments-day-unavailable` |
| Times | `fieldset.com-appointments-slots` with `legend.com-appointments-slots-legend` and `.com-appointments-slot-list` of `div.form-check.com-appointments-slot` (`input.form-check-input[type=radio]`, `label.form-check-label`) |
| Form | `form.com-appointments-form` (booking page also `.com-appointments-booking`, manage pages `.com-appointments-manage-form`), one `div.com-appointments-field` per field with `label.form-label`, `input.form-control` / `textarea.form-control` / `select.form-select` and `div.form-text.com-appointments-field-description`; choice fields are `fieldset.com-appointments-field.com-appointments-field-group` with `legend.form-label` and `div.form-check` entries; telephone fields add `.com-appointments-tel` |
| Checkboxes | `div.form-check` with `input.form-check-input` and `label.form-check-label`; consent `.com-appointments-consent`, confirmation `.com-appointments-confirm` |
| Errors | The control gets `.is-invalid`, `aria-invalid="true"` and `aria-describedby`; the message is `div.invalid-feedback.com-appointments-field-error` with the id `booking-<field>-error` or `manage-<field>-error` |
| Actions | `div.com-appointments-actions` with `button.btn.btn-primary` (cancel and erase: `btn-danger`) |
| Summary | `h2.com-appointments-title`, `dl.com-appointments-summary` (date, time, time zone, location or email address), `p.com-appointments-join > a.btn.btn-primary`, `p.com-appointments-status` |

### Email templates

The installer creates eight editable site-wide templates in each shipped language: confirmation,
pending, approved, reschedule, cancellation, reminder, host notice and erasure notice.
An event-specific template takes precedence over the published site-wide template for that purpose
and language. If neither is available, the corresponding English template is used. Updating the
component preserves existing templates rather than overwriting operator changes. Notification
labels are installed privately from the same shipped language files, so a stored recipient
language does not depend on that Joomla language pack being installed.

Templates are plain text. Common placeholders include `{event_title}`, `{invitee_name}`,
`{invitee_email}`, `{invitee_phone}`, `{guest_emails}`, `{host_name}`, `{host_email}`, `{location}`,
`{conference_url}` and `{custom_fields.ID}`. `{start}`, `{end}` and `{timezone}` show the appointment
in the recipient's zone, with the invitee's stored locale or the host's Joomla profile settings;
`{start_utc}` and `{end_utc}` retain the original UTC values. `{state_label}` is localized.
Use only single-line values in subjects.

Only primary-invitee messages append the action-specific reschedule, cancellation and erasure
links, even if a customized template omits their placeholders. Guests and the host do not receive
these management capabilities. Feed credentials are never included in mail.

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
invitee details or the erasure reason. The provider is asked to release its room after the erasure
transaction commits; teardown failures are reported without undoing erasure.

The deletion audit stores only booking ID, action, path, timestamp and, for administrator actions,
the acting operator's Joomla user ID. It contains no invitee data, but that staff identifier is
accountability data and has its own retention. Staff data-subject requests about `actor_user_id`
entries must be answered by the operator from `#__appointments_audit`; the invitee privacy export
deliberately excludes that domain. The system check reports the oldest retained audit timestamp.

### Booking administration and system checks

The booking list and CSV use the same per-event edit permission. Filters combine event type, state
(including pending `0`), an inclusive UTC start-date range and invitee name/email search. CSV export
requires a token-protected POST and uses a consistent database snapshot with bounded read batches.
Its columns include contacts, guest addresses and raw custom answers; protect downloaded files as
personal data. Spreadsheet-like text is prefixed with an apostrophe to prevent formula execution.

Event, custom-field and email-template lists and event selectors use that same permission scope:
an explicitly denied event's metadata is not disclosed. Site-wide templates additionally require
component options or administrator permission. An editor with no permitted event sees no event rows.

Open a start time to view booking details, readable custom answers, conference diagnostics and
single-event ICS. Rescheduling takes a **UTC** date/time, revalidates current availability and keeps
the original UID and approval state. Approval/cancellation require the event's state permission;
erasure requires delete permission. Moderator links are shown only to the host or a component
administrator, not to an ordinary event editor.

**System checks** requires component options permission. It reports PHP/Joomla versions, the site
time zone, configured mail transport, enabled bundled plugins, each routine's most recent enabled
task and last run, invalid feed-credential counts and the oldest audit record. A configuration
check is not a delivery test or a remote connectivity probe. Configure and monitor both Scheduler
routines; a never-run task is explicitly shown as needing attention.

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
| Conference provider | Inherit the component setting, disable, or select Jitsi, generic URL, fixed room or an installed adapter |
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

8. Configure email templates, conference settings if required, calendar subscriptions and both
   Scheduler routines. Review **System checks** and the site's own privacy notice before publishing.

## Signing keys

`KeyProvider` derives component-purpose keys from the installation's Joomla site secret. Signed
actions, anti-bot checks, keyed rate counters and conference room derivation use these keys without
storing derived keys in the database, displaying them or logging them. Component updates do not
change them. Booking changes invalidate action links through their revision; changing Joomla's
site secret deliberately invalidates existing signatures and changes derived room identities.
The independently random subscription credentials and optional operator JWT signing secret are
different capabilities and have their own rotation/configuration paths.

## Uninstall

Uninstall through **System → Manage → Extensions**. This removes the component files and drops
every table it created, including all stored bookings. Export what you still need first.

## Development

The directory layout follows `com_dmarcreports`, with an additional site part:

```text
com_appointments/
├── appointments.xml                     # Manifest; version managed by release workflow
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
├── plugins/                            # Bundled Scheduler and privacy plugins with licenses
├── updates/update.xml                   # Managed by release workflow
└── tests/                              # Unit tests and Docker integration harness
```

The binding [implementation plan](../.claude/skills/appointments/references/implementation-plan.md)
defines each stage's binding acceptance criteria. Versions are managed by the repository's two-stage
[release workflow](../README.md#releases), never set manually.

## Manual Acceptance Testing

The [manual acceptance plan](tests/manual/README.md) contains 228 identified cases and a complete
end-to-end scenario, including negative, boundary, concurrency and fault cases, real calendar
clients, conferencing and all three erasure paths. Use its
[blank report template](tests/manual/report-template.md) to record the actual tested commit and
observations privately. The plan records no completed manual tests; green CI does not replace
human acceptance. Generic test instructions are public; completed reports and sensitive evidence
must remain in access-controlled storage. The packager excludes the entire `tests/` directory.

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
| Event types / custom fields | Administrator CRUD, settings, publication and menu types |
| Slots | Actual no-JavaScript choices match the pure engine, overrides and busy occupancy |
| Booking | Contact validation, approval and identical/cross-event concurrency |
| Mail / templates | Actual transport, both calendar MIME parts, recipient locales and scoped overrides |
| Lifecycle / erasure | Signed actions, stable UID/sequence, exact erasure and minimal audit |
| Feeds / Scheduler | Credentials, conditional responses, independent rotation, reminders and bounded retention |
| Conference | Automatic links, JWT separation, explicit failure, retry and lifecycle teardown |
| Privacy | Real typed Joomla privacy events, Unicode isolation, export/removal and legal blocking |
| Administration | Filters, CSV safety, reschedule, system checks and restricted event ACL |
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
