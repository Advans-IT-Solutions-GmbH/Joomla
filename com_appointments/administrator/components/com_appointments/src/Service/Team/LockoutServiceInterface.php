<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

defined('_JEXEC') or die;

/**
 * Temporary lock after failed sign-ins (work package F), for CalDAV requests
 * and for the website sign-in of users with a calendar right. Two buckets per
 * attempt, each a keyed hash: the user name (lower case) and the address.
 * From the tenth failure in a bucket, the bucket is locked for 1, 2, 4, 8, 16,
 * 32 and then 60 minutes per further failure, never longer and never
 * permanently. A success resets the user bucket. Counters without a failure for
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

    public function recordFailure(string $username, string $ip): void;

    public function recordSuccess(string $username, string $ip): void;

    /**
     * Delete counters without a failure for 24 hours.
     *
     * @return  int  Rows deleted.
     */
    public function purge(\DateTimeImmutable $nowUtc): int;
}
