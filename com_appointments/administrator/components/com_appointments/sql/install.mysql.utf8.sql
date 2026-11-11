--
-- Schema for com_appointments.
--
-- Every timestamp is UTC and carries the `_utc` suffix, so a value in local time
-- is visible in a code review. Times that belong to a weekly schedule are the
-- exception: they are TIME columns local to the schedule's own time zone and are
-- named without the suffix.
--
-- Comments stand between statements, never inside one. Joomla's statement
-- splitter strips comments, so an inline comment would work, but keeping the
-- statements free of them makes each one readable as the plain DDL it is.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

--
-- A named weekly pattern, reusable across event types. `timezone` is the IANA
-- name that decides what a time such as Tuesday 14:00 means.
--
CREATE TABLE IF NOT EXISTS `#__appointments_privacy_outcomes` (
    `request_id` INT UNSIGNED NOT NULL PRIMARY KEY,
    `complete` TINYINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__appointments_privacy_cleanup` (
    `request_id` INT UNSIGNED NOT NULL,
    `booking_id` INT UNSIGNED NOT NULL,
    `cleanup_key` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `guest` TINYINT NOT NULL,
    `payload` TEXT NOT NULL,
    `retry_order` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`request_id`, `booking_id`, `cleanup_key`),
    KEY `idx_privacy_retry` (`request_id`, `retry_order`, `booking_id`, `cleanup_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__appointments_privacy_staff` (
    `request_id` INT UNSIGNED NOT NULL PRIMARY KEY,
    `payload` TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__appointments_schedules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
    `owner_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `published` TINYINT(1) NOT NULL DEFAULT 1,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- One row per interval; a weekday without a row is unavailable. `weekday` is
-- 1 = Monday to 7 = Sunday per ISO-8601 and is NULL when `specific_date` is set.
-- `start_time` and `end_time` are local to the schedule's time zone, never UTC.
-- A `specific_date` row replaces the weekday rows for that date entirely.
--
CREATE TABLE IF NOT EXISTS `#__appointments_schedule_rules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `schedule_id` INT UNSIGNED NOT NULL,
    `weekday` TINYINT UNSIGNED DEFAULT NULL,
    `specific_date` DATE DEFAULT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `is_unavailable` TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_schedule_weekday` (`schedule_id`, `weekday`),
    KEY `idx_schedule_date` (`schedule_id`, `specific_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- One row per host, keyed by the Joomla user id. It does two jobs that both
-- need a row that is unique per host, which neither a schedule nor an event
-- type is:
--
-- 1. It is the lock target of the booking transaction. A booking selects its
--    host's row FOR UPDATE before it validates and inserts, so two submissions
--    for one host are serialised and the second sees the first one's booking.
--    A schedule row cannot serve for this: a host may own several schedules,
--    and an event type's host is not required to be the owner of its schedule,
--    so two submissions could lock two different rows and both pass.
-- 2. It holds the token of the host's combined feed. The contract wants every
--    feed token independently rotatable, so the host feed cannot borrow or
--    derive the token of one of the host's event types.
--
-- The row is created on demand, the first time a host is given an event type or
-- receives a booking.
--
CREATE TABLE IF NOT EXISTS `#__appointments_hosts` (
    `user_id` INT UNSIGNED NOT NULL,
    `feed_token` CHAR(67) NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `idx_host_feed_token` (`feed_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- The bookable thing. `horizon_days` = 0 means the fixed range in
-- `horizon_start_utc`/`horizon_end_utc` is used instead of a rolling horizon.
-- `max_per_day` and `max_per_week` = 0 mean unlimited. `retention_days` NULL
-- means the component default applies.
--
-- `feed_token` has no default on purpose. It holds a keyed digest ("h1:" plus
-- HMAC-SHA256 in hex over scope, id and token, key derived from the site secret) of a bearer credential
-- of 32 random bytes, never the credential itself (FeedToken), and a
-- default of '' would hand an empty one to the first row inserted by a code
-- path that forgot to generate it; the unique index would only catch the
-- second such row. Without a default, that insert fails instead. The same
-- applies to `feed_token` of `#__appointments_hosts`.
--
CREATE TABLE IF NOT EXISTS `#__appointments_eventtypes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `asset_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `alias` VARCHAR(255) NOT NULL DEFAULT '',
    `description` MEDIUMTEXT,
    `duration_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `slot_step_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `buffer_before_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `buffer_after_minutes` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `min_notice_minutes` INT UNSIGNED NOT NULL DEFAULT 0,
    `horizon_days` SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `horizon_start_utc` DATETIME DEFAULT NULL,
    `horizon_end_utc` DATETIME DEFAULT NULL,
    `max_per_day` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `max_per_week` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `schedule_id` INT UNSIGNED NOT NULL,
    `host_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `location_type` VARCHAR(32) NOT NULL DEFAULT 'custom_text',
    `location_text` TEXT,
    `conference_provider` VARCHAR(64) DEFAULT NULL,
    `conference_params` JSON DEFAULT NULL,
    `requires_approval` TINYINT(1) NOT NULL DEFAULT 0,
    `retention_days` SMALLINT UNSIGNED DEFAULT NULL,
    `colour` VARCHAR(7) NOT NULL DEFAULT '',
    `info_layout` VARCHAR(16) NOT NULL DEFAULT 'logo',
    `info_logo` VARCHAR(2048) NOT NULL DEFAULT '',
    `info_photo` VARCHAR(2048) NOT NULL DEFAULT '',
    `info_name` VARCHAR(255) NOT NULL DEFAULT '',
    `info_role` VARCHAR(255) NOT NULL DEFAULT '',
    `info_title` VARCHAR(16) NOT NULL DEFAULT 'card',
    `calendar_id` INT UNSIGNED DEFAULT NULL,
    `access` INT UNSIGNED NOT NULL DEFAULT 1,
    `language` CHAR(7) NOT NULL DEFAULT '*',
    `published` TINYINT(1) NOT NULL DEFAULT 1,
    `ordering` INT NOT NULL DEFAULT 0,
    `feed_token` CHAR(67) NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_alias` (`alias`),
    UNIQUE KEY `idx_feed_token` (`feed_token`),
    KEY `idx_published_access_language` (`published`, `access`, `language`),
    KEY `idx_schedule` (`schedule_id`),
    FOREIGN KEY (`schedule_id`) REFERENCES `#__appointments_schedules` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Absences, independent of event types. `title` is internal and is never shown
-- to a visitor.
--
CREATE TABLE IF NOT EXISTS `#__appointments_blocked` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `start_utc` DATETIME NOT NULL,
    `end_utc` DATETIME NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_user_period` (`user_id`, `start_utc`, `end_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- The bookings.
--
-- `uid` is the iCalendar UID and stays stable for the whole life of a booking;
-- `sequence` is the iCalendar SEQUENCE and is raised on every change.
-- `start_utc`/`end_utc` are the meeting itself, buffers are not part of it.
-- `state`: 0 pending, 1 confirmed, 2 cancelled.
-- `invitee_name` is the full name ("first last"); `invitee_first_name` and
-- `invitee_last_name` hold its two parts. All three are personal data and are
-- erased together.
--
-- `consent_utc` and `consent_version` are set only in the consent mode of the
-- booking form: when the person ticked the consent checkbox and the sha256 of
-- mode, language and the exact label text. They are proof of the consent, not
-- data of the person, and stay on an anonymised row.
--
-- A reschedule updates this row in place: the times change, `sequence` is
-- raised and `modified_utc` is touched. It does not insert a successor row,
-- because the calendar contract keeps one UID for the life of a booking, and a
-- second row with the same UID would break the unique index on `uid` as well as
-- the subscription feed, where two VEVENTs with one UID are not valid. The
-- unique slot index below also does the work for free: moving a booking onto a
-- slot another active booking already holds fails the UPDATE.
-- There is no IP address column, deliberately. The anti-bot timing check
-- compares the moment the form was rendered with the moment it was submitted
-- and persists nothing, and the rate limiter keeps only a hash keyed with
-- KeyProvider, in #__appointments_ratelimit, which the retention task empties
-- after a day. A column here would keep the raw address for the life of the
-- booking, which the component's privacy contract rules out.
--
-- `conference_state` is NULL for an event type without a conference, `ok` once
-- a link exists and `failed` when the provider could not produce one.
-- `conference_error` holds the short diagnostic the administrator sees next to
-- the retry action; it never holds a credential, a token or a URL, because the
-- reason has to be readable in a list view without leaking anything.
--
-- `state_active` is a generated column that is 1 while the booking holds its
-- slot and NULL otherwise. MySQL and MariaDB ignore NULL in a unique index, so
-- a cancelled booking frees the slot again while a pending or confirmed one
-- blocks it. Of
-- two simultaneous submissions for the identical slot of one event type, the
-- second therefore fails in the database and not in PHP. Do not replace it with
-- a check in PHP.
--
-- This index covers the identical slot and nothing more. Two overlapping
-- bookings have two different start times, and one host can serve several event
-- types, so overlaps and the per-host caps are held by a row-level lock on the
-- host inside the booking transaction, not here. See the component architecture,
-- section Concurrency.
--
-- The column is STORED and not VIRTUAL on purpose: both servers index a stored
-- generated column, while support for indexing a virtual one differs between
-- MySQL and MariaDB versions. The extra byte per row is irrelevant next to
-- losing the guarantee on one of the two servers.
--
CREATE TABLE IF NOT EXISTS `#__appointments_bookings` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uid` CHAR(64) NOT NULL,
    `sequence` INT UNSIGNED NOT NULL DEFAULT 0,
    `eventtype_id` INT UNSIGNED NOT NULL,
    `host_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `start_utc` DATETIME NOT NULL,
    `end_utc` DATETIME NOT NULL,
    `state` TINYINT NOT NULL DEFAULT 0,
    `state_active` TINYINT(1) GENERATED ALWAYS AS (CASE WHEN `state` IN (0, 1) THEN 1 ELSE NULL END) STORED,
    `invitee_name` TEXT,
    `invitee_first_name` TEXT,
    `invitee_last_name` TEXT,
    `invitee_email` TEXT,
    `invitee_phone` TEXT,
    `invitee_timezone` VARCHAR(64) DEFAULT NULL,
    `invitee_language` CHAR(7) DEFAULT NULL,
    `guest_emails` MEDIUMTEXT,
    `custom_fields` MEDIUMTEXT,
    `consent_utc` DATETIME DEFAULT NULL,
    `consent_version` CHAR(64) DEFAULT NULL,
    `conference_provider` VARCHAR(64) DEFAULT NULL,
    `conference_url` TEXT,
    `conference_room` VARCHAR(255) DEFAULT NULL,
    `conference_state` VARCHAR(16) DEFAULT NULL,
    `conference_error` VARCHAR(255) DEFAULT NULL,
    `cancel_reason` MEDIUMTEXT,
    `reminder_sent_utc` JSON DEFAULT NULL,
    `anonymised_utc` DATETIME DEFAULT NULL,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_uid` (`uid`),
    UNIQUE KEY `idx_active_slot` (`eventtype_id`, `start_utc`, `state_active`),
    KEY `idx_host_start` (`host_user_id`, `start_utc`),
    KEY `idx_state_start` (`state`, `start_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Custom booking form fields per event type. Labels and descriptions are site
-- content and are translated through Joomla's content language handling, not
-- through the component's INI files.
--
CREATE TABLE IF NOT EXISTS `#__appointments_fields` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `eventtype_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(32) NOT NULL DEFAULT 'text',
    `label` VARCHAR(255) NOT NULL DEFAULT '',
    `description` TEXT,
    `options` JSON DEFAULT NULL,
    `required` TINYINT(1) NOT NULL DEFAULT 0,
    `ordering` INT NOT NULL DEFAULT 0,
    `published` TINYINT(1) NOT NULL DEFAULT 1,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_eventtype_ordering` (`eventtype_id`, `ordering`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- E-mail templates, one row per scope, purpose and language.
--
-- `eventtype_id` = 0 is the site-wide fallback. A sentinel is used instead of
-- NULL because MySQL and MariaDB ignore NULL in a unique index, which would
-- allow two site-wide rows for the same purpose and language and make the
-- fallback lookup pick one of them at random.
--
CREATE TABLE IF NOT EXISTS `#__appointments_templates` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `eventtype_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `purpose` VARCHAR(32) NOT NULL,
    `language` CHAR(7) NOT NULL,
    `subject` VARCHAR(255) NOT NULL DEFAULT '',
    `body` MEDIUMTEXT NOT NULL,
    `published` TINYINT(1) NOT NULL DEFAULT 1,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_scope_purpose_language` (`eventtype_id`, `purpose`, `language`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Deletion evidence. It carries **no data of an invitee** by design, and a test
-- asserts the exact column set, so an added column fails the suite.
-- `booking_id` may point at a row that no longer exists; that is the point.
--
-- `actor_user_id` is the Joomla user id of the administrator who triggered a
-- deletion and is set for the `admin` path only. It is accountability data
-- about a member of the operator's own staff, the same thing Joomla keeps in
-- `#__action_logs.user_id`, so this table is not described as free of personal
-- data: only free of the invitee's. Audit rows are themselves subject to a
-- retention period, enforced by the same Scheduler task.
--
CREATE TABLE IF NOT EXISTS `#__appointments_audit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `booking_id` INT UNSIGNED NOT NULL,
    `action` VARCHAR(32) NOT NULL,
    `path` VARCHAR(32) NOT NULL,
    `actor_user_id` INT UNSIGNED DEFAULT NULL,
    `occurred_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_booking` (`booking_id`),
    KEY `idx_occurred` (`occurred_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Short-lived counters for the public endpoints. The IP address is hashed with
-- a key from KeyProvider, so this table is not a log of visitors, and the
-- retention task deletes rows older than one day.
--
CREATE TABLE IF NOT EXISTS `#__appointments_ratelimit` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket` VARCHAR(64) NOT NULL,
    `window_utc` DATETIME NOT NULL,
    `hits` INT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_bucket_window` (`bucket`, `window_utc`),
    KEY `idx_window` (`window_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Team calendars (CalDAV). Specification: .claude/skills/appointments/references/team-calendars.md.
--
-- Default deny: a calendar is visible to nobody but the holders of a row in
-- `#__appointments_calendar_grants`. There is no public, secret or token address.
--
-- Calendar contents are encrypted. `payload` holds either an XChaCha20-Poly1305
-- ciphertext under the calendar key of `key_version` (enc_scheme 1) or a sealed
-- box to the calendar's public key (enc_scheme 2, written by the booking flow,
-- which holds no user key). The time skeleton in `timing` is encrypted with a
-- server key derived from the Joomla site secret, so the server can compute busy
-- times for the booking page while a database copy alone shows no times.
--
-- `row_mac` is an HMAC with a server key over the security-relevant fields of the
-- row, so a write to the database alone cannot add a grant, swap a public key or
-- move a device to another user without the change being detected.
--

--
-- Site-wide key material that is not secret: the public half of the recovery
-- key. The secret half is shown once and kept offline by the operator.
--
CREATE TABLE IF NOT EXISTS `#__appointments_keyring` (
    `purpose` VARCHAR(32) NOT NULL,
    `public_key` VARBINARY(32) NOT NULL,
    `fingerprint` CHAR(16) NOT NULL,
    `row_mac` BINARY(32) NOT NULL,
    `created_by` INT UNSIGNED NOT NULL,
    `created_utc` DATETIME NOT NULL,
    PRIMARY KEY (`purpose`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- One X25519 key pair per user with a calendar right. The secret key is stored
-- only wrapped: here under a key stretched from the Joomla password (NULL after a
-- password reset), and per device in `#__appointments_devices.usk_wrapped`.
--
CREATE TABLE IF NOT EXISTS `#__appointments_user_keys` (
    `user_id` INT UNSIGNED NOT NULL,
    `key_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `public_key` VARBINARY(32) NOT NULL,
    `password_wrapped` VARBINARY(255) DEFAULT NULL,
    `password_salt` VARBINARY(16) NOT NULL,
    `pwhash_opslimit` INT UNSIGNED NOT NULL,
    `pwhash_memlimit` INT UNSIGNED NOT NULL,
    `device_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `row_mac` BINARY(32) NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    PRIMARY KEY (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- A calendar. Title, description and colour are not encrypted: they are shown in
-- lists that hold no key, so they must not carry confidential text. `uri` is a
-- random, non-secret path segment; access is decided by the grants alone.
--
CREATE TABLE IF NOT EXISTS `#__appointments_calendars` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uri` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `description` TEXT,
    `colour` VARCHAR(7) NOT NULL DEFAULT '',
    `owner_user_id` INT UNSIGNED NOT NULL,
    `allow_external` TINYINT(1) NOT NULL DEFAULT 0,
    `blocks_availability` TINYINT(1) NOT NULL DEFAULT 1,
    `retention_days` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `synctoken` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `key_version` INT UNSIGNED NOT NULL DEFAULT 1,
    `rotation_due` TINYINT(1) NOT NULL DEFAULT 0,
    `grant_epoch` BIGINT UNSIGNED NOT NULL DEFAULT 1,
    `state` TINYINT NOT NULL DEFAULT 1,
    `row_mac` BINARY(32) NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_calendar_uri` (`uri`),
    KEY `idx_calendar_owner` (`owner_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- One row per calendar key version: the calendar's sealing key pair (public key
-- in clear, secret key wrapped with the calendar key of this version) and the
-- calendar key sealed to the recovery key.
--
CREATE TABLE IF NOT EXISTS `#__appointments_calendar_keys` (
    `calendar_id` INT UNSIGNED NOT NULL,
    `key_version` INT UNSIGNED NOT NULL,
    `box_public_key` VARBINARY(32) NOT NULL,
    `box_secret_wrapped` VARBINARY(255) NOT NULL,
    `recovery_wrapped` VARBINARY(255) NOT NULL,
    `recovery_fingerprint` CHAR(16) NOT NULL,
    `row_mac` BINARY(32) NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `retired_utc` DATETIME DEFAULT NULL,
    PRIMARY KEY (`calendar_id`, `key_version`),
    FOREIGN KEY (`calendar_id`) REFERENCES `#__appointments_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Who may do what with a calendar. No row, no access. `role`: 1 free/busy,
-- 2 read, 3 write, 4 manage. `state`: 1 active, 2 active but waiting for the key
-- wrap, 3 suspended (password reset or user blocked).
--
CREATE TABLE IF NOT EXISTS `#__appointments_calendar_grants` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `calendar_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `role` TINYINT UNSIGNED NOT NULL,
    `state` TINYINT UNSIGNED NOT NULL,
    `audience` VARCHAR(8) NOT NULL,
    `granted_by` INT UNSIGNED NOT NULL,
    `client_props` JSON DEFAULT NULL,
    `row_mac` BINARY(32) NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_grant_calendar_user` (`calendar_id`, `user_id`),
    KEY `idx_grant_user` (`user_id`),
    FOREIGN KEY (`calendar_id`) REFERENCES `#__appointments_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- The calendar key of one version, sealed to one user's public key. Only roles
-- that read contents (read, write, manage) hold a wrap; free/busy never does.
--
CREATE TABLE IF NOT EXISTS `#__appointments_calendar_key_wraps` (
    `calendar_id` INT UNSIGNED NOT NULL,
    `key_version` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `user_key_version` INT UNSIGNED NOT NULL,
    `wrapped` VARBINARY(255) NOT NULL,
    `created_utc` DATETIME NOT NULL,
    PRIMARY KEY (`calendar_id`, `key_version`, `user_id`),
    KEY `idx_wrap_user` (`user_id`),
    FOREIGN KEY (`calendar_id`) REFERENCES `#__appointments_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Calendar objects. No time, title or attendee in clear: see the header.
-- `uid_hash` is a keyed hash of the iCalendar UID per calendar, for the
-- no-uid-conflict precondition of RFC 4791. `booking_id` links an object the
-- booking flow wrote, so anonymising the booking can remove it without a key.
--
CREATE TABLE IF NOT EXISTS `#__appointments_calendar_objects` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `calendar_id` INT UNSIGNED NOT NULL,
    `uri` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `uid_hash` BINARY(32) NOT NULL,
    `etag` CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `size` INT UNSIGNED NOT NULL,
    `component` VARCHAR(16) NOT NULL DEFAULT 'VEVENT',
    `enc_scheme` TINYINT UNSIGNED NOT NULL,
    `key_version` INT UNSIGNED NOT NULL,
    `payload` MEDIUMBLOB NOT NULL,
    `timing` BLOB NOT NULL,
    `booking_id` INT UNSIGNED DEFAULT NULL,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_object_uri` (`calendar_id`, `uri`),
    UNIQUE KEY `idx_object_uid` (`calendar_id`, `uid_hash`),
    KEY `idx_object_booking` (`booking_id`),
    FOREIGN KEY (`calendar_id`) REFERENCES `#__appointments_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
--
-- Change journal for WebDAV sync (RFC 6578) and the CTag. `operation`: 1 added,
-- 2 modified, 3 deleted.
--
CREATE TABLE IF NOT EXISTS `#__appointments_calendar_changes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `calendar_id` INT UNSIGNED NOT NULL,
    `synctoken` BIGINT UNSIGNED NOT NULL,
    `uri` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    `operation` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_change_calendar_token` (`calendar_id`, `synctoken`),
    FOREIGN KEY (`calendar_id`) REFERENCES `#__appointments_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Cached busy intervals of one calendar for the booking page, encrypted with the
-- same server key as `timing`. Valid while `synctoken` equals the calendar's.
--
CREATE TABLE IF NOT EXISTS `#__appointments_calendar_busy` (
    `calendar_id` INT UNSIGNED NOT NULL,
    `synctoken` BIGINT UNSIGNED NOT NULL,
    `window_start_utc` DATETIME NOT NULL,
    `window_end_utc` DATETIME NOT NULL,
    `payload` MEDIUMBLOB NOT NULL,
    `built_utc` DATETIME NOT NULL,
    PRIMARY KEY (`calendar_id`),
    FOREIGN KEY (`calendar_id`) REFERENCES `#__appointments_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- One row per device. The app password itself is never stored: `secret_hash` is
-- an HMAC with a server key, `usk_wrapped` is the user's secret key wrapped with
-- a key derived from the app password. `state`: 1 active, 2 clean-up (calendars
-- served empty), 3 clean-up (calendars hidden), 0 revoked. `removal_mode`, «When
-- removed or blocked»: `cleanup` (the clean-up above) or `block` (revoked at once,
-- never an empty calendar). script.php adds the column on an update as well, with
-- `cleanup` for every existing device.
--
CREATE TABLE IF NOT EXISTS `#__appointments_devices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `client` VARCHAR(32) NOT NULL,
    `removal_mode` VARCHAR(16) NOT NULL DEFAULT 'cleanup',
    `secret_hash` BINARY(32) NOT NULL,
    `kek_salt` VARBINARY(16) NOT NULL,
    `usk_wrapped` VARBINARY(255) DEFAULT NULL,
    `user_key_version` INT UNSIGNED NOT NULL,
    `state` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `rule_version` VARCHAR(16) NOT NULL,
    `rule_accepted_utc` DATETIME NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `last_sync_utc` DATETIME DEFAULT NULL,
    `cleanup_started_utc` DATETIME DEFAULT NULL,
    `revoked_utc` DATETIME DEFAULT NULL,
    `revoked_by` INT UNSIGNED DEFAULT NULL,
    `revoke_reason` VARCHAR(32) DEFAULT NULL,
    `row_mac` BINARY(32) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_device_secret` (`secret_hash`),
    KEY `idx_device_user` (`user_id`, `state`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Per device and calendar: the role it had at its first synchronisation, when it
-- last synchronised, and when the clean-up view was delivered to it.
--
CREATE TABLE IF NOT EXISTS `#__appointments_device_calendars` (
    `device_id` INT UNSIGNED NOT NULL,
    `calendar_id` INT UNSIGNED NOT NULL,
    `role` TINYINT UNSIGNED NOT NULL,
    `synctoken` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_sync_utc` DATETIME NOT NULL,
    `cleanup_delivered_utc` DATETIME DEFAULT NULL,
    PRIMARY KEY (`device_id`, `calendar_id`),
    KEY `idx_device_calendar_calendar` (`calendar_id`),
    FOREIGN KEY (`device_id`) REFERENCES `#__appointments_devices` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`calendar_id`) REFERENCES `#__appointments_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- One-time activation links of accounts that an administrator created (team or
-- external), valid 72 hours. Only a keyed hash of the token is stored; the link
-- is bound to the account and to a keyed hash of the address it was sent to.
-- `state`: 1 open, 2 completed, 0 closed (revoked or expired).
--
CREATE TABLE IF NOT EXISTS `#__appointments_invitations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `token_hash` BINARY(32) NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `email_hash` BINARY(32) NOT NULL,
    `state` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `invited_by` INT UNSIGNED NOT NULL,
    `created_utc` DATETIME NOT NULL,
    `expires_utc` DATETIME NOT NULL,
    `completed_utc` DATETIME DEFAULT NULL,
    `revoked_by` INT UNSIGNED DEFAULT NULL,
    `row_mac` BINARY(32) NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_invitation_token` (`token_hash`),
    KEY `idx_invitation_user` (`user_id`),
    KEY `idx_invitation_expires` (`state`, `expires_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Team calendar log: sign-ins and changes, never read access. Each row carries
-- the hash of its predecessor and its own keyed hash, so a removed or altered
-- row breaks the chain. No calendar content and no invitee data. Rows older than
-- twelve months are pruned; the head table keeps the anchor of the pruned part.
--
CREATE TABLE IF NOT EXISTS `#__appointments_teamlog` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `occurred_utc` DATETIME NOT NULL,
    `event` VARCHAR(48) NOT NULL,
    `actor_user_id` INT UNSIGNED DEFAULT NULL,
    `actor_device_id` INT UNSIGNED DEFAULT NULL,
    `subject_user_id` INT UNSIGNED DEFAULT NULL,
    `calendar_id` INT UNSIGNED DEFAULT NULL,
    `object_ref` VARCHAR(64) DEFAULT NULL,
    `details` JSON DEFAULT NULL,
    `ip_hash` BINARY(32) DEFAULT NULL,
    `prev_hash` BINARY(32) NOT NULL,
    `row_hash` BINARY(32) NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_teamlog_occurred` (`occurred_utc`),
    KEY `idx_teamlog_actor` (`actor_user_id`),
    KEY `idx_teamlog_calendar` (`calendar_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- The single head row of the log chain, also the lock that serialises appends.
--
CREATE TABLE IF NOT EXISTS `#__appointments_teamlog_head` (
    `id` TINYINT UNSIGNED NOT NULL,
    `last_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_hash` BINARY(32) NOT NULL,
    `pruned_through_id` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `pruned_through_hash` BINARY(32) DEFAULT NULL,
    `modified_utc` DATETIME NOT NULL,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Failed sign-ins per keyed bucket (user name, address). Temporary lock with a
-- growing delay after ten failures, never a permanent lock.
--
CREATE TABLE IF NOT EXISTS `#__appointments_auth_failures` (
    `bucket` BINARY(32) NOT NULL,
    `failures` INT UNSIGNED NOT NULL DEFAULT 0,
    `locked_until_utc` DATETIME DEFAULT NULL,
    `last_failure_utc` DATETIME NOT NULL,
    PRIMARY KEY (`bucket`),
    KEY `idx_auth_failure_last` (`last_failure_utc`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Further calendars whose busy times block the slots of an event type, in
-- addition to the event type's own target calendar (`#__appointments_eventtypes.calendar_id`).
--
CREATE TABLE IF NOT EXISTS `#__appointments_eventtype_calendars` (
    `eventtype_id` INT UNSIGNED NOT NULL,
    `calendar_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`eventtype_id`, `calendar_id`),
    KEY `idx_eventtype_calendar_calendar` (`calendar_id`),
    FOREIGN KEY (`eventtype_id`) REFERENCES `#__appointments_eventtypes` (`id`) ON DELETE CASCADE,
    FOREIGN KEY (`calendar_id`) REFERENCES `#__appointments_calendars` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
