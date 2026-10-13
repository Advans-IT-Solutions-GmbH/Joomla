# Data model

All tables use the `#__` prefix. All timestamps are `DATETIME` in **UTC**, named with the `_utc`
suffix so a wrong value is visible in a code review. `utf8mb4_unicode_ci`, InnoDB.

The schema below is the target. Write it as `sql/install.mysql.utf8.sql`, with a matching
`sql/uninstall.mysql.utf8.sql` that drops the tables in reverse dependency order, and add
`sql/updates/mysql/<version>.sql` for every later change. Never alter a shipped install file to
change an existing installation.

## `#__appointments_hosts`

One row per host, keyed by the Joomla user id, created on demand the first time a host is given an
event type or receives a booking. It exists because two things need a row that is unique per host,
and neither a schedule nor an event type is one: a host may own several schedules, and an event
type's `host_user_id` is not required to be the owner of its schedule.

| Column | Type | Notes |
|---|---|---|
| `user_id` | `INT UNSIGNED NOT NULL` | primary key, the Joomla user id |
| `feed_token` | `CHAR(64) NOT NULL` | hex, 32 random bytes, **no default** |
| `created_utc`, `modified_utc` | `DATETIME NOT NULL` | |

Unique `feed_token`.

Its two jobs:

1. **The lock target of the booking transaction.** A booking selects its host's row `FOR UPDATE`
   before it validates and inserts, so two submissions for one host are serialised. See
   `references/architecture.md`, section "Concurrency".
2. **The token of the host's combined feed.** `references/calendar-and-ics.md` requires a feed per
   event type and one per host, each independently rotatable, so the host feed cannot borrow or
   derive the token of one of the host's event types.

## `#__appointments_eventtypes`

The bookable thing.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `asset_id` | `INT UNSIGNED NOT NULL DEFAULT 0` | Joomla ACL |
| `title` | `VARCHAR(255) NOT NULL` | |
| `alias` | `VARCHAR(255) NOT NULL` | unique, used in the URL |
| `description` | `MEDIUMTEXT` | rich text |
| `duration_minutes` | `SMALLINT UNSIGNED NOT NULL` | |
| `slot_step_minutes` | `SMALLINT UNSIGNED NOT NULL` | grid the start times sit on |
| `buffer_before_minutes` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | |
| `buffer_after_minutes` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | |
| `min_notice_minutes` | `INT UNSIGNED NOT NULL DEFAULT 0` | |
| `horizon_days` | `SMALLINT UNSIGNED NOT NULL DEFAULT 30` | 0 means use `horizon_start_utc`/`horizon_end_utc` |
| `horizon_start_utc`, `horizon_end_utc` | `DATETIME NULL` | fixed range alternative |
| `max_per_day`, `max_per_week` | `SMALLINT UNSIGNED NOT NULL DEFAULT 0` | 0 = unlimited |
| `schedule_id` | `INT UNSIGNED NOT NULL` | → `#__appointments_schedules` |
| `host_user_id` | `INT UNSIGNED NOT NULL` | Joomla user who hosts and is notified |
| `location_type` | `VARCHAR(32) NOT NULL` | see `references/scope.md` |
| `location_text` | `TEXT` | address or instructions for the non-conference types |
| `conference_provider` | `VARCHAR(64) NULL` | adapter name, null = none |
| `conference_params` | `JSON NULL` | per-event-type adapter settings |
| `requires_approval` | `TINYINT(1) NOT NULL DEFAULT 0` | |
| `retention_days` | `SMALLINT UNSIGNED NULL` | null = use the component default |
| `colour` | `VARCHAR(7)` | |
| `access` | `INT UNSIGNED NOT NULL` | Joomla view level |
| `language` | `CHAR(7) NOT NULL DEFAULT '*'` | |
| `published` | `TINYINT(1) NOT NULL DEFAULT 1` | |
| `ordering` | `INT NOT NULL DEFAULT 0` | |
| `feed_token` | `CHAR(64) NOT NULL` | hex, 32 random bytes, **no default**: a default of `''` would give the first row inserted by a forgetful code path an empty bearer credential, and the unique index would only catch the second one |
| `created_utc`, `modified_utc` | `DATETIME NOT NULL` | |
| `checked_out`, `checked_out_time` | Joomla standard | |

Indexes: unique `alias`, unique `feed_token`, index `(published, access, language)`.

## `#__appointments_schedules`

A named weekly pattern, reusable across event types.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `title` | `VARCHAR(255) NOT NULL` | |
| `timezone` | `VARCHAR(64) NOT NULL` | IANA name, e.g. `Europe/Zurich` |
| `owner_user_id` | `INT UNSIGNED NOT NULL` | |
| `published` | `TINYINT(1) NOT NULL DEFAULT 1` | |
| `created_utc`, `modified_utc` | `DATETIME NOT NULL` | |

## `#__appointments_schedule_rules`

One row per interval. A weekday with no row is unavailable.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `schedule_id` | `INT UNSIGNED NOT NULL` | |
| `weekday` | `TINYINT UNSIGNED NULL` | 1 = Monday … 7 = Sunday, per ISO-8601. Null when `specific_date` is set. |
| `specific_date` | `DATE NULL` | a date-specific override |
| `start_time`, `end_time` | `TIME NOT NULL` | **local to the schedule's time zone**, never UTC |
| `is_unavailable` | `TINYINT(1) NOT NULL DEFAULT 0` | a date-specific "closed all day" row |

Index `(schedule_id, weekday)`, index `(schedule_id, specific_date)`.

A `specific_date` row replaces the weekly rows for that date entirely. If a date has only an
`is_unavailable` row, the day is closed.

## `#__appointments_blocked`

Absences, independent of event types.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `user_id` | `INT UNSIGNED NOT NULL` | whose time is blocked |
| `title` | `VARCHAR(255)` | internal only, never shown publicly |
| `start_utc`, `end_utc` | `DATETIME NOT NULL` | |
| `created_utc` | `DATETIME NOT NULL` | |

Index `(user_id, start_utc, end_utc)`.

## `#__appointments_bookings`

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `uid` | `CHAR(64) NOT NULL` | the iCalendar `UID`, stable for the booking's whole life |
| `sequence` | `INT UNSIGNED NOT NULL DEFAULT 0` | iCalendar `SEQUENCE`, incremented on every change |
| `eventtype_id` | `INT UNSIGNED NOT NULL` | |
| `host_user_id` | `INT UNSIGNED NOT NULL` | copied at booking time |
| `start_utc`, `end_utc` | `DATETIME NOT NULL` | the meeting itself, buffers excluded |
| `state` | `TINYINT NOT NULL` | 0 pending, 1 confirmed, 2 cancelled. 0 and 1 both hold the slot, see `references/scope.md`, "Booking lifecycle" |
| `invitee_name` | `VARCHAR(255)` | nullable after anonymisation |
| `invitee_email` | `VARCHAR(255)` | |
| `invitee_phone` | `VARCHAR(64)` | |
| `invitee_timezone` | `VARCHAR(64)` | what they saw, for the e-mail |
| `invitee_language` | `CHAR(7)` | which language to write to them in |
| `guest_emails` | `TEXT` | newline separated |
| `custom_fields` | `JSON` | answers, keyed by field id |
| `conference_provider` | `VARCHAR(64) NULL` | |
| `conference_url` | `TEXT NULL` | |
| `conference_room` | `VARCHAR(255) NULL` | identifier for teardown |
| `conference_state` | `VARCHAR(16) NULL` | null = no conference, `ok`, `failed` |
| `conference_error` | `VARCHAR(255) NULL` | the short diagnostic shown next to the retry action; never a credential, a token or a URL |
| `cancel_reason` | `TEXT NULL` | |
| `reminder_sent_utc` | `JSON NULL` | which offsets already went out |
| `anonymised_utc` | `DATETIME NULL` | set when the personal fields are erased |
| `created_utc`, `modified_utc` | `DATETIME NOT NULL` | |
| `created_ip` | `VARBINARY(16) NULL` | anti-bot only, erased with the booking |

Indexes:
- Unique `uid`.
- **Unique `(eventtype_id, start_utc, state_active)`** where `state_active` is a generated column
  that is `1` for `state IN (0,1)` and `NULL` otherwise. MySQL and MariaDB ignore `NULL` in a unique
  index, so cancelled bookings do not block the slot while active ones do. The column is `STORED`,
  not `VIRTUAL`, because support for indexing a virtual generated column differs between MySQL and
  MariaDB versions. This index covers the identical slot of one event type and nothing else: do not
  replace it with a PHP-side check, and do not present it as the whole concurrency guarantee.
  Overlapping intervals and the per-host caps are held by the lock described in
  `references/architecture.md`, section "Concurrency".
- Index `(host_user_id, start_utc)`, index `(invitee_email)`, index `(state, start_utc)`. The first
  one is what the overlap check inside the locked transaction reads.

There is deliberately **no `asset_id`** on this table. A booking inherits the permissions of its
event type; see `references/scope.md`, "Administration". Adding one later would mean an `#__assets`
row per booking, an extra insert in the hot path and an orphan asset after every erasure.

### Rescheduling updates the row

**A reschedule changes this row; it never inserts a successor.** The times change, `sequence` is
raised, `modified_utc` is touched, `uid` and `id` stay. There is no "rescheduled away" state and no
chain to a previous booking, because both would contradict the calendar contract:

- `references/calendar-and-ics.md` requires one `UID` for the whole life of a booking, so that a
  mail client replaces the entry instead of creating a second one. A successor row would need the
  same `uid`, which the unique index on `uid` refuses, and a new `uid` would produce exactly the
  duplicate entry the contract exists to prevent.
- The subscription feed serves one `VEVENT` per active booking. Two rows with one `UID` in one
  `VCALENDAR` are not valid iCalendar.

The unique slot index does the rest without extra code: moving a booking onto a slot that another
active booking already holds makes the `UPDATE` fail with a duplicate key, which the reschedule page
presents as "this time was just taken", exactly like a new booking. Touching `modified_utc` also
invalidates every signed link that was issued before the change, which is the behaviour
`references/architecture.md` asks for.

The previous time of a booking is not kept. Keeping it would mean keeping a second copy of a
personal appointment for no shipped feature, and the retention and erasure paths would have to erase
it too.

## `#__appointments_fields`

Custom booking form fields per event type.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `eventtype_id` | `INT UNSIGNED NOT NULL` | |
| `type` | `VARCHAR(32) NOT NULL` | `text`, `textarea`, `number`, `tel`, `email`, `url`, `radio`, `checkboxes`, `checkbox`, `date` |
| `label`, `description` | `VARCHAR(255)` / `TEXT` | |
| `options` | `JSON NULL` | for the choice types |
| `required` | `TINYINT(1) NOT NULL DEFAULT 0` | |
| `ordering` | `INT NOT NULL DEFAULT 0` | |
| `published` | `TINYINT(1) NOT NULL DEFAULT 1` | |

Labels and descriptions are site content, so they are translated through Joomla's normal content
language handling, not through the component's `.ini` files.

## `#__appointments_templates`

E-mail templates. One row per (event type or site-wide) × purpose × language.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `eventtype_id` | `INT UNSIGNED NOT NULL DEFAULT 0` | `0` = site-wide fallback |
| `purpose` | `VARCHAR(32) NOT NULL` | `confirmation`, `pending`, `approved`, `reschedule`, `cancellation`, `reminder`, `host_notice`, `erasure_notice` |
| `language` | `CHAR(7) NOT NULL` | |
| `subject` | `VARCHAR(255) NOT NULL` | |
| `body` | `MEDIUMTEXT NOT NULL` | |
| `published` | `TINYINT(1) NOT NULL DEFAULT 1` | |

Unique `(eventtype_id, purpose, language)`.

The site-wide scope uses the sentinel `0` and not `NULL`: MySQL and MariaDB ignore `NULL` in a
unique index, so a nullable column would allow two site-wide rows for the same purpose and
language, and the fallback lookup would silently pick one of them. The booking table uses the same
property deliberately, in the opposite direction, for `state_active`.

## `#__appointments_audit`

Deletion evidence. **Contains no data of an invitee.** A test asserts the exact column set.
`actor_user_id` is the one operator identifier it keeps, for the `admin` path only; see
`references/privacy-and-deletion.md`, section "What the audit table is, and what it is not".

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `booking_id` | `INT UNSIGNED NOT NULL` | may point at a deleted row |
| `action` | `VARCHAR(32) NOT NULL` | `anonymised`, `deleted` |
| `path` | `VARCHAR(32) NOT NULL` | `self_service`, `privacy_request`, `retention`, `admin` |
| `actor_user_id` | `INT UNSIGNED NULL` | set only for `admin` |
| `occurred_utc` | `DATETIME NOT NULL` | |

## `#__appointments_ratelimit`

Short-lived counters for the public endpoints.

| Column | Type | Notes |
|---|---|---|
| `id` | `INT UNSIGNED AUTO_INCREMENT` | |
| `bucket` | `VARCHAR(64) NOT NULL` | endpoint plus hashed IP |
| `window_utc` | `DATETIME NOT NULL` | |
| `hits` | `INT UNSIGNED NOT NULL DEFAULT 0` | |

Unique `(bucket, window_utc)`. The retention task deletes rows older than one day. The IP is
hashed with a key from `KeyProvider`, so the table is not a log of visitors.

## Signing keys

`KeyProvider` derives one key per purpose from the **Joomla site secret** of the installation:
`hash_hmac('sha256', 'com_appointments:' . $purpose, $siteSecret, true)`. `SignedLink` and the
rate-limit hash each get their own key, so a key that signs a link can never verify a rate-limit
bucket.

Nothing is stored, which is the point:

- A generated secret in the component parameters is **dropped by Joomla's Options screen** unless it
  is declared in the configuration form, because `com_config` keeps only the parameters the form
  declares. Losing it would silently invalidate every reschedule, cancel and erasure link that is
  already in someone's mailbox.
- Declaring it as a form field would print the secret into the HTML of the Options screen, which is
  exactly what the architecture's "no secret is ever written anywhere" rule forbids.
- The site secret is generated by Joomla at installation, is unique per site, is not rotated in
  normal operation and is never rendered into a page. `com_dmarcreports` derives its encryption key
  the same way, so this is the house pattern rather than a new idea.

`KeyProvider` takes the secret as a constructor argument, not the application, so it is a pure class
and is unit tested without a Joomla bootstrap.
