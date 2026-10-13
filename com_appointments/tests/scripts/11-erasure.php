<?php

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-mail.php';
$fixture = new AppointmentsFixture('erasure');
$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
$originalParams = null;
$erasedIds = [];

try {
    $event = $fixture->event();
    $created = $fixture->booking($event, $day . ' 10:00:00');
    $booking = $created['booking'];
    $id = (int) $booking['id'];
    $erasedIds[] = $id;
    $db = $fixture->db;
    $prefix = $fixture->prefix;
    $private = [
        'invitee_name' => 'CANARY-INVITEE-NAME', 'invitee_email' => 'canary-invitee@example.test', 'invitee_phone' => '+41000000000',
        'invitee_timezone' => 'Europe/Zurich', 'invitee_language' => 'de-DE', 'guest_emails' => 'canary-guest@example.test',
        'custom_fields' => '{"12":"CANARY-ANSWER"}', 'cancel_reason' => 'CANARY-REASON',
        'conference_provider' => 'fixed', 'conference_url' => 'https://example.test/CANARY-LINK',
        'conference_room' => 'CANARY-ROOM', 'conference_state' => 'failed', 'conference_error' => 'CANARY-ERROR',
    ];
    $set = [];
    foreach ($private as $key => $value) {
        $set[] = "`{$key}`='" . $db->real_escape_string($value) . "'";
    }
    $db->query("UPDATE {$prefix}appointments_bookings SET " . implode(',', $set) . " WHERE id={$id}");
    $booking = $fixture->row($id);
    $link = $fixture->link($booking, 'erase');
    $client = $created['browser'];
    $html = $client->request($link);
    $check(str_contains($html, 'cannot be undone') && str_contains($html, 'anonymised record') && str_contains($html, 'canary-invitee@example.test'),
        'One-confirmation erasure page explains exactly what remains and identifies the booking');
    $before = AppointmentsMail::snapshot();
    $html = $fixture->apply($client, $link);
    $erased = $fixture->row($id);
    $check(str_contains($html, 'Your data has already been erased') && $erased['anonymised_utc'] !== null && (int) $erased['state'] === 2,
        'Future booking is cancelled and anonymised in the self-service operation');
    foreach (array_keys($private) as $field) {
        $check($erased[$field] === null, 'Erasure clears exactly the personal/conference field: ' . $field);
    }
    foreach (['id', 'uid', 'eventtype_id', 'host_user_id', 'start_utc', 'end_utc', 'created_utc'] as $field) {
        $check($erased[$field] === $booking[$field], 'Anonymised slot preserves: ' . $field);
    }
    $check((int) $erased['sequence'] === (int) $booking['sequence'] + 1 && $erased['modified_utc'] > $booking['modified_utc'], 'Cancellation sequence and signature revision advance');
    $messages = AppointmentsMail::messagesSince($before);
    $check(count($messages) === 1, 'Erasure sends exactly one host-only calendar cancellation');
    foreach ($messages as $mime) {
        $decoded = AppointmentsMail::decoded($mime);
        foreach (['CANARY-', 'canary-invitee@example.test', 'canary-guest@example.test', '+41000000000', 'Europe/Zurich'] as $canary) {
            $check(!str_contains($decoded, $canary), 'Host cancellation contains no former personal field: ' . $canary);
        }
        $check(str_contains($decoded, 'METHOD:CANCEL') && str_contains($decoded, 'STATUS:CANCELLED')
            && str_contains($decoded, 'UID:' . $booking['uid']), 'Host receives the cancellation of the original calendar UID');
    }
    $audit = $db->query("SELECT * FROM {$prefix}appointments_audit WHERE booking_id={$id}")->fetch_assoc();
    $check($audit && $audit['path'] === 'self_service' && $audit['actor_user_id'] === null, 'Self-service audit has no operator identity');
    $columns = array_keys($audit);
    sort($columns);
    $check($columns === ['action', 'actor_user_id', 'booking_id', 'id', 'occurred_utc', 'path'], 'Audit exact column contract contains no invitee data');
    $client->request($link);
    $check($client->status === 200 && (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE booking_id={$id}")->fetch_assoc()['n'] === 1,
        'Repeated browser receipt creates no second audit or notification');
    $outsider = new AppointmentsHttp('http://localhost', 'erasure-used-link');
    $outsider->request($link);
    $check($outsider->status === 404, 'Used erasure link is rejected without revealing the former booking');
    $fixture->booking($event, $day . ' 10:00:00');

    $row = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc();
    $originalParams = $row['params'];
    $params = json_decode($originalParams, true) ?: [];
    $params['erase_mode'] = 'delete';
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params)) . "' WHERE element='com_appointments' AND type='component'");
    $created = $fixture->booking($event, $day . ' 11:00:00');
    $booking = $created['booking'];
    $id = (int) $booking['id'];
    $erasedIds[] = $id;
    $link = $fixture->link($booking, 'erase');
    $html = $created['browser']->request($link);
    $check(str_contains($html, 'booking row will be deleted entirely'), 'Delete mode is clearly disclosed before confirmation');
    $fixture->apply($created['browser'], $link);
    $check($fixture->row($id) === null && $db->query("SELECT action FROM {$prefix}appointments_audit WHERE booking_id={$id}")->fetch_assoc()['action'] === 'delete',
        'Delete mode removes the row and preserves only the minimal deletion evidence');
    $html = $created['browser']->request($link);
    $check(str_contains($html, 'already been erased'), 'Deleted-row repeat click remains idempotent without exposing data');

    $params['erase_mode'] = 'anonymise';
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params)) . "' WHERE element='com_appointments' AND type='component'");
    $created = $fixture->booking($event, $day . ' 12:00:00');
    $id = (int) $created['booking']['id'];
    $erasedIds[] = $id;
    $admin = new AppointmentsHttp('http://localhost', 'erasure-admin');
    $admin->login();
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.erase', ['cid' => [$id], $token => 1]);
    $audit = $db->query("SELECT * FROM {$prefix}appointments_audit WHERE booking_id={$id}")->fetch_assoc();
    $check($audit && $audit['path'] === 'admin' && (int) $audit['actor_user_id'] === $fixture->host, 'Administrator erasure records only the acting operator identity');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    if ($originalParams !== null) {
        $fixture->db->query("UPDATE {$fixture->prefix}extensions SET params='" . $fixture->db->real_escape_string($originalParams) . "' WHERE element='com_appointments' AND type='component'");
    }
    foreach ($erasedIds as $id) {
        $fixture->db->query("DELETE FROM {$fixture->prefix}appointments_audit WHERE booking_id={$id}");
    }
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
