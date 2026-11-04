<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\User\UserHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

defined('_JEXEC') or die;

/**
 * Temporary lock after failed sign-ins (work package F).
 *
 * Calling contract: call {@see self::retryAfter()} before the secret is checked at all, refuse
 * when it is above 0 (without checking the secret), and afterwards call exactly one of
 * {@see self::recordFailure()} and {@see self::recordSuccess()}. On the website the check runs
 * before Joomla compares the password, in {@see self::guardLogin()} (system plugin, onAfterRoute);
 * a locked sign-in then fails like a wrong password, so the lock is no oracle.
 *
 * Buckets: a keyed hash (key(TEAM_AUTH_BUCKET)) of the lower-case user name, and one of the
 * address; an IPv6 address counts as its /64 prefix, because a client controls the rest of it, and
 * an IPv4-mapped IPv6 address as the IPv4 address. The user bucket locks a user name regardless of
 * the address, so changing addresses does not help; the address bucket locks one source for every
 * user name. Neither stores a user name or an address in clear.
 *
 * Delays: from the tenth failure in a bucket 1, 2, 4, 8, 16, 32 and then 60 minutes per further
 * failure, counted in one atomic statement per bucket, never longer and never permanent. A bucket
 * without a failure for 24 hours starts again at zero, and {@see self::purge()} deletes it.
 *
 * Parallel requests: once a bucket is one failure away from a lock (or past it, between two locks),
 * {@see self::retryAfter()} reserves the next attempt atomically for {@see self::CLAIM_SECONDS}
 * seconds. Requests in parallel are refused with that wait, so a burst cannot test more than one
 * guess per lock window. The reservation ends with the failure (which sets the real lock) or the
 * success (which releases it), or lapses by itself.
 *
 * @since  1.4.0
 */
final class LockoutService implements LockoutServiceInterface
{
    /** How long one reserved attempt holds a bucket at the threshold. */
    public const CLAIM_SECONDS = 30;

    /** A counter without a failure for this long starts again. */
    public const RESET_HOURS = 24;

    /** The longest lock, in minutes. */
    public const MAX_DELAY_MINUTES = 60;

    /**
     * Reservations taken by this PHP request (bucket hex => until), so that a
     * second check within the same sign-in (onUserAuthorisation after
     * guardLogin()) does not see its own reservation as somebody else's lock.
     * Lives for one request only.
     *
     * @var  array<string, string>
     */
    private static $ownClaims = [];

    /**
     * Sign-ins refused by guardLogin() in this request.
     *
     * @var  array<string, bool>
     */
    private static $refused = [];

    public function __construct(private readonly TeamServices $services)
    {
    }

    public function retryAfter(string $username, string $ip): int
    {
        $db = $this->db();
        $now = $this->services->now()->setTimezone(new \DateTimeZone('UTC'));
        $buckets = self::buckets($this->key(), $username, $ip);
        $rows = $this->load($buckets);
        $wait = self::waitFor($rows, $now, self::$ownClaims);

        if ($wait > 0) {
            return $wait;
        }

        $claimed = [];
        $until = $now->modify('+' . self::CLAIM_SECONDS . ' seconds')->format('Y-m-d H:i:s');

        foreach ($rows as $hex => $row) {
            if (self::isStale($row, $now) || (int) $row['failures'] < TeamStates::LOCK_THRESHOLD - 1
                || self::isOwnClaim((string) $hex, $row, self::$ownClaims)) {
                continue;
            }

            $db->setQuery($this->query()->update($db->quoteName('#__appointments_auth_failures'))
                ->set($db->quoteName('locked_until_utc') . ' = ' . $db->quote($until))
                ->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote($hex) . ')')
                ->where($db->quoteName('failures') . ' = ' . (int) $row['failures'])
                ->where('(' . $db->quoteName('locked_until_utc') . ' IS NULL OR ' . $db->quoteName('locked_until_utc')
                    . ' <= ' . $db->quote($now->format('Y-m-d H:i:s')) . ')'))->execute();

            if ((int) $db->getAffectedRows() !== 1) {
                // Another request holds the attempt (or just failed): wait for it.
                foreach ($claimed as $own) {
                    $this->release($own, $until);
                    unset(self::$ownClaims[$own]);
                }

                return max(1, self::waitFor($this->load($buckets), $now, self::$ownClaims));
            }

            $claimed[] = $hex;
            self::$ownClaims[$hex] = $until;
        }

        return 0;
    }

    public function guardLogin(CMSApplicationInterface $app): void
    {
        $input = $app->getInput();

        if (strtoupper((string) $input->getMethod()) !== 'POST') {
            return;
        }

        $option = strtolower($input->getCmd('option', ''));
        $task = strtolower($input->getCmd('task', ''));
        $site = $app->isClient('site') && $option === 'com_users' && $task === 'user.login';
        $admin = $app->isClient('administrator') && $option === 'com_login' && $task === 'login';

        if (!$site && !$admin) {
            return;
        }

        $username = (string) $input->post->get('username', '', 'username');
        $ip = (string) $input->server->getString('REMOTE_ADDR', '');
        $userId = (int) UserHelper::getUserId($username);

        // Only accounts with a calendar right are counted, so only they are locked.
        if ($userId <= 0 || $this->services->grants()->audienceOf($userId) === 'none' || $this->retryAfter($username, $ip) === 0) {
            return;
        }

        // Locked: let Joomla answer the sign-in exactly as it answers a wrong
        // password (same message, same redirect or page, same password hashing
        // time), by replacing the submitted password with one that matches
        // nothing. The real password is never compared, and this refusal is
        // not counted as another failure.
        $unmatchable = bin2hex(random_bytes(32));

        foreach (['password', 'passwd'] as $field) {
            if ($input->post->exists($field)) {
                $input->post->set($field, $unmatchable);
                $input->set($field, $unmatchable);
                $_POST[$field] = $_REQUEST[$field] = $unmatchable;
            }
        }

        self::$refused[self::refusalKey($username, $ip)] = true;
        $this->services->audit()->log(AuditEvent::LOGIN_LOCKED, ['subject_user_id' => $userId, 'ip' => $ip]);
    }
    public function recordFailure(string $username, string $ip): void
    {
        $db = $this->db();
        $now = $this->services->now()->setTimezone(new \DateTimeZone('UTC'));
        $time = $db->quote($now->format('Y-m-d H:i:s'));
        $stale = $db->quote($now->modify('-' . self::RESET_HOURS . ' hours')->format('Y-m-d H:i:s'));
        $failures = $db->quoteName('failures');
        $last = $db->quoteName('last_failure_utc');
        $locked = $db->quoteName('locked_until_utc');

        $buckets = self::buckets($this->key(), $username, $ip);

        foreach ($buckets as $bucket) {
            unset(self::$ownClaims[bin2hex($bucket)]);
        }

        // A sign-in that guardLogin() refused was never checked: it is not
        // another failure, and the lock does not grow by a retry while locked.
        if (isset(self::$refused[self::refusalKey($username, $ip)])) {
            unset(self::$refused[self::refusalKey($username, $ip)]);

            return;
        }

        foreach ($buckets as $bucket) {
            // One statement: concurrent failures can neither be lost nor
            // computed from the same old count. The assignments run left to
            // right, so the lock is computed from the new count and the reset
            // from the old time.
            $db->setQuery('INSERT INTO ' . $db->quoteName('#__appointments_auth_failures')
                . ' (' . implode(',', $db->quoteName(['bucket', 'failures', 'locked_until_utc', 'last_failure_utc'])) . ')'
                . ' VALUES (UNHEX(' . $db->quote(bin2hex($bucket)) . '), 1, NULL, ' . $time . ')'
                . ' ON DUPLICATE KEY UPDATE '
                . $failures . ' = IF(' . $last . ' < ' . $stale . ', 1, ' . $failures . ' + 1), '
                . $locked . ' = IF(' . $failures . ' >= ' . TeamStates::LOCK_THRESHOLD
                . ', DATE_ADD(' . $time . ', INTERVAL ' . self::delaySql($failures) . ' MINUTE), NULL), '
                . $last . ' = ' . $time)->execute();
        }
    }

    public function recordSuccess(string $username, string $ip): void
    {
        $db = $this->db();
        $buckets = self::buckets($this->key(), $username, $ip);
        $table = $db->quoteName('#__appointments_auth_failures');

        foreach ($buckets as $bucket) {
            unset(self::$ownClaims[bin2hex($bucket)]);
        }

        $db->setQuery($this->query()->delete($table)
            ->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote(bin2hex($buckets['user'])) . ')'))->execute();

        if (isset($buckets['ip'])) {
            // The address bucket keeps its count; only a reservation is
            // released. A real lock is exactly last failure + delay(failures).
            $db->setQuery($this->query()->update($table)
                ->set($db->quoteName('locked_until_utc') . ' = NULL')
                ->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote(bin2hex($buckets['ip'])) . ')')
                ->where($db->quoteName('locked_until_utc') . ' IS NOT NULL')
                ->where($db->quoteName('locked_until_utc') . ' <> DATE_ADD(' . $db->quoteName('last_failure_utc')
                    . ', INTERVAL ' . self::delaySql($db->quoteName('failures')) . ' MINUTE)'))->execute();
        }
    }

    public function purge(\DateTimeImmutable $nowUtc): int
    {
        $db = $this->db();
        $now = $nowUtc->setTimezone(new \DateTimeZone('UTC'));
        $db->setQuery($this->query()->delete($db->quoteName('#__appointments_auth_failures'))
            ->where($db->quoteName('last_failure_utc') . ' < ' . $db->quote($now->modify('-' . self::RESET_HOURS . ' hours')->format('Y-m-d H:i:s')))
            ->where('(' . $db->quoteName('locked_until_utc') . ' IS NULL OR ' . $db->quoteName('locked_until_utc')
                . ' < ' . $db->quote($now->format('Y-m-d H:i:s')) . ')'))->execute();

        return (int) $db->getAffectedRows();
    }

    /**
     * Minutes of lock after this many failures: 0 below the threshold, then
     * 1, 2, 4, 8, 16, 32 and 60 at most.
     */
    public static function delayMinutes(int $failures): int
    {
        if ($failures < TeamStates::LOCK_THRESHOLD) {
            return 0;
        }

        return min(self::MAX_DELAY_MINUTES, 2 ** min(6, $failures - TeamStates::LOCK_THRESHOLD));
    }

    /**
     * The address a bucket is kept for: IPv4 as is, IPv4-mapped IPv6 as IPv4,
     * other IPv6 as its /64 prefix. Null for anything that is not an address.
     */
    public static function normaliseAddress(string $ip): ?string
    {
        $ip = trim($ip);

        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $binary = (string) inet_pton($ip);

        if (\strlen($binary) === 16) {
            if (str_starts_with($binary, str_repeat("\0", 10) . "\xff\xff")) {
                return (string) inet_ntop(substr($binary, 12));
            }

            return inet_ntop(substr($binary, 0, 8) . str_repeat("\0", 8)) . '/64';
        }

        return (string) inet_ntop($binary);
    }

    /**
     * @return  array{user: string, ip?: string}  32 raw bytes each.
     */
    public static function buckets(string $key, string $username, string $ip): array
    {
        $buckets = ['user' => hash_hmac('sha256', CipherSuite::associatedData('lockout-user',
            mb_strtolower(trim($username), 'UTF-8')), $key, true)];
        $address = self::normaliseAddress($ip);

        if ($address !== null) {
            $buckets['ip'] = hash_hmac('sha256', CipherSuite::associatedData('lockout-ip', $address), $key, true);
        }

        return $buckets;
    }

    /**
     * Seconds until every given bucket allows an attempt.
     *
     * @param   array<string, array<string, mixed>>  $rows       By hex bucket.
     * @param   array<string, string>                $ownClaims  Reservations of this request, which do not count.
     */
    public static function waitFor(array $rows, \DateTimeImmutable $now, array $ownClaims = []): int
    {
        $wait = 0;

        foreach ($rows as $hex => $row) {
            if (self::isStale($row, $now) || empty($row['locked_until_utc']) || self::isOwnClaim((string) $hex, $row, $ownClaims)) {
                continue;
            }

            $until = new \DateTimeImmutable((string) $row['locked_until_utc'], new \DateTimeZone('UTC'));
            $wait = max($wait, $until->getTimestamp() - $now->getTimestamp());
        }

        return $wait;
    }

    /**
     * @param   array<string, mixed>   $row
     * @param   array<string, string>  $ownClaims
     */
    private static function isOwnClaim(string $hex, array $row, array $ownClaims): bool
    {
        return isset($ownClaims[$hex]) && (string) ($row['locked_until_utc'] ?? '') === $ownClaims[$hex];
    }

    private static function refusalKey(string $username, string $ip): string
    {
        return mb_strtolower(trim($username), 'UTF-8') . "\0" . (self::normaliseAddress($ip) ?? '');
    }

    /**
     * @param   array<string, mixed>  $row
     */
    private static function isStale(array $row, \DateTimeImmutable $now): bool
    {
        $last = new \DateTimeImmutable((string) $row['last_failure_utc'], new \DateTimeZone('UTC'));

        return $last->getTimestamp() < $now->getTimestamp() - self::RESET_HOURS * 3600;
    }

    /**
     * The delay of {@see self::delayMinutes()} as an SQL expression over a failure count.
     */
    private static function delaySql(string $failures): string
    {
        $sql = 'CASE';

        for ($count = TeamStates::LOCK_THRESHOLD; self::delayMinutes($count) < self::MAX_DELAY_MINUTES; $count++) {
            $sql .= ' WHEN ' . $failures . ' = ' . $count . ' THEN ' . self::delayMinutes($count);
        }

        return $sql . ' WHEN ' . $failures . ' > ' . ($count - 1) . ' THEN ' . self::MAX_DELAY_MINUTES . ' ELSE 0 END';
    }

    /**
     * @param   array{user: string, ip?: string}  $buckets
     *
     * @return  array<string, array<string, mixed>>  Rows by hex bucket.
     */
    private function load(array $buckets): array
    {
        $db = $this->db();
        $hex = array_map(static fn (string $bucket): string => 'UNHEX(' . $db->quote(bin2hex($bucket)) . ')', $buckets);
        $rows = $db->setQuery($this->query()->select([
            'LOWER(HEX(' . $db->quoteName('bucket') . ')) AS ' . $db->quoteName('hex'),
            $db->quoteName('failures'), $db->quoteName('locked_until_utc'), $db->quoteName('last_failure_utc'),
        ])->from($db->quoteName('#__appointments_auth_failures'))
            ->where($db->quoteName('bucket') . ' IN (' . implode(',', $hex) . ')'))->loadAssocList('hex');

        return \is_array($rows) ? $rows : [];
    }

    private function release(string $hex, string $until): void
    {
        $db = $this->db();
        $db->setQuery($this->query()->update($db->quoteName('#__appointments_auth_failures'))
            ->set($db->quoteName('locked_until_utc') . ' = NULL')
            ->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote($hex) . ')')
            ->where($db->quoteName('locked_until_utc') . ' = ' . $db->quote($until)))->execute();
    }

    private function key(): string
    {
        return $this->services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_AUTH_BUCKET);
    }

    private function db(): DatabaseInterface
    {
        return $this->services->db();
    }

    private function query(): QueryInterface
    {
        $db = $this->db();

        return method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
    }
}
