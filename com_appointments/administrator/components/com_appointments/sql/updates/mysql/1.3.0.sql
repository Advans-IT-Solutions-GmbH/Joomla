--
-- Information column of the booking page: the layout ("logo", "person" or "event"), its
-- image, name and role, and where the title stands ("card" or "above"). Existing event
-- types keep the company-logo layout with the title in the card, which without a logo
-- shows the event details alone, as before. One column per statement,
-- so Joomla's database check (System > Maintenance > Database) can verify each one.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

ALTER TABLE `#__appointments_eventtypes` ADD COLUMN `info_layout` VARCHAR(16) NOT NULL DEFAULT 'logo' AFTER `colour`;
ALTER TABLE `#__appointments_eventtypes` ADD COLUMN `info_logo` VARCHAR(2048) NOT NULL DEFAULT '' AFTER `info_layout`;
ALTER TABLE `#__appointments_eventtypes` ADD COLUMN `info_photo` VARCHAR(2048) NOT NULL DEFAULT '' AFTER `info_logo`;
ALTER TABLE `#__appointments_eventtypes` ADD COLUMN `info_name` VARCHAR(255) NOT NULL DEFAULT '' AFTER `info_photo`;
ALTER TABLE `#__appointments_eventtypes` ADD COLUMN `info_role` VARCHAR(255) NOT NULL DEFAULT '' AFTER `info_name`;
ALTER TABLE `#__appointments_eventtypes` ADD COLUMN `info_title` VARCHAR(16) NOT NULL DEFAULT 'card' AFTER `info_role`;

--
-- First and last name of the invitee as separate fields. `invitee_name` keeps the
-- full name unchanged. Existing bookings get both parts derived from it: the last
-- word is the last name, the rest the first name, and a single word becomes the
-- first name with an empty last name. Anonymised bookings (`invitee_name` NULL)
-- keep NULL in both columns. The UPDATE only fills rows whose parts are still
-- empty, so running it again changes nothing.
--

ALTER TABLE `#__appointments_bookings` ADD COLUMN `invitee_first_name` VARCHAR(255) DEFAULT NULL AFTER `invitee_name`;
ALTER TABLE `#__appointments_bookings` ADD COLUMN `invitee_last_name` VARCHAR(255) DEFAULT NULL AFTER `invitee_first_name`;
UPDATE `#__appointments_bookings` SET `invitee_first_name` = CASE WHEN LOCATE(' ', TRIM(`invitee_name`)) = 0 THEN TRIM(`invitee_name`) ELSE TRIM(LEFT(TRIM(`invitee_name`), CHAR_LENGTH(TRIM(`invitee_name`)) - CHAR_LENGTH(SUBSTRING_INDEX(TRIM(`invitee_name`), ' ', -1)))) END, `invitee_last_name` = CASE WHEN LOCATE(' ', TRIM(`invitee_name`)) = 0 THEN '' ELSE SUBSTRING_INDEX(TRIM(`invitee_name`), ' ', -1) END WHERE `invitee_name` IS NOT NULL AND `invitee_first_name` IS NULL AND `invitee_last_name` IS NULL;

--
-- Proof of consent, set only when the booking form asks for consent (component
-- option consent_mode = consent). Existing bookings keep NULL.
--

ALTER TABLE `#__appointments_bookings` ADD COLUMN `consent_utc` DATETIME DEFAULT NULL AFTER `custom_fields`;
ALTER TABLE `#__appointments_bookings` ADD COLUMN `consent_version` CHAR(64) DEFAULT NULL AFTER `consent_utc`;
