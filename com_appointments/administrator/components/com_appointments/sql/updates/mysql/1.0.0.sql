--
-- Register the schema baseline and upgrade installations made before schema
-- tracking was enabled. Fresh installs already contain this constraint.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

ALTER TABLE `#__appointments_eventtypes`
    MODIFY `schedule_id` INT UNSIGNED NOT NULL,
    ADD FOREIGN KEY (`schedule_id`) REFERENCES `#__appointments_schedules` (`id`) ON DELETE RESTRICT;
