--
-- Schema update for com_dmarcreports 1.1.0.
--
-- Adds SMTP TLS Reporting (RFC 8460) storage alongside the existing DMARC
-- aggregate tables. Uses CREATE TABLE IF NOT EXISTS so an upgrade of an existing
-- 1.0.x installation adds the new tables without touching, rebuilding or
-- dropping any DMARC domain, source, report or record data.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

CREATE TABLE IF NOT EXISTS `#__dmarc_tlsrpt_reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `source_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `org_name` VARCHAR(255) NOT NULL DEFAULT '',
    `contact_info` VARCHAR(255) NOT NULL DEFAULT '',
    `report_id` VARCHAR(255) NOT NULL DEFAULT '',
    `report_hash` CHAR(64) NOT NULL,
    `date_begin` BIGINT NOT NULL DEFAULT 0,
    `date_end` BIGINT NOT NULL DEFAULT 0,
    `policy_domain` VARCHAR(190) NOT NULL DEFAULT '',
    `success_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `failure_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_dmarc_tlsrpt_hash` (`report_hash`),
    KEY `idx_dmarc_tlsrpt_domain` (`domain_id`, `date_end`, `id`),
    KEY `idx_dmarc_tlsrpt_source` (`source_id`),
    KEY `idx_dmarc_tlsrpt_range` (`date_begin`, `date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__dmarc_tlsrpt_policies` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `domain_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `policy_type` VARCHAR(20) NOT NULL DEFAULT '',
    `policy_domain` VARCHAR(190) NOT NULL DEFAULT '',
    `success_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `failure_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_dmarc_tlsrpt_policy_report` (`report_id`),
    KEY `idx_dmarc_tlsrpt_policy_domain` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__dmarc_tlsrpt_failures` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `policy_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `domain_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `policy_domain` VARCHAR(190) NOT NULL DEFAULT '',
    `result_type` VARCHAR(60) NOT NULL DEFAULT '',
    `sending_mta_ip` VARCHAR(45) NOT NULL DEFAULT '',
    `receiving_mx_host` VARCHAR(190) NOT NULL DEFAULT '',
    `receiving_ip` VARCHAR(45) NOT NULL DEFAULT '',
    `failed_session_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `additional_info` VARCHAR(1024) NOT NULL DEFAULT '',
    `failure_reason_code` VARCHAR(190) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_dmarc_tlsrpt_fail_report` (`report_id`),
    KEY `idx_dmarc_tlsrpt_fail_policy` (`policy_id`),
    KEY `idx_dmarc_tlsrpt_fail_domain` (`domain_id`),
    KEY `idx_dmarc_tlsrpt_fail_result` (`result_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
