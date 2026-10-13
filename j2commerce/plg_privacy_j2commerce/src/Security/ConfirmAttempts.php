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
 * Failed attempts on the confirmation page for privacy requests, counted server-side per e-mail
 * address and per client IP address, so a new session does not start from zero.
 *
 * Only an HMAC-SHA256 of scope and value with the site secret is stored, never the address or
 * the IP address itself. A counter belongs to a window of WINDOW seconds from its first failure;
 * after it the next failure starts a new window. Rows of ended windows are removed on every
 * failure written and by the cleanup task (purgeExpired()).
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
     * Admit an attempt: count it first for every subject (one atomic statement each, see
     * registerFailure()), then check the counts. An attempt is admitted only while every count,
     * its own included, is within the limit, so parallel requests cannot all pass a check made
     * before any of them is counted. An admitted attempt that succeeds is given back with
     * release(); a failed one stays counted.
     *
     * @param   array<string, string>  $subjects  Scope ('email', 'ip') => value; empty values are ignored
     */
    public function reserve(array $subjects, ?int $now = null): bool
    {
        $now = $now ?? time();
        $this->registerFailure($subjects, $now);

        return !$this->exceeds($subjects, $now);
    }

    /**
     * Give back the attempt of a successful confirmation (reserve() counted it).
     *
     * @param   array<string, string>  $subjects  Scope ('email', 'ip') => value; empty values are ignored
     */
    public function release(array $subjects, ?int $now = null): void
    {
        $now   = $now ?? time();
        $since = $this->db->quote(gmdate('Y-m-d H:i:s', $now - self::WINDOW));

        foreach ($subjects as $scope => $value) {
            if ($value === '' || !\in_array($scope, ['email', 'ip'], true)) {
                continue;
            }

            $this->db->setQuery(
                $this->createQuery()
                    ->update($this->db->quoteName(self::TABLE))
                    ->set($this->db->quoteName('failures') . ' = ' . $this->db->quoteName('failures') . ' - 1')
                    ->where($this->db->quoteName('scope') . ' = ' . $this->db->quote($scope))
                    ->where($this->db->quoteName('subject_hash') . ' = ' . $this->db->quote($this->hash($scope, $value)))
                    ->where($this->db->quoteName('failures') . ' > 0')
                    ->where($this->db->quoteName('window_start') . ' > ' . $since)
            )->execute();
        }
    }

    /**
     * Whether one of the subjects has more counted attempts in the current window than its limit
     * allows.
     *
     * @param   array<string, string>  $subjects  Scope ('email', 'ip') => value; empty values are ignored
     */
    public function exceeds(array $subjects, ?int $now = null): bool
    {
        $now     = $now ?? time();
        $limits  = ['email' => self::MAX_EMAIL_FAILURES, 'ip' => self::MAX_IP_FAILURES];
        $since   = gmdate('Y-m-d H:i:s', $now - self::WINDOW);

        foreach ($subjects as $scope => $value) {
            if ($value === '' || !isset($limits[$scope])) {
                continue;
            }

            $query = $this->createQuery()
                ->select($this->db->quoteName('failures'))
                ->from($this->db->quoteName(self::TABLE))
                ->where($this->db->quoteName('scope') . ' = ' . $this->db->quote($scope))
                ->where($this->db->quoteName('subject_hash') . ' = ' . $this->db->quote($this->hash($scope, $value)))
                ->where($this->db->quoteName('window_start') . ' > ' . $this->db->quote($since));

            if ((int) $this->db->setQuery($query)->loadResult() > $limits[$scope]) {
                return true;
            }
        }

        return false;
    }

    /**
     * Count a failed attempt for every subject: a new window when the last one ended, else one
     * more failure in it. Rows of ended windows are removed first.
     *
     * @param   array<string, string>  $subjects  Scope ('email', 'ip') => value; empty values are ignored
     */
    public function registerFailure(array $subjects, ?int $now = null): void
    {
        $now     = $now ?? time();
        $this->purgeExpired($now);
        $current = $this->db->quote(gmdate('Y-m-d H:i:s', $now));
        $ended   = $this->db->quote(gmdate('Y-m-d H:i:s', $now - self::WINDOW));
        $table   = $this->db->quoteName(self::TABLE);
        $columns = implode(', ', $this->db->quoteName(['scope', 'subject_hash', 'failures', 'window_start']));
        $isPg    = method_exists($this->db, 'getServerType') && strtolower((string) $this->db->getServerType()) === 'postgresql';

        foreach ($subjects as $scope => $value) {
            if ($value === '' || !\in_array($scope, ['email', 'ip'], true)) {
                continue;
            }

            $values = $this->db->quote($scope) . ', ' . $this->db->quote($this->hash($scope, $value)) . ', 1, ' . $current;

            // One statement per subject: parallel failures cannot overwrite each other.
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
