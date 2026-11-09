<?php

require __DIR__ . '/appointments-fixtures.php';
$fixture = new AppointmentsFixture('close');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$ids = [];
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
};
$original = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc()['params'];
$params = array_replace(json_decode($original, true) ?: [], [
    'close_enabled' => 0, 'close_origin' => 'https://crm.example.test',
    'close_type' => 'actitype_fixture', 'close_credential_env' => 'SYNTHETIC_CLOSE_KEY',
    'close_fields' => '{"uid":"cf_uid","sequence":"cf_seq","start":"cf_start","end":"cf_end","state":"cf_state","source":"cf_source"}',
    'close_questions' => '{}', 'close_delete_activity' => 1,
]);
$save = static function () use ($db, $prefix, &$params): void {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params))
        . "' WHERE element='com_appointments' AND type='component'");
    \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->loadArray($params);
};
$requests = [];
$activity = [];
$timeout = false;
$requestId = random_int(1000000000, 2000000000);
$transport = static function (string $method, string $path, ?array $payload) use (&$requests, &$activity, &$timeout): array {
    $requests[] = [$method, $path, $payload];
    if ($method === 'GET' && str_starts_with($path, '/api/v1/custom_field/activity/')) {
        return ['code' => 200, 'data' => ['data' => array_map(static fn ($name) => [
            'id' => 'cf_' . $name, 'type' => 'text', 'custom_activity_type_id' => 'actitype_fixture',
        ], ['uid', 'seq', 'start', 'end', 'state', 'source'])]];
    }
    if ($path === '/api/v1/data/search/') {
        return ['code' => 200, 'data' => ['data' => [['id' => 'cont_fixture', 'lead_id' => 'lead_fixture',
            'emails' => [['email' => 'invitee@example.test']]]], 'cursor' => null]];
    }
    if ($method === 'POST' && $path === '/api/v1/activity/custom/') {
        $activity = $payload + ['id' => 'acti_fixture'];
        if ($timeout) {
            throw new \RuntimeException('Synthetic timeout after remote write.');
        }
        return ['code' => 200, 'data' => $activity];
    }
    if ($method === 'GET' && $path === '/api/v1/activity/custom/acti_fixture/') {
        return ['code' => 200, 'data' => $activity];
    }
    if ($method === 'PUT') {
        $activity = array_replace($activity, $payload ?? []);
        return ['code' => 200, 'data' => $activity];
    }
    if ($method === 'DELETE') {
        $activity = [];
        return ['code' => 204, 'body' => ''];
    }
    throw new \RuntimeException('Unexpected mock request.');
};

try {
    require __DIR__ . '/appointments-joomla.php';
    $application->bootComponent('com_appointments');
    $save();
    $event = $fixture->event();
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    $db->query("UPDATE {$prefix}appointments_eventtypes SET requires_approval=1 WHERE id={$event}");
    $local = $fixture->booking($event, $day . ' 09:00:00')['booking'];
    $ids[] = (int) $local['id'];
    $queue = new \Advans\Component\Appointments\Administrator\Service\Close\Queue(
        $joomlaDb, \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments'), $transport);
    $store = new \Advans\Component\Appointments\Administrator\Service\BookingStore($joomlaDb);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $check($queue->run()['disabled'] === 1 && $requests === [], 'Installed default performs no external calls');
    $erased = $store->erase((int) $local['id'], $now, 'admin', actor: $fixture->host);
    $check(empty($erased['_crm_cleanup_error']), 'Never-enrolled local erasure invents no CRM obligation');
    $params['close_enabled'] = 1;
    $save();
    $booking = $fixture->booking($event, $day . ' 10:00:00')['booking'];
    $id = (int) $booking['id'];
    $ids[] = $id;
    $metadata = $db->query("SELECT * FROM {$prefix}appointments_close WHERE booking_id={$id}")->fetch_assoc();
    $check($metadata['state'] === 'pending' && !str_contains(json_encode($metadata), 'invitee@example.test'),
        'Canonical HTTP create atomically enqueues metadata, not invitee payload');
    $check($queue->run()['failed'] === 0 && $activity['custom.cf_state'] === 'pending',
        'Mock receives decrypted pending booking through installed worker');
    $store->changeState($id, 1, $now);
    $queue->run();
    $check($activity['custom.cf_state'] === 'confirmed', 'Canonical approval updates existing Activity');
    $store->reschedule($id, new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC')), $now);
    $queue->run();
    $check($activity['custom.cf_start'] === $day . 'T12:00:00Z' && $activity['custom.cf_seq'] === '2',
        'Canonical reschedule updates current encrypted booking without another Activity');
    $store->changeState($id, 2, $now);
    $queue->run();
    $check($activity['custom.cf_state'] === 'cancelled', 'Canonical cancellation updates Activity');
    $params['close_enabled'] = 0;
    $save();
    $erased = $store->erase($id, $now, 'admin', delete: true, actor: $fixture->host);
    $check(!empty($erased['_crm_cleanup_error']) && !$fixture->stored($id),
        'Disabled existing CRM obligation survives canonical hard deletion');
    $params['close_enabled'] = 1;
    $save();
    $queue->run();
    $check($activity === [] && !$queue->hasPendingErasure($id), 'Mock cleanup resolves own Activity after booking deletion');

    $booking = $fixture->booking($event, $day . ' 14:00:00')['booking'];
    $id = (int) $booking['id'];
    $ids[] = $id;
    $timeout = true;
    $queue->run();
    $before = count($requests);
    $queue->run(20, true);
    $check(count($requests) === $before, 'Installed durable uncertain intent prevents blind POST replay');
    $timeout = false;
    $queue->reconcile($id, 'acti_fixture');
    $queue->run();
    $check($db->query("SELECT state FROM {$prefix}appointments_close WHERE booking_id={$id}")->fetch_row()[0] === 'done',
        'Read-only remote reconciliation resumes existing Activity');
    $cleanup = new \Advans\Component\Appointments\Administrator\Service\PrivacyCleanup($joomlaDb,
        new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) (new JConfig())->secret),
        $requestId, 'invitee@example.test');
    \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, $requestId, false);
    $erased = $store->erase($id, $now, 'privacy_request', privacyCleanup: $cleanup);
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId)
        && $queue->pendingPrivacy($requestId) === 1, 'Native privacy gate sees request-scoped outstanding CRM cleanup');
    // A stale successful flag must not bypass the independently durable cleanup obligation.
    $db->query("UPDATE {$prefix}appointments_privacy_outcomes SET complete=1 WHERE request_id={$requestId}");
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId),
        'Stale successful outcome cannot hide unfinished remote cleanup');
    $queue->run(20, true, $requestId);
    \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, $requestId, true);
    $check(!\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId),
        'Verified own cleanup allows native Complete gate without completing Core automatically');
    $check(!$queue->hasPendingErasure($id), 'Synthetic final cleanup finishes without another booking or mail action');
    $check(count(array_filter($requests, static fn ($request) => $request[0] === 'POST' && $request[1] === '/api/v1/activity/custom/')) === 2,
        'Exactly one Activity create per synthetic booking despite changes and retries');
} catch (Throwable $e) {
    $check(false, 'Installed Close mock failed: ' . get_class($e) . ' at line ' . $e->getLine());
} finally {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($original) . "' WHERE element='com_appointments' AND type='component'");
    if ($ids) {
        $db->query("DELETE FROM {$prefix}appointments_close WHERE booking_id IN (" . implode(',', $ids) . ')');
        $db->query("DELETE FROM {$prefix}appointments_audit WHERE booking_id IN (" . implode(',', $ids) . ')');
    }
    $db->query("DELETE FROM {$prefix}appointments_privacy_outcomes WHERE request_id={$requestId}");
    $db->query("DELETE FROM {$prefix}appointments_privacy_cleanup WHERE request_id={$requestId}");
    $fixture->cleanup();
}
exit($failed > 0 ? 1 : 0);
