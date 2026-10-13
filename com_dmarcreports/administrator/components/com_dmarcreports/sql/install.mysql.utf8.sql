--
-- Installation schema for com_dmarcreports.
--
-- @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
-- @license     GNU General Public License version 3 or later; see LICENSE.txt
-- SPDX-License-Identifier: GPL-3.0-or-later
--

CREATE TABLE IF NOT EXISTS `#__dmarc_domains` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain` VARCHAR(190) NOT NULL,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `expected_policy` VARCHAR(20) NOT NULL DEFAULT 'reject',
    `notes` TEXT NULL,
    `published` TINYINT NOT NULL DEFAULT 1,
    `created` DATETIME NULL,
    `created_by` INT NOT NULL DEFAULT 0,
    `modified` DATETIME NULL,
    `modified_by` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_dmarc_domain` (`domain`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__dmarc_sources` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(255) NOT NULL DEFAULT '',
    `domain_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `protocol` VARCHAR(10) NOT NULL DEFAULT 'imap',
    `host` VARCHAR(255) NOT NULL DEFAULT '',
    `port` INT NOT NULL DEFAULT 993,
    `security` VARCHAR(10) NOT NULL DEFAULT 'ssl',
    `username` VARCHAR(255) NOT NULL DEFAULT '',
    `password_enc` TEXT NULL,
    `folder` VARCHAR(255) NOT NULL DEFAULT 'INBOX',
    `delete_after_import` TINYINT NOT NULL DEFAULT 0,
    `published` TINYINT NOT NULL DEFAULT 1,
    `last_run` DATETIME NULL,
    `last_status` VARCHAR(50) NOT NULL DEFAULT '',
    `last_message` VARCHAR(1024) NOT NULL DEFAULT '',
    `uidvalidity` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `last_uid` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `deferred_uids` VARCHAR(4096) NOT NULL DEFAULT '',
    `created` DATETIME NULL,
    `created_by` INT NOT NULL DEFAULT 0,
    `modified` DATETIME NULL,
    `modified_by` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    KEY `idx_dmarc_source_domain` (`domain_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__dmarc_reports` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `source_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `org_name` VARCHAR(255) NOT NULL DEFAULT '',
    `org_email` VARCHAR(255) NOT NULL DEFAULT '',
    `report_id` VARCHAR(255) NOT NULL DEFAULT '',
    `report_hash` CHAR(64) NOT NULL,
    `date_begin` BIGINT NOT NULL DEFAULT 0,
    `date_end` BIGINT NOT NULL DEFAULT 0,
    `policy_domain` VARCHAR(190) NOT NULL DEFAULT '',
    `policy_p` VARCHAR(20) NOT NULL DEFAULT '',
    `policy_sp` VARCHAR(20) NOT NULL DEFAULT '',
    `policy_pct` INT NOT NULL DEFAULT 100,
    `policy_adkim` CHAR(1) NOT NULL DEFAULT 'r',
    `policy_aspf` CHAR(1) NOT NULL DEFAULT 'r',
    `message_count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created` DATETIME NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_dmarc_report_hash` (`report_hash`),
    KEY `idx_dmarc_report_domain` (`domain_id`, `date_end`, `id`),
    KEY `idx_dmarc_report_source` (`source_id`),
    KEY `idx_dmarc_report_range` (`date_begin`, `date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__dmarc_records` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `report_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `domain_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `source_ip` VARCHAR(45) NOT NULL DEFAULT '',
    `count` BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `disposition` VARCHAR(20) NOT NULL DEFAULT 'none',
    `dkim_result` VARCHAR(20) NOT NULL DEFAULT '',
    `spf_result` VARCHAR(20) NOT NULL DEFAULT '',
    `dkim_aligned` TINYINT NOT NULL DEFAULT 0,
    `spf_aligned` TINYINT NOT NULL DEFAULT 0,
    `header_from` VARCHAR(190) NOT NULL DEFAULT '',
    `envelope_from` VARCHAR(190) NOT NULL DEFAULT '',
    `envelope_to` VARCHAR(190) NOT NULL DEFAULT '',
    PRIMARY KEY (`id`),
    KEY `idx_dmarc_record_report` (`report_id`),
    KEY `idx_dmarc_record_domain` (`domain_id`),
    KEY `idx_dmarc_record_ip` (`source_ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `#__dmarc_actions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `domain_id` INT UNSIGNED NOT NULL DEFAULT 0,
    `action_key` VARCHAR(190) NOT NULL,
    `category` VARCHAR(50) NOT NULL DEFAULT 'general',
    `severity` TINYINT NOT NULL DEFAULT 2,
    `status` VARCHAR(20) NOT NULL DEFAULT 'open',
    `params` TEXT NULL,
    `snooze_until` DATETIME NULL,
    `first_seen` DATETIME NULL,
    `last_seen` DATETIME NULL,
    `modified` DATETIME NULL,
    `modified_by` INT NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx_dmarc_action_key` (`domain_id`, `action_key`),
    KEY `idx_dmarc_action_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
