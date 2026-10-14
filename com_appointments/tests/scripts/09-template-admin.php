<?php

require __DIR__ . '/appointments-http.php';
require '/var/www/html/configuration.php';
$config = new JConfig();
$db = new mysqli($config->host, $config->user, $config->password, $config->db);
$prefix = $config->dbprefix;
$http = new AppointmentsHttp('http://localhost', 'template-admin');
$tag = 'suite-template-' . bin2hex(random_bytes(6));
$scheduleId = 0;
$eventIds = [];
$templateIds = [];
$groupId = 0;
$userId = 0;
$componentRules = $db->query("SELECT rules FROM {$prefix}assets WHERE name='com_appointments'")->fetch_assoc()['rules'];
$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
};
$row = static function (int $id) use ($db, $prefix): ?array {
    return $db->query("SELECT * FROM {$prefix}appointments_templates WHERE id={$id}")->fetch_assoc();
};
$find = static function (int $eventId, string $purpose, string $language) use ($db, $prefix): int {
    $purpose = $db->real_escape_string($purpose);
    $language = $db->real_escape_string($language);

    return (int) ($db->query("SELECT id FROM {$prefix}appointments_templates WHERE eventtype_id={$eventId} AND purpose='{$purpose}' AND language='{$language}'")->fetch_assoc()['id'] ?? 0);
};
$batch = static function (AppointmentsHttp $client, string $task, int $id): string {
    $token = $client->token($client->request('/administrator/index.php?option=com_appointments&view=templates'));

    return $client->request('/administrator/index.php?option=com_appointments', [
        'task' => 'templates.' . $task, 'cid' => [$id], $token => 1,
    ]);
};

try {
    $http->login();
    $hostName = $db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
    $hostId = (int) $db->query("SELECT id FROM {$prefix}users WHERE username='{$hostName}'")->fetch_assoc()['id'];
    $http->submit('schedule', [
        'title' => $tag, 'timezone' => 'Europe/Zurich', 'owner_user_id' => $hostId, 'published' => 1,
        'weekly_rules' => [['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00']],
    ]);
    $scheduleId = (int) ($db->query("SELECT id FROM {$prefix}appointments_schedules WHERE title='{$tag}'")->fetch_assoc()['id'] ?? 0);

    foreach (['a', 'b'] as $suffix) {
        $http->submit('eventtype', [
            'title' => $tag . '-' . $suffix, 'alias' => $tag . '-' . $suffix, 'schedule_id' => $scheduleId,
            'host_user_id' => $hostId, 'duration_minutes' => 30, 'slot_step_minutes' => 30,
            'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'min_notice_minutes' => 0,
            'horizon_days' => 30, 'max_per_day' => 0, 'max_per_week' => 0,
            'location_type' => 'phone_host_calls', 'location_text' => 'Fixture instructions',
            'requires_approval' => 0, 'colour' => '#123456', 'published' => 1, 'access' => 1, 'language' => '*',
        ]);
        $eventIds[] = (int) ($db->query("SELECT id FROM {$prefix}appointments_eventtypes WHERE alias='{$tag}-{$suffix}'")->fetch_assoc()['id'] ?? 0);
    }

    if (!$scheduleId || in_array(0, $eventIds, true)) {
        throw new RuntimeException('Template event fixtures were not created.');
    }

    [$eventA, $eventB] = $eventIds;
    $newHtml = $http->request('/administrator/index.php?option=com_appointments&task=template.add');
    $check($http->status === 200 && str_contains($newHtml, 'jform[eventtype_id]')
        && preg_match('/<option value="0"[^>]*selected/', $newHtml) !== 1, 'New form does not implicitly select the site-wide sentinel');

    $base = [
        'eventtype_id' => $eventA, 'purpose' => 'confirmation', 'language' => 'en-GB',
        'subject' => '  ' . $tag . ' {event_title} & <plain>  ',
        'body' => "  Hello {invitee_name}\n{custom_fields.42}\n<plaintext> & café\n{cancel_url}\n  ",
        'published' => 1,
    ];
    $http->submit('template', $base);
    $id = $find($eventA, 'confirmation', 'en-GB');
    $templateIds[] = $id;
    $saved = $row($id);
    $check($id > 0 && $saved['subject'] === $base['subject'] && $saved['body'] === $base['body'], 'Plain-text subject, body, whitespace and placeholders persist exactly');

    foreach (['de-DE', 'fr-FR'] as $language) {
        $form = array_replace($base, ['language' => $language]);
        $http->submit('template', $form);
        $localeId = $find($eventA, 'confirmation', $language);
        $templateIds[] = $localeId;
        $check($localeId > 0, 'Independent language override saved: ' . $language);
    }

    foreach (['pending', 'approved', 'reschedule', 'cancellation', 'reminder', 'host_notice', 'erasure_notice'] as $purpose) {
        $http->submit('template', array_replace($base, ['purpose' => $purpose]));
        $purposeId = $find($eventA, $purpose, 'en-GB');
        $templateIds[] = $purposeId;
        $check($purposeId > 0, 'Supported notification purpose saved: ' . $purpose);
    }

    $html = $http->submit('template', $base);
    $check($find($eventA, 'confirmation', 'en-GB') === $id
        && (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_templates WHERE eventtype_id={$eventA} AND purpose='confirmation' AND language='en-GB'")->fetch_assoc()['n'] === 1
        && $http->status === 200 && !str_contains($html, 'Duplicate entry')
        && preg_match('/already exists|existiert bereits|existe déjà/u', $html) === 1,
        'Duplicate override is refused with a translated explanation, not a raw database error');

    $edited = array_replace($base, ['id' => $id, 'subject' => $tag . ' edited']);
    $http->submit('template', $edited);
    $check($row($id)['subject'] === $edited['subject'], 'Held edit saves successfully');

    foreach ([
        ['eventtype_id' => ''], ['eventtype_id' => '-1'], ['eventtype_id' => 'not-a-scope'],
        ['eventtype_id' => 4294967295], ['purpose' => 'unknown'], ['language' => 'not_a_tag'],
        ['subject' => ''], ['body' => ''], ['subject' => "Injected\r\nHeader: value"],
    ] as $invalid) {
        $http->submit('template', array_replace($edited, $invalid));
        $check($row($id)['subject'] === $edited['subject'] && (int) $row($id)['eventtype_id'] === $eventA,
            'Invalid template input is refused: ' . implode(',', array_keys($invalid)) . '=' . json_encode(array_values($invalid)));
    }

    $missingScope = $edited;
    unset($missingScope['eventtype_id']);
    $http->submit('template', $missingScope);
    $check((int) $row($id)['eventtype_id'] === $eventA, 'Missing scope cannot silently reparent a record to site-wide');

    $second = new AppointmentsHttp('http://localhost', 'template-unheld');
    $second->login();
    $token = $second->token($second->request('/administrator/index.php?option=com_appointments&view=templates'));
    $second->request('/administrator/index.php?option=com_appointments&id=' . $id, [
        'task' => 'template.save', 'jform' => array_replace($edited, ['subject' => 'Unheld write']), $token => 1,
    ]);
    $check($second->status === 403 && $row($id)['subject'] === $edited['subject'], 'Direct save cannot bypass the held edit session');
    $second->request('/administrator/index.php?option=com_appointments&id=' . $id, ['task' => 'template.cancel', $token => 1]);
    $check($second->status === 403, 'Direct cancellation also requires a held edit session');

    $editHtml = $http->request('/administrator/index.php?option=com_appointments&task=template.edit&id=' . $id);
    $http->request('/administrator/index.php?option=com_appointments&id=' . $id, [
        'task' => 'template.save', 'jform' => array_replace($edited, ['subject' => 'Tokenless write']),
    ]);
    $check($http->status === 403 && $row($id)['subject'] === $edited['subject'], 'Tokenless save is refused before changing data');
    $http->request('/administrator/index.php?option=com_appointments&id=' . $id, ['task' => 'template.cancel']);
    $check($http->status === 403 && (int) $row($id)['checked_out'] === $hostId, 'Tokenless cancel cannot release checkout');
    $http->request('/administrator/index.php?option=com_appointments&id=' . $id, [
        'task' => 'template.cancel', $http->token($editHtml) => 1,
    ]);
    $check(empty($row($id)['checked_out']), 'Held cancellation with token releases checkout');

    // A separate language keeps site-wide fixtures independent of shipped seed rows.
    $siteLanguage = 'en-US';
    $sitePurpose = '';
    foreach (['confirmation', 'pending', 'approved', 'reschedule', 'cancellation', 'reminder', 'host_notice', 'erasure_notice'] as $purpose) {
        if (!$find(0, $purpose, $siteLanguage)) {
            $sitePurpose = $purpose;
            break;
        }
    }
    if ($sitePurpose === '') {
        throw new RuntimeException('No unoccupied site-wide fixture language/purpose.');
    }
    $site = array_replace($base, ['eventtype_id' => '0', 'purpose' => $sitePurpose, 'language' => $siteLanguage, 'subject' => $tag . ' fallback']);
    $missingScope = $site;
    unset($missingScope['eventtype_id']);
    $http->submit('template', $missingScope);
    $check($find(0, $sitePurpose, $siteLanguage) === 0, 'Creation without scope cannot silently create a site-wide override');
    $http->submit('template', $site);
    $siteId = $find(0, $sitePurpose, $siteLanguage);
    $templateIds[] = $siteId;
    $check($siteId > 0 && (int) $row($siteId)['eventtype_id'] === 0, 'Explicit site-wide fallback can be created by an administrator');
    $http->submit('template', array_replace($site, ['id' => $siteId, 'subject' => $tag . ' fallback edited']));
    $check($row($siteId)['subject'] === $tag . ' fallback edited', 'Site-wide fallback can be edited by an administrator');

    $batch($http, 'unpublish', $id);
    $check((int) $row($id)['published'] === 0, 'Unpublish task updates the scoped template');
    $batch($http, 'publish', $id);
    $check((int) $row($id)['published'] === 1, 'Publish task updates the scoped template');

    // The Manager parent supplies backend access without component options permission.
    $manager = $db->query("SELECT id,lft,rgt FROM {$prefix}usergroups WHERE id=6")->fetch_assoc();
    if (!$manager) {
        throw new RuntimeException('The standard Manager group is required for ACL fixtures.');
    }
    $right = (int) $manager['rgt'];
    $db->begin_transaction();
    try {
        $db->query("UPDATE {$prefix}usergroups SET rgt=rgt+2 WHERE rgt>={$right}");
        $db->query("UPDATE {$prefix}usergroups SET lft=lft+2 WHERE lft>{$right}");
        $db->query("INSERT INTO {$prefix}usergroups (parent_id,lft,rgt,title) VALUES (6,{$right}," . ($right + 1) . ",'{$tag}')");
        $groupId = (int) $db->insert_id;
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        $groupId = 0;
        throw $e;
    }
    $password = 'Fixture-' . bin2hex(random_bytes(12));
    $hash = $db->real_escape_string(password_hash($password, PASSWORD_BCRYPT));
    $db->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('{$tag}','{$tag}','{$tag}@example.invalid','{$hash}',0,0,UTC_TIMESTAMP(),'{}')");
    $userId = (int) $db->insert_id;
    $db->query("INSERT INTO {$prefix}user_usergroup_map (user_id,group_id) VALUES ({$userId},{$groupId})");
    $accessRules = json_decode($componentRules, true, 512, JSON_THROW_ON_ERROR);
    foreach (['core.manage', 'core.create'] as $action) {
        $accessRules[$action][(string) $groupId] = 1;
    }
    $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string(json_encode($accessRules, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR))
        . "' WHERE name='com_appointments'");
    foreach ([$eventA => 1, $eventB => 0] as $eventId => $editable) {
        $rules = $db->real_escape_string(json_encode([
            'core.edit' => [(string) $groupId => $editable],
            'core.edit.state' => [(string) $groupId => 0],
            'core.delete' => [(string) $groupId => $editable],
        ], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
        $db->query("UPDATE {$prefix}assets SET rules='{$rules}' WHERE name='com_appointments.eventtype.{$eventId}'");
    }

    $restricted = new AppointmentsHttp('http://localhost', 'template-restricted');
    $originalUsername = getenv('JOOMLA_ADMIN_USERNAME');
    $originalPassword = getenv('JOOMLA_ADMIN_PASSWORD');
    try {
        putenv('JOOMLA_ADMIN_USERNAME=' . $tag);
        putenv('JOOMLA_ADMIN_PASSWORD=' . $password);
        $restricted->login();
    } finally {
        $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string($componentRules) . "' WHERE name='com_appointments'");
        putenv($originalUsername === false ? 'JOOMLA_ADMIN_USERNAME' : 'JOOMLA_ADMIN_USERNAME=' . $originalUsername);
        putenv($originalPassword === false ? 'JOOMLA_ADMIN_PASSWORD' : 'JOOMLA_ADMIN_PASSWORD=' . $originalPassword);
    }

    $restricted->submit('template', array_replace($edited, ['subject' => $tag . ' permitted']));
    $check($row($id)['subject'] === $tag . ' permitted', 'Per-event editor can save its permitted event');
    foreach ([0, $eventB] as $target) {
        $restricted->submit('template', array_replace($edited, ['eventtype_id' => $target, 'subject' => 'Forbidden move']));
        $check((int) $row($id)['eventtype_id'] === $eventA && $row($id)['subject'] === $tag . ' permitted',
            'Reparenting also requires destination permission: ' . $target);
    }
    $deniedEdit = $restricted->request('/administrator/index.php?option=com_appointments&task=template.edit&id=' . $siteId);
    $check(!str_contains($deniedEdit, 'id="template-form"'), 'Event editor cannot acquire a site-wide edit');
    $restrictedToken = $restricted->token($restricted->request('/administrator/index.php?option=com_appointments&view=templates'));
    $restricted->request('/administrator/index.php?option=com_appointments&id=' . $siteId, [
        'task' => 'template.save', 'jform' => array_replace($site, ['id' => $siteId, 'eventtype_id' => $eventA]),
        $restrictedToken => 1,
    ]);
    $check($restricted->status === 403 && (int) $row($siteId)['eventtype_id'] === 0, 'Source scope permission cannot be bypassed with a direct save');
    $batch($restricted, 'unpublish', $id);
    $check((int) $row($id)['published'] === 1, 'State task requires event core.edit.state, not just core.edit');
    $restricted->submit('template', array_replace($edited, ['published' => 0]));
    $check((int) $row($id)['published'] === 1, 'Save cannot bypass event state permission');
    $restricted->submit('template', array_replace($base, ['purpose' => 'reminder', 'language' => 'en-US']));
    $restrictedId = $find($eventA, 'reminder', 'en-US');
    $templateIds[] = $restrictedId;
    $check($restrictedId > 0 && (int) $row($restrictedId)['published'] === 0, 'Event editor creation starts unpublished without state permission');
    $restricted->submit('template', array_replace($base, ['eventtype_id' => $eventB, 'language' => 'en-US']));
    $check($find($eventB, 'confirmation', 'en-US') === 0, 'Creation requires permission on the selected event scope');

    $html = $http->request('/administrator/index.php?option=com_appointments&view=templates&filter_eventtype_id=0');
    $check($http->status === 200 && str_contains($html, $tag . ' fallback edited') && !str_contains($html, $tag . ' permitted'), 'Site-wide list filter uses the sentinel independently of all scopes');
    $html = $http->request('/administrator/index.php?option=com_appointments&view=templates&filter_eventtype_id=' . $eventA);
    $check($http->status === 200 && str_contains($html, $tag) && !str_contains($html, $tag . ' fallback edited'), 'Event-specific list filter excludes site-wide overrides');
    $batch($http, 'delete', $id);
    $check($row($id) === null, 'Delete task removes an authorised template');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    foreach (array_filter($templateIds) as $id) {
        $db->query("DELETE FROM {$prefix}appointments_templates WHERE id=" . (int) $id);
    }
    foreach (array_filter($eventIds) as $eventId) {
        $db->query("DELETE FROM {$prefix}appointments_templates WHERE eventtype_id=" . (int) $eventId);
        $db->query("DELETE FROM {$prefix}appointments_eventtypes WHERE id=" . (int) $eventId);
        $db->query("DELETE FROM {$prefix}assets WHERE name='com_appointments.eventtype." . (int) $eventId . "'");
    }
    if ($scheduleId) {
        $db->query("DELETE FROM {$prefix}appointments_schedule_rules WHERE schedule_id={$scheduleId}");
        $db->query("DELETE FROM {$prefix}appointments_schedules WHERE id={$scheduleId}");
    }
    if ($userId) {
        $db->query("DELETE FROM {$prefix}session WHERE userid={$userId}");
        $db->query("DELETE FROM {$prefix}user_usergroup_map WHERE user_id={$userId}");
        $db->query("DELETE FROM {$prefix}users WHERE id={$userId}");
    }
    if ($groupId) {
        $bounds = $db->query("SELECT lft,rgt FROM {$prefix}usergroups WHERE id={$groupId}")->fetch_assoc();
        $right = (int) $bounds['rgt'];
        $db->query("DELETE FROM {$prefix}usergroups WHERE id={$groupId}");
        $db->query("UPDATE {$prefix}usergroups SET lft=lft-2 WHERE lft>{$right}");
        $db->query("UPDATE {$prefix}usergroups SET rgt=rgt-2 WHERE rgt>{$right}");
    }
}
exit($failed ? 1 : 0);
