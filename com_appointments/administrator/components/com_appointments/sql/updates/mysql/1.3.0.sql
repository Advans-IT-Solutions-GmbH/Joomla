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
