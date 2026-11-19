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

    foreach (['pending', 'approved', 'reschedule', 'cancellation', 'reminder', 'host_notice', 'erasure_notice',
        'guest_confirmation', 'guest_pending', 'guest_approved', 'guest_reschedule', 'guest_cancellation', 'guest_reminder',
        'host_approved', 'host_reschedule', 'host_cancellation', 'host_reminder',
        'calendar_booked', 'calendar_approved', 'calendar_rescheduled', 'calendar_cancelled', 'calendar_reminder',
        'calendar_busy', 'calendar_busy_moved', 'calendar_free'] as $purpose) {
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

    $html = $http->request('/administrator/index.php?option=com_appointments&view=templates&filter_eventtype_id=0&limit=100');
    $check($http->status === 200 && str_contains($html, $tag . ' fallback edited') && !str_contains($html, $tag . ' permitted'), 'Site-wide list filter uses the sentinel independently of all scopes');
    $html = $http->request('/administrator/index.php?option=com_appointments&view=templates&filter_eventtype_id=' . $eventA);
    $check($http->status === 200 && str_contains($html, $tag) && !str_contains($html, $tag . ' fallback edited'), 'Event-specific list filter excludes site-wide overrides');
    $batch($http, 'delete', $id);
    $check($row($id) === null, 'Delete task removes an authorised template');

    // The update path of the stored templates (audit 18.11.2026, B8: the host's cancellation now
    // names the reason). The installer's own template step runs against a «previous version» whose
    // host cancellation had no {cancel_reason}: the unchanged en-GB default follows the new default,
    // the edited de-DE template and a template of one event type stay and are named in the update
    // message. The installed language copies are the previous version only during the step: the
    // step itself copies the package's files back.
    $local = '/var/www/html/administrator/components/com_appointments/language';
    $source = sys_get_temp_dir() . '/appointments-template-update-' . bin2hex(random_bytes(4));
    $key = 'COM_APPOINTMENTS_MAIL_HOST_CANCELLATION_BODY';
    $previous = static fn (string $ini): string => (string) preg_replace_callback('/^' . $key . '=.*$/m',
        static fn (array $line): string => (string) preg_replace('/\\\\n[^"\\\\]*\{cancel_reason\}/', '', $line[0]), $ini);
    $defaults = [];
    foreach (['en-GB', 'de-DE', 'fr-FR'] as $language) {
        $defaults[$language] = file_get_contents($local . '/' . $language . '/com_appointments.ini');
        mkdir($source . '/administrator/language/' . $language, 0700, true);
        file_put_contents($source . '/administrator/language/' . $language . '/com_appointments.ini', $defaults[$language]);
    }
    $body = static fn (string $ini): string => str_replace('\n', "\n", (string) parse_ini_string($ini, false, INI_SCANNER_RAW)[$key]);
    $stored = [];
    foreach (['en-GB', 'de-DE'] as $language) {
        $stored[$language] = $db->query("SELECT id, subject, body FROM {$prefix}appointments_templates WHERE eventtype_id=0"
            . " AND purpose='host_cancellation' AND language='{$language}'")->fetch_assoc();
    }
    try {
        $check(!str_contains($body($previous($defaults['en-GB'])), '{cancel_reason}') && str_contains($body($defaults['en-GB']), '{cancel_reason}'),
            'Fixture: the previous version has no reason in the host cancellation');
        foreach (['en-GB', 'de-DE'] as $language) {
            file_put_contents($local . '/' . $language . '/com_appointments.ini', $previous($defaults[$language]));
        }
        $setBody = static function (int $id, string $text) use ($db, $prefix): void {
            $db->query("UPDATE {$prefix}appointments_templates SET body='" . $db->real_escape_string($text) . "' WHERE id={$id}");
        };
        $setBody((int) $stored['en-GB']['id'], $body($previous($defaults['en-GB'])));
        $subject = (string) parse_ini_string($defaults['en-GB'], false, INI_SCANNER_RAW)['COM_APPOINTMENTS_MAIL_HOST_CANCELLATION_SUBJECT'];
        $db->query("UPDATE {$prefix}appointments_templates SET subject='" . $db->real_escape_string($subject) . "' WHERE id=" . (int) $stored['en-GB']['id']);
        $edited = $body($previous($defaults['de-DE'])) . "\nVom Betreiber ergänzt.";
        $setBody((int) $stored['de-DE']['id'], $edited);
        // The event type's own host cancellation (an earlier step of this suite may have created it).
        $eventTemplate = $find($eventA, 'host_cancellation', 'en-GB');
        if ($eventTemplate > 0) {
            $stored['event'] = $row($eventTemplate);
            $setBody($eventTemplate, 'Own text {start}');
        } else {
            $db->query("INSERT INTO {$prefix}appointments_templates (eventtype_id,purpose,language,subject,body,published)"
                . " VALUES ({$eventA},'host_cancellation','en-GB','{$tag} own cancellation','Own text {start}',1)");
            $templateIds[] = $eventTemplate = (int) $db->insert_id;
        }
        $script = $source . '/run.php';
        file_put_contents($script, '<?php
            require "/var/www/html/tests/scripts/appointments-joomla.php";
            require_once JPATH_ADMINISTRATOR . "/components/com_appointments/script.php";
            $application->getLanguage()->load("com_appointments", JPATH_ADMINISTRATOR);
            $installer = new class (' . var_export($source, true) . ') { public function __construct(private string $source) {} public function getPath(string $name): string { return $this->source; } };
            $parent = new class ($installer) { public function __construct(private object $installer) {} public function getParent(): object { return $this->installer; } };
            $script = new Com_appointmentsInstallerScript();
            foreach (["seedMailTemplates" => [$parent], "reportKeptTemplates" => []] as $method => $arguments) {
                $reflection = new ReflectionMethod($script, $method);
                $reflection->setAccessible(true);
                $reflection->invoke($script, ...$arguments);
            }
            echo "\n@@UPDATE@@" . json_encode(array_column($application->getMessageQueue(), "message"));');
        $output = [];
        exec('cd /var/www/html && php ' . escapeshellarg($script) . ' 2>&1', $output);
        $text = implode("\n", $output);
        $at = strrpos($text, '@@UPDATE@@');
        $messages = $at === false ? $text : implode("\n", (array) json_decode(substr($text, $at + 10), true));
        $check($at !== false && $row((int) $stored['en-GB']['id'])['body'] === $body($defaults['en-GB']),
            'An unchanged default template follows the new default with the reason line: ' . substr($text, 0, 300));
        $check($row((int) $stored['de-DE']['id'])['body'] === $edited && $row($eventTemplate)['body'] === 'Own text {start}',
            'A template the operator changed and a template of one event type stay as they are');
        $check(str_contains($messages, 'gained new placeholders')
            && str_contains($messages, 'Host: booking cancelled (de-DE, Site-wide fallback): {cancel_reason}')
            && str_contains($messages, 'Host: booking cancelled (en-GB, event type ' . $eventA . '): {cancel_reason}')
            && !str_contains($messages, '(en-GB, Site-wide fallback)'),
            'The update message names every kept template that lacks the new placeholder, not the one that followed');
        $check(file_get_contents($local . '/en-GB/com_appointments.ini') === $defaults['en-GB']
            && file_get_contents($local . '/de-DE/com_appointments.ini') === $defaults['de-DE'],
            'The template step installs the language copies of the package again');
    } finally {
        foreach ($defaults as $language => $content) {
            file_put_contents($local . '/' . $language . '/com_appointments.ini', $content);
            @unlink($source . '/administrator/language/' . $language . '/com_appointments.ini');
            @rmdir($source . '/administrator/language/' . $language);
        }
        @unlink($source . '/run.php');
        @rmdir($source . '/administrator/language');
        @rmdir($source . '/administrator');
        @rmdir($source);
        foreach ($stored as $original) {
            if ($original) {
                $db->query("UPDATE {$prefix}appointments_templates SET subject='" . $db->real_escape_string($original['subject'])
                    . "', body='" . $db->real_escape_string($original['body']) . "' WHERE id=" . (int) $original['id']);
            }
        }
    }
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string($componentRules) . "' WHERE name='com_appointments'");
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
