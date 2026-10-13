--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--
-- Failed attempts on the confirmation page for privacy requests, per e-mail address and per
-- client IP address. Only HMAC hashes (site secret) are stored, never the address itself.
--
CREATE TABLE IF NOT EXISTS `#__privacy_j2commerce_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `scope` varchar(16) NOT NULL,
  `subject_hash` char(64) NOT NULL,
  `failures` int unsigned NOT NULL DEFAULT 0,
  `window_start` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_scope_subject` (`scope`, `subject_hash`),
  KEY `idx_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 DEFAULT COLLATE=utf8mb4_unicode_ci;
