<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Plugin\Privacy\Appointments\Team\TeamPrivacy;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/privacy/appointments/src/Team/TeamPrivacy.php';

/**
 * A removal request never changes calendar entries automatically because they hold the
 * address of a team member.
 */
final class TeamPrivacyTeamMemberTest extends TestCase
{
    public function testEntriesOfATeamMemberAreNeverChangedAutomatically(): void
    {
        self::assertFalse(TeamPrivacy::changesEntriesOf(TeamStates::AUDIENCE_TEAM));
    }

    public function testEntriesOfOtherAddressesFollowTheRemoval(): void
    {
        self::assertTrue(TeamPrivacy::changesEntriesOf(TeamStates::AUDIENCE_EXTERNAL));
        self::assertTrue(TeamPrivacy::changesEntriesOf('none'));
    }
}
