--
-- Uninstall schema for com_dmarcreports.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

DROP TABLE IF EXISTS `#__dmarc_tlsrpt_failures`;
DROP TABLE IF EXISTS `#__dmarc_tlsrpt_reports`;
DROP TABLE IF EXISTS `#__dmarc_records`;
DROP TABLE IF EXISTS `#__dmarc_reports`;
DROP TABLE IF EXISTS `#__dmarc_actions`;
DROP TABLE IF EXISTS `#__dmarc_sources`;
DROP TABLE IF EXISTS `#__dmarc_domains`;
