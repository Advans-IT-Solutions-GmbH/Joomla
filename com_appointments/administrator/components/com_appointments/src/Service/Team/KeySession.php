<?php
/**
 * @package     Appointments Component
 * @subpackage  Administrator
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Administrator\Service\Team;

use Advans\Component\Appointments\Administrator\Helper\Team\KeyContext;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;

defined('_JEXEC') or die;

/**
 * Work package B replaces the body of this class; the contract is
 * {@see KeySessionInterface}. Until then every method refuses with 501, so nothing
 * half-built is reachable.
 *
 * @since  1.4.0
 */
final class KeySession implements KeySessionInterface
{
    public function __construct(private readonly TeamServices $services)
    {
    }

    public function onLogin(int $userId, string $clearPassword): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function onLogout(int $userId): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function onPasswordChanged(int $userId, string $clearPassword): void
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function current(): ?KeyContext
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }

    public function requireUnlocked(): KeyContext
    {
        throw new TeamException('COM_APPOINTMENTS_TEAM_NOT_READY', 501);
    }
}
