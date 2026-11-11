<?php
/**
 * @package     Privacy Requests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\System\PrivacyRequests\Service;

use Joomla\Database\DatabaseInterface;

defined('_JEXEC') or die;

/**
 * Atomic, fail-closed admission, following the J2Commerce ConfirmAttempts prior art.
 * The connection limit is charged first, bounding address rows even with fresh sessions.
 */
final class Admission
{
    public function __construct(private DatabaseInterface $db, private string $secret)
    {
        if ($secret === '') {
            throw new \RuntimeException('Privacy request admission needs the site secret.');
        }
    }

    public function hash(string $scope, string $value): string
    {
        return hash_hmac('sha256', $scope . "\0" . $value, $this->secret);
    }

    public function reserve(string $operation, string $email, string $ip, ?int $now = null): bool
    {
        $now ??= time();
        if ($ip === '' || !$this->count($this->hash($operation . ':ip', $ip), 20, $now)) {
            return false;
        }
        $this->purgeExpired($now);
        if ($operation === 'confirm') {
            // No address bucket for confirmation: anyone could exhaust it with wrong codes and so
            // refuse the data subject's genuine code until it expires. The keyed 128-bit code is
            // not guessable within the client limit; the request bucket still limits mail sends.
            return true;
        }

        return $this->count($this->hash($operation . ':email', self::address($email)), 5, $now);
    }

    public static function address(string $email): string
    {
        return mb_strtolower(trim($email), 'UTF-8');
    }

    private function count(string $hash, int $limit, int $now): bool
    {
        $db = $this->db;
        $table = $db->quoteName('#__privacy_guest_attempts');
        $key = $db->quote($hash);
        $db->transactionStart();
        try {
            $sql = "INSERT INTO $table (subject_hash, attempts, window_start) VALUES ($key, 0, $now)";
            $sql .= strtolower($db->getServerType()) === 'postgresql'
                ? ' ON CONFLICT (subject_hash) DO NOTHING'
                : ' ON DUPLICATE KEY UPDATE subject_hash = subject_hash';
            $db->setQuery($sql)->execute();
            $row = $db->setQuery("SELECT attempts, window_start FROM $table WHERE subject_hash = $key FOR UPDATE")->loadObject();
            $start = (int) $row->window_start;
            $count = $start <= $now - 900 ? 1 : min($limit + 1, (int) $row->attempts + 1);
            $start = $start <= $now - 900 ? $now : $start;
            $db->setQuery("UPDATE $table SET attempts = $count, window_start = $start WHERE subject_hash = $key")->execute();
            $db->transactionCommit();
        } catch (\Throwable $e) {
            $db->transactionRollback();
            throw $e;
        }
        return $count <= $limit;
    }

    private function purgeExpired(int $now): void
    {
        $db = $this->db;
        $table = $db->quoteName('#__privacy_guest_attempts');
        $expired = $db->setQuery($db->createQuery()->select($db->quoteName('subject_hash'))->from($table)
            ->where($db->quoteName('window_start') . ' < ' . ($now - 1800))
            ->order($db->quoteName(['window_start', 'subject_hash']))->setLimit(100))->loadColumn();
        if ($expired !== []) {
            $keys = implode(',', array_map(static fn ($hash): string => $db->quote((string) $hash), $expired));
            // A concurrent reservation may have renewed a selected bucket.
            $db->setQuery("DELETE FROM $table WHERE subject_hash IN ($keys) AND window_start < " . ($now - 1800))->execute();
        }
    }
}
