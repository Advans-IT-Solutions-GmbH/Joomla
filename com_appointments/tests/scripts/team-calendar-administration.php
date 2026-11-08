<?php

use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Model\EventtypeModel;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarService;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Access\Access;
use Joomla\CMS\Factory;

/** Runs against native Joomla and its real database; owns and removes every fixture. */
return static function (TeamServices $services, int $creator): array {
    $db = $services->db();
    $group = (int) ((array) $services->params()->get('team_groups', []))[0];
    $tag = 'admincalendar' . bin2hex(random_bytes(4));
    $users = [];
    $calendars = [];
    $results = [];
    $asset = $db->setQuery("SELECT id, rules FROM #__assets WHERE name='com_appointments'")->loadObject();
    $totp = $db->setQuery("SELECT extension_id, enabled FROM #__extensions WHERE type='plugin' AND folder='multifactorauth' AND element='totp'")->loadObject();
    $originalIdentity = Factory::getApplication()->getIdentity();
    $event = $schedule = 0;
    $contexts = [];
    $check = static function (string $name, bool $passed) use (&$results): void {
        $results[$name] = $passed;
        if (!$passed) {
            throw new RuntimeException($name);
        }
    };
    $denied = static function (callable $action): bool {
        try {
            $action();
            return false;
        } catch (TeamException $e) {
            return $e->getCode() === 403;
        }
    };
    $user = static function (string $suffix, array $groups, bool $mfa = true) use ($db, $tag, &$users): int {
        $row = (object) [
            'name' => $tag . $suffix, 'username' => $tag . $suffix, 'email' => $tag . $suffix . '@example.test',
            'password' => password_hash('Synthetic-Test-Password!', PASSWORD_BCRYPT), 'block' => 0,
            'sendEmail' => 0, 'registerDate' => gmdate('Y-m-d H:i:s'), 'params' => '{}',
        ];
        $db->insertObject('#__users', $row, 'id');
        $id = (int) $row->id;
        $users[] = $id;
        foreach ($groups as $groupId) {
            $membership = (object) ['user_id' => $id, 'group_id' => $groupId];
            $db->insertObject('#__user_usergroup_map', $membership);
        }
        if ($mfa) {
            $db->setQuery("INSERT INTO #__user_mfa (user_id,title,method,`default`,options,created_on) VALUES ($id,'synthetic','totp',1,'{}',UTC_TIMESTAMP())")->execute();
        }
        return $id;
    };
    try {
        $rules = json_decode($asset->rules, true, 512, JSON_THROW_ON_ERROR);
        foreach (['core.create', 'core.edit', 'core.delete'] as $action) {
            $rules[$action][(string) $group] = true;
        }
        $db->setQuery('UPDATE #__assets SET rules=' . $db->quote(json_encode($rules, JSON_THROW_ON_ERROR)) . ' WHERE id=' . (int) $asset->id)->execute();
        $db->setQuery('UPDATE #__extensions SET enabled=1 WHERE extension_id=' . (int) $totp->extension_id)->execute();
        Access::clearStatics();
        $admin = $user('admin', [8, $group]);
        $noMfaAdmin = $user('noMfaAdmin', [8], false);
        $owner = $user('owner', [$group]);
        $newOwner = $user('newOwner', [$group]);
        $limited = $user('limited', [7]);
        $outsider = $user('outsider', [2]);
        $keys = $services->keys();
        foreach ([$owner, $newOwner, $admin] as $id) {
            $contexts[$id] = $keys->createUserKey($id, 'Synthetic-Test-Password!');
        }
        $calendar = $services->calendars()->create($creator, ['title' => $tag, 'owner_user_id' => $owner]);
        $calendars[] = $calendar;
        $check('Grantless Super User has no personal access or calendar keys', $services->grants()->accessFor($admin, $calendar) === null
            && $services->grants()->calendarsFor($admin) === [] && $keys->openKeyring($calendar, $contexts[$admin]) === null);
        $services->calendars()->update($noMfaAdmin, $calendar, ['title' => $tag . '-edited', 'retention_days' => 10]);
        $check('Grantless Super User edits foreign metadata without MFA or keyring', $services->calendars()->get($calendar)->retention_days === 10);
        $check('External-sharing changes retain their separate MFA requirement',
            $denied(static fn () => $services->calendars()->update($noMfaAdmin, $calendar, ['allow_external' => true])));
        $check('Limited Administrator and unauthorised user cannot administer foreign calendars',
            !GrantService::isAdministrator($limited) && $denied(static fn () => $services->calendars()->update($limited, $calendar, ['title' => 'denied']))
            && $denied(static fn () => $services->calendars()->delete($outsider, $calendar)));
        $db->setQuery("UPDATE #__users SET block=1 WHERE id=$admin")->execute();
        $check('Blocked Super User loses administrative authority', !GrantService::isAdministrator($admin)
            && $denied(static fn () => $services->calendars()->update($admin, $calendar, ['title' => 'denied'])));
        $db->setQuery("UPDATE #__users SET block=0 WHERE id=$admin")->execute();
        $services->calendars()->update($owner, $calendar, ['colour' => '#123456']);
        $check('Ordinary effective manager retains metadata administration', $services->calendars()->get($calendar)->colour === '#123456');
        $check('Administrative grants and owner assignment still require MFA',
            $denied(static fn () => $services->grants()->grant($noMfaAdmin, $calendar, $newOwner, CalendarRole::Manage, null, true)));
        $services->grants()->grant($admin, $calendar, $admin, CalendarRole::Read, null);
        $check('Administrative self-grant is pending and creates no fabricated wrap', $services->grants()->accessFor($admin, $calendar) === null
            && (int) $db->setQuery("SELECT COUNT(*) FROM #__appointments_calendar_key_wraps WHERE calendar_id=$calendar AND user_id=$admin")->loadResult() === 0);
        $services->grants()->grant($admin, $calendar, $newOwner, CalendarRole::Manage, null, true);
        $check('Owner transfer retains old grant but pending owner has no effective Manage or keyring',
            (int) $services->calendars()->get($calendar)->owner_user_id === $newOwner
            && $services->grants()->accessFor($owner, $calendar)?->role === CalendarRole::Manage
            && $services->grants()->accessFor($newOwner, $calendar) === null
            && $keys->openKeyring($calendar, $contexts[$newOwner]) === null);
        $ownerKeyring = $keys->openKeyring($calendar, $contexts[$owner]);
        try {
            $completed = $keys->completePendingWraps($ownerKeyring);
        } finally {
            $ownerKeyring->wipe();
        }
        $newOwnerKeyring = $keys->openKeyring($calendar, $contexts[$newOwner]);
        try {
            $check('Only genuine holder completion activates pending administrative grants and owner access',
                $completed === 2 && $newOwnerKeyring !== null
                && $services->grants()->accessFor($newOwner, $calendar)?->role === CalendarRole::Manage
                && $services->grants()->accessFor($admin, $calendar)?->role === CalendarRole::Read);
        } finally {
            $newOwnerKeyring?->wipe();
        }
        $check('Normal manager cannot change ownership',
            $denied(static fn () => $services->grants()->grant($owner, $calendar, $newOwner, CalendarRole::Manage, $contexts[$owner], true)));
        $epoch = (int) $services->calendars()->get($calendar)->grant_epoch;
        $services->grants()->revoke($noMfaAdmin, $calendar, $owner, null);
        $check('Administrative old-owner revocation removes wraps, advances epoch and marks rotation due',
            $services->grants()->accessFor($owner, $calendar) === null
            && (int) $services->calendars()->get($calendar)->grant_epoch > $epoch
            && (int) $services->calendars()->get($calendar)->rotation_due === 1
            && (int) $db->setQuery("SELECT COUNT(*) FROM #__appointments_calendar_key_wraps WHERE calendar_id=$calendar AND user_id=$owner")->loadResult() === 0);
        $services->grants()->revoke($noMfaAdmin, $calendar, $newOwner, null);
        $check('Revoking the current owner atomically clears ownership', (int) $services->calendars()->get($calendar)->owner_user_id === 0);
        $services->grants()->revoke($admin, $calendar, $admin, null);
        $check('Administrative self-revocation is allowed', $services->grants()->grantsOf($calendar) === []);
        $services->calendars()->delete($noMfaAdmin, $calendar);
        $check('Valid grantless orphan is deletable without MFA or keyring', $services->calendars()->get($calendar) === null);

        $foreign = $services->calendars()->create($creator, ['title' => $tag . '-links', 'owner_user_id' => $owner]);
        $calendars[] = $foreign;
        [$visible, $writable, $hidden, $failed] = EventtypeModel::reachable($owner, $admin);
        $check('Administrative link selection uses real host Write without actor content access',
            !$failed && isset($visible[$foreign], $writable[$foreign]) && $hidden === [] && $services->grants()->accessFor($admin, $foreign) === null);
        [$visible, $writable] = EventtypeModel::reachable($admin, $admin);
        $check('Super User host gains no invented access or Write', $visible === [] && $writable === []);
        [$visible, $writable, $hidden] = EventtypeModel::reachable($owner, $limited);
        $check('Ordinary editor still cannot publish another calendar free/busy', $visible === [] && $writable === [] && isset($hidden[$foreign]));
        $services->grants()->grant($owner, $foreign, $newOwner, CalendarRole::Read, $contexts[$owner]);
        [$visible, $writable] = EventtypeModel::reachable($newOwner, $admin);
        $check('A Read host is selectable for busy time but never as a writable target', isset($visible[$foreign]) && !isset($writable[$foreign]));
        $bridge = $services->bookings();
        $canWrite = new ReflectionMethod($bridge, 'canWrite');
        $check('Booking execution still requires genuine host Write, even for Super Users',
            $canWrite->invoke($bridge, $owner, $foreign) && !$canWrite->invoke($bridge, $newOwner, $foreign)
            && !$canWrite->invoke($bridge, $admin, $foreign));
        $configuration = new JConfig();
        $other = (new Joomla\Database\DatabaseFactory())->getDriver($configuration->dbtype, [
            'host' => $configuration->host, 'user' => $configuration->user, 'password' => $configuration->password,
            'database' => $configuration->db, 'prefix' => $configuration->dbprefix,
        ]);
        try {
            foreach (['calendar_grants', 'calendar_key_wraps'] as $table) {
                $saved = $other->setQuery("SELECT * FROM #__appointments_$table WHERE calendar_id=$foreign AND user_id=$owner")->loadObjectList();
                $db->setQuery('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')->execute();
                $db->transactionStart();
                try {
                    $before = $services->grants()->accessFor($owner, $foreign)?->role === CalendarRole::Manage;
                    $other->setQuery("DELETE FROM #__appointments_$table WHERE calendar_id=$foreign AND user_id=$owner")->execute();
                    $stale = $services->grants()->accessFor($owner, $foreign)?->role === CalendarRole::Manage;
                    $check('Locked non-admin mutations refuse stale ' . $table . ' authority', $before && $stale
                        && $denied(static fn () => $services->calendars()->update($owner, $foreign, ['title' => 'denied']))
                        && $denied(static fn () => $services->grants()->grant($owner, $foreign, $newOwner, CalendarRole::FreeBusy, null))
                        && $denied(static fn () => $services->grants()->revoke($owner, $foreign, $newOwner, null))
                        && $denied(static fn () => $services->calendars()->delete($owner, $foreign)));
                } finally {
                    $db->transactionRollback();
                    foreach ($saved as $row) {
                        $other->insertObject('#__appointments_' . $table, $row);
                    }
                }
            }
        } finally {
            $other->disconnect();
        }

        $now = gmdate('Y-m-d H:i:s');
        $row = (object) ['title' => $tag, 'timezone' => 'UTC', 'created_utc' => $now, 'modified_utc' => $now];
        $db->insertObject('#__appointments_schedules', $row, 'id');
        $schedule = (int) $row->id;
        $row = (object) ['title' => $tag, 'alias' => $tag, 'schedule_id' => $schedule, 'host_user_id' => $owner,
            'calendar_id' => $foreign, 'feed_token' => bin2hex(random_bytes(32)), 'created_utc' => $now, 'modified_utc' => $now];
        $db->insertObject('#__appointments_eventtypes', $row, 'id');
        $event = (int) $row->id;
        $link = (object) ['eventtype_id' => $event, 'calendar_id' => $foreign];
        $db->insertObject('#__appointments_eventtype_calendars', $link);
        $keyring = $keys->openKeyring($foreign, $contexts[$owner]);
        try {
            $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Synthetic//EN\r\nBEGIN:VEVENT\r\nUID:$tag\r\nDTSTAMP:20261108T120000Z\r\nDTSTART:20261109T120000Z\r\nDTEND:20261109T130000Z\r\nSUMMARY:Synthetic\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
            $services->objects()->put($keyring, 'synthetic.ics', $ics, null, true, null);
        } finally {
            $keyring?->wipe();
        }
        $check('Deletion fixture contains real encrypted object and journal',
            count($services->objects()->all($foreign)) === 1
            && (int) $db->setQuery("SELECT COUNT(*) FROM #__appointments_calendar_changes WHERE calendar_id=$foreign")->loadResult() > 0);
        $model = new EventtypeModel(['ignore_request' => true]);
        $model->setDatabase($db);
        $method = new ReflectionMethod($model, 'calendarLinks');
        $data = ['calendar_id' => 0, 'busy_calendars' => []];
        Factory::getApplication()->loadIdentity(GrantService::user($limited));
        try {
            $method->invokeArgs($model, [&$data, $event, $owner, $owner]);
            $protected = false;
        } catch (DomainException) {
            $protected = true;
        }
        $check('Ordinary editor cannot remove inaccessible calendar links', $protected);
        Factory::getApplication()->loadIdentity(GrantService::user($admin));
        $data = ['calendar_id' => 0, 'busy_calendars' => []];
        $check('Administrator can deliberately remove inaccessible public links', $method->invokeArgs($model, [&$data, $event, $owner, $owner]) === []);
        $services->cleanup()->onUserDeleted($owner);
        $db->setQuery("DELETE FROM #__users WHERE id=$owner")->execute();
        $check('Deleted owner releases valid calendar without deleting contents', (int) $services->calendars()->get($foreign)->owner_user_id === 0 && count($services->objects()->all($foreign)) === 1);
        $services->calendars()->delete($noMfaAdmin, $foreign);
        foreach (['calendar_objects', 'calendar_changes', 'calendar_keys', 'calendar_key_wraps', 'calendar_grants', 'calendar_busy', 'device_calendars', 'eventtype_calendars'] as $table) {
            $check('Administrative orphan deletion cascades ' . $table, (int) $db->setQuery("SELECT COUNT(*) FROM #__appointments_$table WHERE calendar_id=$foreign")->loadResult() === 0);
        }
        $check('Administrative deletion clears booking target and retains tombstone',
            $db->setQuery("SELECT calendar_id FROM #__appointments_eventtypes WHERE id=$event")->loadResult() === null
            && $services->anchor()->minimum('calendar', $foreign) > 1);
        return $results;
    } finally {
        Factory::getApplication()->loadIdentity($originalIdentity);
        foreach ($contexts as $context) {
            $context->wipe();
        }
        if ($event) {
            $db->setQuery("DELETE FROM #__appointments_eventtypes WHERE id=$event")->execute();
        }
        if ($schedule) {
            $db->setQuery("DELETE FROM #__appointments_schedules WHERE id=$schedule")->execute();
        }
        foreach ($calendars as $id) {
            if ($services->calendars()->get($id) !== null) {
                $services->calendars()->delete($creator, $id);
            }
        }
        foreach ($users as $id) {
            $services->cleanup()->onUserDeleted($id);
            foreach (['user_mfa', 'user_usergroup_map', 'users'] as $table) {
                $column = $table === 'users' ? 'id' : 'user_id';
                $db->setQuery("DELETE FROM #__$table WHERE $column=$id")->execute();
            }
        }
        $db->setQuery('UPDATE #__assets SET rules=' . $db->quote($asset->rules) . ' WHERE id=' . (int) $asset->id)->execute();
        $db->setQuery('UPDATE #__extensions SET enabled=' . (int) $totp->enabled . ' WHERE extension_id=' . (int) $totp->extension_id)->execute();
        Access::clearStatics();
    }
};
