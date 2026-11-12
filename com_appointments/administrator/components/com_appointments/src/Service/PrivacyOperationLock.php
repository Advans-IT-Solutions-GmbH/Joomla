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

/**
 * Serialises Core privacy Remove and Complete for one request, whether or not the generic
 * privacy request adapter is enabled. The connection-scoped lock name equals the adapter's
 * own operation lock, so both cooperate: the same connection re-enters it, any other
 * connection is refused without waiting. The database releases it if the connection ends.
 */
final class PrivacyOperationLock
{
    private string $key;
    private bool $postgres;
    private bool $held = true;

    private function __construct(private DatabaseInterface $db, string $key, bool $postgres)
    {
        $this->key = $key;
        $this->postgres = $postgres;
    }

    /** Returns null when another connection is running an operation on the same request. */
    public static function acquire(DatabaseInterface $db, int $requestId, string $secret): ?self
    {
        $postgres = strtolower($db->getServerType()) === 'postgresql';
        $hash = hash_hmac('sha256', 'privacy-operation:' . $requestId, $secret);
        $key = $postgres ? (string) hexdec(substr($hash, 0, 15)) : $db->quote($hash);
        $result = $db->setQuery($postgres ? "SELECT pg_try_advisory_lock($key)" : "SELECT GET_LOCK($key, 0)")
            ->loadResult();

        return in_array($result, [1, '1', true, 't'], true) ? new self($db, $key, $postgres) : null;
    }

    /**
     * Re-reads the persisted request after acquisition: Core's own state check ran before the lock.
     *
     * @return array{status: int, type: string}|null
     */
    public static function state(DatabaseInterface $db, int $requestId): ?array
    {
        $row = $db->setQuery('SELECT ' . $db->quoteName('status') . ', ' . $db->quoteName('request_type')
            . ' FROM ' . $db->quoteName('#__privacy_requests') . ' WHERE ' . $db->quoteName('id') . ' = ' . $requestId)
            ->loadAssoc();

        return is_array($row) ? ['status' => (int) $row['status'], 'type' => (string) $row['request_type']] : null;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }
        $this->held = false;
        $this->db->setQuery($this->postgres ? "SELECT pg_advisory_unlock($this->key)" : "SELECT RELEASE_LOCK($this->key)")
            ->loadResult();
    }

    public function __destruct()
    {
        try {
            $this->release();
        } catch (\Throwable) {
            // The database already released a lock whose connection has ended.
        }
    }
}
