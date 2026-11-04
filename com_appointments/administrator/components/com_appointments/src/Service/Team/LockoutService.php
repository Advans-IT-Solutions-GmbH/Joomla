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
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
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
 * a locked sign-in then fails exactly like a wrong password, so the lock is no oracle.
 *
 * Buckets: a keyed hash (key(TEAM_AUTH_BUCKET)) of the account, and one of the address. The account
 * is the Joomla user id when the name belongs to an account, so spellings the database treats as the
 * same name (case, accents, ignorable characters) share one bucket; only an unknown name is
 * bucketed by its lower-case spelling. The address is IPv4 as is, an IPv4-mapped IPv6 address as
 * IPv4, any other IPv6 address as its /64 prefix, because a client controls the rest. The account
 * bucket locks regardless of the address, so changing addresses does not help; the address bucket
 * locks one source for every account. Neither stores a name or an address in clear.
 *
 * Delays: from the tenth failure in a bucket 1, 2, 4, 8, 16, 32 and then 60 minutes per further
 * failure, never longer and never permanent. A bucket without a failure for 24 hours starts again
 * at zero, and {@see self::purge()} deletes it.
 *
 * Parallel requests: every attempt is taken from the bucket under a row lock before the secret is
 * checked. Below the threshold the attempt is counted at once (a success gives it back); one
 * failure away from a lock, and between two locks, the attempt is reserved for
 * {@see self::CLAIM_SECONDS} seconds and requests in parallel are refused. A burst therefore tests
 * at most nine guesses before the first lock and one per lock window after it. A reservation ends
 * with the failure (which sets the real lock), the success, or, without a result, at the end of the
 * request as a failure.
 *
 * Subjects (`$username`) are opaque: the website passes the sign-in name, which is mapped to the
 * user id; CalDAV passes `dav:<name>@<address>`, used as it is, so CalDAV and website lock
 * independently. The address bucket counts both.
 *
 * Accepted by the specification (section 1, «never permanent»): whoever knows a user name can keep
 * that account locked, renewable without limit, at the cost of about one wrong password per lock
 * window (60 minutes at most). Administrators can lift it at once in the devices view
 * ({@see self::clearFor()}); every lock is logged as `login.locked`.
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

    /** `login.locked` is logged at most once per account in this many minutes. */
    public const LOCKED_LOG_MINUTES = 15;

    private const COUNTED = 'counted';

    /**
     * The attempts this PHP request took (bucket hex => 'counted' or the
     * reservation's end time), so a second check within the same sign-in
     * (onUserAuthorisation after guardLogin()) takes nothing again and does
     * not see its own reservation as somebody else's lock. One request only.
     *
     * @var  array<string, string>
     */
    private static $attempts = [];

    /**
     * Sign-ins refused by guardLogin() in this request.
     *
     * @var  array<string, bool>
     */
    private static $refused = [];

    /**
     * @var  bool
     */
    private static $shutdown = false;

    public function __construct(private readonly TeamServices $services)
    {
    }

    public function retryAfter(string $username, string $ip): int
    {
        $now = $this->services->now()->setTimezone(new \DateTimeZone('UTC'));
        $buckets = self::buckets($this->key(), $this->identity($username), $ip);
        $taken = [];

        foreach ($buckets as $bucket) {
            $hex = bin2hex($bucket);

            if (isset(self::$attempts[$hex])) {
                continue;
            }

            $outcome = $this->take($hex, $now);

            if (\is_int($outcome)) {
                foreach ($taken as $own) {
                    $this->giveBack($own);
                }

                // The longest wait of all buckets, not only of the one that refused.
                foreach ($buckets as $other) {
                    $outcome = max($outcome, self::waitFor([$this->row(bin2hex($other))], $now));
                }

                return max(1, $outcome);
            }

            self::$attempts[$hex] = $outcome;
            $taken[] = $hex;
        }

        $this->releaseAtShutdown();

        return 0;
    }

    public function guardLogin(CMSApplicationInterface $app): void
    {
        $input = $app->getInput();

        if (strtoupper((string) $input->getMethod()) !== 'POST' || !($app->isClient('site') || $app->isClient('administrator'))) {
            return;
        }

        // Every sign-in form posts a user name and a password at the top
        // level, whatever option or task it names (and the administrator
        // routes every guest request to its login), so this does not depend
        // on the route.
        $post = $input->post;
        $field = $post->exists('password') ? 'password' : ($post->exists('passwd') ? 'passwd' : null);
        $username = trim((string) $post->get('username', '', 'username'));

        if ($field === null || $username === '') {
            return;
        }

        // A request without a valid form token never reaches the password
        // check, so it must not take an attempt either. Compared directly:
        // Session::checkToken() would redirect a new session itself.
        if ($post->get(Session::getFormToken(), '', 'alnum') !== '1') {
            return;
        }

        $userId = (int) UserHelper::getUserId($username);
        $ip = (string) $input->server->getString('REMOTE_ADDR', '');

        // Only accounts with a calendar right are counted, so only they are locked.
        if ($userId <= 0 || $this->services->grants()->audienceOf($userId) === 'none') {
            return;
        }

        $wait = $this->retryAfter($username, $ip);

        if ($wait === 0) {
            return;
        }

        // Locked: let Joomla answer the sign-in exactly as it answers a wrong
        // password (same message, same redirect or page, same password hashing
        // time), by replacing the submitted password with 256 random bits,
        // which no stored password hash can match. The real password is never
        // compared, and this refusal is not counted as another failure.
        $unmatchable = bin2hex(random_bytes(32));

        foreach (['password', 'passwd'] as $name) {
            if ($post->exists($name)) {
                $post->set($name, $unmatchable);
                $input->set($name, $unmatchable);
                $_POST[$name] = $_REQUEST[$name] = $unmatchable;
            }
        }

        self::$refused[$this->refusalKey($username, $ip)] = true;

        // Logged, and the administrators mailed, at most once per account in
        // LOCKED_LOG_MINUTES, so a lock by somebody else is visible but no flood.
        $this->announceLock($userId, $ip, $wait);
    }

    public function recordFailure(string $username, string $ip): void
    {
        $identity = $this->identity($username);
        $buckets = self::buckets($this->key(), $identity, $ip);

        // A sign-in that guardLogin() refused was never checked: it is no
        // further failure, and the lock does not grow by retrying while locked.
        // The mark stays until the end of the request, so wasRefused() answers
        // the same before and after this call.
        if (isset(self::$refused[$this->refusalKey($username, $ip)])) {
            return;
        }

        $db = $this->db();
        $now = $this->services->now()->setTimezone(new \DateTimeZone('UTC'));

        foreach ($buckets as $bucket) {
            $hex = bin2hex($bucket);
            $taken = self::$attempts[$hex] ?? null;
            unset(self::$attempts[$hex]);

            if ($taken === self::COUNTED) {
                // Counted when the attempt was taken, below the threshold.
                $db->setQuery($this->query()->update($db->quoteName('#__appointments_auth_failures'))
                    ->set($db->quoteName('last_failure_utc') . ' = ' . $db->quote($now->format('Y-m-d H:i:s')))
                    ->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote($hex) . ')'))->execute();

                continue;
            }

            $this->addFailure($hex, $now);
        }

        // A lock that just started on an account is logged and mailed to the
        // administrators (at most every LOCKED_LOG_MINUTES per account), also
        // when nobody is refused yet, so a lock by somebody else is visible.
        if (str_starts_with($identity, 'uid:')) {
            $row = $this->row(bin2hex($buckets['user']));

            // A real lock only (last failure + delay), not a parallel request's reservation.
            if (self::isRealLock($row)) {
                $this->announceLock((int) substr($identity, 4), $ip, self::waitFor([$row], $now));
            }
        }
    }

    public function recordSuccess(string $username, string $ip): void
    {
        $db = $this->db();
        $buckets = self::buckets($this->key(), $this->identity($username), $ip);
        $table = $db->quoteName('#__appointments_auth_failures');
        $userHex = bin2hex($buckets['user']);
        unset(self::$attempts[$userHex], self::$refused[$this->refusalKey($username, $ip)]);
        $db->setQuery($this->query()->delete($table)->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote($userHex) . ')'))->execute();

        if (!isset($buckets['ip'])) {
            return;
        }

        // The address bucket keeps its failures; it only gets back what this
        // attempt took: the counted attempt, or the reservation.
        $hex = bin2hex($buckets['ip']);
        $taken = self::$attempts[$hex] ?? null;
        unset(self::$attempts[$hex]);
        $where = $db->quoteName('bucket') . ' = UNHEX(' . $db->quote($hex) . ')';

        if ($taken === self::COUNTED) {
            $db->setQuery($this->query()->update($table)
                ->set($db->quoteName('failures') . ' = GREATEST(' . $db->quoteName('failures') . ' - 1, 0)')->where($where))->execute();

            return;
        }

        // Only the reservation this request took, and only while it is still
        // the stored one: a later request's reservation stays untouched.
        if ($taken !== null) {
            $db->setQuery($this->query()->update($table)
                ->set($db->quoteName('locked_until_utc') . ' = NULL')
                ->where($where)
                ->where($db->quoteName('locked_until_utc') . ' = ' . $db->quote($taken)))->execute();
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
     * The account part of a bucket: `uid:<id>` for an existing account, else
     * the lower-case name. Pure; {@see self::identity()} resolves the id.
     */
    public static function identityFor(int $userId, string $username): string
    {
        return $userId > 0 ? 'uid:' . $userId : 'name:' . mb_strtolower(trim($username), 'UTF-8');
    }

    /**
     * @return  array{user: string, ip?: string}  32 raw bytes each.
     */
    public static function buckets(string $key, string $identity, string $ip): array
    {
        $buckets = ['user' => hash_hmac('sha256', CipherSuite::associatedData('lockout-user', $identity), $key, true)];
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
     * @param   array<string, string>                $ownClaims  Reservations of this request (hex => end time), which do not count.
     */
    public static function waitFor(array $rows, \DateTimeImmutable $now, array $ownClaims = []): int
    {
        $wait = 0;

        foreach ($rows as $hex => $row) {
            if (self::isStale($row, $now) || empty($row['locked_until_utc'])
                || (isset($ownClaims[$hex]) && (string) $row['locked_until_utc'] === $ownClaims[$hex])) {
                continue;
            }

            $until = new \DateTimeImmutable((string) $row['locked_until_utc'], new \DateTimeZone('UTC'));
            $wait = max($wait, $until->getTimestamp() - $now->getTimestamp());
        }

        return $wait;
    }

    /**
     * Take one attempt from a bucket under its row lock: the seconds to wait,
     * or what was taken ('counted' or the reservation's end time).
     */
    private function take(string $hex, \DateTimeImmutable $now): int|string
    {
        $db = $this->db();
        $table = $db->quoteName('#__appointments_auth_failures');
        $where = $db->quoteName('bucket') . ' = UNHEX(' . $db->quote($hex) . ')';
        $time = $now->format('Y-m-d H:i:s');
        // The row exists before it is locked, so the lock is a record lock and
        // concurrent first attempts cannot deadlock on the insert.
        $db->setQuery('INSERT IGNORE INTO ' . $table . ' (' . implode(',', $db->quoteName(['bucket', 'failures', 'locked_until_utc', 'last_failure_utc']))
            . ') VALUES (UNHEX(' . $db->quote($hex) . '), 0, NULL, ' . $db->quote($time) . ')')->execute();
        $db->transactionStart(true);

        try {
            $row = $db->setQuery((string) $this->query()->select($db->quoteName(['failures', 'locked_until_utc', 'last_failure_utc']))
                ->from($table)->where($where) . ' FOR UPDATE')->loadAssoc();

            if (!$row) {
                throw new \RuntimeException('The sign-in counter could not be locked.');
            }

            $stale = self::isStale($row, $now);
            $failures = $stale ? 0 : (int) $row['failures'];
            $wait = $stale ? 0 : self::waitFor(['x' => $row], $now);

            if ($wait > 0) {
                $db->transactionCommit(true);

                return $wait;
            }

            if ($failures >= TeamStates::LOCK_THRESHOLD - 1) {
                $until = $now->modify('+' . self::CLAIM_SECONDS . ' seconds')->format('Y-m-d H:i:s');
                $db->setQuery($this->query()->update($table)->set($db->quoteName('locked_until_utc') . ' = ' . $db->quote($until))
                    ->where($where))->execute();
                $db->transactionCommit(true);

                return $until;
            }

            // Counting an attempt does not move the time of the last failure
            // (a success gives the attempt back, and old failures must still
            // expire 24 hours after the last real one); only a counter that
            // starts again gets a new time.
            $query = $this->query()->update($table)
                ->set($db->quoteName('failures') . ' = ' . ($failures + 1))
                ->set($db->quoteName('locked_until_utc') . ' = NULL')
                ->where($where);

            if ($stale) {
                $query->set($db->quoteName('last_failure_utc') . ' = ' . $db->quote($time));
            }

            $db->setQuery($query)->execute();
            $db->transactionCommit(true);

            return self::COUNTED;
        } catch (\Throwable $e) {
            $db->transactionRollback(true);

            throw $e;
        }
    }

    /**
     * Undo what this request took from a bucket when another bucket refused.
     */
    private function giveBack(string $hex): void
    {
        $taken = self::$attempts[$hex] ?? null;
        unset(self::$attempts[$hex]);

        if ($taken === null) {
            return;
        }

        $db = $this->db();
        $query = $this->query()->update($db->quoteName('#__appointments_auth_failures'))
            ->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote($hex) . ')');

        if ($taken === self::COUNTED) {
            $query->set($db->quoteName('failures') . ' = GREATEST(' . $db->quoteName('failures') . ' - 1, 0)');
        } else {
            $query->set($db->quoteName('locked_until_utc') . ' = NULL')
                ->where($db->quoteName('locked_until_utc') . ' = ' . $db->quote($taken));
        }

        $db->setQuery($query)->execute();
    }

    /**
     * A request that ends without a recorded result books what it took as a
     * failure: a counted attempt stays counted, a reservation becomes a
     * failure (and so the next lock). An error between the password check and
     * the result is therefore never a free guess, and nobody can hold a
     * reservation without paying a failure for it. The price, accepted: a
     * form that only looks like a sign-in (top-level user name and password,
     * valid token) counts as an attempt for that account.
     */
    private function releaseAtShutdown(): void
    {
        if (self::$shutdown) {
            return;
        }

        self::$shutdown = true;
        register_shutdown_function(function (): void {
            try {
                $now = $this->services->now()->setTimezone(new \DateTimeZone('UTC'));

                foreach (self::$attempts as $hex => $taken) {
                    // The secret was compared, but no result was recorded (an
                    // error in a listener, an aborted request): a counted
                    // attempt stays counted and a reservation becomes a
                    // failure, so an error is never a free guess.
                    if ($taken !== self::COUNTED) {
                        $this->addFailure((string) $hex, $now);
                    }
                }
            } catch (\Throwable $e) {
                // The reservation lapses by itself after CLAIM_SECONDS.
            }

            self::$attempts = [];
            self::$refused = [];
            self::$shutdown = false;
        });
    }

    /**
     * One more failure in a bucket, in one statement: concurrent failures can
     * neither be lost nor computed from the same old count. The assignments
     * run left to right, so the lock is computed from the new count and the
     * reset from the old time.
     */
    private function addFailure(string $hex, \DateTimeImmutable $now): void
    {
        $db = $this->db();
        $time = $db->quote($now->format('Y-m-d H:i:s'));
        $stale = $db->quote($now->modify('-' . self::RESET_HOURS . ' hours')->format('Y-m-d H:i:s'));
        $failures = $db->quoteName('failures');
        $last = $db->quoteName('last_failure_utc');
        $locked = $db->quoteName('locked_until_utc');
        $db->setQuery('INSERT INTO ' . $db->quoteName('#__appointments_auth_failures')
            . ' (' . implode(',', $db->quoteName(['bucket', 'failures', 'locked_until_utc', 'last_failure_utc'])) . ')'
            . ' VALUES (UNHEX(' . $db->quote($hex) . '), 1, NULL, ' . $time . ')'
            . ' ON DUPLICATE KEY UPDATE '
            . $failures . ' = IF(' . $last . ' < ' . $stale . ', 1, ' . $failures . ' + 1), '
            . $locked . ' = IF(' . $failures . ' >= ' . TeamStates::LOCK_THRESHOLD
            . ', DATE_ADD(' . $time . ', INTERVAL ' . self::delaySql($failures) . ' MINUTE), NULL), '
            . $last . ' = ' . $time)->execute();
    }

    /**
     * Whether a counter row holds a lock set by failures (exactly last failure
     * plus the delay of its count), not a reservation.
     *
     * @param   array<string, mixed>  $row
     */
    public static function isRealLock(array $row): bool
    {
        $delay = self::delayMinutes((int) ($row['failures'] ?? 0));

        if ($delay === 0 || empty($row['locked_until_utc']) || empty($row['last_failure_utc'])) {
            return false;
        }

        $last = new \DateTimeImmutable((string) $row['last_failure_utc'], new \DateTimeZone('UTC'));

        return $last->modify('+' . $delay . ' minutes')->format('Y-m-d H:i:s') === (string) $row['locked_until_utc'];
    }

    /**
     * @return  array<string, mixed>  The counter row of a bucket, or a row without a lock.
     */
    private function row(string $hex): array
    {
        $db = $this->db();

        return $db->setQuery($this->query()->select($db->quoteName(['failures', 'locked_until_utc', 'last_failure_utc']))
            ->from($db->quoteName('#__appointments_auth_failures'))
            ->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote($hex) . ')'))->loadAssoc()
            ?: ['failures' => 0, 'locked_until_utc' => null, 'last_failure_utc' => '1970-01-01 00:00:00'];
    }

    /**
     * Log `login.locked` and mail the administrators, at most once per account
     * in LOCKED_LOG_MINUTES.
     */
    private function announceLock(int $userId, string $ip, int $wait): void
    {
        if ($userId <= 0) {
            return;
        }

        // One request per account wins the window: a named database lock
        // around the check and the log row, so parallel refusals send one mail.
        $db = $this->db();
        $name = $db->quote('com_appointments.lock.' . $userId);

        if ((int) $db->setQuery('SELECT GET_LOCK(' . $name . ', 0)')->loadResult() !== 1) {
            return;
        }

        try {
            if ($this->lockedLoggedRecently($userId)) {
                return;
            }

            $this->services->audit()->log(AuditEvent::LOGIN_LOCKED, ['subject_user_id' => $userId, 'ip' => $ip]);
        } finally {
            $db->setQuery('SELECT RELEASE_LOCK(' . $name . ')')->loadResult();
        }

        try {
            $this->services->mailer()->accountLocked($userId, $wait);
        } catch (\Throwable $e) {
            Log::add('Team calendars: the lock mail could not be sent (' . \get_class($e) . ').', Log::WARNING, 'com_appointments');
        }
    }

    private function lockedLoggedRecently(int $userId): bool
    {
        $db = $this->db();
        $since = $this->services->now()->setTimezone(new \DateTimeZone('UTC'))
            ->modify('-' . self::LOCKED_LOG_MINUTES . ' minutes')->format('Y-m-d H:i:s');

        return (int) $db->setQuery($this->query()->select('COUNT(*)')->from($db->quoteName('#__appointments_teamlog'))
            ->where($db->quoteName('event') . ' = ' . $db->quote(AuditEvent::LOGIN_LOCKED))
            ->where($db->quoteName('subject_user_id') . ' = ' . $userId)
            ->where($db->quoteName('occurred_utc') . ' >= ' . $db->quote($since)))->loadResult() > 0;
    }

    /**
     * The account part of the buckets: the user id when the name belongs to an
     * account (the database compares names with its collation), else the name.
     */
    private function identity(string $username): string
    {
        $username = trim($username);
        $userId = $username === '' ? 0 : (int) UserHelper::getUserId($username);

        // A website account always counts by its id, whatever it is called.
        // Only a subject that is no account name and comes from CalDAV
        // (`dav:<name>@<address>`) is used as it is.
        if ($userId === 0 && str_starts_with($username, 'dav:')) {
            return $username;
        }

        return self::identityFor($userId, $username);
    }

    /**
     * Whether guardLogin() refused this sign-in in this request, so the user
     * plugin can skip its `login.failure` row for a password that was never
     * compared.
     */
    public function wasRefused(string $username, string $ip): bool
    {
        return isset(self::$refused[$this->refusalKey($username, $ip)]);
    }

    /**
     * Lift the lock of an account at once (an administrator, from the devices
     * view): the account's website bucket is deleted. Address buckets stay.
     */
    public function clearFor(int $userId, ?int $actorUserId): void
    {
        if ($userId <= 0) {
            return;
        }

        $db = $this->db();
        $bucket = self::buckets($this->key(), self::identityFor($userId, ''), '')['user'];
        $db->setQuery($this->query()->delete($db->quoteName('#__appointments_auth_failures'))
            ->where($db->quoteName('bucket') . ' = UNHEX(' . $db->quote(bin2hex($bucket)) . ')'))->execute();
        $this->services->audit()->log(AuditEvent::LOGIN_UNLOCKED, ['actor_user_id' => $actorUserId, 'subject_user_id' => $userId]);
    }

    private function refusalKey(string $username, string $ip): string
    {
        return $this->identity($username) . "\0" . (self::normaliseAddress($ip) ?? '');
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
