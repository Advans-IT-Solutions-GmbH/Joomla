<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Joomla\CMS\Application\CMSApplicationInterface;

defined('_JEXEC') or die;

/**
 * Temporary lock after failed sign-ins (work package F), for CalDAV requests
 * and for the website sign-in of users with a calendar right. Two buckets per
 * attempt, each a keyed hash: the subject and the address (IPv6 per /64).
 * `$username` is an opaque subject: the website passes the sign-in name (F maps
 * it to the user id), CalDAV passes `dav:<name>@<address>`, which F uses as it
 * is, so CalDAV and website lock independently.
 * From the tenth failure in a bucket, the bucket is locked for 1, 2, 4, 8, 16,
 * 32 and then 60 minutes per further failure, never longer and never
 * permanently. A success resets the user bucket.
 *
 * When the check itself fails (exception): the website sign-in fails open, so a
 * database error never locks everybody out, including administrators; the
 * failure is logged (Joomla log and `login.guard_failed` when the log is
 * writable) and the system check warns. A CalDAV sign-in and «Add device» fail
 * closed: the request is refused with a generic 401 or 503 without detail. Counters without a failure for
 * 24 hours are deleted. While locked, a correct password is refused too, so the
 * lock is no oracle.
 *
 * @since  1.4.0
 */
interface LockoutServiceInterface
{
    /**
     * @return  int  Seconds until the next attempt is allowed, 0 when allowed now.
     */
    public function retryAfter(string $username, string $ip): int;

    /**
     * Read the existing account/address lock without reserving an authentication
     * attempt. For already authenticated actions that do not compare a secret.
     */
    public function remainingLock(string $username, string $ip): int;

    /**
     * Before Joomla checks a password. When the request is a sign-in (site:
     * com_users task user.login; administrator: com_login task login) and the
     * user name or the address is locked, the sign-in is answered exactly like a
     * wrong password (same message, same redirect) without checking the
     * password, and `login.locked` is logged. Called by the system plugin in
     * onAfterRoute; `onUserAuthorisation` stays as the second safeguard.
     */
    public function guardLogin(CMSApplicationInterface $app): void;

    /**
     * Whether guardLogin() refused this subject and address in the current
     * request. The user plugin then writes no login.failure for the attempt.
     */
    public function wasRefused(string $username, string $ip): bool;

    public function recordFailure(string $username, string $ip): void;

    public function recordSuccess(string $username, string $ip): void;

    /**
     * Delete counters without a failure for 24 hours.
     *
     * @return  int  Rows deleted.
     */
    public function purge(\DateTimeImmutable $nowUtc): int;
}
