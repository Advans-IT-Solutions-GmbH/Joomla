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
-- The bookable thing. `horizon_days` = 0 means the fixed range in
-- `horizon_start_utc`/`horizon_end_utc` is used instead of a rolling horizon.
-- `max_per_day` and `max_per_week` = 0 mean unlimited. `retention_days` NULL
-- means the component default applies.
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
    `schedule_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `host_user_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `location_type` VARCHAR(32) NOT NULL DEFAULT 'custom_text',
    `location_text` TEXT,
    `conference_provider` VARCHAR(64) DEFAULT NULL,
    `conference_params` JSON DEFAULT NULL,
    `requires_approval` TINYINT(1) NOT NULL DEFAULT 0,
    `retention_days` SMALLINT UNSIGNED DEFAULT NULL,
    `colour` VARCHAR(7) NOT NULL DEFAULT '',
    `access` INT UNSIGNED NOT NULL DEFAULT 1,
    `language` CHAR(7) NOT NULL DEFAULT '*',
    `published` TINYINT(1) NOT NULL DEFAULT 1,
    `ordering` INT NOT NULL DEFAULT 0,
    `feed_token` CHAR(64) NOT NULL DEFAULT '',
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_alias` (`alias`),
    UNIQUE KEY `idx_feed_token` (`feed_token`),
    KEY `idx_published_access_language` (`published`, `access`, `language`),
    KEY `idx_schedule` (`schedule_id`)
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
--
-- A reschedule updates this row in place: the times change, `sequence` is
-- raised and `modified_utc` is touched. It does not insert a successor row,
-- because the calendar contract keeps one UID for the life of a booking, and a
-- second row with the same UID would break the unique index on `uid` as well as
-- the subscription feed, where two VEVENTs with one UID are not valid. The
-- unique slot index below also does the work for free: moving a booking onto a
-- slot another active booking already holds fails the UPDATE.
-- `created_ip` exists for the anti-bot timing check only and is erased together
-- with the rest of the personal data.
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
    `invitee_name` VARCHAR(255) DEFAULT NULL,
    `invitee_email` VARCHAR(255) DEFAULT NULL,
    `invitee_phone` VARCHAR(64) DEFAULT NULL,
    `invitee_timezone` VARCHAR(64) DEFAULT NULL,
    `invitee_language` CHAR(7) DEFAULT NULL,
    `guest_emails` TEXT,
    `custom_fields` JSON DEFAULT NULL,
    `conference_provider` VARCHAR(64) DEFAULT NULL,
    `conference_url` TEXT,
    `conference_room` VARCHAR(255) DEFAULT NULL,
    `conference_state` VARCHAR(16) DEFAULT NULL,
    `conference_error` VARCHAR(255) DEFAULT NULL,
    `cancel_reason` TEXT,
    `reminder_sent_utc` JSON DEFAULT NULL,
    `anonymised_utc` DATETIME DEFAULT NULL,
    `created_utc` DATETIME NOT NULL,
    `modified_utc` DATETIME NOT NULL,
    `created_ip` VARBINARY(16) DEFAULT NULL,
    `checked_out` INT UNSIGNED DEFAULT NULL,
    `checked_out_time` DATETIME DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_uid` (`uid`),
    UNIQUE KEY `idx_active_slot` (`eventtype_id`, `start_utc`, `state_active`),
    KEY `idx_host_start` (`host_user_id`, `start_utc`),
    KEY `idx_invitee_email` (`invitee_email`),
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
-- Deletion evidence. Carries no personal data by design, and a test asserts the
-- column set, so an added personal column fails the suite. `booking_id` may
-- point at a row that no longer exists. `actor_user_id` is set for the `admin`
-- path only.
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
