<?php

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-mail.php';
$fixture = new AppointmentsFixture('lifecycle');
$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');

try {
    $event = $fixture->event();
    $created = $fixture->booking($event, $day . ' 10:00:00', ['guests' => 'guest@example.test']);
    $booking = $created['booking'];
    $client = $created['browser'];
    $reschedule = $fixture->link($booking, 'reschedule');
    $cancel = $fixture->link($booking, 'cancel');
    $html = $client->request($reschedule);
    $check($client->status === 200 && str_contains($html, 'name="start"'), 'Signed reschedule page works without login or JavaScript');
    $token = $client->token($html);
    $client->request('/index.php?option=com_appointments&task=booking.manage', [
        'id' => $booking['id'], 'action' => 'erase', 'signature' => str_repeat('0', 64), 'confirm' => 1, $token => 1,
    ]);
    $check($client->status === 404 && $fixture->row((int) $booking['id'])['anonymised_utc'] === null, 'Wrong signature cannot erase data');
    parse_str(parse_url($cancel, PHP_URL_QUERY), $cancelQuery);
    $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($cancelQuery, ['action' => 'erase', 'confirm' => 1, $token => 1]));
    $check($client->status === 404 && $fixture->row((int) $booking['id'])['anonymised_utc'] === null, 'Cancel signature cannot be replayed for erasure');
    $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($cancelQuery, ['confirm' => 1]));
    $check($client->status === 403 && (int) $fixture->row((int) $booking['id'])['state'] === 1, 'Every lifecycle POST requires CSRF');

    $before = AppointmentsMail::snapshot();
    $fixture->apply($client, $reschedule, ['start' => $day . ' 11:00:00', 'month' => substr($day, 0, 7), 'timezone' => 'Europe/Zurich']);
    $moved = $fixture->row((int) $booking['id']);
    $check($moved && $moved['uid'] === $booking['uid'] && (int) $moved['sequence'] === 1 && $moved['start_utc'] === $day . ' 11:00:00'
        && $moved['invitee_timezone'] === 'Europe/Zurich', 'Reschedule updates the same UID/id, sequence and displayed zone');
    $check((int) $fixture->db->query("SELECT COUNT(*) AS n FROM {$fixture->prefix}appointments_bookings WHERE uid='" . $booking['uid'] . "'")->fetch_assoc()['n'] === 1,
        'Exactly one row remains for the calendar UID');
    $messages = AppointmentsMail::messagesSince($before);
    $check(count($messages) === 3, 'Reschedule sends actual mail to invitee, guest and host');
    foreach ($messages as $mime) {
        $decoded = AppointmentsMail::decoded($mime);
        $check(str_contains($mime, 'Content-Type: text/calendar;') && str_contains($mime, 'Content-Type: application/ics')
            && str_contains($decoded, 'UID:' . $booking['uid']) && str_contains($decoded, "SEQUENCE:1\r\n")
            && str_contains($decoded, "METHOD:REQUEST\r\n"), 'Every reschedule recipient receives both MIME calendar parts with the updated original UID');
    }
    $outsider = new AppointmentsHttp('http://localhost', 'lifecycle-used-link');
    $outsider->request($reschedule);
    $check($outsider->status === 404, 'Used reschedule signature is rejected');
    $outsider->request($cancel);
    $check($outsider->status === 404, 'Pre-reschedule cancel link is also invalidated');

    $fresh = $fixture->link($moved, 'reschedule');
    $form = $client->request($fresh);
    $token = $client->token($form);
    $check(str_contains($form, $day . ' 12:00:00'), 'Reschedule form offers the slot before the competing booking');
    $otherEvent = $fixture->event();
    $fixture->booking($otherEvent, $day . ' 12:00:00');
    parse_str(parse_url($fresh, PHP_URL_QUERY), $query);
    $html = $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($query, [
        $token => 1, 'confirm' => 1, 'start' => $day . ' 12:00:00', 'month' => substr($day, 0, 7), 'timezone' => 'UTC',
    ]));
    $check(str_contains($html, 'This time was just taken')
        && $fixture->row((int) $booking['id'])['start_utc'] === $day . ' 11:00:00', 'Overlapping cross-event reschedule is refused without changing the appointment');
    $freshCancel = $fixture->link($moved, 'cancel');
    $html = $fixture->apply($client, $freshCancel, ['reason' => 'Fixture reason']);
    $cancelled = $fixture->row((int) $booking['id']);
    $check(str_contains($html, 'Your appointment has been cancelled') && (int) $cancelled['state'] === 2
        && (int) $cancelled['sequence'] === 2 && $cancelled['uid'] === $booking['uid'] && $cancelled['cancel_reason'] === 'Fixture reason',
        'Signed cancellation stores the optional reason and updates the stable calendar entry');
    $check(str_contains($client->request($freshCancel), 'Your appointment has been cancelled'), 'Repeated same-browser completion is an idempotent receipt');
    $outsider->request($freshCancel);
    $check($outsider->status === 404, 'Used cancellation signature is rejected in another browser');
    $replacement = $fixture->booking($event, $day . ' 11:00:00')['booking'];
    $check((int) $replacement['id'] !== (int) $booking['id'], 'Cancellation releases the exact slot');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
