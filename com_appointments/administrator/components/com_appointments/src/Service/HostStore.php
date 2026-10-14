<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class HostStore
{
    public function __construct(private DatabaseInterface $db)
    {
    }

    public function ensure(int $userId, \DateTimeImmutable $now): void
    {
        if ($userId < 1) {
            throw new \InvalidArgumentException('A host must be a Joomla user.');
        }

        $db = $this->db;
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('id'))
            ->from($db->quoteName('#__users'))
            ->where($db->quoteName('id') . ' = ' . $userId);

        if (!$db->setQuery($query)->loadResult()) {
            throw new \InvalidArgumentException('The host does not exist.');
        }

        $time = $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $token = bin2hex(random_bytes(32));
        // An existing host keeps its independently rotatable feed credential.
        $sql = 'INSERT INTO ' . $db->quoteName('#__appointments_hosts')
            . ' (' . implode(',', $db->quoteName(['user_id', 'feed_token', 'created_utc', 'modified_utc'])) . ')'
            . ' VALUES (' . $userId . ',' . $db->quote($token) . ',' . $db->quote($time) . ',' . $db->quote($time) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('user_id') . ' = ' . $db->quoteName('user_id');
        $db->setQuery($sql)->execute();
    }
}
