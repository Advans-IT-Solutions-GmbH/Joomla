<?php

require __DIR__ . '/appointments-http.php';
require '/var/www/html/configuration.php';
$config = new JConfig();
$db = new mysqli($config->host, $config->user, $config->password, $config->db);
$prefix = $config->dbprefix;
$http = new AppointmentsHttp();
$tag = 'suite-eventtypes-' . bin2hex(random_bytes(6));
$scheduleId = 0;
$eventId = 0;
$fieldId = 0;
$failed = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
};

try {
    $http->login();
    foreach (['eventtype' => ['jform_schedule_id'], 'field' => ['jform_eventtype_id'], 'template' => ['jform_eventtype_id', 'jform_purpose']] as $view => $selectIds) {
        $html = $http->request('/administrator/index.php?option=com_appointments&view=' . $view . '&layout=edit');
        $document = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new DOMXPath($document);

        foreach ($selectIds as $selectId) {
            $placeholder = $xpath->query('//select[@id="' . $selectId . '"]/option[@value=""]')->item(0);
            $check($placeholder !== null && trim($placeholder->textContent) === 'Select',
                $view . ': empty selection uses the translated Joomla core placeholder');
        }
    }
    $hostName = $db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
    $host = (int) $db->query("SELECT id FROM {$prefix}users WHERE username='{$hostName}'")->fetch_assoc()['id'];
    $schedule = [
        'title' => $tag, 'timezone' => 'Europe/Zurich', 'owner_user_id' => $host, 'published' => 1,
        'weekly_rules' => [
            ['weekday' => 1, 'start_time' => '09:00', 'end_time' => '12:00'],
            ['weekday' => 3, 'start_time' => '14:00', 'end_time' => '17:00'],
            ['weekday' => 5, 'start_time' => '14:00', 'end_time' => '17:00'],
        ],
    ];
    $http->submit('schedule', $schedule);
    $scheduleId = (int) ($db->query("SELECT id FROM {$prefix}appointments_schedules WHERE title='{$tag}'")->fetch_assoc()['id'] ?? 0);
    $check($scheduleId > 0, 'Acceptance schedule created entirely through the form');
    $event = [
        'title' => $tag, 'alias' => $tag, 'description' => '<p>Fixture description</p>', 'schedule_id' => $scheduleId,
        'host_user_id' => $host, 'duration_minutes' => 20, 'slot_step_minutes' => 30,
        'buffer_before_minutes' => 0, 'buffer_after_minutes' => 0, 'min_notice_minutes' => 0,
        'horizon_days' => 30, 'horizon_start_utc' => '', 'horizon_end_utc' => '',
        'max_per_day' => 0, 'max_per_week' => 0, 'location_type' => 'phone_host_calls',
        'location_text' => 'Fixture instructions', 'requires_approval' => 0, 'retention_days' => '',
        'colour' => '#123456', 'published' => 1, 'access' => 1, 'language' => '*', 'ordering' => 3,
        'info_layout' => 'person', 'info_logo' => '', 'info_photo' => 'images/joomla_black.png',
        'info_name' => 'Fixture & "host"', 'info_role' => 'Fixture role',
    ];
    $http->submit('eventtype', $event);
    $row = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE alias='{$tag}'")->fetch_assoc();
    $eventId = (int) ($row['id'] ?? 0);
    $check($eventId > 0 && (int) $row['duration_minutes'] === 20 && (int) $row['slot_step_minutes'] === 30, 'Event type retains acceptance settings');
    $check($row && preg_match('/^[a-f0-9]{64}$/D', $row['feed_token']) === 1, 'Feed credential generated');
    $check($row && (int) $row['asset_id'] > 0, 'Joomla per-event asset created');
    $check($row && $row['info_layout'] === 'person' && $row['info_photo'] === 'images/joomla_black.png'
        && $row['info_name'] === 'Fixture & "host"' && $row['info_role'] === 'Fixture role' && $row['info_logo'] === '',
        'The display settings of the information column are saved through the form');
    $form = $http->request('/administrator/index.php?option=com_appointments&task=eventtype.edit&id=' . $eventId);
    $check(str_contains($form, 'name="jform[info_layout]"') && str_contains($form, 'name="jform[info_title]"') && str_contains($form, 'name="jform[info_photo]"')
        && str_contains($form, 'id="eventtype-display"') && !str_contains($form, 'COM_APPOINTMENTS_EVENTTYPE_INFO'),
        'The edit form shows the translated display settings');
    $http->request('/administrator/index.php?option=com_appointments&task=eventtype.cancel&id=' . $eventId, [$http->token($form) => 1]);
    $hostRow = $db->query("SELECT * FROM {$prefix}appointments_hosts WHERE user_id={$host}")->fetch_assoc();
    $check($hostRow && $hostRow['feed_token'] !== $row['feed_token'], 'Host feed credential independent');

    $field = ['eventtype_id' => $eventId, 'type' => 'tel', 'label' => $tag . '-phone', 'required' => 1, 'published' => 1, 'ordering' => 0, 'options' => ''];
    $http->submit('field', $field);
    $fieldId = (int) ($db->query("SELECT id FROM {$prefix}appointments_fields WHERE label='{$tag}-phone'")->fetch_assoc()['id'] ?? 0);
    $check($fieldId > 0, 'Required telephone field created through form');

    foreach (['text', 'text', 'textarea'] as $i => $type) {
        $http->submit('field', ['eventtype_id' => $eventId, 'type' => $type, 'label' => $tag . '-answer-' . $i,
            'required' => $i < 2 ? 1 : 0, 'published' => 1, 'ordering' => $i + 1, 'options' => '']);
    }
    $check((int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_fields WHERE eventtype_id={$eventId}")->fetch_assoc()['n'] === 4, 'All acceptance custom fields created');

    $event['id'] = $eventId;
    $event['title'] = $tag . '-edited';
    $event['feed_token'] = str_repeat('0', 64);
    $http->submit('eventtype', $event);
    $edited = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc();
    $check($edited && $edited['title'] === $tag . '-edited' && $edited['feed_token'] === $row['feed_token'], 'Edit preserves feed token and persists content');

    $event['info_layout'] = 'bogus';
    $http->submit('eventtype', $event);
    $check($db->query("SELECT info_layout FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc()['info_layout'] === 'person',
        'An unknown layout of the information column is refused');
    $event['info_layout'] = 'event';
    $http->submit('eventtype', $event);
    $saved = $db->query("SELECT info_layout, info_photo FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc();
    $check($saved['info_layout'] === 'event' && $saved['info_photo'] === 'images/joomla_black.png',
        'The layout without logo and photo is saved and keeps the stored photo');
    $event['info_layout'] = 'person';
    $http->submit('eventtype', $event);
    $check($db->query("SELECT info_layout FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc()['info_layout'] === 'person',
        'The person layout can be chosen again');
    $event['info_title'] = 'above';
    $http->submit('eventtype', $event);
    $check($db->query("SELECT info_title FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc()['info_title'] === 'above',
        'The title above the card is saved through the form');
    $event['info_title'] = 'bogus';
    $http->submit('eventtype', $event);
    $check($db->query("SELECT info_title FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc()['info_title'] === 'above',
        'An unknown place of the title is refused');
    $event['info_title'] = 'card';
    $http->submit('eventtype', $event);
    $event['duration_minutes'] = 0;
    $http->submit('eventtype', $event);
    $check((int) $db->query("SELECT duration_minutes FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc()['duration_minutes'] === 20, 'Invalid duration does not change event type');
    $event['duration_minutes'] = 20;
    $event['horizon_days'] = 0;
    $event['horizon_start_utc'] = '2026-02-31 10:00:00';
    $event['horizon_end_utc'] = '2027-01-01 10:00:00';
    $html = $http->submit('eventtype', $event);
    $check(str_contains($html, '2026-02-31 10:00:00')
        && (int) $db->query("SELECT horizon_days FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc()['horizon_days'] === 30, 'Invalid fixed horizon retains exact rejected input');

    foreach (['eventtypes', 'fields'] as $view) {
        $html = $http->request('/administrator/index.php?option=com_appointments&view=' . $view);
        $check($http->status === 200 && str_contains($html, $tag), 'Administrator list renders: ' . $view);
    }

    $public = new AppointmentsHttp('http://localhost', 'events-public');
    // The booking page below the list of event types, whose way back leads to that list.
    $belowList = '/index.php?option=com_appointments&view=eventtype&id=' . $eventId
        . '&Itemid=' . appointments_menu_item('index.php?option=com_appointments&view=eventtypes');
    $html = $public->request($belowList);
    $check($public->status === 200 && str_contains($html, $tag . '-edited'), 'Single event menu target renders publicly');
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $overview = (new DOMXPath($document))->query(
        '(//div[contains(concat(" ", normalize-space(@class), " "), " com-appointments-eventtype ")]//a)[1]'
    )->item(0);
    $check($overview instanceof DOMElement && trim($overview->textContent) === 'Back to appointments',
        'The booking branch has a translated return to its public overview');
    if ($overview instanceof DOMElement) {
        $public->request('/' . ltrim($overview->getAttribute('href'), '/'));
        $check($public->status === 200, 'The public overview return link is usable without administrator permission');
    }
    $check(str_contains($html, 'Fixture &amp; &quot;host&quot;') && str_contains($html, 'com-appointments-host-photo')
        && str_contains($html, 'Fixture role'), 'The booking page shows the saved person layout, escaped');
    if ($public->status !== 200 || !str_contains($html, $tag . '-edited')) {
        echo 'Public response status: ' . $public->status . "\n";
        echo substr(preg_replace('/\s+/', ' ', strip_tags($html)), 0, 3000) . "\n";
    }
    $db->query("UPDATE {$prefix}appointments_eventtypes SET published=0 WHERE id={$eventId}");
    $public->request($belowList);
    $check($public->status === 404, 'Unpublished single event target is inaccessible');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    if ($eventId) {
        $db->query("DELETE FROM {$prefix}appointments_fields WHERE eventtype_id={$eventId}");
        $db->query("DELETE FROM {$prefix}appointments_eventtypes WHERE id={$eventId}");
        $db->query("DELETE FROM {$prefix}assets WHERE name='com_appointments.eventtype.{$eventId}'");
    }
    if ($scheduleId) {
        $db->query("DELETE FROM {$prefix}appointments_schedule_rules WHERE schedule_id={$scheduleId}");
        $db->query("DELETE FROM {$prefix}appointments_schedules WHERE id={$scheduleId}");
    }
}
exit($failed ? 1 : 0);
