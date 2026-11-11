<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\StaffPrivacy;
use Advans\Plugin\Privacy\Appointments\Team\TeamPrivacy;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/plugins/privacy/appointments/src/Team/TeamPrivacy.php';
require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team/GrantServiceInterface.php';
require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team/GrantService.php';
require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/StaffPrivacy.php';

/**
 * A removal request never changes calendar entries automatically because they hold the
 * address of a team member, and the captured scope decides who is one.
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

    public function testTheCapturedScopeUsesTheAudienceRuleOfTheInternetCalendars(): void
    {
        self::assertTrue(StaffPrivacy::isTeamMember([2, 10], [10, 11], 12));
        self::assertFalse(StaffPrivacy::isTeamMember([2], [10, 11], 12));
        // A member of the external group is external, also when in a team group.
        self::assertFalse(StaffPrivacy::isTeamMember([10, 12], [10], 12));
        self::assertFalse(StaffPrivacy::isTeamMember([10], [], 0));
    }
}
