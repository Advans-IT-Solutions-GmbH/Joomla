--
-- Token feeds: the column holds a keyed digest of the credential ("h1:" plus
-- HMAC-SHA256 in hex), never the credential itself. The key is derived from
-- the site secret, which the database does not hold, so script.php converts
-- the existing clear-text values after this file has run. Every existing
-- subscription keeps working.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

ALTER TABLE `#__appointments_hosts` MODIFY `feed_token` CHAR(67) NOT NULL;
ALTER TABLE `#__appointments_eventtypes` MODIFY `feed_token` CHAR(67) NOT NULL;
