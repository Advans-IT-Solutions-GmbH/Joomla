--
-- Uninstall schema for com_appointments: drops every table the install file
-- creates, in reverse dependency order, so no operational data is left behind.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

DROP TABLE IF EXISTS `#__appointments_ratelimit`;
DROP TABLE IF EXISTS `#__appointments_audit`;
DROP TABLE IF EXISTS `#__appointments_templates`;
DROP TABLE IF EXISTS `#__appointments_fields`;
DROP TABLE IF EXISTS `#__appointments_bookings`;
DROP TABLE IF EXISTS `#__appointments_blocked`;
DROP TABLE IF EXISTS `#__appointments_eventtypes`;
DROP TABLE IF EXISTS `#__appointments_schedule_rules`;
DROP TABLE IF EXISTS `#__appointments_schedules`;
