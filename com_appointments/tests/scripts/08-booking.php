<?php

require __DIR__ . '/appointments-http.php';
require '/var/www/html/configuration.php';
$config = new JConfig();
$db = new mysqli($config->host, $config->user, $config->password, $config->db);
$prefix = $config->dbprefix;
$tag = 'suite-booking-' . bin2hex(random_bytes(6));
$scheduleIds = [];
$eventIds = [];
$failures = 0;
$check = static function (bool $condition, string $label) use (&$failures): void {
    echo ($condition ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failures += $condition ? 0 : 1;
};
$utc = new DateTimeZone('UTC');
$day = (new DateTimeImmutable('now', $utc))->modify('+2 days')->format('Y-m-d');
$slot = $day . ' 10:00:00';
$admin = new AppointmentsHttp('http://localhost', 'booking-admin');

$prepare = static function (int $event, string $start) use ($check): array {
    $client = new AppointmentsHttp('http://localhost', 'booking-guest');
    $html = $client->request('/index.php?option=com_appointments&view=eventtype&id=' . $event . '&timezone=UTC&month=' . substr($start, 0, 7) . '&day=' . substr($start, 0, 10));
    $check($client->status === 200 && str_contains($html, 'name="booking[start]"'), 'No-JavaScript booking form rendered');
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $hidden = [];
    foreach ($xpath->query('//input[@type="hidden"]') as $input) {
        $hidden[$input->getAttribute('name')] = $input->getAttribute('value');
    }
    $data = [
        $client->token($html) => 1, 'rendered_at' => $hidden['rendered_at'] ?? '',
        'form_signature' => $hidden['form_signature'] ?? '', 'website' => '', 'consent' => 1,
        'booking' => ['name' => 'Fixture Invitee', 'email' => 'invitee@example.test', 'timezone' => 'UTC', 'start' => $start],
    ];
    return [$client, '/index.php?option=com_appointments&task=booking.submit&id=' . $event, $data];
};
$dom = static function (string $html): DOMXPath {
    $document = new DOMDocument();
    @$document->loadHTML($html);

    return new DOMXPath($document);
};
// A field error is wired to its control: aria-invalid, aria-describedby and the message next to it.
$fieldError = static function (DOMXPath $xpath, string $control, string $message): bool {
    $input = $xpath->query('//*[@id="' . $control . '"]')->item(0);
    $error = $xpath->query('//*[@id="' . $control . '-error"]')->item(0);

    return $input instanceof DOMElement && $error !== null
        && $input->getAttribute('aria-invalid') === 'true'
        && in_array($control . '-error', preg_split('/\s+/', $input->getAttribute('aria-describedby')), true)
        && trim($error->textContent) === $message;
};
$bookings = static fn (int $event): int => (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_bookings WHERE eventtype_id={$event}")->fetch_assoc()['n'];

try {
    $admin->login();
    $username = $db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
    $host = (int) $db->query("SELECT id FROM {$prefix}users WHERE username='{$username}'")->fetch_assoc()['id'];
    $time = (new DateTimeImmutable('now', $utc))->format('Y-m-d H:i:s');
    foreach ([0, 1] as $i) {
        $title = $tag . '-' . $i;
        $db->query("INSERT INTO {$prefix}appointments_schedules (title,timezone,owner_user_id,published,created_utc,modified_utc) VALUES ('{$title}','UTC',{$host},1,'{$time}','{$time}')");
        $schedule = (int) $db->insert_id;
        $scheduleIds[] = $schedule;
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $db->query("INSERT INTO {$prefix}appointments_schedule_rules (schedule_id,weekday,start_time,end_time) VALUES ({$schedule},{$weekday},'09:00:00','17:00:00')");
        }
        $token = bin2hex(random_bytes(32));
        $db->query("INSERT INTO {$prefix}appointments_eventtypes (title,alias,schedule_id,host_user_id,duration_minutes,slot_step_minutes,feed_token,created_utc,modified_utc) VALUES ('{$title}','{$title}',{$schedule},{$host},60,30,'{$token}','{$time}','{$time}')");
        $eventIds[] = (int) $db->insert_id;
    }
    $token = bin2hex(random_bytes(32));
    $db->query("INSERT INTO {$prefix}appointments_hosts (user_id,feed_token,created_utc,modified_utc) VALUES ({$host},'{$token}','{$time}','{$time}') ON DUPLICATE KEY UPDATE user_id=user_id");

    $check($scheduleIds[0] !== $scheduleIds[1], 'Overlap fixture uses two different schedules');
    $first = $prepare($eventIds[0], $slot);
    $second = $prepare($eventIds[0], $slot);
    sleep(2);
    $bodies = AppointmentsHttp::parallel([$first, $second]);
    $successes = count(array_filter($bodies, static fn ($html) => str_contains($html, 'Appointment received')));
    $friendly = count(array_filter($bodies, static fn ($html) => str_contains($html, 'This time was just taken')));
    $count = (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_bookings WHERE eventtype_id={$eventIds[0]} AND start_utc='{$slot}' AND state IN(0,1)")->fetch_assoc()['n'];
    $check($successes === 1 && $friendly === 1 && $count === 1, 'Identical concurrent slot: exactly one success and one friendly refreshed form');

    $db->query("DELETE FROM {$prefix}appointments_bookings WHERE eventtype_id IN(" . implode(',', $eventIds) . ')');
    $first = $prepare($eventIds[0], $slot);
    $secondSlot = $day . ' 10:30:00';
    $second = $prepare($eventIds[1], $secondSlot);
    sleep(2);
    $db->begin_transaction();
    $db->query("SELECT user_id FROM {$prefix}appointments_hosts WHERE user_id={$host} FOR UPDATE");
    $started = microtime(true);
    $released = false;
    $bothWaited = false;
    try {
        $bodies = AppointmentsHttp::parallel([$first, $second], static function (int $running) use ($db, &$released, &$bothWaited, $started): void {
            if ($released) {
                return;
            }
            $processes = $db->query('SHOW PROCESSLIST');
            $waiting = 0;
            while ($row = $processes->fetch_assoc()) {
                if ((int) $row['Id'] !== $db->thread_id && str_contains((string) $row['Info'], 'appointments_hosts')) {
                    $waiting++;
                }
            }
            if ($waiting >= 2 || microtime(true) - $started > 12 || !$running) {
                $bothWaited = $waiting >= 2;
                $db->commit();
                $released = true;
            }
        });
    } finally {
        if (!$released) {
            $db->rollback();
        }
    }
    $successes = count(array_filter($bodies, static fn ($html) => str_contains($html, 'Appointment received')));
    $friendly = count(array_filter($bodies, static fn ($html) => str_contains($html, 'This time was just taken')));
    $count = (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_bookings WHERE eventtype_id IN(" . implode(',', $eventIds) . ') AND state IN(0,1)')->fetch_assoc()['n'];
    $check($bothWaited, 'Both requests wait on the host row rather than their different schedules');
    $check($successes === 1 && $friendly === 1 && $count === 1, 'Cross-event overlapping concurrent slots: exactly one success and one friendly refreshed form');

    $db->query("DELETE FROM {$prefix}appointments_bookings WHERE eventtype_id IN(" . implode(',', $eventIds) . ')');
    $db->query("UPDATE {$prefix}appointments_eventtypes SET requires_approval=1 WHERE id={$eventIds[0]}");
    $request = $prepare($eventIds[0], $slot);
    sleep(2);
    $body = $request[0]->request($request[1], $request[2]);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$eventIds[0]}")->fetch_assoc();
    $check($booking && (int) $booking['state'] === 0 && str_contains($body, 'held while'), 'Pending request holds its slot');
    $bookingId = (int) ($booking['id'] ?? 0);
    $page = $admin->request('/administrator/index.php?option=com_appointments&view=bookings');
    $check($admin->status === 200 && str_contains($page, 'invitee@example.test'), 'Booking visible in administrator list');
    $admin->request('/administrator/index.php?option=com_appointments', ['task' => 'bookings.approve', 'cid' => [$bookingId], $admin->token($page) => 1]);
    $approved = $db->query("SELECT state,sequence,uid,modified_utc FROM {$prefix}appointments_bookings WHERE id={$bookingId}")->fetch_assoc();
    $check($approved && (int) $approved['state'] === 1 && (int) $approved['sequence'] === 1 && $approved['uid'] === $booking['uid'] && $approved['modified_utc'] > $booking['modified_utc'], 'Approval raises sequence and invalidates previous links');
    $page = $admin->request('/administrator/index.php?option=com_appointments&view=bookings');
    $admin->request('/administrator/index.php?option=com_appointments', ['task' => 'bookings.cancel', 'cid' => [$bookingId], $admin->token($page) => 1]);
    $cancelled = $db->query("SELECT state,state_active,sequence FROM {$prefix}appointments_bookings WHERE id={$bookingId}")->fetch_assoc();
    $check($cancelled && (int) $cancelled['state'] === 2 && $cancelled['state_active'] === null && (int) $cancelled['sequence'] === 2, 'Cancellation releases active slot index');

    $request = $prepare($eventIds[0], $slot);
    $request[2]['consent'] = 0;
    sleep(2);
    $body = $request[0]->request($request[1], $request[2]);
    $check($fieldError($dom($body), 'booking-consent', 'Please confirm your consent.')
        && !str_contains($body, 'The form has expired') && $bookings($eventIds[0]) === 1,
        'Missing consent gets its own message at the checkbox and never creates a booking');
    $request[2]['consent'] = 1;
    unset($request[2][$request[0]->token($body)]);
    $request[0]->request($request[1], $request[2]);
    $check($request[0]->status === 403, 'Missing CSRF token is rejected before mutation');

    // Release test 22.10.: every invalid field gets its own message at the field.
    $request = $prepare($eventIds[0], $slot);
    $request[2]['booking']['name'] = '';
    $request[2]['booking']['email'] = 'not-an-address';
    sleep(2);
    $body = $request[0]->request($request[1], $request[2]);
    $xpath = $dom($body);
    $check($fieldError($xpath, 'booking-name', 'Please enter your name.')
        && $fieldError($xpath, 'booking-email', 'Please enter a valid email address.'),
        'Empty name and invalid email each get their own message at the field with aria-invalid and aria-describedby');
    $check(substr_count($body, 'Please enter your name.') === 1 && !str_contains($body, 'Please complete all required fields'),
        'Field messages are not repeated as a collective message above the form');
    $email = $xpath->query('//input[@id="booking-email"]')->item(0);
    $check($email instanceof DOMElement && $email->getAttribute('value') === 'not-an-address' && $bookings($eventIds[0]) === 1,
        'A refused submission keeps the entered values and creates no booking');

    $request = $prepare($eventIds[0], $slot);
    $request[2]['consent'] = 0;
    $request[2]['booking']['name'] = '';
    sleep(2);
    $xpath = $dom($request[0]->request($request[1], $request[2]));
    $check($fieldError($xpath, 'booking-consent', 'Please confirm your consent.')
        && $fieldError($xpath, 'booking-name', 'Please enter your name.'),
        'A missing consent and an empty name are reported together in one round trip');

    $request = $prepare($eventIds[0], $slot);
    $request[2]['website'] = 'filled by a robot';
    sleep(2);
    $body = $request[0]->request($request[1], $request[2]);
    $check(str_contains($body, 'The form has expired or could not be verified.') && !str_contains($body, 'Please confirm your consent.')
        && $bookings($eventIds[0]) === 1, 'A filled honeypot gets the form message, never the consent message');

    // Release test 22.10.: dates in words of the site language, times without a zone abbreviation.
    $dates = new AppointmentsHttp('http://localhost', 'booking-dates');
    $html = $dates->request('/index.php?option=com_appointments&view=eventtype&id=' . $eventIds[1]
        . '&timezone=UTC&month=' . substr($day, 0, 7) . '&day=' . $day);
    $xpath = $dom($html);
    $date = new DateTimeImmutable($day, $utc);
    $text = static fn (string $query): string => trim((string) ($xpath->query($query)->item(0)?->textContent ?? ''));
    $check($text('//*[@id="appointment-calendar-title"]') === $date->format('F Y'), 'The month heading is written in words');
    $check($text('//legend[contains(@class, "com-appointments-slots-legend")]') === 'Available times on ' . $date->format('l, j F Y'),
        'The slot legend names the weekday and the date in words');
    $check($text('//label[@for="booking-slot-0"]') === '09:00' && preg_match('/\b\d{2}:\d{2} (?:UTC|CES?T)\b/', $html) === 0,
        'Slot times read 09:00 without a zone abbreviation at every time');
    $check(substr_count($html, 'All times are shown in the UTC time zone.') === 1, 'The time zone is stated once');
    $weekdays = $xpath->query('//table[contains(@class, "com-appointments-calendar-grid")]/thead//th');
    $firstWeekday = $xpath->query('//table[contains(@class, "com-appointments-calendar-grid")]/thead//th//abbr')->item(0);
    $check($weekdays->length === 7 && $firstWeekday instanceof DOMElement && $firstWeekday->getAttribute('title') === 'Monday',
        'The calendar has a weekday row that starts on Monday');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if ($eventIds) {
        $db->query("DELETE FROM {$prefix}appointments_bookings WHERE eventtype_id IN(" . implode(',', $eventIds) . ')');
        $db->query("DELETE FROM {$prefix}appointments_eventtypes WHERE id IN(" . implode(',', $eventIds) . ')');
    }
    if ($scheduleIds) {
        $db->query("DELETE FROM {$prefix}appointments_schedule_rules WHERE schedule_id IN(" . implode(',', $scheduleIds) . ')');
        $db->query("DELETE FROM {$prefix}appointments_schedules WHERE id IN(" . implode(',', $scheduleIds) . ')');
    }
}
exit($failures ? 1 : 0);
