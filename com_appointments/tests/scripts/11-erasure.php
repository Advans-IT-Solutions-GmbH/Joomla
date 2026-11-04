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
        'invitee_name' => 'CANARY-INVITEE-NAME', 'invitee_first_name' => 'CANARY-FIRST-NAME', 'invitee_last_name' => 'CANARY-LAST-NAME',
        'invitee_email' => 'canary-invitee@example.test', 'invitee_phone' => '+41000000000',
        'invitee_timezone' => 'Europe/Zurich', 'invitee_language' => 'de-DE', 'guest_emails' => 'canary-guest@example.test',
        'custom_fields' => '{"12":"CANARY-ANSWER"}', 'cancel_reason' => 'CANARY-REASON',
        'conference_provider' => 'fixed', 'conference_url' => 'https://example.test/CANARY-LINK',
        'conference_room' => 'CANARY-ROOM', 'conference_state' => 'failed', 'conference_error' => 'CANARY-ERROR',
    ];
    // Stored the way the application stores them: the personal fields sealed.
    $fixture->update($id, $private);
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

    // Guest self-removal: the signed link of one guest removes only that guest's address.
    $created = $fixture->booking($event, $day . ' 13:00:00', ['guests' => 'keep@example.test, Leave@example.test']);
    $guestBooking = $created['booking'];
    $gid = (int) $guestBooking['id'];
    $erasedIds[] = $gid;
    $guestLink = $fixture->guestLink($guestBooking, 'leave@example.test');
    parse_str((string) parse_url($guestLink, PHP_URL_QUERY), $guestQuery);
    $check(!str_contains($guestLink, 'example.test') && !str_contains($guestLink, 'example%2Etest') && !str_contains(strtolower($guestLink), 'leave'),
        'The guest link carries no address');
    $visitor = new AppointmentsHttp('http://localhost', 'erasure-guest');
    $html = $visitor->request($guestLink);
    $check($visitor->status === 200 && str_contains($html, 'Leave@example.test') && str_contains($html, 'Remove my address')
        && !str_contains($html, 'keep@example.test') && !str_contains($html, 'invitee@example.test') && !str_contains($html, 'Fixture Invitee'),
        'The guest confirmation page names only the guest address and the appointment, not the booking person or the other guests');
    $check($fixture->row($gid)['guest_emails'] === $guestBooking['guest_emails'], 'Opening the guest link changes nothing');
    $tampered = $guestLink . '0';
    $outsider = new AppointmentsHttp('http://localhost', 'erasure-guest-tampered');
    $outsider->request($tampered);
    $tamperedStatus = $outsider->status;
    $outsider->request(str_replace('id=' . $gid, 'id=' . ($gid + 1000), $guestLink));
    $check($tamperedStatus === 404 && $outsider->status === 404, 'A tampered guest signature or another booking id answers 404');
    $outsider->request($fixture->guestLink($guestBooking, 'stranger@example.test'));
    $check($outsider->status === 404, 'A correctly signed link for an address that is not a guest answers 404');
    $visitor->request('/index.php?option=com_appointments&task=guest.remove', ['id' => $gid, 'signature' => $guestQuery['signature']]);
    $check($visitor->status === 403 && $fixture->row($gid)['guest_emails'] === $guestBooking['guest_emails'], 'Guest removal without the form token is refused');
    $html = $visitor->request('/index.php?option=com_appointments&task=guest.remove', [
        'id' => $gid, 'signature' => $guestQuery['signature'], $visitor->token($visitor->request($guestLink)) => 1,
    ]);
    $after = $fixture->row($gid);
    $check(str_contains($html, 'Your address has been removed from this appointment.') && $after['guest_emails'] === 'keep@example.test',
        'The confirmed guest link removes exactly that address from the guest list');
    $check($after['invitee_email'] === $guestBooking['invitee_email'] && $after['invitee_name'] === $guestBooking['invitee_name']
        && (int) $after['state'] === (int) $guestBooking['state'] && $after['anonymised_utc'] === null
        && $after['modified_utc'] === $guestBooking['modified_utc'] && (int) $after['sequence'] === (int) $guestBooking['sequence'],
        'The booking, the booking person and the row version stay unchanged');
    $audit = $db->query("SELECT * FROM {$prefix}appointments_audit WHERE booking_id={$gid}")->fetch_all(MYSQLI_ASSOC);
    $check(count($audit) === 1 && $audit[0]['action'] === 'remove_guest' && $audit[0]['path'] === 'self_service' && $audit[0]['actor_user_id'] === null,
        'Guest removal writes one audit row without any address');
    $visitor->request($guestLink);
    $check($visitor->status === 200 && (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE booking_id={$gid}")->fetch_assoc()['n'] === 1,
        'The same browser sees its receipt again without a second removal');
    $replay = new AppointmentsHttp('http://localhost', 'erasure-guest-replay');
    $replay->request($guestLink);
    $check($replay->status === 404, 'A used guest link is rejected in another browser');
    $keep = new AppointmentsHttp('http://localhost', 'erasure-guest-keep');
    $keep->request($fixture->guestLink($after, 'keep@example.test'));
    $inviteeLink = new AppointmentsHttp('http://localhost', 'erasure-guest-invitee');
    $inviteeLink->request($fixture->link($after, 'erase'));
    $check($keep->status === 200 && $inviteeLink->status === 200, 'The other guest and the booking person keep their working links');
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
