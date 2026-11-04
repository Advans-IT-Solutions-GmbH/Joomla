--
-- Team calendars with CalDAV: the target calendar of an event type and the tables
-- of calendars, grants, keys, devices, invitations, objects and the log. The
-- statements are the same as in install.mysql.utf8.sql, one change per statement,
-- so Joomla's database check (System > Maintenance > Database) can verify each one.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

ALTER TABLE `#__appointments_eventtypes` ADD COLUMN `calendar_id` INT UNSIGNED DEFAULT NULL AFTER `info_title`;
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
-- served empty), 3 clean-up (calendars hidden), 0 revoked.
--
CREATE TABLE IF NOT EXISTS `#__appointments_devices` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `client` VARCHAR(32) NOT NULL,
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
-- Per device and calendar: when it last synchronised, and when the clean-up view
-- was delivered to it.
--
CREATE TABLE IF NOT EXISTS `#__appointments_device_calendars` (
    `device_id` INT UNSIGNED NOT NULL,
    `calendar_id` INT UNSIGNED NOT NULL,
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
