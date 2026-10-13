<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Security
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Plugin\Privacy\J2Commerce\Security;

defined('_JEXEC') or die;

use Joomla\Database\DatabaseInterface;

/**
 * Attempts on the confirmation page for privacy requests, counted server-side per e-mail address
 * and per client IP address, so a new session does not start from zero.
 *
 * Only an HMAC-SHA256 of scope and value with the site secret is stored, never the address or
 * the IP address itself. A counter belongs to a window of WINDOW seconds from its first counted
 * attempt; after it the next attempt starts a new window. Rows of ended windows are removed on
 * every attempt counted and by the cleanup task (purgeExpired()).
 */
final class ConfirmAttempts
{
    public const TABLE = '#__privacy_j2commerce_attempts';

    /** Failed attempts per e-mail address within WINDOW seconds before the page refuses. */
    public const MAX_EMAIL_FAILURES = 5;

    /**
     * Failed attempts per client IP address within WINDOW seconds before the page refuses: higher
     * than the per-address limit, so one blocked address does not block the other customers of
     * the same network (an office or a mobile carrier shares one address).
     */
    public const MAX_IP_FAILURES = 20;

    public const WINDOW = 900;

    private DatabaseInterface $db;

    private string $secret;

    public function __construct(DatabaseInterface $db, string $secret)
    {
        $this->db     = $db;
        $this->secret = $secret;
    }

    /**
     * Hash of a counted value (no raw e-mail or IP address is stored).
     */
    public function hash(string $scope, string $value): string
    {
        return hash_hmac('sha256', $scope . "\0" . $value, $this->secret);
    }

    /**
     * Admit an attempt: count it and read the count it produced, its own attempt included, in one
     * step per subject (see countAttempt()). Parallel requests for a subject are serialised, so
     * exactly the first attempts up to the limit are admitted, never more and never fewer. The
     * client IP address is counted and checked first: once it is over
     * its limit, nothing else is written (no row for a new e-mail address, no purge), so a client
     * can create at most MAX_IP_FAILURES address rows per window. An admitted attempt that
     * succeeds is given back with release(); a failed one stays counted.
     *
     * @param   array<string, string>  $subjects  Scope ('email', 'ip') => value; empty values are ignored
     */
    public function reserve(array $subjects, ?int $now = null): bool
    {
        $now   = $now ?? time();
        $ip    = (string) ($subjects['ip'] ?? '');
        $email = (string) ($subjects['email'] ?? '');

        if ($ip !== '' && $this->countAttempt('ip', $ip, $now) > self::MAX_IP_FAILURES) {
            return false;
        }

        $this->purgeExpired($now);

        return $email === '' || $this->countAttempt('email', $email, $now) <= self::MAX_EMAIL_FAILURES;
    }

    /**
     * Whether one of the subjects has more counted attempts in the current window than its limit
     * allows.
     *
     * @param   array<string, string>  $subjects  Scope ('email', 'ip') => value; empty values are ignored
     */
    public function exceeds(array $subjects, ?int $now = null): bool
    {
        $now    = $now ?? time();
        $limits = ['email' => self::MAX_EMAIL_FAILURES, 'ip' => self::MAX_IP_FAILURES];

        foreach ($subjects as $scope => $value) {
            if ($value !== '' && isset($limits[$scope]) && $this->count($scope, (string) $value, $now) > $limits[$scope]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count a failed attempt that was not admitted through reserve() (for example refused by the
     * session limit): the client IP address first, the e-mail address only while the IP address
     * is within its limit (see reserve()).
     *
     * @param   array<string, string>  $subjects  Scope ('email', 'ip') => value; empty values are ignored
     */
    public function registerFailure(array $subjects, ?int $now = null): void
    {
        $now   = $now ?? time();
        $ip    = (string) ($subjects['ip'] ?? '');
        $email = (string) ($subjects['email'] ?? '');

        if ($ip !== '' && $this->countAttempt('ip', $ip, $now) > self::MAX_IP_FAILURES) {
            return;
        }

        $this->purgeExpired($now);

        if ($email !== '') {
            $this->countAttempt('email', $email, $now);
        }
    }

    /**
     * Give back the attempt of a successful confirmation (reserve() counted it): the limits count
     * failed attempts, so customers who confirm correctly from one shared network (an office, a
     * mobile carrier) do not use up its IP limit. A count that reaches zero removes its row, so
     * the next attempt starts a new window with itself and no window starts with an attempt that
     * was given back. Each subject in one transaction (see transaction()); a window that ended in
     * between is left alone.
     *
     * @param   array<string, string>  $subjects  Scope ('email', 'ip') => value; empty values are ignored
     */
    public function release(array $subjects, ?int $now = null): void
    {
        $now = $now ?? time();

        foreach (['ip', 'email'] as $scope) {
            $value = (string) ($subjects[$scope] ?? '');

            if ($value === '') {
                continue;
            }

            $this->transaction(function () use ($scope, $value, $now): int {
                $subject = [
                    $this->db->quoteName('scope') . ' = ' . $this->db->quote($scope),
                    $this->db->quoteName('subject_hash') . ' = ' . $this->db->quote($this->hash($scope, $value)),
                ];

                $this->db->setQuery(
                    $this->createQuery()
                        ->update($this->db->quoteName(self::TABLE))
                        ->set($this->db->quoteName('failures') . ' = ' . $this->db->quoteName('failures') . ' - 1')
                        ->where($subject)
                        ->where($this->db->quoteName('failures') . ' > 0')
                        ->where($this->db->quoteName('window_start') . ' > ' . $this->db->quote(gmdate('Y-m-d H:i:s', $now - self::WINDOW)))
                )->execute();

                $this->db->setQuery(
                    $this->createQuery()
                        ->delete($this->db->quoteName(self::TABLE))
                        ->where($subject)
                        ->where($this->db->quoteName('failures') . ' <= 0')
                )->execute();

                return 0;
            });
        }
    }

    /**
     * Count one attempt of a subject and return the count of the current window, this attempt
     * included. Upsert and read run in one transaction: the upsert locks the row until the commit,
     * so a parallel attempt for the same subject waits for it, and every attempt reads exactly the
     * count it produced (the attempts of a window read 1, 2, 3 and so on, each number once).
     */
    private function countAttempt(string $scope, string $value, int $now): int
    {
        return $this->transaction(function () use ($scope, $value, $now): int {
            $this->increment($scope, $value, $now);

            return $this->count($scope, $value, $now, true);
        });
    }

    /**
     * Run $work in one database transaction. A transaction the database aborts (for example as a
     * deadlock victim) changed nothing and is repeated, at most three times in all; then the
     * error goes to the caller (which refuses the attempt).
     *
     * @param   callable(): int  $work
     */
    private function transaction(callable $work): int
    {
        for ($try = 1; ; $try++) {
            $this->db->transactionStart();

            try {
                $result = $work();
                $this->db->transactionCommit();

                return $result;
            } catch (\Throwable $e) {
                try {
                    $this->db->transactionRollback();
                } catch (\Throwable $ignored) {
                    // The database already ended the transaction.
                }

                if ($try >= 3) {
                    throw $e;
                }
            }
        }
    }

    /**
     * Counted attempts of a subject in the current window; $lock reads the row as locked by the
     * running transaction (the latest version, never an older snapshot).
     */
    private function count(string $scope, string $value, int $now, bool $lock = false): int
    {
        $query = $this->createQuery()
            ->select($this->db->quoteName('failures'))
            ->from($this->db->quoteName(self::TABLE))
            ->where($this->db->quoteName('scope') . ' = ' . $this->db->quote($scope))
            ->where($this->db->quoteName('subject_hash') . ' = ' . $this->db->quote($this->hash($scope, $value)))
            ->where($this->db->quoteName('window_start') . ' > ' . $this->db->quote(gmdate('Y-m-d H:i:s', $now - self::WINDOW)));

        return (int) $this->db->setQuery($lock ? (string) $query . ' FOR UPDATE' : $query)->loadResult();
    }

    /**
     * Count one attempt of a subject with one atomic statement: a new window when the last one
     * ended, else one more in it. Parallel attempts cannot overwrite each other. Called only
     * inside a transaction (countAttempt()).
     */
    private function increment(string $scope, string $value, int $now): void
    {
        $current = $this->db->quote(gmdate('Y-m-d H:i:s', $now));
        $ended   = $this->db->quote(gmdate('Y-m-d H:i:s', $now - self::WINDOW));
        $table   = $this->db->quoteName(self::TABLE);
        $columns = implode(', ', $this->db->quoteName(['scope', 'subject_hash', 'failures', 'window_start']));
        $isPg    = method_exists($this->db, 'getServerType') && strtolower((string) $this->db->getServerType()) === 'postgresql';
        $values  = $this->db->quote($scope) . ', ' . $this->db->quote($this->hash($scope, $value)) . ', 1, ' . $current;

        if ($isPg) {
            $old = $table . '.' . $this->db->quoteName('window_start');
            $sql = 'INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . $values . ')'
                . ' ON CONFLICT (' . implode(', ', $this->db->quoteName(['scope', 'subject_hash'])) . ') DO UPDATE SET '
                . $this->db->quoteName('failures') . ' = CASE WHEN ' . $old . ' <= ' . $ended . ' THEN 1 ELSE '
                . $table . '.' . $this->db->quoteName('failures') . ' + 1 END, '
                . $this->db->quoteName('window_start') . ' = CASE WHEN ' . $old . ' <= ' . $ended . ' THEN ' . $current . ' ELSE ' . $old . ' END';
        } else {
            // MySQL assigns from left to right: failures is set while window_start is still old.
            $old = $this->db->quoteName('window_start');
            $sql = 'INSERT INTO ' . $table . ' (' . $columns . ') VALUES (' . $values . ')'
                . ' ON DUPLICATE KEY UPDATE '
                . $this->db->quoteName('failures') . ' = IF(' . $old . ' <= ' . $ended . ', 1, ' . $this->db->quoteName('failures') . ' + 1), '
                . $old . ' = IF(' . $old . ' <= ' . $ended . ', ' . $current . ', ' . $old . ')';
        }

        $this->db->setQuery($sql)->execute();
    }

    /**
     * Remove the rows of ended windows.
     *
     * @return  int  Number of rows removed
     */
    public function purgeExpired(?int $now = null): int
    {
        $now = $now ?? time();

        $this->db->setQuery(
            $this->createQuery()
                ->delete($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('window_start') . ' <= ' . $this->db->quote(gmdate('Y-m-d H:i:s', $now - self::WINDOW)))
        )->execute();

        return (int) $this->db->getAffectedRows();
    }

    private function createQuery()
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }
}
