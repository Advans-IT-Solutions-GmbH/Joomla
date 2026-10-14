<?php

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-joomla.php';

$fixture = new AppointmentsFixture('administration');
$admin = new AppointmentsHttp('http://localhost', 'administration-admin');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$groupId = 0;
$userId = 0;
$events = [];
$tag = 'suite-admin-' . bin2hex(random_bytes(6));
$componentRules = $db->query("SELECT rules FROM {$prefix}assets WHERE name='com_appointments'")->fetch_assoc()['rules'];
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$listing = static function (AppointmentsHttp $client, array $filters = []): string {
    return $client->request('/administrator/index.php?' . http_build_query(array_replace([
        'option' => 'com_appointments', 'view' => 'bookings', 'filter_eventtype_id' => '',
        'filter_state' => '', 'filter_search' => '', 'filter_from' => '', 'filter_to' => '',
    ], $filters)));
};
$export = static function (AppointmentsHttp $client, array $filters = []) use ($listing): string {
    $token = $client->token($listing($client, $filters));
    return $client->request('/administrator/index.php?option=com_appointments', array_replace([
        'task' => 'bookings.exportCsv', $token => 1, 'filter_eventtype_id' => '',
        'filter_state' => '', 'filter_search' => '', 'filter_from' => '', 'filter_to' => '',
    ], $filters));
};

try {
    $admin->login();
    foreach ([0, 1] as $index) {
        $id = $fixture->event();
        $events[] = $id;
        $row = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$id}")->fetch_assoc();
        $row['conference_params'] = [];
        $admin->submit('eventtype', $row);
        $asset = $db->query("SELECT id FROM {$prefix}assets WHERE name='com_appointments.eventtype.{$id}'")->fetch_assoc();
        $check($asset && (int) $asset['id'] > 0, 'Real event administration creates its permission asset');
    }
    [$eventA, $eventB] = $events;
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    $first = $fixture->booking($eventA, $day . ' 10:00:00', ['name' => '=1+1'])['booking'];
    $second = $fixture->booking($eventA, $day . ' 11:00:00', ['name' => $tag . '-allowed'])['booking'];
    $third = $fixture->booking($eventB, $day . ' 12:00:00', ['name' => $tag . '-denied', 'email' => 'other@example.test'])['booking'];
    $id = (int) $first['id'];
    $db->query("UPDATE {$prefix}appointments_bookings SET state=0 WHERE id={$id}");
    $html = $listing($admin, ['filter_state' => '0']);
    $check($admin->status === 200 && str_contains($html, '=1+1') && !str_contains($html, $tag . '-allowed'),
        'Pending status is an explicit filter, not an empty all-status value');
    $html = $listing($admin, ['filter_eventtype_id' => $eventA, 'filter_search' => $tag . '-allowed', 'filter_from' => $day, 'filter_to' => $day]);
    $check($admin->status === 200 && str_contains($html, $tag . '-allowed')
        && !str_contains($html, '=1+1') && !str_contains($html, $tag . '-denied'), 'Event, UTC date and contact search filters combine');
    $csv = $export($admin, ['filter_eventtype_id' => $eventA]);
    $check($admin->status === 200 && str_starts_with($csv, 'id,eventtype_title,')
        && str_contains($csv, "'=1+1") && str_contains($csv, $tag . '-allowed') && !str_contains($csv, $tag . '-denied'),
        'CSV uses the selected scope, neutralises spreadsheet formulas and is not an HTML response');
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.exportCsv');
    $check($admin->status === 403, 'CSV requires a token-protected POST');
    $detail = $admin->request('/administrator/index.php?option=com_appointments&view=booking&id=' . $id);
    $check($admin->status === 200 && str_contains($detail, $first['uid']) && str_contains($detail, 'bookings.reschedule')
        && str_contains($detail, 'name="start"'), 'Booking detail displays its stable calendar identity and reschedule form');
    $token = $admin->token($detail);
    $admin->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.reschedule', 'id' => $id, 'start' => $day . 'T14:00', $token => 1,
    ]);
    $moved = $fixture->row($id);
    $check($admin->status === 200 && $moved['uid'] === $first['uid'] && (int) $moved['sequence'] === 1
        && $moved['start_utc'] === $day . ' 14:00:00' && (int) $moved['state'] === 0,
        'Administrator reschedule preserves UID and pending state, raises sequence and moves in place');
    $admin->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.reschedule', 'id' => $id, 'start' => $day . 'T15:00',
    ]);
    $check($admin->status === 403 && $fixture->row($id)['start_utc'] === $day . ' 14:00:00', 'Tokenless administrator reschedule changes nothing');
    $detail = $admin->request('/administrator/index.php?option=com_appointments&view=booking&id=' . $id);
    $admin->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.approve', 'cid' => [$id], $admin->token($detail) => 1,
    ]);
    $check((int) $fixture->row($id)['state'] === 1, 'Detail action approves a pending booking through the same locked lifecycle');
    $dashboard = $admin->request('/administrator/index.php?option=com_appointments&view=dashboard');
    $check($admin->status === 200 && str_contains($dashboard, 'Upcoming active appointments')
        && str_contains($dashboard, 'Bookings by authorised event type'), 'Dashboard renders time-aware and per-event statistics');
    $system = $admin->request('/administrator/index.php?option=com_appointments&view=system');
    $config = new JConfig();
    $check($admin->status === 200 && str_contains($system, 'Oldest accountability audit record')
        && str_contains($system, 'Latest enabled retention task') && !str_contains($system, $config->secret),
        'System checks report task/audit health without exposing signing credentials');

    $parent = $db->query("SELECT rgt FROM {$prefix}usergroups WHERE id=6")->fetch_assoc();
    $right = (int) $parent['rgt'];
    $db->begin_transaction();
    try {
        $db->query("UPDATE {$prefix}usergroups SET rgt=rgt+2 WHERE rgt>={$right}");
        $db->query("UPDATE {$prefix}usergroups SET lft=lft+2 WHERE lft>{$right}");
        $db->query("INSERT INTO {$prefix}usergroups (parent_id,lft,rgt,title) VALUES (6,{$right}," . ($right + 1) . ",'{$tag}')");
        $groupId = (int) $db->insert_id;
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $password = 'Fixture-' . bin2hex(random_bytes(12));
    $hash = $db->real_escape_string(password_hash($password, PASSWORD_BCRYPT));
    $db->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('{$tag}','{$tag}','{$tag}@example.test','{$hash}',0,0,UTC_TIMESTAMP(),'{}')");
    $userId = (int) $db->insert_id;
    $db->query("INSERT INTO {$prefix}user_usergroup_map (user_id,group_id) VALUES ({$userId},{$groupId})");
    $rules = json_decode($componentRules, true, 512, JSON_THROW_ON_ERROR);
    $rules['core.manage'][(string) $groupId] = 1;
    $rules['core.options'][(string) $groupId] = 0;
    $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string(json_encode($rules, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR)) . "' WHERE name='com_appointments'");
    foreach ([$eventA => 1, $eventB => 0] as $event => $edit) {
        $rules = $db->real_escape_string(json_encode([
            'core.edit' => [(string) $groupId => $edit], 'core.edit.state' => [(string) $groupId => 0],
            'core.delete' => [(string) $groupId => 0],
        ], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
        $db->query("UPDATE {$prefix}assets SET rules='{$rules}' WHERE name='com_appointments.eventtype.{$event}'");
    }
    $restricted = new AppointmentsHttp('http://localhost', 'administration-restricted');
    $oldUsername = getenv('JOOMLA_ADMIN_USERNAME');
    $oldPassword = getenv('JOOMLA_ADMIN_PASSWORD');
    try {
        putenv('JOOMLA_ADMIN_USERNAME=' . $tag);
        putenv('JOOMLA_ADMIN_PASSWORD=' . $password);
        $restricted->login();
    } finally {
        putenv($oldUsername === false ? 'JOOMLA_ADMIN_USERNAME' : 'JOOMLA_ADMIN_USERNAME=' . $oldUsername);
        putenv($oldPassword === false ? 'JOOMLA_ADMIN_PASSWORD' : 'JOOMLA_ADMIN_PASSWORD=' . $oldPassword);
    }
    $html = $listing($restricted);
    $check($restricted->status === 200 && str_contains($html, $tag . '-allowed') && !str_contains($html, $tag . '-denied'),
        'Per-event editor sees only permitted booking contacts');
    $csv = $export($restricted);
    $check(str_contains($csv, $tag . '-allowed') && !str_contains($csv, 'other@example.test'),
        'CSV cannot widen the editor scope');
    $dashboard = $restricted->request('/administrator/index.php?option=com_appointments&view=dashboard');
    $deniedTitle = $db->query("SELECT title FROM {$prefix}appointments_eventtypes WHERE id={$eventB}")->fetch_assoc()['title'];
    $check($restricted->status === 200 && !str_contains($dashboard, $deniedTitle), 'Dashboard statistics exclude denied event types');
    $restricted->request('/administrator/index.php?option=com_appointments&view=booking&id=' . (int) $third['id']);
    $check($restricted->status === 403, 'Direct booking details require the event permission');
    $token = $restricted->token($listing($restricted));
    $restricted->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.reschedule', 'id' => $third['id'], 'start' => $day . 'T15:00', $token => 1,
    ]);
    $check($restricted->status === 403 && $fixture->row((int) $third['id'])['start_utc'] === $third['start_utc'],
        'Direct administrator reschedule cannot bypass event permission');
    $restricted->request('/administrator/index.php?option=com_appointments&task=feeds.export&id=' . (int) $third['id']);
    $check($restricted->status === 403, 'Single-booking ICS export also enforces event permission');
    $restricted->request('/administrator/index.php?option=com_appointments&view=system');
    $check($restricted->status === 403, 'System configuration diagnostics require options permission');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string($componentRules) . "' WHERE name='com_appointments'");
    foreach ($events as $event) {
        $row = $db->query("SELECT id FROM {$prefix}assets WHERE name='com_appointments.eventtype.{$event}'")->fetch_assoc();
        if ($row) {
            $asset = new \Joomla\CMS\Table\Asset($joomlaDb);
            if (!$asset->delete((int) $row['id'])) {
                echo "FAIL Permission asset cleanup failed.\n";
                $failed++;
            }
        }
    }
    if ($userId) {
        $db->query("DELETE FROM {$prefix}session WHERE userid={$userId}");
        $db->query("DELETE FROM {$prefix}user_usergroup_map WHERE user_id={$userId}");
        $db->query("DELETE FROM {$prefix}users WHERE id={$userId}");
    }
    if ($groupId) {
        $row = $db->query("SELECT rgt FROM {$prefix}usergroups WHERE id={$groupId}")->fetch_assoc();
        $right = (int) $row['rgt'];
        $db->query("DELETE FROM {$prefix}usergroups WHERE id={$groupId}");
        $db->query("UPDATE {$prefix}usergroups SET lft=lft-2 WHERE lft>{$right}");
        $db->query("UPDATE {$prefix}usergroups SET rgt=rgt-2 WHERE rgt>{$right}");
    }
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
