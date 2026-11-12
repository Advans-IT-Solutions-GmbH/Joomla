<?php
/**
 * @package     Privacy Requests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

defined('_JEXEC') or die;

require_once is_file(__DIR__ . '/advansinstaller/load.php')
    ? __DIR__ . '/advansinstaller/load.php'
    : dirname(__DIR__) . '/shared/installer/load.php';

use Advans\Installer\InstallerScript;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;

class PlgSystemPrivacyrequestsInstallerScript extends InstallerScript
{
    protected $minimumJoomla = '5.4';
    protected $minimumPhp = '8.1';

    public function preflight($type, $parent)
    {
        return parent::preflight($type, $parent);
    }

    protected function extensionPostflight(string $type, object $parent): void
    {
        if (!in_array($type, ['install', 'update'], true)) {
            return;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $mysql = strtolower($db->getServerType()) === 'mysql';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
        foreach ([
            '#__privacy_guest_subjects' => 'subject_hash CHAR(64) PRIMARY KEY, request_id INTEGER NOT NULL DEFAULT 0, delivery_failed INTEGER NOT NULL DEFAULT 0',
            '#__privacy_guest_attempts' => 'subject_hash CHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, window_start BIGINT NOT NULL',
            '#__privacy_guest_outcomes' => 'request_id INTEGER PRIMARY KEY, reviewed_result INTEGER NOT NULL DEFAULT 0',
        ] as $table => $columns) {
            $db->setQuery('CREATE TABLE IF NOT EXISTS ' . $db->quoteName($table) . ' (' . $columns . ')' . $suffix)->execute();
        }
        if (!array_key_exists('delivery_failed', $db->getTableColumns('#__privacy_guest_subjects'))) {
            $db->setQuery('ALTER TABLE ' . $db->quoteName('#__privacy_guest_subjects')
                . ' ADD COLUMN delivery_failed INTEGER NOT NULL DEFAULT 0')->execute();
        }
        foreach ([
            ['#__privacy_guest_expiry', '#__privacy_guest_attempts', ['window_start', 'subject_hash']],
            ['#__privacy_guest_request', '#__privacy_guest_subjects', ['request_id']],
        ] as [$name, $table, $columns]) {
            $index = $db->replacePrefix($name);
            $exists = false;
            foreach ($db->getTableKeys($table) as $key) {
                $exists = $exists || ($key->Key_name ?? $key->idxName ?? '') === $index;
            }
            if (!$exists) {
                $db->setQuery('CREATE INDEX ' . $db->quoteName($index) . ' ON '
                    . $db->quoteName($table) . ' (' . implode(',', $db->quoteName($columns)) . ')')->execute();
            }
        }
        // Publishing and mail configuration are deliberately left to the operator.
    }

    public function uninstall($parent)
    {
        // Only the plugin's own auxiliary tables are removed; Core requests and the action log stay.
        $db = Factory::getContainer()->get(DatabaseInterface::class);
        foreach (['#__privacy_guest_subjects', '#__privacy_guest_attempts', '#__privacy_guest_outcomes'] as $table) {
            $db->setQuery('DROP TABLE IF EXISTS ' . $db->quoteName($table))->execute();
        }

        return true;
    }
}
