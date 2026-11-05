<?php
/**
 * Work package C: calendars, grants, account activation, administration.
 *
 * 1. Schema and installation (scaffold): default deny in the schema, groups and
 *    MFA enforcement after installation.
 * 2. Administrator over HTTP, always: every view renders, every task refuses a
 *    request without token, without POST, or for a calendar whose edit id is not
 *    held, and a refused request changes nothing.
 * 3. Services end to end, in this process: the grant matrix against the real
 *    database, tampered rows, Super Users without a grant, activation links.
 *    These need the key service (B) and, for activation links, the mailer (D);
 *    while those are stubs the cases are reported as pending.
 *
 * Every row this part creates is removed at the end, and the component options
 * are restored, so the later parts see the installation as it was.
 */

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\KeyService;
use Advans\Component\Appointments\Administrator\Service\Team\CleanupService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamMailer;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;

return static function (TeamSuite $suite): void {
    $p = $suite->prefix;
    $db = $suite->db;

    // ------------------------------------------------------------------
    // 1. Schema and installation
    // ------------------------------------------------------------------

    $suite->test('A calendar has exactly the specified columns, none of them a secret address', static function () use ($suite) {
        // `synctoken` is the WebDAV sync counter (RFC 6578), not a credential.
        return $suite->columns('appointments_calendars') === ['id', 'uri', 'title', 'description', 'colour', 'owner_user_id', 'allow_external', 'blocks_availability', 'retention_days', 'synctoken', 'key_version', 'rotation_due', 'grant_epoch', 'state', 'row_mac', 'created_utc', 'modified_utc', 'checked_out', 'checked_out_time'];
    });

    $suite->test('At most one grant per user and calendar', static function () use ($suite) {
        $result = $suite->db->query("SHOW INDEX FROM {$suite->prefix}appointments_calendar_grants WHERE Key_name = 'idx_grant_calendar_user' AND Non_unique = 0");

        return $result && $result->num_rows === 2;
    });

    $suite->test('Activation links store only keyed hashes of their token and address', static function () use ($suite) {
        $columns = $suite->columns('appointments_invitations');

        return in_array('token_hash', $columns, true) && in_array('email_hash', $columns, true)
            && !in_array('token', $columns, true) && !in_array('email', $columns, true);
    });

    $suite->test('Installation created both team groups below the registration group and enforces MFA for them', static function () use ($suite) {
        $result = $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'");
        $params = json_decode((string) ($result ? $result->fetch_assoc()['params'] : ''), true) ?: [];
        $internal = (int) (($params['team_groups'] ?? [])[0] ?? 0);
        $external = (int) ($params['external_group'] ?? 0);
        $result = $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_users'");
        $users = json_decode((string) ($result ? $result->fetch_assoc()['params'] : ''), true) ?: [];
        $parent = (int) ($users['new_usertype'] ?? 2) ?: 2;
        $forced = array_map('intval', (array) ($users['forceMFAUserGroups'] ?? []));

        return $internal > 0 && $external > 0 && $internal !== $external
            && $suite->count("SELECT COUNT(*) FROM {$suite->prefix}usergroups WHERE id IN ({$internal},{$external}) AND parent_id = {$parent}") === 2
            && in_array($internal, $forced, true) && in_array($external, $forced, true)
            && (int) ($params['team_viewlevel'] ?? 0) > 0;
    });

    $suite->test('The team groups carry no permission of their own', static function () use ($suite) {
        $result = $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'");
        $params = json_decode((string) ($result ? $result->fetch_assoc()['params'] : ''), true) ?: [];
        $ids = array_filter([(int) (($params['team_groups'] ?? [])[0] ?? 0), (int) ($params['external_group'] ?? 0)]);
        $result = $suite->db->query("SELECT rules FROM {$suite->prefix}assets WHERE name = 'root.1'");
        $rules = json_decode((string) ($result ? $result->fetch_assoc()['rules'] : ''), true) ?: [];

        foreach ($rules as $groups) {
            foreach ($ids as $id) {
                if (isset($groups[(string) $id])) {
                    return false;
                }
            }
        }

        return $ids !== [];
    });

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    require_once __DIR__ . '/appointments-http.php';
    require_once __DIR__ . '/appointments-mail.php';
    require_once __DIR__ . '/appointments-joomla.php';

    $row = $db->query("SELECT params FROM {$p}extensions WHERE type='component' AND element='com_appointments'")->fetch_assoc();
    $originalParams = (string) $row['params'];
    $params = json_decode($originalParams, true) ?: [];
    $teamGroup = (int) (($params['team_groups'] ?? [])[0] ?? 0);
    $externalGroup = (int) ($params['external_group'] ?? 0);
    $tag = 'teamc' . bin2hex(random_bytes(4));
    $users = [];
    $calendars = [];
    $hash = password_hash('Fixture-Password-1!', PASSWORD_BCRYPT);
    // This part runs as another system user than the web server: an epoch
    // anchor it creates is removed again, one it changes is handed back to
    // the web server's user, so later web requests can still update it.
    $anchorFile = '/var/www/html/administrator/components/com_appointments/data/epochs.php';
    $anchorExisted = is_file($anchorFile);
    $anchorDir = is_dir(\dirname($anchorFile));
    $setOptions = static function (array $changes) use ($db, $p, $params): void {
        $json = $db->real_escape_string(json_encode(array_replace($params, $changes)));
        $db->query("UPDATE {$p}extensions SET params='{$json}' WHERE type='component' AND element='com_appointments'");
    };
    $user = static function (string $name, array $groups, bool $mfa) use ($db, $p, $tag, $hash, &$users): int {
        $login = $tag . $name;
        $db->query("INSERT INTO {$p}users (name,username,email,password,block,sendEmail,registerDate,params)"
            . " VALUES ('{$login}','{$login}','{$login}@example.test','{$hash}',0,0,UTC_TIMESTAMP(),'{}')");
        $id = (int) $db->insert_id;
        $users[] = $id;

        foreach ($groups as $group) {
            $db->query("INSERT INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$id}, " . (int) $group . ')');
        }

        if ($mfa) {
            $db->query("INSERT INTO {$p}user_mfa (user_id,title,method,`default`,options,created_on,last_used)"
                . " VALUES ({$id},'fixture','totp',1,'{}',UTC_TIMESTAMP(),NULL)");
        }

        return $id;
    };
    // A calendar row written by SQL alone: its MAC cannot be valid.
    $forgedCalendar = static function () use ($db, $p, &$calendars): int {
        $uri = bin2hex(random_bytes(16));
        $mac = bin2hex(random_bytes(32));
        $db->query("INSERT INTO {$p}appointments_calendars (uri,title,owner_user_id,allow_external,row_mac,created_utc,modified_utc)"
            . " VALUES ('{$uri}','forged',1,1,UNHEX('{$mac}'),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $id = (int) $db->insert_id;
        $calendars[] = $id;

        return $id;
    };

    try {
        // --------------------------------------------------------------
        // 2. Administrator over HTTP (no other package needed)
        // --------------------------------------------------------------

        $admin = new AppointmentsHttp('http://localhost', 'team-c');
        $admin->login();
        $base = '/administrator/index.php?option=com_appointments';

        $setOptions(['teamcal_enabled' => 0]);
        $suite->test('Switched off: the calendar list explains it and no task answers', static function () use ($admin, $base) {
            $html = $admin->request($base . '&view=calendars');
            $ok = $admin->status === 200 && str_contains($html, 'Internet calendars are switched off');
            $token = $admin->token($admin->request($base . '&view=fields'));
            $admin->request($base, ['task' => 'calendars.add', $token => 1]);

            return $ok && $admin->status === 404;
        });

        $setOptions(['teamcal_enabled' => 1]);

        foreach (['calendars' => 'Recovery key', 'calendar' => 'Owner', 'invitations' => 'Send an activation link'] as $view => $marker) {
            $suite->test("Switched on: view {$view} renders for an administrator", static function () use ($admin, $base, $view, $marker) {
                $html = $admin->request($base . '&view=' . $view);

                return $admin->status === 200 && str_contains($html, $marker) && !preg_match('/Fatal error|Warning<\/b>|Stack trace/', $html);
            });
        }

        $foreign = $forgedCalendar();
        $countCalendars = static fn (): int => $suite->count("SELECT COUNT(*) FROM {$p}appointments_calendars");
        $countGrants = static fn (): int => $suite->count("SELECT COUNT(*) FROM {$p}appointments_calendar_grants");

        $suite->test('A save without form token is refused and creates nothing', static function () use ($admin, $base, $countCalendars) {
            $before = $countCalendars();
            $admin->request($base, ['task' => 'calendars.save', 'jform' => ['title' => 'x', 'owner_user_id' => 1]]);

            return $admin->status === 403 && $countCalendars() === $before;
        });

        foreach (['grant', 'revoke', 'rotate', 'delete', 'apply'] as $task) {
            $suite->test("calendars.{$task} for a calendar whose edit id is not held is refused", static function () use ($suite, $admin, $base, $task, $foreign, $countGrants) {
                $token = $admin->token($admin->request($base . '&view=calendars'));
                $before = $countGrants();
                $admin->request($base . '&id=' . $foreign, [
                    'task' => 'calendars.' . $task, 'id' => $foreign, 'user_id' => 1, 'role' => CalendarRole::Manage->value, 'confirm' => 1,
                    'jform' => ['title' => 'taken over'], $token => 1,
                ]);

                return $admin->status === 403 && $countGrants() === $before
                    && $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_calendars WHERE id={$foreign} AND title='forged'") === 1;
            });
        }

        $suite->test('calendars.grant by GET is refused', static function () use ($admin, $base, $foreign, $countGrants) {
            $before = $countGrants();
            $admin->request($base . '&task=calendars.grant&id=' . $foreign . '&user_id=1&role=4');

            return $admin->status === 403 && $countGrants() === $before;
        });

        $suite->test('The edit view of a calendar needs its edit id held', static function () use ($admin, $base, $foreign) {
            $admin->request($base . '&view=calendar&layout=edit&id=' . $foreign);

            return $admin->status === 403;
        });

        $suite->test('Recovery tasks without form token are refused', static function () use ($admin, $base, $suite, $p) {
            $before = $suite->count("SELECT COUNT(*) FROM {$p}appointments_keyring");
            $admin->request($base, ['task' => 'calendars.recoveryCreate']);
            $first = $admin->status;
            $admin->request($base, ['task' => 'calendars.recoveryUse', 'calendar_id' => 1, 'user_id' => 1, 'role' => 4, 'recovery_secret' => 'x']);

            $second = $admin->status;
            $admin->request($base, ['task' => 'calendars.discardKey', 'user_id' => 1, 'confirm' => 1]);

            return $first === 403 && $second === 403 && $admin->status === 403 && $suite->count("SELECT COUNT(*) FROM {$p}appointments_keyring") === $before;
        });

        $outsider = $user('outsider', [2], true);
        $suite->test('An activation link is never sent without token, nor to an account outside the calendar groups', static function () use ($admin, $base, $outsider, $suite, $p) {
            $admin->request($base, ['task' => 'invitations.invite', 'user_id' => $outsider]);
            $tokenless = $admin->status === 403;
            // The invitations view renders no form while no account qualifies.
            $token = $admin->token($admin->request($base . '&view=fields'));
            $html = $admin->request($base, ['task' => 'invitations.invite', 'user_id' => $outsider, $token => 1]);

            return $tokenless && str_contains($html, 'You are not allowed to do this.')
                && $suite->count("SELECT COUNT(*) FROM {$p}appointments_invitations WHERE user_id={$outsider}") === 0;
        });

        $suite->test('invitations.revoke without confirmation or token changes nothing', static function () use ($admin, $base) {
            $admin->request($base, ['task' => 'invitations.revoke', 'id' => 1, 'confirm' => 1]);

            return $admin->status === 403;
        });

        // --------------------------------------------------------------
        // 3. Services end to end (need B, for activation also D)
        // --------------------------------------------------------------

        $needKeys = $suite->isStub(KeyService::class);
        $needMail = $needKeys || $suite->isStub(TeamMailer::class);
        $config = new JConfig();
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $clock = static function () use (&$now): DateTimeImmutable {
            return $now;
        };
        $services = new TeamServices(
            \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class),
            new KeyProvider($config->secret),
            new \Joomla\Registry\Registry(array_replace($params, ['teamcal_enabled' => 1])),
            $clock
        );
        $refused = static function (callable $call, int $code): bool {
            try {
                $call();

                return false;
            } catch (TeamException $e) {
                return $e->getCode() === $code;
            }
        };
        $grantE2e = [
            'The owner manages a new calendar, a Super User without a grant sees nothing',
            'A manager grants the team, a reader grants nobody, a manager does not share externally',
            'Only the right to share externally grants an external user, at most write',
            'Nobody changes the owner\'s grant or their own',
            'A grant without second factor, of a blocked account or outside the groups is not effective',
            'A grant or calendar row written by SQL alone grants nothing, also not after a suspension',
            'Revoking removes the key wraps and marks the rotation due',
            'Activation link: once, 72 hours, bound to the account address, closes the older link',
            'Activation link: never for an administrator account, team accounts only with core.admin',
            'Revoking a completed activation removes every grant and blocks the account',
        ];
        $replayE2e = [
            'A grant row played back after its revocation grants nothing',
            'A calendar row rolled back together with its grants is refused by the anchor',
        ];
        $deleteE2e = 'Deleting a calendar removes it with its grants and mails the owner and the managers';
        $aclE2e = 'Real Joomla ACL controls creation, external sharing and deletion independently of calendar management';
        $groupE2e = 'Real group changes invalidate the old audience and an external membership takes precedence';
        $externalSnapshotE2e = 'Disabling external access removes grants committed after the caller snapshot and never revives them';
        $snapshotE2e = array_combine(['grant', 'wrap'], array_map(
            static fn (string $lost): string => "A stale REPEATABLE READ snapshot cannot authorise mutations after a manager loses its {$lost}",
            ['grant', 'wrap']
        ));

        if ($needKeys) {
            foreach ($grantE2e as $index => $name) {
                $suite->pending($name, $index === 9 ? 'B, D, F' : ($index >= 7 ? 'B, D' : 'B'));
            }

            foreach ($replayE2e as $name) {
                $suite->pending($name, 'B');
            }

            foreach (array_merge([$aclE2e, $groupE2e, $externalSnapshotE2e], array_values($snapshotE2e)) as $name) {
                $suite->pending($name, 'B');
            }

            $suite->pending($deleteE2e, 'B, D');

            return;
        }

        // A row exactly as it was, written back: binary columns as hex, the rest quoted.
        $restore = static function (string $table, array $row) use ($db, $p): void {
            $values = array_map(static fn (string $column, $value): string => $value === null ? 'NULL'
                : ($column === 'row_mac' ? "UNHEX('" . bin2hex($value) . "')" : "'" . $db->real_escape_string((string) $value) . "'"), array_keys($row), $row);
            // Not REPLACE: its delete would cascade to the keys, wraps and grants.
            $updates = implode(',', array_map(static fn (string $column): string => "`{$column}`=VALUES(`{$column}`)", array_keys($row)));
            $db->query("INSERT INTO {$p}{$table} (`" . implode('`,`', array_keys($row)) . '`) VALUES (' . implode(',', $values) . ") ON DUPLICATE KEY UPDATE {$updates}");
        };

        $super = $user('super', [8], true);
        $owner = $user('owner', [$teamGroup], true);
        $member = $user('member', [$teamGroup], true);
        $nomfa = $user('nomfa', [$teamGroup], false);
        $external = $user('external', [$externalGroup], true);
        $keys = $services->keys();
        $createdRecovery = $keys->recoveryFingerprint() === null;

        if ($createdRecovery) {
            $keys->createRecoveryKey($super);
        }

        $ownerKeys = $keys->createUserKey($owner, 'Fixture-Password-1!');
        $keys->createUserKey($member, 'Fixture-Password-1!');
        $keys->createUserKey($external, 'Fixture-Password-1!');
        $calendar = $services->calendars()->create($super, ['title' => $tag, 'owner_user_id' => $owner, 'allow_external' => 1]);
        $calendars[] = $calendar;
        $grants = $services->grants();

        $suite->test('An unavailable calendar retains its held id and token-protected Close form', static function () use ($calendar) {
            $input = \Joomla\CMS\Factory::getApplication()->getInput();
            $originalId = $input->get('id', null, 'raw');
            $input->set('id', $calendar);
            $view = new class {
                public bool $enabled = true;
                public bool $available = false;

                public function render(): string
                {
                    ob_start();

                    try {
                        include JPATH_ADMINISTRATOR . '/components/com_appointments/tmpl/calendar/edit.php';

                        return (string) ob_get_contents();
                    } finally {
                        ob_end_clean();
                    }
                }
            };

            try {
                $html = $view->render();
            } finally {
                $input->set('id', $originalId);
            }

            return str_contains($html, 'id="calendar-form" class="form-validate"')
                && str_contains($html, 'name="id" value="' . $calendar . '"')
                && preg_match('/name="[a-f0-9]{32}" value="1"/', $html) === 1
                && !str_contains($html, 'name="jform[');
        });

        $suite->test('A calendar edit can be cancelled after disabling the feature, with token and edit-id release', static function () use ($admin, $base, $calendar, $setOptions) {
            $admin->request($base . '&task=calendars.edit&id=' . $calendar);
            $setOptions(['teamcal_enabled' => 0]);

            try {
                $html = $admin->request($base . '&view=calendar&layout=edit&id=' . $calendar);
                $renders = $admin->status === 200 && str_contains($html, 'id="toolbar-cancel"');
                $admin->request($base, ['task' => 'calendars.cancel', 'id' => $calendar]);
                $protected = $admin->status === 403;
                $admin->request($base, ['task' => 'calendars.cancel', 'id' => $calendar, $admin->token($html) => 1]);
                $closed = $admin->status === 200;
            } finally {
                $setOptions(['teamcal_enabled' => 1]);
            }

            $admin->request($base . '&view=calendar&layout=edit&id=' . $calendar);

            return $renders && $protected && $closed && $admin->status === 403;
        });

        $suite->test($aclE2e, static function () use (
            $services, $grants, $owner, $member, $nomfa, $external, $outsider, $teamGroup, $refused, $db, $p, $tag, &$calendars
        ) {
            $asset = $db->query("SELECT id,rules FROM {$p}assets WHERE name='com_appointments'")->fetch_assoc();
            $original = (string) $asset['rules'];
            $rules = json_decode($original, true) ?: [];
            $apply = static function (array $permissions) use ($db, $p, $asset, $rules, $teamGroup): void {
                $changed = $rules;

                foreach ($permissions as $action => $allowed) {
                    $changed[$action][(string) $teamGroup] = $allowed;
                }

                $json = $db->real_escape_string(json_encode($changed));
                $db->query("UPDATE {$p}assets SET rules='{$json}' WHERE id=" . (int) $asset['id']);
                \Joomla\CMS\Access\Access::clearStatics();
            };

            try {
                $apply(['core.create' => false, 'core.delete' => false, 'appointments.team.external' => false]);
                $deniedCreate = $refused(static fn () => $services->calendars()->create($owner, ['title' => $tag . '-acl', 'owner_user_id' => $owner]), 403);
                $apply(['core.create' => true, 'core.delete' => false, 'appointments.team.external' => true]);
                $id = $services->calendars()->create($owner, ['title' => $tag . '-acl', 'owner_user_id' => $owner, 'allow_external' => 1]);
                $calendars[] = $id;
                // This member has the Joomla sharing permission but no calendar grant.
                $grants->grant($member, $id, $external, CalendarRole::FreeBusy, null);
                $shared = $grants->accessFor($external, $id)?->role === CalendarRole::FreeBusy
                    && $grants->accessFor($member, $id) === null;
                $limits = $refused(static fn () => $grants->grant($member, $id, $external, CalendarRole::Manage, null), 403)
                    && $refused(static fn () => $grants->grant($member, $id, $nomfa, CalendarRole::Read, null), 403)
                    && $refused(static fn () => $grants->grant($owner, $id, $outsider, CalendarRole::FreeBusy, null), 403);
                $deniedDelete = $refused(static fn () => $services->calendars()->delete($owner, $id), 403);
                $apply(['core.create' => true, 'core.delete' => false, 'appointments.team.external' => false]);
                $deniedShare = $refused(static fn () => $grants->grant($owner, $id, $external, CalendarRole::Read, null), 403);
                $apply(['core.delete' => true]);
                $services->calendars()->delete($owner, $id);

                return $deniedCreate && $shared && $limits && $deniedDelete && $deniedShare
                    && (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_calendars WHERE id={$id}")->fetch_row()[0] === 0;
            } finally {
                $json = $db->real_escape_string($original);
                $db->query("UPDATE {$p}assets SET rules='{$json}' WHERE id=" . (int) $asset['id']);
                \Joomla\CMS\Access\Access::clearStatics();
            }
        });

        $suite->test($groupE2e, static function () use ($grants, $keys, $user, $teamGroup, $externalGroup, $super, $owner, $ownerKeys, $calendar, $refused, $db, $p) {
            $dual = $user('dual', [$teamGroup, $externalGroup], true);
            $keys->createUserKey($dual, 'Fixture-Password-1!');
            $externalFirst = $grants->audienceOf($dual) === TeamStates::AUDIENCE_EXTERNAL;
            $noManager = $refused(static fn () => $grants->grant($super, $calendar, $dual, CalendarRole::Manage, null), 403);
            $grants->grant($super, $calendar, $dual, CalendarRole::FreeBusy, null);
            $externalAccess = $grants->accessFor($dual, $calendar)?->role === CalendarRole::FreeBusy;
            $db->query("DELETE FROM {$p}user_usergroup_map WHERE user_id={$dual} AND group_id={$externalGroup}");
            $oldAudienceRefused = $grants->accessFor($dual, $calendar) === null;
            $grants->grant($owner, $calendar, $dual, CalendarRole::Read, $ownerKeys);
            $teamAccess = $grants->accessFor($dual, $calendar)?->role === CalendarRole::Read;
            $db->query("INSERT INTO {$p}user_usergroup_map (user_id,group_id) VALUES ({$dual},{$externalGroup})");

            return $externalFirst && $noManager && $externalAccess && $oldAudienceRefused && $teamAccess
                && $grants->accessFor($dual, $calendar) === null;
        });

        $suite->test($grantE2e[0], static function () use ($grants, $calendar, $owner, $super) {
            return $grants->accessFor($owner, $calendar)?->role === CalendarRole::Manage
                && $grants->accessFor($super, $calendar) === null
                && array_filter($grants->calendarsFor($super), static fn ($a) => $a->calendarId === $calendar) === [];
        });

        $suite->test($grantE2e[1], static function () use ($grants, $calendar, $owner, $member, $nomfa, $external, $ownerKeys, $refused) {
            $grants->grant($owner, $calendar, $member, CalendarRole::Read, $ownerKeys);

            return $grants->accessFor($member, $calendar)?->role === CalendarRole::Read
                && $refused(static fn () => $grants->grant($member, $calendar, $nomfa, CalendarRole::Read, null), 403)
                && $refused(static fn () => $grants->grant($owner, $calendar, $external, CalendarRole::Read, $ownerKeys), 403);
        });

        $suite->test($grantE2e[2], static function () use ($grants, $calendar, $super, $external, $refused) {
            $grants->grant($super, $calendar, $external, CalendarRole::FreeBusy, null);

            return $grants->accessFor($external, $calendar)?->role === CalendarRole::FreeBusy
                && $refused(static fn () => $grants->grant($super, $calendar, $external, CalendarRole::Manage, null), 403);
        });

        $suite->test($grantE2e[3], static function () use ($grants, $calendar, $owner, $member, $super, $refused) {
            return $refused(static fn () => $grants->grant($owner, $calendar, $owner, CalendarRole::Read, null), 403)
                && $refused(static fn () => $grants->revoke($owner, $calendar, $owner, null), 403)
                && $refused(static fn () => $grants->grant($member, $calendar, $member, CalendarRole::Manage, null), 403)
                && $refused(static fn () => $grants->revoke($super, $calendar, $owner, null), 403);
        });

        $suite->test($grantE2e[4], static function () use ($grants, $calendar, $owner, $member, $nomfa, $ownerKeys, $teamGroup, $db, $p) {
            $grants->grant($owner, $calendar, $nomfa, CalendarRole::FreeBusy, $ownerKeys);
            $noSecondFactor = $grants->accessFor($nomfa, $calendar) === null;
            $db->query("UPDATE {$p}users SET block=1 WHERE id={$member}");
            $blocked = $grants->accessFor($member, $calendar) === null;
            $db->query("UPDATE {$p}users SET block=0 WHERE id={$member}");
            $db->query("DELETE FROM {$p}user_usergroup_map WHERE user_id={$member} AND group_id={$teamGroup}");
            $outside = $grants->accessFor($member, $calendar) === null;
            $db->query("INSERT INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$member}, {$teamGroup})");

            return $noSecondFactor && $blocked && $outside && $grants->accessFor($member, $calendar)?->role === CalendarRole::Read;
        });

        $suite->test($grantE2e[5], static function () use ($grants, $services, $calendar, $member, $outsider, $foreign, $teamGroup, $db, $p) {
            $raised = $db->query("UPDATE {$p}appointments_calendar_grants SET role=" . CalendarRole::Manage->value . " WHERE calendar_id={$calendar} AND user_id={$member}");
            $tampered = $raised && $grants->accessFor($member, $calendar) === null;
            $db->query("UPDATE {$p}appointments_calendar_grants SET role=" . CalendarRole::Read->value . " WHERE calendar_id={$calendar} AND user_id={$member}");
            $db->query("INSERT INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$outsider}, {$teamGroup})");
            $mac = bin2hex(random_bytes(32));
            $db->query("INSERT INTO {$p}appointments_calendar_grants (calendar_id,user_id,role,state,audience,granted_by,row_mac,created_utc,modified_utc)"
                . " VALUES ({$calendar},{$outsider},4,1,'team',1,UNHEX('{$mac}'),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
            $forgedGrant = $grants->accessFor($outsider, $calendar) === null;
            $grants->suspendAllForUser($outsider, 'test');
            $db->query("UPDATE {$p}appointments_calendar_grants SET state=1 WHERE user_id={$outsider}");
            $stillForged = $grants->accessFor($outsider, $calendar) === null;
            $db->query("UPDATE {$p}appointments_calendars SET allow_external=0 WHERE id={$calendar}");
            $calendarTampered = $services->calendars()->get($calendar) === null && $grants->accessFor($member, $calendar) === null;
            $db->query("UPDATE {$p}appointments_calendars SET allow_external=1 WHERE id={$calendar}");

            return $tampered && $forgedGrant && $stillForged && $calendarTampered
                && $services->calendars()->get($foreign) === null && $grants->accessFor(1, $foreign) === null;
        });

        $suite->test($grantE2e[6], static function () use ($grants, $calendar, $owner, $member, $db, $p) {
            $grants->revoke($owner, $calendar, $member, null);
            $row = $db->query("SELECT rotation_due FROM {$p}appointments_calendars WHERE id={$calendar}")->fetch_assoc();

            return $grants->accessFor($member, $calendar) === null && (int) $row['rotation_due'] === 1
                && (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_calendar_key_wraps WHERE calendar_id={$calendar} AND user_id={$member}")->fetch_row()[0] === 0;
        });

        $suite->test($replayE2e[0], static function () use ($grants, $calendar, $super, $external, $restore, $db, $p) {
            $before = $db->query("SELECT * FROM {$p}appointments_calendar_grants WHERE calendar_id={$calendar} AND user_id={$external}")->fetch_assoc();
            $effective = $grants->accessFor($external, $calendar)?->role === CalendarRole::FreeBusy;
            $grants->revoke($super, $calendar, $external, null);
            $restore('appointments_calendar_grants', $before);
            $replayed = $grants->accessFor($external, $calendar) === null;
            $db->query("DELETE FROM {$p}appointments_calendar_grants WHERE calendar_id={$calendar} AND user_id={$external}");

            return $before !== null && $effective && $replayed;
        });

        $boss = $user('boss', [$teamGroup, 8], true);
        $keys->createUserKey($boss, 'Fixture-Password-1!');
        $second = $services->calendars()->create($boss, ['title' => $tag . '-second', 'owner_user_id' => $boss, 'allow_external' => 1]);
        $calendars[] = $second;

        foreach ($snapshotE2e as $lost => $name) {
            $suite->test($name, static function () use (
                $services, $grants, $owner, $ownerKeys, $boss, $member, $refused, $db, $p, $tag, &$calendars, $lost
            ) {
                $title = $tag . '-snapshot-' . $lost;
                $id = $services->calendars()->create($boss, ['title' => $title, 'owner_user_id' => $owner]);
                $calendars[] = $id;
                $grants->grant($owner, $id, $boss, CalendarRole::Manage, $ownerKeys);
                $grants->grant($owner, $id, $member, CalendarRole::Read, $ownerKeys);
                $connection = $services->db();
                $connection->setQuery('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->execute();
                $connection->transactionStart();

                try {
                    $before = $grants->accessFor($boss, $id)?->role === CalendarRole::Manage;
                    // The suite's mysqli connection commits independently of Joomla's
                    // snapshot. Do not advance the anchor: it must not be needed to
                    // detect a missing grant or wrap in the current database.
                    $table = $lost === 'grant' ? 'appointments_calendar_grants' : 'appointments_calendar_key_wraps';
                    $db->query("DELETE FROM {$p}{$table} WHERE calendar_id={$id} AND user_id={$boss}");
                    $stale = $grants->accessFor($boss, $id)?->role === CalendarRole::Manage;
                    $checks = [];
                    $checks[] = $refused(static fn () => $services->calendars()->update($boss, $id, ['title' => 'refused']), 403);
                    $checks[] = $refused(static fn () => $grants->grant($boss, $id, $member, CalendarRole::FreeBusy, null), 403);
                    $checks[] = $refused(static fn () => $grants->revoke($boss, $id, $member, null), 403);
                    $checks[] = $refused(static fn () => $services->calendars()->delete($boss, $id), 403);
                    $unchanged = $connection->setQuery("SELECT title FROM {$p}appointments_calendars WHERE id={$id} FOR UPDATE")->loadResult() === $title
                        && (int) $connection->setQuery("SELECT role FROM {$p}appointments_calendar_grants WHERE calendar_id={$id} AND user_id={$member} FOR UPDATE")->loadResult() === CalendarRole::Read->value;

                    return $before && $stale && !in_array(false, $checks, true) && $unchanged;
                } finally {
                    $connection->transactionRollback();
                }
            });
        }

        $suite->test($externalSnapshotE2e, static function () use ($services, $grants, $boss, $external, $config, $params, $clock, $tag, &$calendars) {
            $id = $services->calendars()->create($boss, ['title' => $tag . '-external-snapshot', 'owner_user_id' => $boss, 'allow_external' => 1]);
            $calendars[] = $id;
            $other = (new \Joomla\Database\DatabaseFactory())->getDriver($config->dbtype, [
                'host' => $config->host, 'user' => $config->user, 'password' => $config->password,
                'database' => $config->db, 'prefix' => $config->dbprefix,
            ]);
            $concurrent = new TeamServices(
                $other, new KeyProvider($config->secret),
                new \Joomla\Registry\Registry(array_replace($params, ['teamcal_enabled' => 1])), $clock
            );
            $connection = $services->db();
            $connection->setQuery('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->execute();
            $connection->transactionStart();

            try {
                $empty = $grants->accessFor($external, $id) === null;
                $concurrent->grants()->grant($boss, $id, $external, CalendarRole::FreeBusy, null);
                $created = $concurrent->grants()->accessFor($external, $id)?->role === CalendarRole::FreeBusy;
                $services->calendars()->update($boss, $id, ['allow_external' => 0]);
                $removed = (int) $connection->setQuery(
                    'SELECT user_id FROM ' . $connection->quoteName('#__appointments_calendar_grants')
                    . ' WHERE calendar_id=' . $id . ' AND user_id=' . $external . ' FOR UPDATE'
                )->loadResult() === 0;
                $services->calendars()->update($boss, $id, ['allow_external' => 1]);
            } finally {
                // Roll back the settings changes after observing them; the
                // independent grant remains committed for the second check.
                $connection->transactionRollback();
                $other->disconnect();
            }

            // Repeat outside the old snapshot to verify committed off/on
            // never brings back access without a new explicit grant.
            $services->calendars()->update($boss, $id, ['allow_external' => 0]);
            $services->calendars()->update($boss, $id, ['allow_external' => 1]);

            return $empty && $created && $removed && $grants->accessFor($external, $id) === null;
        });

        $suite->test($replayE2e[1], static function () use ($grants, $services, $second, $boss, $external, $restore, $db, $p) {
            $grants->grant($boss, $second, $external, CalendarRole::FreeBusy, null);
            $calendarBefore = $db->query("SELECT * FROM {$p}appointments_calendars WHERE id={$second}")->fetch_assoc();
            $grantBefore = $db->query("SELECT * FROM {$p}appointments_calendar_grants WHERE calendar_id={$second} AND user_id={$external}")->fetch_assoc();
            $services->calendars()->update($boss, $second, ['allow_external' => 0]);
            $removed = $grants->accessFor($external, $second) === null;
            $restore('appointments_calendars', $calendarBefore);
            $restore('appointments_calendar_grants', $grantBefore);
            $refused = $services->calendars()->get($second) === null && $grants->accessFor($external, $second) === null;

            return $services->anchor()->writable() && $removed && $refused;
        });

        if ($needMail) {
            foreach (array_slice($grantE2e, 7) as $name) {
                $suite->pending($name, 'D');
            }

            $suite->pending($deleteE2e, 'D');

            return;
        }

        $invitations = $services->invitations();
        $tokenFor = static function (array $before): ?string {
            foreach (AppointmentsMail::messagesSince($before) as $mime) {
                if (preg_match('#https://[^\s"<>]*token=([A-Za-z0-9_-]{43})#', AppointmentsMail::decoded($mime), $match)) {
                    $parts = parse_url(html_entity_decode($match[0], ENT_QUOTES, 'UTF-8'));
                    parse_str($parts['query'] ?? '', $query);
                    $path = $parts['path'] ?? '';

                    if (!str_ends_with($path, '/index.php') || substr_count($path, '/index.php') !== 1
                        || ($query['option'] ?? '') !== 'com_appointments' || ($query['view'] ?? '') !== 'activation') {
                        throw new RuntimeException('The activation mail does not contain a direct, canonical activation URL.');
                    }

                    return $match[1];
                }
            }

            return null;
        };
        $fresh = $user('fresh', [$teamGroup], false);

        $suite->test($grantE2e[7], static function () use ($invitations, $super, $fresh, $tokenFor, $refused, &$now, $db, $p) {
            $before = AppointmentsMail::snapshot();
            $invitations->invite($super, $fresh);
            $old = $tokenFor($before);
            $before = AppointmentsMail::snapshot();
            $invitations->invite($super, $fresh);
            $token = $tokenFor($before);

            $closedOld = $old !== null && $invitations->inspect($old) === null;
            $shown = $token !== null && ($invitations->inspect($token)['username'] ?? '') !== '';
            $weak = $refused(static fn () => $invitations->complete($token, 'short'), 400);
            $later = $now;
            $now = $now->modify('+73 hours');
            $expired = $invitations->inspect($token) === null;
            $now = $later;
            $db->query("UPDATE {$p}users SET email='other-{$fresh}@example.test' WHERE id={$fresh}");
            $rebound = $invitations->inspect($token) === null;
            $db->query("UPDATE {$p}users SET email=CONCAT(username,'@example.test') WHERE id={$fresh}");
            $done = $invitations->complete($token, 'Fixture-Password-2!x') === $fresh;
            $once = $refused(static fn () => $invitations->complete($token, 'Fixture-Password-3!x'), 404);

            return $closedOld && $shown && $weak && $expired && $rebound && $done && $once;
        });

        $suite->test($grantE2e[8], static function () use ($invitations, $super, $owner, $external, $user, $refused, $teamGroup) {
            $manager = $user('backend', [$teamGroup, 6], false);

            return $refused(static fn () => $invitations->invite($super, $manager), 409)
                && $refused(static fn () => $invitations->invite($owner, $external), 403)
                && $refused(static fn () => $invitations->invite($external, $owner), 403);
        });

        if ($suite->isStub(CleanupService::class)) {
            // The one-step revoke starts the device clean-up before it closes the link.
            $suite->pending($grantE2e[9], 'F');
        } else $suite->test($grantE2e[9], static function () use ($invitations, $grants, $super, $owner, $user, $teamGroup, $tokenFor, $calendar, $ownerKeys, $refused, $db, $p) {
            $fresh = $user('revoked', [$teamGroup], false);
            $before = AppointmentsMail::snapshot();
            $id = $invitations->invite($super, $fresh);
            $token = $tokenFor($before);

            if ($token === null) {
                throw new RuntimeException('The activation fixture mail contains no token.');
            }

            $invitations->complete($token, 'Fixture-Password-2!x');
            $grants->grant($owner, $calendar, $fresh, CalendarRole::Read, $ownerKeys);
            $nobody = $refused(static fn () => $invitations->revoke($owner, $id), 403);
            $invitations->revoke($super, $id);

            return $id > 0 && $nobody && $refused(static fn () => $invitations->revoke($super, $id), 404)
                && (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_calendar_grants WHERE user_id={$fresh}")->fetch_row()[0] === 0
                && (int) $db->query("SELECT block FROM {$p}users WHERE id={$fresh}")->fetch_row()[0] === 1;
        });

        $third = $services->calendars()->create($boss, ['title' => $tag . '-third', 'owner_user_id' => $owner]);
        $calendars[] = $third;
        $grants->grant($owner, $third, $boss, CalendarRole::Manage, $ownerKeys);

        $suite->test($deleteE2e, static function () use ($services, $third, $boss, $tag, $db, $p) {
            $before = AppointmentsMail::snapshot();
            $services->calendars()->delete($boss, $third);
            $mailed = false;

            foreach (AppointmentsMail::messagesSince($before) as $mime) {
                $mailed = $mailed || str_contains(AppointmentsMail::decoded($mime), $tag . 'owner@example.test');
            }

            return $mailed
                && (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_calendars WHERE id={$third}")->fetch_row()[0] === 0
                && (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_calendar_grants WHERE calendar_id={$third}")->fetch_row()[0] === 0;
        });
    } finally {
        $json = $db->real_escape_string($originalParams);
        $db->query("UPDATE {$p}extensions SET params='{$json}' WHERE type='component' AND element='com_appointments'");

        foreach ($calendars as $id) {
            $db->query("UPDATE {$p}appointments_eventtypes SET calendar_id=NULL WHERE calendar_id=" . (int) $id);
            $db->query("DELETE FROM {$p}appointments_calendars WHERE id=" . (int) $id);
        }

        if ($users !== []) {
            $list = implode(',', array_map('intval', $users));

            foreach (['appointments_invitations', 'appointments_calendar_grants', 'appointments_calendar_key_wraps', 'appointments_user_keys', 'user_mfa', 'user_usergroup_map'] as $table) {
                $db->query("DELETE FROM {$p}{$table} WHERE user_id IN ({$list})");
            }

            $db->query("DELETE FROM {$p}users WHERE id IN ({$list})");
        }

        if (!empty($createdRecovery)) {
            $db->query("DELETE FROM {$p}appointments_keyring WHERE purpose='recovery'");
        }

        if (!$anchorExisted) {
            foreach (array_merge([$anchorFile, $anchorFile . '.lock'], glob(\dirname($anchorFile) . '/*.tmp') ?: []) as $file) {
                @unlink($file);
            }

            if (!$anchorDir) {
                @rmdir(\dirname($anchorFile));
            }
        } elseif (\function_exists('posix_getpwnam') && ($web = posix_getpwnam('www-data'))) {
            foreach ([\dirname($anchorFile), $anchorFile, $anchorFile . '.lock'] as $file) {
                if (file_exists($file) && fileowner($file) !== $web['uid']) {
                    @chown($file, $web['uid']);
                    @chgrp($file, $web['gid']);
                }
            }
        }
    }
};
