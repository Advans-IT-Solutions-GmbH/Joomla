<?php
/**
 * @package     Appointments Component
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

final class RateLimiter
{
    public function __construct(private DatabaseInterface $db, private KeyProvider $keys)
    {
    }

    public function allow(string $endpoint, string $ip, \DateTimeImmutable $now, int $limit, int $windowSeconds): bool
    {
        if ($limit < 1 || $windowSeconds < 1 || !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Invalid rate-limit input.');
        }

        $bucket = hash_hmac('sha256', $endpoint . ':' . $ip, $this->keys->key(KeyProvider::PURPOSE_RATE_LIMIT));
        $window = $now->setTimestamp(intdiv($now->getTimestamp(), $windowSeconds) * $windowSeconds)
            ->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $db = $this->db;
        $sql = 'INSERT INTO ' . $db->quoteName('#__appointments_ratelimit')
            . ' (' . implode(',', $db->quoteName(['bucket', 'window_utc', 'hits'])) . ')'
            . ' VALUES (' . $db->quote($bucket) . ',' . $db->quote($window) . ',1)'
            . ' ON DUPLICATE KEY UPDATE ' . $db->quoteName('hits') . ' = ' . $db->quoteName('hits') . ' + 1';
        $db->setQuery($sql)->execute();
        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
        $query->select($db->quoteName('hits'))->from($db->quoteName('#__appointments_ratelimit'))
            ->where($db->quoteName('bucket') . ' = ' . $db->quote($bucket))
            ->where($db->quoteName('window_utc') . ' = ' . $db->quote($window));

        return (int) $db->setQuery($query)->loadResult() <= $limit;
    }
}
