<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;

defined('_JEXEC') or die;

/**
 * Work package F replaces the body of this class; the contract is
 * {@see LockoutServiceInterface}. Until then every method refuses with 501, so nothing
 * half-built is reachable.
 *
 * @since  1.4.0
 */
final class LockoutService implements LockoutServiceInterface
{
    public function __construct(private readonly TeamServices $services)
    {
    }

    public function retryAfter(string $username, string $ip): int
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function recordFailure(string $username, string $ip): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function recordSuccess(string $username, string $ip): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function purge(\DateTimeImmutable $nowUtc): int
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }
}
