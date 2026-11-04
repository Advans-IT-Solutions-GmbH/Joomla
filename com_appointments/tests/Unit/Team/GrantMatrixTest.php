<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/TeamServiceAutoload.php';

/**
 * The grant decisions of work package C as pure functions: who may give whom
 * which role (actor role × right to share externally × audience of the grantee
 * × role × calendar setting), who may remove, and when a grant is effective.
 */
final class GrantMatrixTest extends TestCase
{
    private const TEAM = TeamStates::AUDIENCE_TEAM;
    private const EXTERNAL = TeamStates::AUDIENCE_EXTERNAL;
    private const NONE = GrantService::AUDIENCE_NONE;

    /**
     * The whole matrix, computed from the rules in the specification
     * independently of the implementation, then compared case by case.
     */
    public function testGrantMatrixIsExactlyTheSpecifiedOne(): void
    {
        $actorRoles = array_merge([null], CalendarRole::cases());
        $count = 0;

        foreach ($actorRoles as $actorRole) {
            foreach ([false, true] as $shares) {
                foreach ([self::TEAM, self::EXTERNAL, self::NONE] as $audience) {
                    foreach (CalendarRole::cases() as $role) {
                        foreach ([false, true] as $allowsExternal) {
                            foreach ([false, true] as $owner) {
                                foreach ([false, true] as $self) {
                                    $expected = !$self && match ($audience) {
                                        self::TEAM     => $actorRole === CalendarRole::Manage && (!$owner || $role === CalendarRole::Manage),
                                        self::EXTERNAL => $shares && $allowsExternal && !$owner && $role->value <= CalendarRole::Write->value,
                                        default        => false,
                                    };
                                    $actual = GrantService::mayGrant($actorRole, $shares, $audience, $role, $allowsExternal, $owner, $self);
                                    self::assertSame($expected, $actual, sprintf(
                                        'actor %s, shares %d, grantee %s, role %s, allows external %d, owner %d, self %d',
                                        $actorRole?->name ?? 'none', $shares, $audience, $role->name, $allowsExternal, $owner, $self
                                    ));
                                    $count++;
                                }
                            }
                        }
                    }
                }
            }
        }

        self::assertSame(5 * 2 * 3 * 4 * 2 * 2 * 2, $count);
    }

    public function testNoActorEverGrantsAnExternalUserManage(): void
    {
        foreach (array_merge([null], CalendarRole::cases()) as $actorRole) {
            self::assertFalse(GrantService::mayGrant($actorRole, true, self::EXTERNAL, CalendarRole::Manage, true, false, false));
        }
    }

    public function testAManagerWithoutTheExternalRightCannotShareExternally(): void
    {
        self::assertFalse(GrantService::mayGrant(CalendarRole::Manage, false, self::EXTERNAL, CalendarRole::FreeBusy, true, false, false));
    }

    public function testTheExternalRightAloneDoesNotShareWithTheTeam(): void
    {
        // A Super User has every permission, but no grant: default deny holds.
        self::assertFalse(GrantService::mayGrant(null, true, self::TEAM, CalendarRole::Read, true, false, false));
        self::assertFalse(GrantService::mayGrant(CalendarRole::Write, true, self::TEAM, CalendarRole::Read, true, false, false));
    }

    public function testNobodyRaisesTheirOwnRole(): void
    {
        foreach (CalendarRole::cases() as $role) {
            self::assertFalse(GrantService::mayGrant(CalendarRole::Manage, true, self::TEAM, $role, true, false, true));
        }
    }

    public function testRevokeMatrix(): void
    {
        foreach (array_merge([null], CalendarRole::cases()) as $actorRole) {
            foreach ([false, true] as $shares) {
                foreach ([self::TEAM, self::EXTERNAL] as $stored) {
                    foreach ([self::TEAM, self::EXTERNAL, self::NONE] as $current) {
                        foreach ([false, true] as $owner) {
                            foreach ([false, true] as $self) {
                                $expected = !$self && !$owner && ($actorRole === CalendarRole::Manage
                                    || ($shares && ($stored === self::EXTERNAL || $current === self::EXTERNAL)));
                                self::assertSame($expected, GrantService::mayRevoke($actorRole, $shares, $stored, $current, $owner, $self));
                            }
                        }
                    }
                }
            }
        }
    }

    public function testRecoveryBypassesTheManagerRuleButNotTheAudienceRules(): void
    {
        self::assertTrue(GrantService::mayGrantWithRecovery(true, false, self::TEAM, CalendarRole::Manage, false, false));
        self::assertFalse(GrantService::mayGrantWithRecovery(false, true, self::TEAM, CalendarRole::Read, true, false));
        self::assertFalse(GrantService::mayGrantWithRecovery(true, true, self::EXTERNAL, CalendarRole::Manage, true, false));
        self::assertFalse(GrantService::mayGrantWithRecovery(true, false, self::EXTERNAL, CalendarRole::Read, true, false));
        self::assertFalse(GrantService::mayGrantWithRecovery(true, true, self::EXTERNAL, CalendarRole::Read, false, false));
        self::assertTrue(GrantService::mayGrantWithRecovery(true, true, self::EXTERNAL, CalendarRole::Write, true, false));
        self::assertFalse(GrantService::mayGrantWithRecovery(true, true, self::NONE, CalendarRole::Read, true, false));
        self::assertFalse(GrantService::mayGrantWithRecovery(true, false, self::TEAM, CalendarRole::Read, false, true));
    }

    public function testAMemberOfBothGroupsIsExternal(): void
    {
        self::assertSame(self::EXTERNAL, GrantService::audienceFromGroups([2, 10, 11], [10], 11));
        self::assertSame(self::TEAM, GrantService::audienceFromGroups([2, 10], [10, 12], 11));
        self::assertSame(self::NONE, GrantService::audienceFromGroups([2, 8], [10], 11));
        self::assertSame(self::NONE, GrantService::audienceFromGroups([2], [], 0));
        self::assertSame(self::NONE, GrantService::audienceFromGroups([0], [0], 0));
    }

    /**
     * @return  array<string, array{0: array<string, mixed>}>
     */
    public static function ineffectiveGrants(): array
    {
        return [
            'forged grant row'               => [['grant_mac' => false]],
            'forged calendar row'            => [['calendar_mac' => false]],
            'suspended'                      => [['grant_state' => TeamStates::GRANT_SUSPENDED]],
            'unknown state'                  => [['grant_state' => 9]],
            'calendar not active'            => [['calendar_state' => 0]],
            'account deleted'                => [['account_exists' => false]],
            'account blocked'                => [['account_blocked' => true]],
            'account left the groups'        => [['audience' => self::NONE]],
            'team grant of an external now'  => [['audience' => self::EXTERNAL]],
            'external grant, externals off'  => [['audience' => self::EXTERNAL, 'grant_audience' => self::EXTERNAL, 'calendar_allows_external' => false]],
            'external with manage'           => [['audience' => self::EXTERNAL, 'grant_audience' => self::EXTERNAL, 'role' => CalendarRole::Manage]],
            'no second factor'               => [['second_factor' => false]],
            'key role without wrap'          => [['wrap' => false]],
            'unknown role'                   => [['role' => null]],
        ];
    }

    #[DataProvider('ineffectiveGrants')]
    public function testEveryMissingConditionMakesAGrantIneffective(array $change): void
    {
        self::assertTrue(GrantService::isEffective(self::facts()));
        self::assertFalse(GrantService::isEffective(array_replace(self::facts(), $change)));
    }

    public function testPendingKeyWithAWrapIsEffectiveAndFreeBusyNeedsNoWrap(): void
    {
        self::assertTrue(GrantService::isEffective(array_replace(self::facts(), ['grant_state' => TeamStates::GRANT_PENDING_KEY])));
        self::assertTrue(GrantService::isEffective(array_replace(self::facts(), ['role' => CalendarRole::FreeBusy, 'wrap' => false])));
        self::assertTrue(GrantService::isEffective(array_replace(self::facts(), [
            'audience' => self::EXTERNAL, 'grant_audience' => self::EXTERNAL, 'role' => CalendarRole::Write,
        ])));
    }

    public function testExpensiveFactsAreOnlyLookedUpWhenNeeded(): void
    {
        $called = false;
        $facts = array_replace(self::facts(), [
            'grant_mac' => false,
            'second_factor' => static function () use (&$called): bool {
                $called = true;

                return true;
            },
        ]);

        self::assertFalse(GrantService::isEffective($facts));
        self::assertFalse($called);
    }

    public function testMacFieldsAreNormalisedAndCoverNoDisplayData(): void
    {
        $fromDatabase = (object) [
            'id' => '7', 'calendar_id' => '3', 'user_id' => '5', 'role' => '2', 'state' => '1', 'audience' => 'team',
            'granted_by' => '9', 'client_props' => '{"colour":"#FF0000"}', 'row_mac' => 'x', 'created_utc' => 'a', 'modified_utc' => 'b',
        ];
        $written = ['calendar_id' => 3, 'user_id' => 5, 'role' => 2, 'state' => 1, 'audience' => 'team', 'granted_by' => 9];

        self::assertSame($written, GrantService::macFields($fromDatabase));
        self::assertSame(GrantService::macFields($written), GrantService::macFields($fromDatabase));
    }

    public function testClientPropsAcceptOnlyColourAndOrder(): void
    {
        self::assertSame(['colour' => '#AABBCC', 'order' => 5], GrantService::mergeClientProps([], ['order' => 5, 'colour' => '#aabbcc']));
        self::assertSame(['order' => 5], GrantService::mergeClientProps(['colour' => '#AABBCC', 'order' => 5], ['colour' => null]));
        self::assertSame(['colour' => '#AABBCCDD'], GrantService::mergeClientProps(['junk' => 1], ['colour' => '#aabbccdd']));

        foreach ([['colour' => 'red'], ['colour' => '#abc'], ['order' => -1], ['order' => 10000], ['order' => '5'], ['role' => 4]] as $bad) {
            try {
                GrantService::mergeClientProps([], $bad);
                self::fail('Accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testEveryRoleHasItsOwnLabel(): void
    {
        $labels = array_map(static fn (?CalendarRole $role): string => GrantService::roleLabelKey($role), array_merge([null], CalendarRole::cases()));

        self::assertSame($labels, array_values(array_unique($labels)));
    }

    /**
     * @return  array<string, mixed>  An effective team grant with the read role.
     */
    private static function facts(): array
    {
        return [
            'role' => CalendarRole::Read, 'grant_mac' => true, 'grant_state' => TeamStates::GRANT_ACTIVE,
            'grant_audience' => self::TEAM, 'calendar_mac' => true, 'calendar_state' => 1,
            'calendar_allows_external' => true, 'account_exists' => true, 'account_blocked' => false,
            'audience' => self::TEAM, 'second_factor' => true, 'wrap' => true,
        ];
    }
}
