--
-- Token feeds: the column holds a keyed digest of the credential ("h1:" plus
-- HMAC-SHA256 in hex), never the credential itself. The key is derived from
-- the site secret, which the database does not hold, so script.php converts
-- the existing clear-text values after this file has run. Every existing
-- subscription keeps working.
--
-- The account a Joomla privacy request concerns, captured by the removal check while Joomla still
-- finds the account (StaffPrivacy::capture()), so a retry after the account was pseudonymised
-- still removes the staff data and keeps the calendar entries of a team member.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

ALTER TABLE `#__appointments_hosts` MODIFY `feed_token` CHAR(67) NOT NULL;
ALTER TABLE `#__appointments_eventtypes` MODIFY `feed_token` CHAR(67) NOT NULL;

CREATE TABLE IF NOT EXISTS `#__appointments_privacy_scope` (
    `request_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `team_member` TINYINT(1) NOT NULL DEFAULT 0,
    `created_utc` DATETIME NOT NULL,
    PRIMARY KEY (`request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
