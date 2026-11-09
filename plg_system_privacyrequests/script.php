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
        // Publishing and mail configuration are deliberately left to the operator.
    }
}
