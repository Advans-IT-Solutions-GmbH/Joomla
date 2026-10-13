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
    ];
    $http->submit('eventtype', $event);
    $row = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE alias='{$tag}'")->fetch_assoc();
    $eventId = (int) ($row['id'] ?? 0);
    $check($eventId > 0 && (int) $row['duration_minutes'] === 20 && (int) $row['slot_step_minutes'] === 30, 'Event type retains acceptance settings');
    $check($row && preg_match('/^[a-f0-9]{64}$/D', $row['feed_token']) === 1, 'Feed credential generated');
    $check($row && (int) $row['asset_id'] > 0, 'Joomla per-event asset created');
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
    $html = $public->request('/index.php?option=com_appointments&view=eventtype&id=' . $eventId);
    $check($public->status === 200 && str_contains($html, $tag . '-edited'), 'Single event menu target renders publicly');
    $db->query("UPDATE {$prefix}appointments_eventtypes SET published=0 WHERE id={$eventId}");
    $public->request('/index.php?option=com_appointments&view=eventtype&id=' . $eventId);
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
