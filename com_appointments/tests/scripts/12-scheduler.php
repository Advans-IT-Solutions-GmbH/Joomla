<?php

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-mail.php';
$fixture = new AppointmentsFixture('scheduler');
$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$db = $fixture->db;
$prefix = $fixture->prefix;
$originalParams = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc()['params'];
$tasks = [];
$auditIds = [];
$deletedBookingIds = [];
$bucket = bin2hex(random_bytes(32));
$createTask = static function (string $routine, int $size) use ($db, $prefix, &$tasks): int {
    $time = gmdate('Y-m-d H:i:s');
    $params = $db->real_escape_string(json_encode(['batch_size' => $size]));
    $type = $db->real_escape_string('plg_task_appointments.' . $routine);
    $db->query("INSERT INTO {$prefix}scheduler_tasks (title,type,execution_rules,cron_rules,state,last_exit_code,times_executed,times_failed,priority,ordering,params,note,created,created_by) VALUES ('Appointments test','{$type}','{\"rule-type\":\"manual\"}','{\"type\":\"manual\",\"exp\":\"\"}',1,0,0,0,0,0,'{$params}','','{$time}',0)");
    $id = (int) $db->insert_id;
    $tasks[] = $id;

    return $id;
};
$run = static function (string $routine, int $size = 100, bool $expectSuccess = true) use ($db, $prefix, $createTask, $check): void {
    $id = $createTask($routine, $size);
    exec('cd /var/www/html && HTTP_HOST=localhost php cli/joomla.php scheduler:run --id=' . $id . ' 2>&1', $output, $exit);
    echo implode("\n", $output) . "\n";
    $row = $db->query("SELECT times_executed,last_exit_code FROM {$prefix}scheduler_tasks WHERE id={$id}")->fetch_assoc();
    $check((int) $row['times_executed'] === 1
        && ($expectSuccess ? $exit === 0 && (int) $row['last_exit_code'] === 0 : (int) $row['last_exit_code'] !== 0),
        'Real Scheduler CLI discovers the ' . $routine . ' routine and records ' . ($expectSuccess ? 'success' : 'failure'));
};
$setParams = static function (array $changes) use ($db, $prefix, $originalParams): void {
    $params = array_replace(json_decode($originalParams, true) ?: [], ['public_site_url' => 'http://localhost'], $changes);
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params)) . "' WHERE element='com_appointments' AND type='component'");
};

try {
    $plugin = $db->query("SELECT enabled FROM {$prefix}extensions WHERE type='plugin' AND folder='task' AND element='appointments'")->fetch_assoc();
    $check($plugin && (int) $plugin['enabled'] === 1, 'Bundled Scheduler plugin is installed and enabled');
    $check(is_file('/var/www/html/plugins/task/appointments/LICENSE.txt'), 'Bundled Scheduler license is installed');
    $event = $fixture->event();
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    $created = $fixture->booking($event, $day . ' 10:00:00', ['guests' => 'guest@example.test']);
    $id = (int) $created['booking']['id'];
    $start = gmdate('Y-m-d H:i:s', time() + 3600);
    $end = gmdate('Y-m-d H:i:s', time() + 4800);
    $db->query("UPDATE {$prefix}appointments_bookings SET start_utc='{$start}',end_utc='{$end}',state=1 WHERE id={$id}");
    $setParams(['reminder_offsets' => '120', 'retention_days' => 365, 'audit_retention_years' => 3]);
    $before = AppointmentsMail::snapshot();
    $run('reminders');
    $messages = AppointmentsMail::messagesSince($before);
    $check(count($messages) === 3, 'Reminder reaches invitee, guest and host through the configured transport');
    foreach ($messages as $mime) {
        $decoded = AppointmentsMail::decoded($mime);
        $check(str_contains($mime, 'Content-Type: text/calendar') && str_contains($mime, 'Content-Type: application/ics')
            && substr_count($decoded, 'BEGIN:VCALENDAR') === 2 && str_contains($decoded, 'UID:' . $created['booking']['uid']),
            'Reminder has both calendar parts with the existing booking UID');
    }
    $invitee = array_values(array_filter($messages, static fn (string $mime): bool => preg_match('/^To:.*invitee@example\.test/mi', $mime) === 1));
    $check($invitee && str_contains(AppointmentsMail::decoded($invitee[0]), 'http://localhost/index.php?option=com_appointments'),
        'CLI reminder signed links use the configured public site root, not a CLI path');
    $row = $fixture->row($id);
    $markers = json_decode($row['reminder_sent_utc'], true, 512, JSON_THROW_ON_ERROR);
    $check(isset($markers[120]) && $row['modified_utc'] === $created['booking']['modified_utc'], 'Offset marker persists without invalidating action links');
    $before = AppointmentsMail::snapshot();
    $run('reminders');
    $check(AppointmentsMail::messagesSince($before) === [], 'Repeated reminder run delivers no duplicate completed offset');
    $db->query("UPDATE {$prefix}appointments_bookings SET state=0,reminder_sent_utc=NULL WHERE id={$id}");
    $run('reminders');
    $check(AppointmentsMail::messagesSince($before) === [], 'Pending approval does not receive a confirmed-appointment reminder');
    $setParams(['reminder_offsets' => 'invalid']);
    $run('reminders', 100, false);
    $reminders = array_filter(AppointmentsMail::messagesSince($before), static fn (string $mime): bool =>
        str_contains(AppointmentsMail::decoded($mime), 'UID:' . $created['booking']['uid']));
    $check($reminders === [], 'Invalid reminder configuration never sends an appointment reminder; Scheduler failure notices are separate');
    $setParams(['reminder_offsets' => '120', 'retention_days' => 365, 'audit_retention_years' => 3]);

    $oldIds = [];
    foreach (['10:00:00', '11:00:00', '12:00:00'] as $time) {
        $old = $fixture->booking($event, $day . ' ' . $time);
        $oldId = (int) $old['booking']['id'];
        $oldIds[] = $oldId;
        $oldStart = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
        $oldEnd = gmdate('Y-m-d H:i:s', time() - 400 * 86400 + 1200);
        $db->query("UPDATE {$prefix}appointments_bookings SET start_utc='{$oldStart}',end_utc='{$oldEnd}' WHERE id={$oldId}");
    }
    $fresh = $fixture->booking($event, $day . ' 13:00:00');
    $freshId = (int) $fresh['booking']['id'];
    $freshStart = gmdate('Y-m-d H:i:s', time() - 300 * 86400);
    $freshEnd = gmdate('Y-m-d H:i:s', time() - 300 * 86400 + 1200);
    $db->query("UPDATE {$prefix}appointments_bookings SET start_utc='{$freshStart}',end_utc='{$freshEnd}' WHERE id={$freshId}");
    $before = AppointmentsMail::snapshot();
    $run('retention', 2);
    $count = (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND anonymised_utc IS NOT NULL")->fetch_assoc()['n'];
    $check($count === 2 && $fixture->row($freshId)['anonymised_utc'] === null, 'Retention respects the configured period and a two-booking batch');
    $run('retention', 2);
    $count = (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE booking_id IN (" . implode(',', $oldIds) . ") AND path='retention' AND actor_user_id IS NULL")->fetch_assoc()['n'];
    $check($count === 3, 'Subsequent batch writes exactly one retention audit per expired booking');
    $run('retention', 2);
    $count = (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE booking_id IN (" . implode(',', $oldIds) . ")")->fetch_assoc()['n'];
    $check($count === 3 && AppointmentsMail::messagesSince($before) === [], 'Retention is idempotent and never sends historical cancellation mail');
    $db->query("UPDATE {$prefix}appointments_eventtypes SET retention_days=200 WHERE id={$event}");
    $run('retention');
    $check($fixture->row($freshId)['anonymised_utc'] !== null && $fixture->row($id)['anonymised_utc'] === null,
        'Event-type retention override erases the older completed booking, never a future booking');

    foreach (['old' => '-4 years', 'current' => '-1 year'] as $key => $period) {
        $time = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify($period)->format('Y-m-d H:i:s');
        $db->query("INSERT INTO {$prefix}appointments_audit (booking_id,action,path,actor_user_id,occurred_utc) VALUES ({$freshId},'anonymise','admin',{$fixture->host},'{$time}')");
        $auditIds[$key] = (int) $db->insert_id;
    }
    $oldWindow = gmdate('Y-m-d H:i:s', time() - 2 * 86400);
    $freshWindow = gmdate('Y-m-d H:i:s');
    $db->query("INSERT INTO {$prefix}appointments_ratelimit (bucket,window_utc,hits) VALUES ('{$bucket}','{$oldWindow}',1),('{$bucket}','{$freshWindow}',1)");
    $run('retention');
    $check((int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE id={$auditIds['old']}")->fetch_assoc()['n'] === 0
        && (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE id={$auditIds['current']}")->fetch_assoc()['n'] === 1,
        'Separate three-year audit retention removes old staff accountability only');
    $check((int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_ratelimit WHERE bucket='{$bucket}'")->fetch_assoc()['n'] === 1
        && $db->query("SELECT window_utc FROM {$prefix}appointments_ratelimit WHERE bucket='{$bucket}'")->fetch_assoc()['window_utc'] === $freshWindow,
        'One-day rate cleanup deletes only an expired window, not a current window of the same bucket');

    $race = $fixture->booking($event, $day . ' 14:00:00')['booking'];
    $raceId = (int) $race['id'];
    $expired = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
    $db->query("UPDATE {$prefix}appointments_bookings SET start_utc='{$expired}',end_utc='{$expired}' WHERE id={$raceId}");
    $raceTask = $createTask('retention', 100);
    $outputFile = tempnam(sys_get_temp_dir(), 'appointments-retention-');
    if ($outputFile === false) {
        throw new RuntimeException('Could not allocate the retention worker output file.');
    }
    $worker = null;
    $locked = false;
    try {
        $db->begin_transaction();
        $locked = true;
        $db->query("SELECT user_id FROM {$prefix}appointments_hosts WHERE user_id={$fixture->host} FOR UPDATE");
        $worker = proc_open('cd /var/www/html && HTTP_HOST=localhost php cli/joomla.php scheduler:run --id=' . $raceTask,
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $outputFile, 'w'], 2 => ['file', $outputFile, 'a']], $pipes);
        if (!is_resource($worker)) {
            throw new RuntimeException('Could not start the retention race worker.');
        }
        $waiting = false;
        $deadline = microtime(true) + 30;
        do {
            foreach ($db->query('SHOW FULL PROCESSLIST')->fetch_all(MYSQLI_ASSOC) as $process) {
                $sql = (string) ($process['Info'] ?? '');
                if (str_contains($sql, $prefix . 'appointments_hosts') && str_contains($sql, 'FOR UPDATE')) {
                    $waiting = true;
                    break;
                }
            }
            if (!$waiting) {
                usleep(50000);
            }
        } while (!$waiting && microtime(true) < $deadline && proc_get_status($worker)['running']);
        $check($waiting, 'Retention selected the expired candidate and is actually waiting on the host lock');
        $start = $db->real_escape_string($race['start_utc']);
        $end = $db->real_escape_string($race['end_utc']);
        $db->query("UPDATE {$prefix}appointments_bookings SET start_utc='{$start}',end_utc='{$end}',"
            . "sequence=sequence+1,modified_utc=DATE_ADD(modified_utc,INTERVAL 1 SECOND) WHERE id={$raceId}");
        $db->commit();
        $locked = false;
        $deadline = microtime(true) + 60;
        while (proc_get_status($worker)['running'] && microtime(true) < $deadline) {
            usleep(50000);
        }
        if (proc_get_status($worker)['running']) {
            throw new RuntimeException('The retention race worker did not finish after releasing its host lock.');
        }
        proc_close($worker);
        $worker = null;
        $task = $db->query("SELECT times_executed,last_exit_code FROM {$prefix}scheduler_tasks WHERE id={$raceTask}")->fetch_assoc();
        $row = $fixture->row($raceId);
        $check((int) $task['times_executed'] === 1 && (int) $task['last_exit_code'] === 0
            && $row['anonymised_utc'] === null && $row['invitee_email'] === $race['invitee_email']
            && $row['start_utc'] === $race['start_utc'] && $row['uid'] === $race['uid']
            && (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE booking_id={$raceId}")->fetch_assoc()['n'] === 0,
            'A stale retention candidate rescheduled under the host lock keeps its data and UID without an erasure audit');
    } finally {
        if ($locked) {
            $db->rollback();
        }
        if (is_resource($worker)) {
            if (proc_get_status($worker)['running']) {
                proc_terminate($worker);
            }
            proc_close($worker);
        }
        unlink($outputFile);
    }

    $deletion = $fixture->booking($event, $day . ' 14:30:00')['booking'];
    $deletionId = (int) $deletion['id'];
    $deletedBookingIds[] = $deletionId;
    $expired = gmdate('Y-m-d H:i:s', time() - 400 * 86400);
    $db->query("UPDATE {$prefix}appointments_bookings SET start_utc='{$expired}',end_utc='{$expired}' WHERE id={$deletionId}");
    $setParams(['erase_mode' => 'delete']);
    $before = AppointmentsMail::snapshot();
    $run('retention');
    $audit = $db->query("SELECT action,path,actor_user_id FROM {$prefix}appointments_audit WHERE booking_id={$deletionId}")->fetch_all(MYSQLI_ASSOC);
    $check($fixture->row($deletionId) === null && count($audit) === 1 && $audit[0]['action'] === 'delete'
        && $audit[0]['path'] === 'retention' && $audit[0]['actor_user_id'] === null
        && AppointmentsMail::messagesSince($before) === [], 'Retention delete mode removes the expired row with one minimal audit and no historical mail');
    $run('retention');
    $check((int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE booking_id={$deletionId}")->fetch_assoc()['n'] === 1,
        'Repeated retention deletion does not duplicate the audit');
} catch (Throwable $e) {
    $check(false, 'Scheduler integration failed: ' . $e->getMessage());
} finally {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($originalParams) . "' WHERE element='com_appointments' AND type='component'");
    foreach ($tasks as $task) {
        $db->query("DELETE FROM {$prefix}scheduler_tasks WHERE id={$task}");
    }
    foreach ($deletedBookingIds as $id) {
        $db->query("DELETE FROM {$prefix}appointments_audit WHERE booking_id={$id}");
    }
    $db->query("DELETE FROM {$prefix}appointments_ratelimit WHERE bucket='{$bucket}'");
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
