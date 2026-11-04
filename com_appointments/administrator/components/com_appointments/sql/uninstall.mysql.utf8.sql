--
-- Uninstall schema for com_appointments: drops every table the install file
-- creates, in reverse dependency order, so no operational data is left behind.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

DROP TABLE IF EXISTS `#__appointments_eventtype_calendars`;
DROP TABLE IF EXISTS `#__appointments_auth_failures`;
DROP TABLE IF EXISTS `#__appointments_teamlog_head`;
DROP TABLE IF EXISTS `#__appointments_teamlog`;
DROP TABLE IF EXISTS `#__appointments_invitations`;
DROP TABLE IF EXISTS `#__appointments_device_calendars`;
DROP TABLE IF EXISTS `#__appointments_devices`;
DROP TABLE IF EXISTS `#__appointments_calendar_busy`;
DROP TABLE IF EXISTS `#__appointments_calendar_changes`;
DROP TABLE IF EXISTS `#__appointments_calendar_object_emails`;
DROP TABLE IF EXISTS `#__appointments_calendar_objects`;
DROP TABLE IF EXISTS `#__appointments_calendar_key_wraps`;
DROP TABLE IF EXISTS `#__appointments_calendar_grants`;
DROP TABLE IF EXISTS `#__appointments_calendar_keys`;
DROP TABLE IF EXISTS `#__appointments_calendars`;
DROP TABLE IF EXISTS `#__appointments_user_keys`;
DROP TABLE IF EXISTS `#__appointments_keyring`;
DROP TABLE IF EXISTS `#__appointments_ratelimit`;
DROP TABLE IF EXISTS `#__appointments_audit`;
DROP TABLE IF EXISTS `#__appointments_templates`;
DROP TABLE IF EXISTS `#__appointments_fields`;
DROP TABLE IF EXISTS `#__appointments_bookings`;
DROP TABLE IF EXISTS `#__appointments_blocked`;
DROP TABLE IF EXISTS `#__appointments_eventtypes`;
DROP TABLE IF EXISTS `#__appointments_hosts`;
DROP TABLE IF EXISTS `#__appointments_schedule_rules`;
DROP TABLE IF EXISTS `#__appointments_schedules`;
