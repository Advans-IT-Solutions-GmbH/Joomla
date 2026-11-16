<?php

/**
 * Search tools of the administrator lists: every filter narrows the list it belongs to, never
 * beyond the permission scope of the user, paging and the number per page work, the stored
 * filters can be cleared, earlier flat links keep working, a forged value of a strict list is
 * refused, and the booking fields keep their per-event-type drag order.
 */

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-joomla.php';

$fixture = new AppointmentsFixture('listfilters');
$admin = new AppointmentsHttp(appointments_test_base_url(), 'listfilters-admin');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$groupId = 0;
$userId = 0;
$events = [];
$templateIds = [];
$blockedIds = [];
$logIds = [];
$tag = 'suite-filter-' . bin2hex(random_bytes(5));
$componentRules = $db->query("SELECT rules FROM {$prefix}assets WHERE name='com_appointments'")->fetch_assoc()['rules'];
$componentParams = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc()['params'];
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
// A list page with searchtools parameters, the way the filter form submits them.
$page = static function (AppointmentsHttp $client, string $view, array $filter, array $list = [], array $extra = []): string {
    return $client->request('/administrator/index.php?' . http_build_query(array_replace([
        'option' => 'com_appointments', 'view' => $view, 'filter' => $filter,
        'list' => array_replace(['limit' => 100], $list),
    ], $extra)));
};
$ids = static function (string $html): array {
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $found = [];

    foreach ((new DOMXPath($document))->query('//form[@id="adminForm"]//tbody/tr//input[@name="cid[]"]') as $input) {
        $found[] = (int) $input->getAttribute('value');
    }

    return $found;
};
$sorted = static function (array $values): array {
    sort($values);

    return $values;
};
$options = static function (string $html, string $name): array {
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $found = [];

    foreach ((new DOMXPath($document))->query('//select[@name="' . $name . '"]/option') as $option) {
        $found[(string) $option->getAttribute('value')] = trim($option->textContent);
    }

    return $found;
};
$searchtools = static function (string $html, array $filters): bool {
    foreach ($filters as $name) {
        if (!str_contains($html, 'name="filter[' . $name . ']"')) {
            return false;
        }
    }

    return str_contains($html, 'name="list[limit]"') && str_contains($html, 'name="list[fullordering]"')
        && str_contains($html, 'js-stools-btn-clear') && !str_contains($html, 'COM_APPOINTMENTS_');
};

try {
    $admin->login();

    // Two event types with permission assets, so a per-event permission can be set.
    foreach (['a', 'b'] as $letter) {
        $id = $fixture->event();
        $events[] = $id;
        $row = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$id}")->fetch_assoc();
        $row['title'] = $tag . '-event-' . $letter;
        $row['conference_params'] = [];
        $admin->submit('eventtype', $row);
    }
    [$eventA, $eventB] = $events;
    $scheduleA = (int) $db->query("SELECT schedule_id FROM {$prefix}appointments_eventtypes WHERE id={$eventA}")->fetch_row()[0];
    $scheduleB = (int) $db->query("SELECT schedule_id FROM {$prefix}appointments_eventtypes WHERE id={$eventB}")->fetch_row()[0];
    $check($scheduleA > 0 && $scheduleB > 0 && $db->query("SELECT COUNT(*) FROM {$prefix}assets WHERE name IN ('com_appointments.eventtype.{$eventA}','com_appointments.eventtype.{$eventB}')")->fetch_row()[0] == 2,
        'Fixture event types have their permission assets');

    // A second administrator account: the editor of event type A only, and a host for the lists.
    $right = (int) $db->query("SELECT rgt FROM {$prefix}usergroups WHERE id=6")->fetch_assoc()['rgt'];
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
    $db->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('{$tag}-host','{$tag}','{$tag}@example.test','{$hash}',0,0,UTC_TIMESTAMP(),'{}')");
    $userId = (int) $db->insert_id;
    $db->query("INSERT INTO {$prefix}user_usergroup_map (user_id,group_id) VALUES ({$userId},{$groupId})");
    $rules = json_decode($componentRules, true, 512, JSON_THROW_ON_ERROR);
    $rules['core.manage'][(string) $groupId] = 1;
    $rules['core.options'][(string) $groupId] = 0;
    $rules['core.admin'][(string) $groupId] = 0;
    $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string(json_encode($rules, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR)) . "' WHERE name='com_appointments'");
    foreach ([$eventA => 1, $eventB => 0] as $event => $edit) {
        $eventRules = $db->real_escape_string(json_encode([
            'core.edit' => [(string) $groupId => $edit], 'core.edit.state' => [(string) $groupId => 0],
            'core.delete' => [(string) $groupId => 0],
        ], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
        $db->query("UPDATE {$prefix}assets SET rules='{$eventRules}' WHERE name='com_appointments.eventtype.{$event}'");
    }
    $restricted = new AppointmentsHttp(appointments_test_base_url(), 'listfilters-restricted');
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

    // ------------------------------------------------------------------ booking fields
    $field = static function (int $event, string $label, string $type, int $required, int $published, int $ordering, string $choices = '[]') use ($db, $prefix): int {
        $label = $db->real_escape_string($label);
        $db->query("INSERT INTO {$prefix}appointments_fields (eventtype_id,label,type,options,required,published,ordering)"
            . " VALUES ({$event},'{$label}','{$type}','{$choices}',{$required},{$published},{$ordering})");

        return (int) $db->insert_id;
    };
    $fA1 = $field($eventA, $tag . '-text', 'text', 1, 1, 1);
    $fA2 = $field($eventA, $tag . '-notes', 'textarea', 0, 0, 2);
    $fA3 = $field($eventA, $tag . '-choice', 'radio', 1, 1, 3, '["x","y"]');
    $fB1 = $field($eventB, $tag . '-mail', 'email', 1, 1, 1);
    $fB2 = $field($eventB, $tag . '-other', 'text', 0, 1, 2);
    $fieldFilter = ['search' => $tag, 'eventtype_id' => '', 'type' => '', 'required' => '', 'published' => ''];
    $fields = static fn (AppointmentsHttp $client, array $filter, array $list = [], array $extra = []): string
        => $page($client, 'fields', array_replace($fieldFilter, $filter), array_replace(['fullordering' => 'f.ordering ASC'], $list), $extra);

    $html = $fields($admin, []);
    $check($admin->status === 200 && $ids($html) === [$fA1, $fA2, $fA3, $fB1, $fB2],
        'Booking fields sorted by order are grouped by event type, in booking-form order within each group');
    $check($searchtools($html, ['search', 'eventtype_id', 'type', 'required', 'published']),
        'Booking fields show the searchtools: search, event type, field type, required, status, sorting, number per page, clear');
    $eventOptions = $options($html, 'filter[eventtype_id]');
    $check(isset($eventOptions[(string) $eventA], $eventOptions[(string) $eventB]), 'The event type filter offers the event types the administrator may edit');
    $typeOptions = $options($html, 'filter[type]');
    $check(isset($typeOptions['radio'], $typeOptions['checkboxes'], $typeOptions['date']) && \count($typeOptions) === 11,
        'The field type filter offers exactly the ten types of the edit form');
    $check($ids($fields($admin, ['eventtype_id' => $eventA])) === [$fA1, $fA2, $fA3], 'Event type filter narrows the booking fields');
    $check($ids($fields($admin, ['type' => 'radio'])) === [$fA3], 'Field type filter narrows the booking fields');
    $check($ids($fields($admin, ['required' => '1'])) === [$fA1, $fA3, $fB1]
        && $ids($fields($admin, ['required' => '0'])) === [$fA2, $fB2], 'Required filter narrows the booking fields in both directions');
    $check($ids($fields($admin, ['published' => '0'])) === [$fA2], 'Status filter narrows the booking fields');
    $check($ids($fields($admin, ['eventtype_id' => $eventB, 'required' => '1'])) === [$fB1], 'Booking field filters combine');
    $check($ids($fields($admin, ['search' => $tag . '-choice'])) === [$fA3], 'The search finds a booking field by its label');
    $check($ids($fields($admin, ['search' => 'id:' . $fB2])) === [$fB2], 'The search finds a booking field by id:<number>');
    // Not a type name: dropped like an empty filter, never part of the query.
    $check($ids($fields($admin, ['type' => "text' OR '1'='1"])) === [$fA1, $fA2, $fA3, $fB1, $fB2] && $admin->status === 200,
        'A crafted field type is ignored as a filter and never reaches the query');
    $paged = [];
    foreach ([0, 2, 4] as $start) {
        $paged[] = $ids($fields($admin, [], ['limit' => 2], ['limitstart' => $start]));
    }
    $check(array_map('count', $paged) === [2, 2, 1] && array_merge(...$paged) === [$fA1, $fA2, $fA3, $fB1, $fB2],
        'The number per page and the pages of the booking fields cover every row once');
    $fields($admin, ['eventtype_id' => $eventA]);
    $kept = $ids($admin->request('/administrator/index.php?option=com_appointments&view=fields'));
    $cleared = $ids($fields($admin, []));
    $check($kept === [$fA1, $fA2, $fA3] && $cleared === [$fA1, $fA2, $fA3, $fB1, $fB2],
        'A filter is kept for the next visit and clearing it shows every row again');

    // Drag and drop: within the event type only, also while the list is filtered by event type.
    $html = $fields($admin, ['eventtype_id' => $eventA]);
    $check(str_contains($html, 'js-draggable') && str_contains($html, 'task=fields.saveOrderAjax')
        && str_contains($html, 'data-nested="true"') && str_contains($html, 'data-draggable-group="' . $eventA . '"')
        && substr_count($html, 'name="order[]"') === 3, 'The booking fields filtered by event type can be dragged within the event type');
    $check(!str_contains($fields($admin, [], ['fullordering' => 'f.label ASC']), 'js-draggable'),
        'Dragging is off when the list is not sorted by the field order');
    $token = $admin->token($html);
    $response = $admin->request('/administrator/index.php?option=com_appointments&task=fields.saveOrderAjax&tmpl=component', [
        $token => 1, 'cid' => [$fA3, $fA1, $fA2], 'order' => [1, 2, 3],
    ]);
    $order = static function (int $event) use ($db, $prefix): array {
        $result = $db->query("SELECT id FROM {$prefix}appointments_fields WHERE eventtype_id={$event} ORDER BY ordering, id");

        return array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'id'));
    };
    $numbers = static function (int $event) use ($db, $prefix): array {
        $result = $db->query("SELECT ordering FROM {$prefix}appointments_fields WHERE eventtype_id={$event} ORDER BY ordering, id");

        return array_map('intval', array_column($result->fetch_all(MYSQLI_ASSOC), 'ordering'));
    };
    $check(trim($response) === '1' && $order($eventA) === [$fA3, $fA1, $fA2] && $numbers($eventA) === [1, 2, 3]
        && $order($eventB) === [$fB1, $fB2] && $numbers($eventB) === [1, 2],
        'Saving the dragged order renumbers the event type 1, 2, 3 and leaves the other event type alone');
    $check($ids($fields($admin, ['eventtype_id' => $eventA])) === [$fA3, $fA1, $fA2], 'The list shows the saved booking-form order');
    $admin->request('/administrator/index.php?option=com_appointments&task=fields.saveOrderAjax&tmpl=component', [
        'cid' => [$fA2, $fA3, $fA1], 'order' => [1, 2, 3],
    ]);
    $check($order($eventA) === [$fA3, $fA1, $fA2], 'Saving an order needs the form token');
    $admin->request('/administrator/index.php?option=com_appointments&task=fields.saveOrderAjax&tmpl=component', [
        $token => 1, 'cid' => [2147480000], 'order' => [1],
    ]);
    $check((int) $db->query("SELECT COUNT(*) FROM {$prefix}appointments_fields WHERE label=''")->fetch_row()[0] === 0,
        'An unknown id in a saved order creates no empty booking field');

    // The editor of event type A: no state permission, so no drag handle and no reordering.
    $html = $fields($restricted, []);
    $check($restricted->status === 200 && $ids($html) === [$fA3, $fA1, $fA2] && !str_contains($html, 'name="order[]"'),
        'An editor sees only the fields of their event type and, without state permission, no drag order');
    $restrictedOptions = $options($html, 'filter[eventtype_id]');
    $check(isset($restrictedOptions[(string) $eventA]) && !isset($restrictedOptions[(string) $eventB]),
        'The event type filter of an editor offers only their event types');
    $check($ids($fields($restricted, ['eventtype_id' => $eventB])) === [] && $ids($fields($restricted, ['search' => 'id:' . $fB1])) === [],
        'A filter on a denied event type or id shows nothing of it');
    $restricted->request('/administrator/index.php?option=com_appointments&task=fields.saveOrderAjax&tmpl=component', [
        $restricted->token($html) => 1, 'cid' => [$fA2, $fA3, $fA1], 'order' => [1, 2, 3],
    ]);
    $check($order($eventA) === [$fA3, $fA1, $fA2], 'An editor without state permission cannot reorder the booking fields');

    // ------------------------------------------------------------------ e-mail templates
    $template = static function (int $event, string $purpose, string $language, string $subject, int $published) use ($db, $prefix): int {
        $subject = $db->real_escape_string($subject);
        $db->query("INSERT INTO {$prefix}appointments_templates (eventtype_id,purpose,language,subject,body,published)"
            . " VALUES ({$event},'{$purpose}','{$language}','{$subject}','Fixture',{$published})");

        return (int) $db->insert_id;
    };
    $tA1 = $templateIds[] = $template($eventA, 'confirmation', 'en-GB', $tag . ' tpl one', 1);
    $tA2 = $templateIds[] = $template($eventA, 'reminder', 'de-DE', $tag . ' tpl two', 0);
    $tB1 = $templateIds[] = $template($eventB, 'confirmation', 'it-CH', $tag . ' tpl three', 1);
    $tS = $templateIds[] = $template(0, 'cancellation', 'xq-XQ', $tag . ' tpl site', 1);
    $templateFilter = ['search' => $tag, 'eventtype_id' => '', 'purpose' => '', 'language' => '', 'published' => ''];
    $templates = static fn (AppointmentsHttp $client, array $filter): string
        => $page($client, 'templates', array_replace($templateFilter, $filter), ['fullordering' => 't.id ASC']);
    $html = $templates($admin, []);
    $check($admin->status === 200 && $ids($html) === [$tA1, $tA2, $tB1, $tS]
        && $searchtools($html, ['search', 'eventtype_id', 'purpose', 'language', 'published']),
        'E-mail templates show the searchtools: search, scope, purpose, language, status, sorting, number per page, clear');
    $scopes = $options($html, 'filter[eventtype_id]');
    $languages = $options($html, 'filter[language]');
    $purposes = $options($html, 'filter[purpose]');
    $check(isset($scopes['0'], $scopes[(string) $eventA], $scopes[(string) $eventB], $languages['it-CH'], $languages['xq-XQ'], $purposes['reminder'], $purposes['team_account_locked']),
        'The template filters offer site-wide, every event type, the languages in use and every purpose of the edit form');
    $check($ids($templates($admin, ['eventtype_id' => $eventA])) === [$tA1, $tA2], 'Scope filter narrows the templates to one event type');
    $check($ids($templates($admin, ['eventtype_id' => '0'])) === [$tS], 'Scope filter 0 is the site-wide fallback and not every scope');
    $check($ids($templates($admin, ['purpose' => 'reminder'])) === [$tA2], 'Purpose filter narrows the templates');
    $check($ids($templates($admin, ['language' => 'it-CH'])) === [$tB1], 'Language filter narrows the templates');
    $check($ids($templates($admin, ['published' => '0'])) === [$tA2], 'Status filter narrows the templates');
    $check($ids($templates($admin, ['search' => $tag . ' tpl three'])) === [$tB1], 'The search finds a template by its subject');
    $legacy = static fn (AppointmentsHttp $client, string $scope): array => $ids($client->request('/administrator/index.php?' . http_build_query([
        'option' => 'com_appointments', 'view' => 'templates', 'filter_eventtype_id' => $scope, 'filter_search' => $tag, 'limit' => 100,
    ])));
    $check($legacy($admin, '0') === [$tS] && $legacy($admin, '-1') === [$tA1, $tA2, $tB1, $tS] && $legacy($admin, (string) $eventB) === [$tB1],
        'Links of earlier releases with filter_eventtype_id (-1, 0, an id) still filter the templates');
    $html = $templates($restricted, []);
    $restrictedLanguages = $options($html, 'filter[language]');
    $restrictedScopes = $options($html, 'filter[eventtype_id]');
    $check($ids($html) === [$tA1, $tA2] && isset($restrictedLanguages['de-DE']) && !isset($restrictedLanguages['it-CH'])
        && !isset($restrictedLanguages['xq-XQ']) && !isset($restrictedScopes['0']) && !isset($restrictedScopes[(string) $eventB]),
        'An editor sees only their templates, and the scope and language filters reveal nothing beyond them');
    $check($ids($templates($restricted, ['eventtype_id' => $eventB])) === [] && $ids($templates($restricted, ['eventtype_id' => '0'])) === []
        && $ids($templates($restricted, ['language' => 'it-CH'])) === [],
        'Template filters on a denied scope or language show nothing of it');

    // ------------------------------------------------------------------ blocked periods
    $blocked = static function (int $user, string $title, string $start, string $end) use ($db, $prefix): int {
        $title = $db->real_escape_string($title);
        $db->query("INSERT INTO {$prefix}appointments_blocked (user_id,title,start_utc,end_utc,created_utc)"
            . " VALUES ({$user},'{$title}','{$start}','{$end}',UTC_TIMESTAMP())");

        return (int) $db->insert_id;
    };
    $at = static fn (string $modify): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modify)->format('Y-m-d H:i:s');
    $bPast = $blockedIds[] = $blocked($fixture->host, $tag . ' past', $at('-10 days'), $at('-9 days'));
    $bNext = $blockedIds[] = $blocked($fixture->host, $tag . ' next', $at('+5 days'), $at('+6 days'));
    $bOther = $blockedIds[] = $blocked($userId, $tag . ' other', $at('+7 days'), $at('+8 days'));
    $blockedFilter = ['search' => $tag, 'user_id' => '', 'period' => ''];
    $blockeds = static fn (array $filter, array $list = []): string
        => $page($admin, 'blockeds', array_replace($blockedFilter, $filter), array_replace(['fullordering' => 'b.start_utc DESC'], $list));
    $html = $blockeds([]);
    $hosts = $options($html, 'filter[user_id]');
    $check($admin->status === 200 && $ids($html) === [$bOther, $bNext, $bPast] && $searchtools($html, ['search', 'user_id', 'period'])
        && ($hosts[(string) $userId] ?? '') === $tag . '-host',
        'Blocked periods show the searchtools: search, host, period, sorting, number per page, clear');
    $check($ids($blockeds(['period' => 'past'])) === [$bPast] && $ids($blockeds(['period' => 'upcoming'])) === [$bOther, $bNext],
        'Period filter separates past from current and upcoming blocked periods');
    $check($ids($blockeds(['user_id' => (string) $userId])) === [$bOther], 'Host filter narrows the blocked periods');
    $check($ids($blockeds(['search' => $tag . ' next'])) === [$bNext], 'The search finds a blocked period by its note');
    $check($ids($blockeds([], ['fullordering' => 'b.title ASC'])) === [$bNext, $bOther, $bPast], 'Blocked periods sort by note');
    $check(\count($ids($blockeds([], ['limit' => 2]))) === 2, 'The number per page limits the blocked periods');

    // ------------------------------------------------------------------ schedules and event types
    $db->query("UPDATE {$prefix}appointments_schedules SET title='{$tag} schedule a' WHERE id={$scheduleA}");
    $db->query("UPDATE {$prefix}appointments_schedules SET title='{$tag} schedule b', published=0, owner_user_id={$userId} WHERE id={$scheduleB}");
    $scheduleFilter = ['search' => $tag, 'published' => '', 'owner_user_id' => ''];
    $schedules = static fn (array $filter): string
        => $page($admin, 'schedules', array_replace($scheduleFilter, $filter), ['fullordering' => 's.title ASC']);
    $html = $schedules([]);
    $check($admin->status === 200 && $ids($html) === [$scheduleA, $scheduleB] && $searchtools($html, ['search', 'published', 'owner_user_id']),
        'Schedules show the searchtools: search, status, host, sorting, number per page, clear');
    $check($ids($schedules(['published' => '0'])) === [$scheduleB] && $ids($schedules(['published' => '1'])) === [$scheduleA],
        'Status filter narrows the schedules');
    $check($ids($schedules(['owner_user_id' => (string) $userId])) === [$scheduleB], 'Host filter narrows the schedules');
    $db->query("UPDATE {$prefix}appointments_eventtypes SET published=0 WHERE id={$eventB}");
    $eventFilter = ['search' => $tag, 'published' => '', 'schedule_id' => ''];
    $eventtypes = static fn (AppointmentsHttp $client, array $filter): string
        => $page($client, 'eventtypes', array_replace($eventFilter, $filter), ['fullordering' => 'e.id ASC']);
    $html = $eventtypes($admin, []);
    $check($admin->status === 200 && $ids($html) === [$eventA, $eventB] && $searchtools($html, ['search', 'published', 'schedule_id']),
        'Event types show the searchtools: search, status, schedule, sorting, number per page, clear');
    $check($ids($eventtypes($admin, ['published' => '0'])) === [$eventB] && $ids($eventtypes($admin, ['schedule_id' => (string) $scheduleA])) === [$eventA],
        'Status and schedule filters narrow the event types');
    $check($ids($eventtypes($restricted, [])) === [$eventA] && $ids($eventtypes($restricted, ['schedule_id' => (string) $scheduleB])) === [],
        'An editor sees only their event types, also through the schedule filter');
    // Bookable again for the booking fixtures below.
    $db->query("UPDATE {$prefix}appointments_eventtypes SET published=1 WHERE id={$eventB}");
    $db->query("UPDATE {$prefix}appointments_schedules SET published=1, owner_user_id={$fixture->host} WHERE id={$scheduleB}");

    // ------------------------------------------------------------------ bookings
    // The required fixture fields above would need answers; the bookings here only test the list.
    $db->query("UPDATE {$prefix}appointments_fields SET required=0 WHERE eventtype_id IN ({$eventA},{$eventB})");
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    $k1 = (int) $fixture->booking($eventA, $day . ' 10:00:00', ['first_name' => $tag . '-one'])['booking']['id'];
    $k2 = (int) $fixture->booking($eventA, $day . ' 11:00:00', ['first_name' => $tag . '-two'])['booking']['id'];
    $k3 = (int) $fixture->booking($eventB, $day . ' 12:00:00', ['first_name' => $tag . '-three'])['booking']['id'];
    $bookingFilter = ['search' => $tag, 'eventtype_id' => '', 'state' => '', 'from' => '', 'to' => ''];
    $bookings = static fn (AppointmentsHttp $client, array $filter, array $list = [], array $extra = []): string
        => $page($client, 'bookings', array_replace($bookingFilter, $filter), array_replace(['fullordering' => 'b.start_utc ASC'], $list), $extra);
    $html = $bookings($admin, []);
    $check($admin->status === 200 && $ids($html) === [$k1, $k2, $k3] && $searchtools($html, ['search', 'eventtype_id', 'state', 'from', 'to']),
        'Bookings show the searchtools: search, event type, status, from, to, sorting, number per page, clear');
    $check($ids($bookings($admin, ['eventtype_id' => $eventB])) === [$k3] && $ids($bookings($admin, ['from' => $day, 'to' => $day])) === [$k1, $k2, $k3]
        && $ids($bookings($admin, ['to' => (new DateTimeImmutable($day))->modify('-1 day')->format('Y-m-d')])) === [],
        'Event type and date filters narrow the bookings');
    $check($ids($bookings($admin, ['search' => $tag . '-two'])) === [$k2], 'The search finds a booking by the encrypted first name');
    $paged = [];
    foreach ([0, 1, 2] as $start) {
        $paged[] = $ids($bookings($admin, [], ['limit' => 1], ['limitstart' => $start]));
    }
    $check(array_merge(...$paged) === [$k1, $k2, $k3], 'One booking per page: the pages cover every booking once');
    $bookings($admin, ['state' => '9']);
    $status = $admin->status;
    $after = $admin->request('/administrator/index.php?option=com_appointments&view=bookings');
    $check($status === 400 && $admin->status === 200, 'A forged booking status is refused, and the list is not stuck on the error afterwards');
    $bookings($admin, ['search' => [$tag]]);
    $check($admin->status === 400, 'A booking filter that is not a single value is refused');
    $check($ids($bookings($restricted, [])) === [$k1, $k2] && $ids($bookings($restricted, ['eventtype_id' => $eventB])) === [],
        'An editor sees only the bookings of their event type, also with a filter on another one');
    $listHtml = $bookings($admin, ['eventtype_id' => $eventB]);
    $csv = $admin->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.exportCsv', $admin->token($listHtml) => 1,
        'filter' => array_replace($bookingFilter, ['eventtype_id' => $eventB]), 'list' => ['fullordering' => 'b.start_utc ASC'],
    ]);
    $rows = array_values(array_filter(array_map(static fn (string $line): array => str_getcsv($line, ',', '"', ''), preg_split('/\r\n|\n/', trim($csv))), static fn ($row) => isset($row[0]) && ctype_digit((string) $row[0])));
    $check(str_starts_with($csv, 'id,') && array_map('intval', array_column($rows, 0)) === [$k3],
        'The CSV export uses the filters of the search tools');

    // ------------------------------------------------------------------ internet calendar log and devices
    $params = json_decode($componentParams, true, 512, JSON_THROW_ON_ERROR);
    $params['teamcal_enabled'] = 1;
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params, JSON_THROW_ON_ERROR)) . "' WHERE element='com_appointments' AND type='component'");
    $log = static function (string $when, string $event, int $user, string $reference = '') use ($db, $prefix): int {
        $db->query("INSERT INTO {$prefix}appointments_teamlog (occurred_utc,event,actor_user_id,object_ref,prev_hash,row_hash)"
            . " VALUES ('{$when}','{$event}',{$user},'{$reference}',UNHEX(REPEAT('00',32)),UNHEX(REPEAT('00',32)))");

        return (int) $db->insert_id;
    };
    $l1 = $logIds[] = $log($at('-40 days'), 'login.success', $userId);
    $l2 = $logIds[] = $log($at('-20 days'), 'login.failure', $userId, $tag . '-ref');
    $l3 = $logIds[] = $log($at('-19 days'), 'login.success', $userId);
    $logFilter = ['search' => '', 'event' => '', 'user_id' => (string) $userId, 'from' => '', 'to' => ''];
    $teamlog = static fn (array $filter, array $list = []): string
        => $page($admin, 'teamlog', array_replace($logFilter, $filter), array_replace(['fullordering' => 'l.id ASC'], $list));
    $logRows = static function (string $html): array {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $found = [];

        foreach ((new DOMXPath($document))->query('//form[@id="adminForm"]//tbody/tr/td[1]') as $cell) {
            $found[] = (int) trim($cell->textContent);
        }

        return $found;
    };
    $utcDay = static fn (string $modify): string => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($modify)->format('Y-m-d');
    $html = $teamlog([]);
    $check($admin->status === 200 && $logRows($html) === [$l1, $l2, $l3] && $searchtools($html, ['search', 'event', 'user_id', 'from', 'to']),
        'The internet calendar log shows the searchtools: search, event, user, from, to, sorting, number per page, clear');
    $check($logRows($teamlog(['event' => 'login.failure'])) === [$l2], 'Event filter narrows the log');
    $check($logRows($teamlog(['search' => $tag . '-ref'])) === [$l2], 'The search finds a log entry by its reference');
    $check($logRows($teamlog(['from' => $utcDay('-21 days')])) === [$l2, $l3] && $logRows($teamlog(['to' => $utcDay('-30 days')])) === [$l1],
        'Date filters narrow the log by UTC day');
    $check($logRows($teamlog([], ['fullordering' => 'l.id DESC', 'limit' => 1])) === [$l3], 'The log sorts newest first and limits the number per page');
    $teamlog(['from' => $utcDay('-1 day'), 'to' => $utcDay('-30 days')]);
    $status = $admin->status;
    $admin->request('/administrator/index.php?option=com_appointments&view=teamlog');
    $check($status === 400 && $admin->status === 200, 'A reversed date range is refused and the log is not stuck on the error');
    $html = $page($admin, 'devices', ['search' => '', 'state' => ''], ['fullordering' => 'd.last_sync_utc DESC']);
    $check($admin->status === 200 && $searchtools($html, ['search', 'state']), 'Devices show the searchtools: search, status, sorting, number per page, clear');
    $page($admin, 'devices', ['search' => '', 'state' => '7']);
    $status = $admin->status;
    $admin->request('/administrator/index.php?option=com_appointments&view=devices');
    $check($status === 400 && $admin->status === 200, 'A forged device status is refused and the list is not stuck on the error');

    // ------------------------------------------------------------------ date-specific times of a schedule
    $edit = $admin->request('/administrator/index.php?option=com_appointments&task=schedule.edit&id=' . $scheduleA);
    $check($admin->status === 200 && str_contains($edit, 'id="appointments-hide-past-overrides"')
        && str_contains($edit, 'data-today="' . gmdate('Y-m-d') . '"') && str_contains($edit, 'past dates are hidden'),
        'The schedule form offers hiding past dates, with today in the schedule zone');
    $admin->request('/administrator/index.php?option=com_appointments', ['task' => 'schedule.cancel', $admin->token($edit) => 1]);
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($componentParams) . "' WHERE element='com_appointments' AND type='component'");
    $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string($componentRules) . "' WHERE name='com_appointments'");
    foreach ($logIds as $id) {
        $db->query("DELETE FROM {$prefix}appointments_teamlog WHERE id={$id}");
    }
    foreach ($blockedIds as $id) {
        $db->query("DELETE FROM {$prefix}appointments_blocked WHERE id={$id}");
    }
    foreach ($templateIds as $id) {
        $db->query("DELETE FROM {$prefix}appointments_templates WHERE id={$id}");
    }
    foreach ($events as $event) {
        $db->query("DELETE FROM {$prefix}appointments_fields WHERE eventtype_id={$event}");
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
