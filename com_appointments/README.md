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
- PHP 8.1 or later with the sodium extension (libsodium). The installer refuses without it, because
  bookings are stored encrypted.
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
  manager, in one content language or in *All*, with SEF addresses. Direct links enforce
  publication, access and content language too.
- **Public booking.** A booking page in steps, one at a time, beside an information column of
  the event type: 1. the month calendar, then the times of the chosen day, where the chosen time
  shows its **Next** button; 2. the visitor's details, with the chosen time and a **Back** link in
  the information column; 3. the confirmation with the summary, an `.ics` download and the
  reschedule and cancel links. On a narrow screen the date, the times and the details follow one
  after the other. Every step has its own address (`month`, `day`, `start`), so a reload and the
  browser's back button keep it, and everything works without JavaScript; the bundled booking
  script loads the next step in the background, writes it into the browser history, moves the
  focus and announces the step, and applies the browser's time zone. The form collects first and
  last name (two required fields), the email address, the telephone number for telephone
  appointments, optional guests up to the configured maximum and configured custom fields. A privacy
  notice (optionally an acknowledgement or consent checkbox), a honeypot, a session-bound signed render time and a short-lived keyed rate
  limit protect submission without an external CAPTCHA.
- **Booking integrity and approval.** Pending and confirmed appointments both hold their slot.
  Submission serialises on the host row, rechecks the exact slot against current availability,
  and retains the database's unique active-slot constraint. The administrator can approve a
  pending appointment or cancel an appointment.

- **Email and calendar invitations.** Confirmations, pending notices, approval and cancellation
  notifications are sent to the invitee, each additional guest and the host through Joomla's
  configured mail transport. Each additional guest receives a guest template of its own with a
  neutral salutation, never one addressed to the booking person. Every message carries both the
  `text/calendar` and `application/ics` attachments. UTC calendar entries retain their UID and increase their sequence on changes.
  Notification failure never discards a saved booking: the page explicitly reports the mail problem.
  The mails follow the site's mail templates setting (com_mails): with «Mail Format» set to HTML or
  «HTML and plain text», the text is sent as HTML in the site's mail layout, including a template
  override such as `mytemplate:mailtemplate`, with links clickable and the text as alternative part.
  The reschedule, cancel, erase and meeting links show their label as link text in the HTML part, so a
  long signed URL cannot widen the mail. In the plain-text part the links the component adds itself stand
  as full URLs, each on a line of its own.
  A site with plain-text mails keeps plain-text appointment mails.

- **Signed appointment management and erasure.** Only the primary invitee receives the no-login
  reschedule, cancellation and erasure capabilities, including in reminders. The host receives
  calendar invitations without these bearer links. Each additional guest receives only a signed
  link of their own that removes their address from the booking and changes nothing else. Rescheduling updates the existing
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

- **Booking administration.** Filter by event, status and UTC date range, search invitee full,
  first or last name and email, sort by start or last name,
  export a filtered CSV, inspect details and custom answers, and reschedule using the same locked
  lifecycle. System checks report platform, mail configuration, Scheduler and audit health.

- **Joomla privacy integration.** The bundled privacy plugin exports readable booking records and
  removes matching records through Joomla's formal data-subject workflow. An address that is an
  additional guest of someone else's booking is exported and removed as a guest only.
  Email ownership is exact after Unicode case normalisation; accent-insensitive database collations
  cannot expose another person's booking. The minimal accountability audit is excluded from exports.

## Configuration

Open **Components → Appointments → Options → Permissions** to configure the component's Joomla
ACL. Event types, custom fields, schedules and blocked periods are configured from the dashboard.

### Links and web-server rules

The booking pages have a SEF router, built from Joomla's standard routing rules like the core
components. The guest links in e-mails and the calendar subscriptions stay non-SEF on purpose:

- **Booking pages** are reached through a Joomla menu item of type *Event types* or *Book an
  appointment*, in one content language or in *All*. The event type of a *Book an appointment* item
  is chosen from the published event types; an item whose event type was unpublished later keeps it,
  marked as unpublished. Links the component builds (the event type list, the month and day
  navigation, redirects inside the booking flow) use the event type's own menu item in the current
  language or in *All*, otherwise an *Event types* item (`/<list item>/<event type alias>`),
  otherwise `/component/appointments/eventtype/<event type alias>`. Earlier addresses such as
  `/component/appointments/?view=eventtype&id=…` and non-SEF
  `index.php?option=com_appointments&view=eventtype&id=…` keep working.
- **Links in e-mails** (reschedule, cancel, erase) are built as
  `index.php?option=com_appointments&view=manage&id=…&action=…&signature=…` from the public site URL.
  They are signed, are also sent by the reminder task from the command line, where no site router is
  available, and must stay valid for as long as the booking exists. On a multilingual site Joomla
  adds the language prefix when such a link is opened.
- **Calendar subscriptions** use `index.php?option=com_appointments&task=feed.download&…`.

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

The router does not remove these exceptions. The signed mail links and feed URLs have to be
stable and buildable without a site router, and without a menu item a routed address is still
`/component/appointments/…`.

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
the primary invitee's contact details by appearing in a booking: when the request address is a
guest of another person's booking, a separate domain (`appointments_guest_invitations`) lists only
the booking ID, the guest address as stored, the event type title, the UTC start and end and the
state. Guest addresses are matched exactly per line of `guest_emails` (Unicode case folding, no
substring match), so `notguest@example.com` never matches `guest@example.com`.

Formal removal takes such a guest address out of the other person's guest list and leaves that
booking, its invitee and the other guests unchanged; each removal writes an audit row with the
action `remove_guest` and the path `privacy_request`, without the address. A configured legal
reason blocks guest removals like invitee removals.

Formal removal uses the same transaction, anonymisation/deletion mode and host-only future
cancellation notice as the signed erase link. A nonempty **Legal reason to block formal removal**
blocks matching Joomla removal requests and explains the reason to the administrator. This option
does not alter signed self-service erasure or automatic retention; operators must configure those
policies consistently with their legal obligations. Accent differences never establish ownership,
and the request's email remains authoritative even if another privacy plugin has already changed
the associated Joomla user object.

### Scheduler, reminders and retention

The component installs and enables **Task - Appointments**. Updates preserve an existing task's
enabled/disabled state; uninstalling the component removes the plugin and its Scheduler tasks.
On installation and updates, Joomla's Scheduler ensures these tasks exist in **System → Scheduled Tasks**:
**Appointments: reminders**, running every five minutes, and **Appointments: retention**,
running daily, each with a batch size of 100. A first installation creates both tasks enabled.
An update creates a missing task **disabled** and says so, because retention would otherwise start
anonymising existing bookings and reminders start writing to invitees without your decision: enable
the task yourself. A task is created only when no task of its routine exists in any state: a task
you disabled or trashed is never enabled or created again, and its schedule and options are never
overwritten. A task deleted permanently is created again by the next update, so disable a task
instead of deleting it. If a task cannot be created,
the installation shows a warning and you create it by hand. Configure Joomla's Scheduler execution mechanism or
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

### Internet calendars

**Implementation and verification status:** This section documents the 1.4.0 target behaviour; it
is not evidence that the feature is available in a published release or that every behaviour has
passed its checks. The component remains unreleased, and the [manual client acceptance
plan](tests/manual/teamcalendars.md) records no completed manual tests. Do not rely on internet
calendars in production until the release and its required automated and human acceptance checks
are complete.

An internet calendar is a calendar on your own site that every authorised user reads and writes
with a CalDAV client, signed in with an app password. Calendars are created in the administrator
and shared per user; **nothing is visible that was not explicitly granted** (default deny), and
there are no secret addresses. Bookings of an event type land in its target calendar, and busy
times in a calendar block the booking page's slots. The binding specification is
[`team-calendars.md`](../.claude/skills/appointments/references/team-calendars.md).

#### Requirements a host can block

The component runs on any host that runs Joomla 5.4 or 6. The following can still be blocked by a
host's configuration. **Components → Appointments → System checks** tests each point on the site itself
and names the remedy.

| Requirement | Remedy when the system check fails |
|---|---|
| HTTPS. CalDAV answers over HTTPS only; a plain HTTP request gets `403` without a body or redirect, before its `Authorization` header is even read. | Enable TLS for the site. |
| HSTS (`Strict-Transport-Security`), recommended rather than required. | Enable HSTS in Joomla's HTTP headers plugin or in the web server. |
| The WebDAV methods `OPTIONS`, `PROPFIND`, `REPORT`, `PUT`, `DELETE` and `MKCALENDAR` reach PHP. | Allow them in the web server or firewall rules (ModSecurity or another web application firewall). |
| The `Authorization` header reaches PHP. | Keep the rule `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` from Joomla's `htaccess.txt`, or use the host's equivalent setting. |
| `/.well-known/caldav` reaches Joomla. | Add the rewrite, or configure clients with the full CalDAV address; only automatic discovery is lost. |
| PHP with libsodium, including Argon2id (`sodium_crypto_pwhash`). Required for the whole component, not only for the internet calendars: the installer refuses without it, because bookings are stored encrypted. | Enable the sodium extension. |
| `memory_limit` of at least 128M (Argon2id uses 64 MiB per sign-in). | Raise `memory_limit`. |

Every calendar user also needs a second factor in Joomla's multi-factor authentication. Only these
count, each with its Joomla plugin enabled: an **authenticator app** (TOTP), a **passkey**
(WebAuthn) and a **YubiKey**. Codes by e-mail, the fixed code and backup codes give no effective
grant: e-mail is the same channel as a password reset, so it would let a reset by mail alone reach
the calendars. Neither does an account in a group listed under «Never use MFA for these user
groups».

**Failed sign-ins** lock an account temporarily (after 10 failures, with a growing delay, never
permanently). The lock is checked before the password, on Joomla's own sign-in forms of the site
and the administrator and on CalDAV. Administrators get a mail when an account with a calendar
right is locked and can lift the lock in the devices view. Not covered, stated plainly:

- Joomla's **API Authentication - Basic Auth** plugin signs in with a password, without second
  factor and without the lock-out. Keep it disabled, as Joomla ships it; the system check warns
  when it is enabled.
- Sign-ins of other extensions that do not post `username` and `password` like Joomla's forms are
  refused while locked, but Joomla's answer there shows that the password was right.
- Guessing the second factor after a correct password is limited by Joomla itself (Users → Options
  → Multi-factor Authentication, by default 10 tries per hour); the system check warns when that
  limit is off or above 10.
- If the lock-out check itself fails (a database error), the website sign-in still works, so that
  nobody, administrators included, is locked out; the system check then warns. CalDAV and «Add
  device» refuse in that case.

#### Setting up

1. In **Options → Internet calendars**, switch **Internet calendars** on. While it is off, no
   calendar address answers and no key is handled at sign-in.
2. Enable the plugins **System - Appointments CalDAV** and **User - Appointments internet
   calendars**. Both are installed disabled.
3. Check the groups. With **Create user groups** on, installation and update create the groups
   «Internet calendars internal» and «Internet calendars external» below the registration group,
   the access level «Internet calendars», and add both groups to **Users → Options →
   Multi-factor Authentication → Enforce MFA for these user groups**. Never list them under the
   groups that skip MFA; the system check reports that as an error.
4. Create the **recovery key** in **Internet calendars** as a Super User who is also a member of
   «Internet calendars internal» (that membership gives the account a key pair; it needs no calendar
   grant). Sign out and in again with password and second factor first: the key operations need a
   session unlocked by the password. Its secret is shown once.
   Print it **twice together with the site secret** (`$secret` in `configuration.php`) and keep the
   two printouts offline in separate places. The recovery key is never stored, and without it no
   calendar can be created.
5. Create a calendar, name its owner (the owner gets the manage role) and grant access to users.
6. Add a menu item **My calendars** with the access level «Internet calendars». There each user
   adds one device per client and receives the app password and the instructions for that client.

#### Accounts

**Only an administrator creates accounts**, for team members and external users alike, in Joomla's
user management, in the team group or the external group. Joomla's own registration stays off;
there is no self-registration. The administrator grants the calendars and sends the activation
link: valid once, for 72 hours, bound to the account and its address. The person sets the password
with it; Joomla enforces the second factor at the first sign-in. When the activation is completed,
every user with the permission «Share with external users» receives a mail with a link that
revokes the access in one step (all grants removed, account blocked, devices cleaned up).

That first password sign-in also creates the user's key pair; a grant becomes usable only after
it.

#### Options

| Option | Default | Meaning |
|---|---|---|
| Internet calendars | off | The single switch of the feature. |
| Team groups | the group created by the installer | Members may receive grants from a calendar's managers. |
| Group of external users | the group created by the installer | Accounts of external users belong here. Only users with the permission «Share with external users» grant to them, at most the write role, and only on calendars that allow externals. A member of both groups counts as external. |
| Create user groups | on | Create missing groups and the access level on installation and update, and enforce MFA for both groups. |
| Access level of «My calendars» | the level created by the installer | Shows the menu item to calendar users only. |
| Days per clean-up phase | 14 | Longest duration of each clean-up phase of a blocked device (see below). |
| Subscription feeds with token | off on a new installation, on after an update | The previous token feeds, replaced by the internet calendars. |

The CalDAV plugin holds the **CalDAV base address**, an absolute `https://` address (default: the
site address plus `/dav/`). The CalDAV path, the well-known redirect, the client instructions, the
mails and the system check all derive from it. A configured address is used only when it is valid:
`https://` with a host, no user name, password, query or fragment, at most 255 characters, and a
path below the site's own path that adds 1 to 3 plain segments (letters, digits, `.`, `_`, `~`,
`-`), the first of which is not a Joomla folder such as `administrator`, `media` or `images`.
Otherwise the default applies. The permission **Share with
external users** (`appointments.team.external`) is granted to nobody by default; Super Users have
it implicitly.

#### Roles

| Role | In the client | Web |
|---|---|---|
| Free/busy | read-only calendar of busy blocks titled «Busy», without any content | sees the calendar in «My calendars» |
| Read | read | same |
| Write | view, create, change and delete events | same |
| Manage | as write | shares with team members, revokes, edits title, colour and description |

#### Devices and app passwords

In **My calendars → Add device**, the user names the device, picks the client and confirms the usage
rule: a lock code on the device, no forwarding to iCloud or Google calendars, report a loss at once.
Private smartphones are allowed under that rule. Adding a device requires a password sign-in with
the second factor in the same session and at least one grant. The app password is shown once and
stored only as a keyed hash; the user receives a mail for every new device. The Joomla password
never works for CalDAV. **Remove device** starts the clean-up.

#### Client instructions

The user name is the Joomla user name, the password is the device's app password. `<site>` stands
for your site's address; the CalDAV address below assumes the default base address.

| Client | Address | Refresh |
|---|---|---|
| Classic Outlook (Office LTSC 2024) with the CalDav Synchronizer add-in | profile «Generic CalDAV/CardDAV», DAV URL `https://<site>/dav/`, then «Discover resources and assign to Outlook folders», each calendar to a new, empty folder | sync mode two-way, interval 5 minutes, «Use WebDAV collection sync» on |
| iOS calendar | Settings → Calendar → Accounts → Add Account → Other → CalDAV account, server `<site>` | Fetch New Data → every 15 minutes |
| DAVx⁵ (Android) | «Login with URL and user name», `https://<site>/` | calendar sync interval 15 minutes (the Android minimum) |
| Thunderbird | New Calendar → On the Network, `https://<site>/dav/` | calendar properties → refresh every 5 minutes |
| eM Client | account → CalDAV, `https://<site>/dav/` | account → synchronisation every 5 minutes |

**Not supported:** the new Outlook for Windows and the Outlook apps on phones, because they do not
speak CalDAV. Use classic Outlook with CalDav Synchronizer on Windows and the phone's own calendar
(iOS) or DAVx⁵ (Android). The macOS calendar works with the iOS settings, but it is not part of the
tested set.

Clients refresh by polling; the server keeps the CTag and the sync token (RFC 6578) exact, so a
refresh without changes transfers no events. Menu paths can differ between client versions.

#### Blocking and clean-up

A device to be blocked first gets its calendars **empty**, so the client deletes its local copy;
then the calendars are **no longer listed**, so the client removes them; then every request is
refused. Each phase ends when the device has fetched it, at the latest after **Days per clean-up
phase**. Writes are refused during the clean-up, so a client cannot upload the deleted events
again. Triggers: «Remove device», an administrator blocking a device or a user's calendar access,
a blocked Joomla account and a password reset. A device that never connects again keeps its local
copy; that is why the usage rule asks for a lock code.

#### What the encryption protects, and what not

**In one sentence: the encryption protects when the database or a backup of it leaks, not when
somebody executes code on the server.** It is encryption at rest; end-to-end encryption is not
possible with CalDAV clients.

- **Protected:** a copy of the database alone (dump, database backup, SQL injection in any
  extension) shows no content and no times of calendar entries, no app password and no key. Of
  the bookings, the personal fields (names, addresses, phone, answers) are encrypted, while their
  dates, times, event types and states stay readable, because reminders and the slot calculation
  need them. A row added or changed in the database without the site
  secret grants nothing. A Super User without a grant sees calendar lists and the log, never
  contents; granting oneself access is possible, but it is logged and mailed to the calendar's
  owner and managers.
- **Not protected:** anybody who executes code on the server, which includes a compromised
  extension, the hosting provider and anybody with access to the files: while a key holder's request
  runs, the calendar key is in memory, and such an attacker can also capture app passwords. A copy
  of database **and** files together (the site secret is in `configuration.php`) reveals when
  events take place and decrypts the personal fields of bookings, because both use server keys;
  the contents of calendar entries stay encrypted. Devices that never connect again. Booking mails to the team, which carry content and ICS as before.
- **The site secret is recovery material.** Changing `$secret` in `configuration.php` invalidates
  every app password, every server-side key and every encrypted booking field, and the recovery key
  alone does not repair that; the way back is the old site secret. That is why it is printed with
  the recovery key.
- **Password reset:** a reset by mail or a password set by an administrator gives a new key pair;
  the user's grants are suspended and the devices go into clean-up. A manager grants again, or an
  administrator uses the recovery key. A password reset alone never gives anybody calendar access.
- **Losing the recovery key and every key holder** of a calendar loses that calendar's contents, by
  design.
- **Known residual risk:** while a calendar connected to an event type cannot be read at all, a
  forged booking request (a crafted POST, not the booking page) for a time the page did not offer
  is accepted, because the busy times of that calendar are unknown. The booking page itself offers
  only free times.

#### Updating to 1.4.0

The update encrypts the personal fields of existing bookings in batches, inside the update itself;
whatever it does not finish is encrypted at the next visit of an administrator to the component,
independent of the Scheduler. This migration window lasts at most 7 days. **An update never makes
data unreadable:** a booking still in clear text after the window stays readable, is shown as «not
verified», and **System checks** warns and shows how many are left. The action **Encrypt now**
there finishes the migration. After the window nothing else encrypts clear text, so that clear
text planted in the database later is never taken for a genuine booking.

#### Restoring a backup

Grants, calendars and devices carry counters (epochs) that every key rotation, revocation and
device block raises. A signed anchor file,
`administrator/components/com_appointments/data/epochs.php`, keeps the highest value, so a row
from an older database copy no longer counts. This is the protection against played-back rows.

- **Database only restored**, from a backup older than the last key rotation, revocation or device
  block (or an older row written back into it): the affected calendars and users **stay closed**, on
  purpose; revoked grants and devices cannot come back this way. To open them, delete
  `data/epochs.php`, which deliberately resets the protection, and then revoke again every device
  and grant that was revoked after the backup was taken.
- **Files and database restored together** is a legitimate rollback, and the anchor goes back too.
  Revoke again every device and grant that was revoked after the backup was taken.
- **System checks** reports when the anchor file cannot be written or is not kept up to date (the
  protection is then off), and when an anchored value lies below the verified database value.
- The anchor belongs to one installation: a fresh installation and an uninstall remove
  `data/epochs.php`.

#### Privacy requests and retention

Joomla's privacy component (**Users → Privacy**) handles export and removal requests. There is no
index of e-mail addresses anywhere, because whoever holds the server key could test known
addresses against it.

- **Bookings** are decrypted on the server in batches and matched by the invitee's and guests'
  addresses.
- **Calendar entries** are searched only in the session of the person handling the request, and
  only in the calendars that person can unlock (owner, manager, or keys recovered with the recovery
  key). Removal deletes the matching entries as normal changes, so clients drop them at the next
  synchronisation.
- **Never silently incomplete:** every calendar that could not be searched is named in the export
  and in the removal result, with the way to search it (sign in as a key holder, or use the
  recovery key).
- Searches, exports and removals are logged with counts only, never the address or content.

Each calendar has its own **retention**; the Scheduler deletes older entries and logs only their
number.

#### Replacing the token feeds

The internet calendars replace the token feeds of the next section, whose secret addresses
contradict default deny. From 1.4.0 the option **Subscription feeds with token** controls them: off
on a new installation, on after an update (never overwritten). Off, the feed address answers `404`
and the Calendar feeds view explains the replacement; on, the feeds work unchanged with a
deprecation notice. Version 2.0.0 removes the feeds. Move each feed user to an internet calendar
before that.

#### Testing with real clients

The [manual test plan for internet calendars](tests/manual/teamcalendars.md) checks every supported
client, including the clean-up and the refresh interval. It runs only against a test installation.

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

In **Options**, configure the booking data information text and your site's privacy notice: a
**menu item** (optionally with a section anchor such as `privacy`; on a multilingual site the menu
item's language association is followed) or, without a menu item, a **URL** such as
`/privacy#booking`. The privacy notice in the information column of the booking card and, where
there is one, the checkbox label link to it in a new window; without either setting both are plain
text. The component has no default for either setting, because only the site knows the
address of its privacy notice: set one before you publish a booking page. The information text explains what you collect and why; it is not a legal privacy
notice. The timing check requires at least two seconds after
rendering and expires after one hour; reload an expired form.

| Option | Values | Effect |
|---|---|---|
| **Privacy notice on the booking form** (`consent_mode`) | `notice` (default) | No checkbox; only the privacy notice with its link. A posted checkbox value is ignored. Unknown values behave the same. |
| | `acknowledge` | A required checkbox "I have taken note …"; a booking without it is refused at the checkbox. Nothing is stored. |
| | `consent` | A required consent checkbox. The booking stores `consent_utc` (UTC time of the booking) and `consent_version`, the sha256 of mode, language tag and the exact plain label text, so a changed wording is recognisable. Choose it only if your processing is based on consent. |
| **Maximum number of additional guests** (`max_guests`) | 0–50, default 10 | See below. |
| **Preselected calling code** (`phone_default_country`) | digits, for example `41` | See "Telephone". |
| **Calling code choice** (`phone_country_choice`) | 1 (default) visitor chooses, 0 only the preselected code | See "Telephone". |

The consent proof is shown in the administrator's booking detail, exported in the CSV and in the
formal privacy export, and kept on an anonymised booking: it is evidence of the consent and holds
no data of the person. Joomla's **System - Privacy Consent** plugin does not touch this form (it
extends only the registration and profile forms of com_users), but when enabled it can redirect a
logged-in user who has not yet accepted the site's privacy policy to their profile before any site
page, including the booking page.
**Name.** First name and last name are separate required fields (`booking[first_name]`,
`booking[last_name]`, at most 127 characters each). The booking stores both parts in
`invitee_first_name` and `invitee_last_name` and the full name "first last" in `invitee_name`, which
the calendar entry (`ATTENDEE;CN`), the host notice and `{invitee_name}` keep using. Bookings made
before version 1.3.0 get both parts derived from the stored full name by the update: the last word
is the last name, the rest the first name, a single word becomes the first name.

Additional guests are optional, deduplicated and limited by **Options → Maximum number of
additional guests** (`max_guests`, 0–50, default 10; 0 removes the guest field, and a guest list
submitted anyway is refused as a tampered form). The server-side check is authoritative; the
field hint and the error message state the configured number. The field is a closed disclosure
("Invite further guests") that opens when it holds a value or an error.

**Telephone.** An event type whose location is *Telephone: host calls invitee* or *Telephone:
invitee calls host* always asks for the invitee's telephone number. It is a fixed, required field
next to name and email address, stored in the booking's `invitee_phone` column and therefore shown
in `{invitee_phone}` of the mails, the administrator's booking detail and CSV export, and erased
with the other contact data. Custom telephone fields of other event types keep filling
`invitee_phone` with their first answer as before; on a telephone event type the fixed field takes
precedence and custom telephone answers are only kept with the other custom answers.
Every telephone field has a calling-code selector and a number field. **Options → Preselected
calling code** (digits only, for example `41`) preselects a code. With **Options → Calling code
choice** set to *Only the preselected calling code* and a valid preselected code, every telephone
field accepts only numbers with that code: an empty selector means that code, and another code,
chosen or typed with `+` or `00`, is refused at the field. The visitor may also type the
whole international number starting with `+` or `00`; the code is then read from the number.
Spaces, brackets, dots, slashes and hyphens are ignored, and a single national trunk zero is
dropped ("+41 079 …" is stored as `+4179…`), except for the calling codes whose national numbers
keep it (Italy, San Marino, Vatican City, Côte d'Ivoire, Benin, Republic of the Congo). Numbers are
stored in E.164 form, at most 15 digits.

A telephone event type that collected the number through a custom telephone field (before the
fixed field existed) asks twice after the update. Unpublish that custom field; do not delete it, so
the answers already stored with earlier bookings keep their label in the booking detail. Those
bookings already have `invitee_phone` filled from the custom field, so no data has to be moved.

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

The booking page shows the times in the schedule's zone. The **Time zone** field below the calendar
is always visible and shows the zone of the times. Without JavaScript it is a normal select with a
button that applies the choice. The booking script switches to the browser's zone on arrival
(unless the address names a zone already) and turns the field into a button with the name of the zone in the page language as the browser knows it (for example "Mitteleuropäische Zeit", the identifier as tooltip) and its
current time: it opens a panel with a search field (an ARIA combobox) over the list of zones (an
ARIA listbox, each zone with its name and current time from the browser) and a switch between the
12-hour and the 24-hour clock. Arrow keys, Page Up and Page Down move through the list, Enter
chooses and Escape closes. The clock applies to every time of the page; it starts from the
language (`COM_APPOINTMENTS_CLOCK_DEFAULT`, `24` in all three bundled languages, 12-hour times use
`COM_APPOINTMENTS_DATE_FORMAT_TIME_12`) and a switched clock is the address parameter `clock`.

The displayed zone never changes the stored UTC appointment. Public times always come from the
schedule, including its clock-change conventions. If someone else takes a time while the form is
open, submission returns to the times of the same day with a friendly message and refreshed slot
choices; an address of step 2 whose time is no longer offered shows the times of its day with the
same message. A refusal the visitor can fix in a field returns to step 2 of the same time.
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

**Information column.** Each event type chooses in **Display → Information column** what the
column beside the calendar shows: *Company logo and appointment* (an optional
logo from the media manager; the default, also for event types created before this setting) or
*Person with photo* (an optional photo shown as a circle, a name and an optional role), or
*Appointment only* (no image at all, for example when the site header already shows the logo). All
three show the title, duration, location type, description and location text, and on step 2 the
chosen date, time and time zone. An empty image, name or role is left out, and an unknown value falls back to
the logo layout. Images are local paths chosen in the media field; a relative path is made
absolute so it also resolves below a menu item's address. **Display → Title** places the title of
the event type *In the card* (the default, at the top of the information column) or *Above the
card* (as a heading above the card, aligned with its left edge, for all three layouts). Either way
it is the one main heading (`h1`) of the page.

**Step 1.** From the first view the card has its full width: the times column stands beside the
calendar and asks the visitor to choose a day until one is chosen, so the card does not grow when
the times appear (on a narrow column the calendar and the times stay views of their own). The
times legend shows the weekday and date without the year, which the month title already names;
screen readers hear the full "Available times on …" wording. The time zone field stands below the
times, with its label **Time zone** in the small size.

**Step 2.** One field per row, each as wide as the form (at most `--appointments-field-max-width`):
first name, last name, email, the telephone for telephone appointments, the custom fields and the
further guests. Every required field carries the same `*` after its label
(`.com-appointments-required`, hidden from screen readers, which hear that the control is
required). A telephone field is one input group: the calling code before the number, a select or,
with **Only the preselected calling code**, a fixed prefix with the flag of the country (the image
of Joomla's language switcher, when the code belongs to one country). The further guests are a text
field of addresses separated by commas, with a hint naming the maximum (**Maximum guests**); the
booking script turns it into chips: an address becomes a chip with Enter, a space, a comma or a
semicolon, pasted text becomes one chip per address, Backspace in the empty field removes the last
chip, and an invalid address or one beyond the maximum is marked. The server checks the list either
way. With the script the browser checks the form before sending and shows its messages at the
fields like a refusal of the server. On a narrow column the information column of step 2 keeps the
chosen date, time and zone and leaves out the description and the location text.

**Privacy notice.** With a configured privacy notice (menu item or address) the information
column ends with one short sentence that links to it, in every step and in the small size. It is
the only privacy text of the page with the default **Privacy notice on the booking form** *Notice
only*; the checkbox modes add their checkbox to step 2.

**Stylesheet and script versions.** The page loads the stylesheet and the booking script with the
CRC32 checksum of the served file as version (`appointments.css?1a2b3c4d`), also for a template
override of the file. A browser that keeps a static file for days therefore never combines an old
stylesheet or script with new markup after an update.

**Customisation.** The public pages are template-neutral: Joomla's standard form classes plus
`com-appointments-*` hooks. The bundled stylesheet draws a neutral look (a card with the
information column, round days, a column of times with a **Next** button on the chosen time) from
CSS custom properties on `.com-appointments`. A site template restyles the pages by setting them,
for example in its `user.css`:

```css
.com-appointments {
    --appointments-accent: #000;
    --appointments-accent-hover: #333;
    --appointments-radius: 0;
    --appointments-font-heading: "My Display Font", sans-serif;
}
```

The values must come after the component stylesheet or carry a more specific selector (for example
`body .com-appointments`). A template stylesheet registered in the template's `index.php` comes
after it, because the component registers its own stylesheet first.

The layout adapts to the width of the template's content column (container queries on
`.com-appointments-container`), not only to the screen. Below 36rem the steps stand one after the
other without a card frame, with one column of fields. From 36rem the page is a card with the
calendar and the times side by side and the information as a bar above them, from 54rem the
information is a column of its own beside them. The card is only as
wide as its content (information column, calendar, times and the card paddings), so a wide content
column keeps a compact card. The two thresholds follow from the default sizes below; a template
that makes the days, the times column or the information column much wider should check the
narrower widths.

| Property | Default | Used for |
|---|---|---|
| `--appointments-accent` | `#0b57d0` | Primary buttons, available days, the times, the selected day, the loading bar |
| `--appointments-accent-contrast` | `#fff` | Text on the accent colour |
| `--appointments-accent-hover` | `#0842a0` | Primary buttons on hover and keyboard focus |
| `--appointments-accent-hover-contrast` | `#fff` | Text on the hover colour |
| `--appointments-accent-soft` | accent at 10 % | Background of available days, month arrows and the confirmation mark |
| `--appointments-selected` | `#3c4043` | Background of the chosen time |
| `--appointments-selected-contrast` | `#fff` | Text of the chosen time |
| `--appointments-selected-border` | selected colour | Border of the chosen time |
| `--appointments-selected-border-width` | `1px` | Border width of the chosen time, for example `2px` for an outline without fill |
| `--appointments-slot-hover-background` | surface | Background of a time on hover |
| `--appointments-nav-background` | accent soft | Background of the month arrows |
| `--appointments-nav-hover-background` | accent soft | Background of the month arrows on hover |
| `--appointments-nav-hover-border-color` | accent | Border of the month arrows on hover |
| `--appointments-surface` | `#fff` | Background of the card, the time buttons and the list entries |
| `--appointments-surface-alt` | `#f6f7f9` | Background of the summary and of secondary buttons on hover |
| `--appointments-text` | `#1f2328` | Text and headings |
| `--appointments-muted` | `#59636e` | Secondary text: meta line, weekdays, notes |
| `--appointments-unavailable` | muted | Days without free times |
| `--appointments-unavailable-opacity` | `0.6` | Opacity of those days (`1` for a flat colour) |
| `--appointments-border-color` | `#d0d7de` | Card, column and secondary-button borders |
| `--appointments-border` | `1px solid` border colour | The border shorthand of card, columns and list entries |
| `--appointments-error` | `#b3261e` | Field errors and the danger button |
| `--appointments-focus` | accent | Focus outline of every control |
| `--appointments-radius` | `0.5rem` | Card, fields, list entries |
| `--appointments-button-radius` | radius | Buttons and time buttons |
| `--appointments-day-radius` | `50%` | Calendar days and month arrows (`0` for squares) |
| `--appointments-shadow` | soft shadow | Card shadow (`none` for a flat card) |
| `--appointments-font` | `inherit` | Text font of the pages |
| `--appointments-font-heading` | `inherit` | The main heading (event type title, confirmation) |
| `--appointments-heading-weight` | `700` | Weight of the main heading |
| `--appointments-heading-size` | `1.5rem` | Size of the main heading |
| `--appointments-page-title-gap` | `1.5rem` | Space between the title above the card and the card |
| `--appointments-font-subheading` | heading font | Step, month and summary titles |
| `--appointments-subheading-weight` | heading weight | Weight of those titles |
| `--appointments-label-weight` | `600` | Field labels and the times legend |
| `--appointments-label-letter-spacing` | `normal` | Field labels |
| `--appointments-button-font` | `inherit` | Button font |
| `--appointments-button-size` | `1rem` | Button text size |
| `--appointments-button-weight` | `600` | Button text weight |
| `--appointments-button-transform` | `none` | Button text transform, for example `uppercase` |
| `--appointments-button-letter-spacing` | `normal` | Button letter spacing |
| `--appointments-strong-weight` | `600` | Emphasised text: the chosen date, time and zone, names, summary terms, field errors |
| `--appointments-meta-weight` | strong weight | Duration and location type in the information column |
| `--appointments-weekday-weight` | strong weight | Weekday letters of the calendar |
| `--appointments-small-size` | `0.875rem` | Small text: time-zone line, field hints, consent note, location text, errors |
| `--appointments-step-title-size` | `1.125rem` | Step and summary titles |
| `--appointments-month-size` | `0.9375rem` | Month title of the calendar |
| `--appointments-day-font-size` | `0.9375rem` | Day numbers of the calendar |
| `--appointments-weekday-size` | `0.6875rem` | Weekday letters of the calendar |
| `--appointments-slot-font-size` | `1rem` | The times |
| `--appointments-field-background` | surface | Background of text fields and lists |
| `--appointments-field-border-color` | border colour | Border of text fields and lists |
| `--appointments-field-border-width` | `1px` | Border width of fields, for example `0 0 1px` for an underline |
| `--appointments-textarea-border-width` | field border width | Border width of multi-line fields |
| `--appointments-field-radius` | radius | Corner radius of fields |
| `--appointments-field-max-width` | `none` | Maximum width of a field |
| `--appointments-field-font-size` | `max(1rem, 16px)` | Text size in fields; below 16 px iOS zooms into the page |
| `--appointments-field-padding` | `0.5rem 0.75rem` | Inner padding of fields |
| `--appointments-field-line-height` | `1.5` | Line height in fields |
| `--appointments-field-min-height` | touch target | Minimum height of fields |
| `--appointments-field-gap` | `1rem` | Space between the fields of step 2 |
| `--appointments-label-size` | `inherit` | Text size of field labels |
| `--appointments-label-gap` | `0.375rem` | Space between a label and its field |
| `--appointments-error-size` | small size | Text size of field errors |
| `--appointments-error-weight` | strong weight | Weight of field errors |
| `--appointments-tel-gap` | `0.5rem` | Space between calling code and number (`0` when the group draws one line) |
| `--appointments-tel-border-width` | `0` | Border width of the telephone group in the field border colour, for example `0 0 1px` with fields drawn as a line |
| `--appointments-tel-prefix-background` | surface alt | Background of a fixed calling code |
| `--appointments-chip-background` | surface alt | Background of a guest chip |
| `--appointments-chip-radius` | radius | Corner radius of a guest chip |
| `--appointments-zone-label-weight` | `400` | Weight of the label **Time zone** |
| `--appointments-check-size` | `1.25rem` | Size of checkboxes and radio buttons |
| `--appointments-gap` | `0.75rem` | Gap between list entries |
| `--appointments-target` | `44px` | Minimum size of every touch target |
| `--appointments-day-size` | `2.5rem` | Diameter of a calendar day (never below the touch target) |
| `--appointments-calendar-width` | seven days plus `0.75rem` | Maximum width of the month calendar |
| `--appointments-card-padding` | `1.25rem` | Inner padding of the card columns and the confirmation |
| `--appointments-max-width` | `66rem` | Outer width limit of the booking page; the card itself is only as wide as its columns |
| `--appointments-narrow-width` | `42rem` | Width of the list, confirmation and manage pages |
| `--appointments-info-width` | `16rem` | Width of the information column beside the step |
| `--appointments-times-width` | `12rem` | Width of the times column beside the calendar |

Markup changes go into Joomla template overrides (**System → Site Templates → your template →
Create Overrides → com_appointments**), which copies the files to
`templates/<template>/html/com_appointments/`. Each part of the booking page is a file of its own:

| File | Part |
|---|---|
| `eventtype/default.php` | The booking page: the card, the information column, the step title and the step (also the time-zone choice) |
| `eventtype/default_info_logo.php` | Information column, layout *Company logo and appointment* |
| `eventtype/default_info_person.php` | Information column, layout *Person with photo* |
| `eventtype/default_info_event.php` | The event details every column layout shows, with the chosen time on step 2; on its own the layout *Appointment only* |
| `eventtype/default_zone.php` | The time zone field below the times: the select and the panel of the booking script |
| `eventtype/default_zone_note.php` | The sentence naming the zone of the times, read by screen readers with the field (also sent with the background update) |
| `eventtype/default_privacy_note.php` | The privacy notice at the foot of the information column |
| `eventtype/default_picker.php` | Step 1: calendar and times; also the whole response of the slot endpoint |
| `eventtype/default_details.php` | Step 2: the details form |
| `booking/default.php` | Step 3: the confirmation |
| `eventtypes/default.php` | The list of event types |
| `manage/default.php` | The reschedule, cancel and erase pages |

An override must keep the ids, the `data-appointments-*` attributes and the form field names it
copies, because the booking script and the server rely on them.

| Part | Structure and classes |
|---|---|
| Page | `.com-appointments` plus the view (`-eventtypes`, `-eventtype`, `-confirmation`, `-manage`), inside it `.com-appointments-container` (centred column, `max-width: var(--appointments-max-width)`, on the other views `--appointments-narrow-width`); on the booking page `[data-appointments-view]` with `data-step="time"` or `"details"`, the part the script replaces |
| Card | `#appointment-booking.com-appointments-layout` with `.com-appointments-layout-time` or `-details`, `.com-appointments-layout-day` while the times of a day are shown and `.com-appointments-info-logo` or `-person`; inside it `header.com-appointments-info.com-appointments-header` and `.com-appointments-main` |
| Information column | On step 2 first `a.btn.btn-outline-secondary.com-appointments-back[data-appointments-step]`; the logo `.com-appointments-info-media.com-appointments-info-logo > img.com-appointments-logo`, or the person `.com-appointments-info-media.com-appointments-host` with `img.com-appointments-host-photo` and `p.com-appointments-host-text` (`.com-appointments-host-name`, `.com-appointments-host-role`); then `h1.com-appointments-heading`, `ul.com-appointments-meta` (`li.com-appointments-duration`, `li.com-appointments-location-type`, on step 2 `li.com-appointments-selected` with `.com-appointments-selected-date` and `.com-appointments-selected-time`, and `li.com-appointments-selected-zone`, each with an inline `svg.com-appointments-icon`), `.com-appointments-description` and `p.com-appointments-location` |
| Step title | `h2#appointment-step-title.com-appointments-step-title` (`tabindex="-1"`, receives the focus after a step change) with a visually hidden "Step 1 of 3:" |
| Date and time | `form#appointment-time-form.com-appointments-time-form` (GET) around `#appointment-picker.com-appointments-picker` (layout `default_picker.php`; `.com-appointments-picker-day` and `data-day` once a day is chosen) with `.com-appointments-picker-grid` (calendar and `#appointment-times.com-appointments-times`), `p.form-text.com-appointments-zone-note#appointment-zone-note` and the hidden `timezone`; while the script loads it carries `aria-busy="true"` (progress cursor, a loading bar as `.com-appointments-calendar-nav::after`, the previous times disabled) |
| Time zone | `.com-appointments-zone-line` with the visually hidden `p#appointment-zone-note` and `form#appointment-zone-form.com-appointments-zone-form` (GET): `label.com-appointments-zone-label`, `.com-appointments-zone-row` (`select.form-select.com-appointments-zone-select`, `button.btn.btn-secondary.com-appointments-zone-submit`, hidden while the script runs) and `[data-appointments-zone].com-appointments-zone-picker` (hidden until the script shows it: `button.com-appointments-zone-button` with `.com-appointments-zone-current` and `.com-appointments-zone-now`, the panel `.com-appointments-zone-panel[role=dialog]` with `input.com-appointments-zone-search[role=combobox]`, `.com-appointments-clock` of two `button.com-appointments-clock-option[aria-pressed]` and `ul.com-appointments-zone-list[role=listbox]` of `li.com-appointments-zone-option`). The manage page keeps `form.com-appointments-zone` |
| Calendar | `section.com-appointments-calendar` with `nav.com-appointments-calendar-nav` (arrow buttons `a.btn.btn-outline-secondary.com-appointments-calendar-prev` / `-next` with `aria-label`, an inline chevron `svg.com-appointments-icon`; the way back from the current month is `span.disabled`; `h3.com-appointments-calendar-title`) and `table.com-appointments-calendar-grid` of always six weeks: a `thead` row of `th.com-appointments-weekday > abbr[title]` from Monday, `td.com-appointments-day-cell` (empty cells add `.com-appointments-day-empty`) holding `a.com-appointments-day.com-appointments-day-available` (selected day: `.com-appointments-day-selected`, `aria-current="date"`) or `span.com-appointments-day.com-appointments-day-unavailable` |
| Times | `a.btn.com-appointments-back.com-appointments-times-back` (back to the calendar, narrow columns only), `fieldset.com-appointments-slots` with `legend.com-appointments-slots-legend` and `.com-appointments-slot-list` of `div.form-check.com-appointments-slot` (`input.form-check-input.com-appointments-slot-input[type=radio][name=start]`, visually hidden; `label.form-check-label.com-appointments-slot-time`, the visible time button; `button.btn.btn-primary.com-appointments-slot-next`, shown for the checked time only), or `p.com-appointments-empty` |
| Details | `form#appointment-details-form.com-appointments-form.com-appointments-booking` (POST) with `section#appointment-details.com-appointments-step.com-appointments-step-details` and the hidden `booking[start]` and `booking[timezone]` |
| Form | `form.com-appointments-form` (manage pages `.com-appointments-manage-form`); on the booking page the fields stand in `.com-appointments-fields`, one field per row, one `div.com-appointments-field` per field with `label.form-label`, `input.form-control` / `textarea.form-control` / `select.form-select` and `div.form-text.com-appointments-field-description`; choice fields are `fieldset.com-appointments-field.com-appointments-field-group` with `legend.form-label` and `div.form-check` entries; telephone fields are `div.input-group.com-appointments-tel` with `select.com-appointments-tel-country` or, for a fixed code (`.com-appointments-tel-fixed`), `span.input-group-text.com-appointments-tel-prefix` (`img.com-appointments-tel-flag`, `.com-appointments-tel-code`), and `input.com-appointments-tel-number`, the fixed one in `.com-appointments-field-phone`; guests in `details.com-appointments-guests` with `textarea#booking-guests[data-appointments-guests]`, which the script hides behind `.com-appointments-chips` (`ul.com-appointments-chip-list` of `li.com-appointments-chip`, invalid ones `.is-invalid`, each with `button.com-appointments-chip-remove`, and `input.com-appointments-chip-entry`) |
| Privacy notice | `p.com-appointments-privacy-note` with `a.com-appointments-privacy-link`, the last element of the information column |
| Checkboxes | `div.form-check` with `input.form-check-input` and `label.form-check-label` (no margin, centred with the box); with a checkbox mode the consent `.com-appointments-privacy > .com-appointments-consent` with the link `a.com-appointments-privacy-link` and `p.form-text.com-appointments-consent-text`, confirmation `.com-appointments-confirm` |
| Errors | The control gets `.is-invalid`, `aria-invalid="true"` and `aria-describedby`; the message is `div.invalid-feedback.com-appointments-field-error` with the id `booking-<field>-error` or `manage-<field>-error` (the browser's check before sending: `<control id>-check`) |
| Actions | `div.com-appointments-actions` with `button.btn.btn-primary` (cancel and erase: `btn-danger`) |
| Confirmation | `.com-appointments-card.com-appointments-confirmation-card.com-appointments-state-<0/1/2>` with `span.com-appointments-confirmation-mark`, `h1.com-appointments-heading` ("Appointment confirmed", "Appointment received" while it awaits approval, "Appointment cancelled"), `p.com-appointments-status`, `h2.com-appointments-title`, `dl.com-appointments-summary`, `p.com-appointments-join > a.btn.btn-primary`, and `.com-appointments-confirmation-actions` with `a.com-appointments-calendar-add` (the `.ics` download), `a.com-appointments-reschedule` and `a.com-appointments-cancel`, then `p.com-appointments-confirmation-note` |
| Summary | `h2.com-appointments-title`, `dl.com-appointments-summary` (date, time, time zone, location or email address), `p.com-appointments-status` |

**Steps and addresses.** Step 1 is the event type's address plus `month` (`YYYY-MM`), and `day`
(`YYYY-MM-DD`) once a day is chosen; step 2 adds `start`, the chosen time in UTC
(`YYYY-MM-DD HH:MM:SS`), and takes month and day from it; every address carries the displayed
`timezone`. A malformed `start` is a 400. The **Next** button of a time sends the GET form
`#appointment-time-form` to the step 2 address; the details are sent as before with
`task=booking.submit`. The confirmation offers the appointment as an `.ics` file through
`task=booking.ics&id=<booking>` (a link of an earlier confirmation is refused), with the same UID and sequence as the attachment of the confirmation email, and
the signed reschedule and cancel links of that email; like the confirmation page itself it is
only available to the browser session that made the booking. When the links cannot be built (an
invalid public site address in **Options**), only the `.ics` download is shown.

**Booking script and slot endpoint.** `media/com_appointments/js/booking.js` is registered as the
web asset `com_appointments.booking` (deferred, no dependency) and loaded by the booking page. It
adds `.com-appointments-js` to `.com-appointments`. A day or month link (`a[data-appointments-load]`)
and the zone selection request the same address plus `format=raw` and replace `#appointment-picker`
with the response; that response is the layout `default_picker.php` alone, so a template override
of it applies to the page and to the update, and the script never formats a date or time itself.
The **Next** button and the **Back** link (`a[data-appointments-step]`) request the full page of
their address and replace `[data-appointments-view]` with its counterpart. Each change is written
into the browser history (`history.pushState`; the back and forward buttons load the step of their
address the same way), the focus moves to the new times or the new step title, and a polite live
region announces the times legend or the step title. Details typed into step 2 are kept when the
visitor goes back for another time. Without JavaScript every link and the time form load the full
page and land at `#appointment-picker` or `#appointment-booking`; a refused submission lands at
`#appointment-details` when its messages stand at the fields. The script writes no text of its
own, contains no inline code and makes no third-party request, so the page works under a Content
Security Policy without `unsafe-inline`. When a background request fails, it falls back to the
normal page request.

### Email templates

The installer creates eighteen editable site-wide templates in each shipped language: confirmation,
pending, approved, reschedule, cancellation, reminder, host notice and erasure notice, plus the six
guest templates `guest_confirmation`, `guest_pending`, `guest_approved`, `guest_reschedule`,
`guest_cancellation` and `guest_reminder`, and the four host templates `host_approved`,
`host_reschedule`, `host_cancellation` and `host_reminder`. Each recipient gets the template written
for them: the booking person the plain purpose, each additional guest the guest template (greeted
neutrally, never with the booking person's name), and the host `host_notice` for a new or pending
booking, the host template for approval, reschedule, cancellation and reminder (host wording with
the booking person's details, never a mail addressed to the booking person), and the erasure notice.
An update adds missing templates, including the guest and host templates on an existing
installation, and never overwrites a template you edited. If you customised the invitee templates
of those purposes for the host as well, adapt the new host templates.
An event-specific template takes precedence over the published site-wide template for that purpose
and language. If neither is available, the corresponding English template is used. Updating the
component preserves existing templates rather than overwriting operator changes. Notification
labels are installed privately from the same shipped language files, so a stored recipient
language does not depend on that Joomla language pack being installed.

Templates are plain text. Placeholders are written `{name}` and replaced in one pass. A detail line
that holds only a label and one placeholder (`Location: {location}`) is left out when the value is
empty, so a telephone appointment shows no empty location:

| Placeholder | Value |
|---|---|
| `{event_title}`, `{description}`, `{location}`, `{location_text}`, `{location_type}` | The event type |
| `{host_name}`, `{host_email}` | The host |
| `{invitee_name}` | Full name of the booking person ("first last"), unchanged since earlier versions |
| `{invitee_first_name}`, `{invitee_last_name}` | First and last name of the booking person |
| `{invitee_email}`, `{invitee_phone}`, `{invitee_timezone}`, `{invitee_language}` | Contact data of the booking person |
| `{guest_emails}` | All additional guests, one per line |
| `{conference_url}` | The joining link (the host's moderator link in the host's mail) |
| `{custom_fields.ID}` | One answer, ID being the custom field ID; `{custom_fields}` all answers as JSON |
| `{start}`, `{end}`, `{timezone}` | The appointment in the recipient's zone, with the invitee's stored locale or the host's Joomla profile settings (a host without a profile language gets the site's default language) |
| `{state_label}` | The translated status |
| `{consent_utc}`, `{consent_version}` | The consent proof (UTC time and label version) with **Privacy notice on the booking form** *Consent checkbox*, otherwise empty |
| `{start_utc}`, `{end_utc}`, `{state}`, `{id}`, `{uid}`, `{sequence}` and the other booking columns | Raw booking values |
| `{reschedule_url}`, `{cancel_url}`, `{erase_url}` | Signed links, in mails to the booking person only |
| `{guest_email}`, `{guest_remove_url}` | In a guest template only: the guest's own address and the signed link that removes only that address |

Use only single-line values in subjects.

Only primary-invitee messages append the action-specific reschedule, cancellation and erasure
links, even if a customized template omits their placeholders. Every guest message appends the
guest's own removal link the same way. The host does not receive these capabilities, and a guest
never receives the invitee's. Feed credentials are never included in mail.

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
of the booking row. Both remove the full, first and last name, email, phone, invitee zone/language, guest addresses,
custom answers, cancellation text and conference metadata. A future appointment is cancelled
first. Its host-only cancellation contains just the slot and calendar identifiers, not the former
invitee details or the erasure reason. The provider is asked to release its room after the erasure
transaction commits; teardown failures are reported without undoing erasure.

**Guests.** Every mail to an additional guest carries a signed link
(`index.php?option=com_appointments&task=guest.confirm&id=…&signature=…`) with its own signing key.
It is bound to the booking, the guest's address and the booking's current version; the address
itself is not part of the URL. The page shows the appointment and the guest's address and a
confirmation button (POST with the Joomla CSRF token, task `guest.remove`); only then is that one
address removed from `guest_emails`. The booking, the booking person's data, the other guests and
the booking version stay unchanged, so the other people's links keep working. A used, tampered or
outdated link and a link of an erased booking answer 404; the same browser sees its receipt again.
Both steps are rate limited. A calendar entry the guest already accepted stays in their calendar,
which the page says.

The deletion audit stores only booking ID, action (`anonymise`, `delete` or `remove_guest`), path,
timestamp and, for administrator actions, the acting operator's Joomla user ID. It contains no invitee data, but that staff identifier is
accountability data and has its own retention. Staff data-subject requests about `actor_user_id`
entries must be answered by the operator from `#__appointments_audit`; the invitee privacy export
deliberately excludes that domain. The system check reports the oldest retained audit timestamp.

### Booking administration and system checks

The booking list and CSV use the same per-event edit permission. Filters combine event type, state
(including pending `0`), an inclusive UTC start-date range and a search in the invitee's full,
first and last name and email address. The list sorts by start (default, latest first) or by last
name, then first name. The CSV has the columns `invitee_name`, `invitee_first_name` and
`invitee_last_name`, and the booking detail shows first and last name. CSV export
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
| Display: information column | *Company logo and appointment* (logo), *Person with photo* (photo, name, role) or *Appointment only* (no image) beside the calendar; see "Page markup and styling" |
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

The workflow also tests the actual published `appointments-v1.3.0` package as the starting point
for an update on Joomla 5.4 and Joomla 6. It seeds prior-version schedules, event types and
plaintext bookings, installs the exact candidate ZIP through Joomla's CLI, then verifies the
database migration, preservation and readability of booking data during the seven-day migration
window, and legacy-feed compatibility. It expires the authentic migration token and exercises the
administrator encrypt-now action against those same upgraded bookings, requiring `v2:` ciphertext
and exactly one audit row per booking. The upgraded installations also run booking and strict
team-calendar behavior tests; Joomla 6 runs the real CalDAV clients. The transition-specific lane
reports an explicit skip once the repository's latest-release-only policy retires the v1.3.0 asset.

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
