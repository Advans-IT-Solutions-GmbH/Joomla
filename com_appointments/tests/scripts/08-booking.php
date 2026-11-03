<?php

require __DIR__ . '/appointments-http.php';
require __DIR__ . '/appointments-mail.php';
require '/var/www/html/configuration.php';
$config = new JConfig();
$db = new mysqli($config->host, $config->user, $config->password, $config->db);
$prefix = $config->dbprefix;
$tag = 'suite-booking-' . bin2hex(random_bytes(6));
$scheduleIds = [];
$eventIds = [];
$componentParams = null;
$privacyMenu = null;
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
    $html = $client->request('/index.php?option=com_appointments&view=eventtype&id=' . $event . '&timezone=UTC&start=' . urlencode($start));
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
$pageTitle = static fn (string $html): string => preg_match('#<title>(.*?)</title>#s', $html, $m) ? trim(html_entity_decode($m[1])) : '';
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
    $successes = count(array_filter($bodies, static fn ($html) => str_contains($html, 'Appointment confirmed')));
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
    $successes = count(array_filter($bodies, static fn ($html) => str_contains($html, 'Appointment confirmed')));
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
    $check($pageTitle($body) === 'Appointment received', 'The confirmation page sets its own title without a menu item of its own');
    // Step 3: the calendar entry and the reschedule and cancel links of the confirmation mail.
    $confirmation = $dom($body);
    $calendarLink = $confirmation->query('//a[contains(@class, "com-appointments-calendar-add")]')->item(0);
    $calendarHref = $calendarLink instanceof DOMElement ? preg_replace('#^https?://[^/]+#', '', html_entity_decode($calendarLink->getAttribute('href'))) : '';
    $check(str_contains($calendarHref, 'task=booking.ics') && str_contains($calendarHref, 'id=' . (int) ($booking['id'] ?? 0))
        && $confirmation->query('//a[contains(@class, "com-appointments-reschedule")][contains(@href, "action=reschedule")]')->length === 1
        && $confirmation->query('//a[contains(@class, "com-appointments-cancel")][contains(@href, "action=cancel")]')->length === 1,
        'The confirmation offers the calendar entry and the signed reschedule and cancel links');
    $ics = $calendarHref !== '' ? $request[0]->request($calendarHref) : '';
    $check($request[0]->status === 200 && str_starts_with($ics, 'BEGIN:VCALENDAR') && str_contains($ics, 'UID:' . ($booking['uid'] ?? '-')),
        'The .ics download of the confirmation carries the appointment with the UID of its mail attachment');
    $request[0]->request(str_replace('id=' . (int) ($booking['id'] ?? 0), 'id=' . ((int) ($booking['id'] ?? 0) + 1000), $calendarHref));
    $stale = $request[0]->status;
    $check($stale === 404, 'An .ics link of another appointment is refused in the booking session');
    $stranger = new AppointmentsHttp('http://localhost', 'booking-stranger');
    $stranger->request($calendarHref !== '' ? $calendarHref : '/index.php?option=com_appointments&task=booking.ics&id=1');
    $check($stranger->status === 404, 'The .ics download is not available to another browser session');
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
    $focused = $xpath->query('//*[@autofocus]');
    $check($focused->length === 1 && $focused->item(0)->getAttribute('id') === 'booking-name',
        'The first invalid control in page order receives the focus, not the consent checkbox');

    $request = $prepare($eventIds[0], $slot);
    $request[2]['website'] = 'filled by a robot';
    sleep(2);
    $body = $request[0]->request($request[1], $request[2]);
    $check(str_contains($body, 'The form has expired or could not be verified.') && !str_contains($body, 'Please confirm your consent.')
        && $bookings($eventIds[0]) === 1, 'A filled honeypot gets the form message, never the consent message');

    // A sent time that is no time at all names no day: the reason stands above the calendar.
    $request = $prepare($eventIds[0], $slot);
    $request[2]['booking']['start'] = 'not-a-time';
    sleep(2);
    $xpath = $dom($request[0]->request($request[1], $request[2]));
    $check(trim((string) ($xpath->query('//*[@id="booking-start-error"]')->item(0)?->textContent ?? '')) === 'Please choose an available time.'
        && $xpath->query('//form[@id="appointment-details-form"]')->length === 0 && $bookings($eventIds[0]) === 1,
        'A malformed time returns to the calendar with its message, without a booking');

    // Release test 22.10.: dates in words of the site language, times without a zone abbreviation.
    $dates = new AppointmentsHttp('http://localhost', 'booking-dates');
    $html = $dates->request('/index.php?option=com_appointments&view=eventtype&id=' . $eventIds[1]
        . '&timezone=UTC&month=' . substr($day, 0, 7) . '&day=' . $day);
    $xpath = $dom($html);
    $date = new DateTimeImmutable($day, $utc);
    $text = static fn (string $query): string => trim((string) ($xpath->query($query)->item(0)?->textContent ?? ''));
    $check($pageTitle($html) === $tag . '-1' && $text('//h1[contains(@class, "com-appointments-heading")]') === $tag . '-1',
        'The booking page uses the event type title as page title and heading without a menu item of its own');
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

    // A UTC schedule seen from Zurich on the next day the clocks go back: the repeated
    // local hour must not show two different slots with the same label.
    $zurich = new DateTimeZone('Europe/Zurich');
    $back = null;
    $previous = null;
    foreach ($zurich->getTransitions(time() + 86400, time() + 400 * 86400) as $index => $transition) {
        if ($index > 0 && $transition['offset'] < $previous) {
            $back = (int) $transition['ts'];
            break;
        }
        $previous = $transition['offset'];
    }
    $check($back !== null, 'Fixture finds the next change back to standard time in Europe/Zurich');
    $title = $tag . '-repeated';
    $db->query("INSERT INTO {$prefix}appointments_schedules (title,timezone,owner_user_id,published,created_utc,modified_utc) VALUES ('{$title}','UTC',{$host},1,'{$time}','{$time}')");
    $schedule = (int) $db->insert_id;
    $scheduleIds[] = $schedule;
    $weekday = (int) gmdate('N', $back - 3600);
    $from = gmdate('H:i:s', $back - 3600);
    $until = gmdate('H:i:s', $back + 7200);
    $db->query("INSERT INTO {$prefix}appointments_schedule_rules (schedule_id,weekday,start_time,end_time) VALUES ({$schedule},{$weekday},'{$from}','{$until}')");
    $token = bin2hex(random_bytes(32));
    $db->query("INSERT INTO {$prefix}appointments_eventtypes (title,alias,schedule_id,host_user_id,duration_minutes,slot_step_minutes,horizon_days,feed_token,created_utc,modified_utc) VALUES ('{$title}','{$title}',{$schedule},{$host},60,60,400,'{$token}','{$time}','{$time}')");
    $eventIds[] = $repeatedEvent = (int) $db->insert_id;
    $localDay = (new DateTimeImmutable('@' . ($back - 3600)))->setTimezone($zurich);
    $html = $dates->request('/index.php?option=com_appointments&view=eventtype&id=' . $repeatedEvent
        . '&timezone=Europe%2FZurich&month=' . $localDay->format('Y-m') . '&day=' . $localDay->format('Y-m-d'));
    $xpath = $dom($html);
    $labels = [];
    foreach ($xpath->query('//div[contains(@class, "com-appointments-slot")]/label') as $label) {
        $labels[] = trim($label->textContent);
    }
    $first = (new DateTimeImmutable('@' . ($back - 3600)))->setTimezone($zurich);
    $second = (new DateTimeImmutable('@' . $back))->setTimezone($zurich);
    $third = (new DateTimeImmutable('@' . ($back + 3600)))->setTimezone($zurich);
    $check($labels === [
        $first->format('H:i') . ' (UTC' . $first->format('P') . ')',
        $second->format('H:i') . ' (UTC' . $second->format('P') . ')',
        $third->format('H:i'),
    ] && count(array_unique($labels)) === 3, 'Slots in the repeated local hour carry their UTC offset, other slots stay compact');

    // Booking form rework: the submissions above used most of the hourly booking budget of
    // this one client address, so the following checks start with a fresh rate-limit window.
    $db->query("DELETE FROM {$prefix}appointments_ratelimit");
    $page = new AppointmentsHttp('http://localhost', 'booking-page');
    // Step 1 shows the times of the day, step 2 (start=) the details form of the chosen time.
    $show = static fn (int $event, string $start = ''): DOMXPath => $dom($page->request('/index.php?option=com_appointments&view=eventtype&id=' . $event
        . '&timezone=UTC' . ($start === '' ? '&month=' . substr($day, 0, 7) . '&day=' . $day : '&start=' . urlencode($start))));
    $freeSlot = $day . ' 15:00:00';

    // Without JavaScript every step is a link or a form; the script is an external, deferred
    // file of the component and the markup carries no inline code (CSP without unsafe-inline).
    $xpath = $show($eventIds[1]);
    $root = '//div[contains(@class, "com-appointments-eventtype")]';
    $script = $xpath->query('//script[contains(@src, "media/com_appointments/js/booking.js")]')->item(0);
    $check($script instanceof DOMElement && $script->hasAttribute('defer'), 'The booking script is loaded as a deferred file through the asset manager');
    $check($xpath->query($root . '//script')->length === 0 && $xpath->query($root . '//@*[starts-with(name(), "on")]')->length === 0,
        'The booking markup has no inline script and no inline event handler');
    $dayLink = $xpath->query($root . '//a[contains(@class, "com-appointments-day-available")]')->item(0);
    $check($dayLink instanceof DOMElement && str_ends_with($dayLink->getAttribute('href'), '#appointment-picker')
        && $xpath->query('//*[@id="appointment-picker"]')->length === 1,
        'Without JavaScript a day link reloads the page and lands at the date and time part');
    $check($xpath->query('//a[contains(@class, "com-appointments-calendar-next")][@aria-label="Next month"]')->length === 1
        && trim($xpath->query('//a[contains(@class, "com-appointments-calendar-next")]')->item(0)->textContent) === ''
        && $xpath->query('//*[contains(@class, "com-appointments-calendar-prev")][contains(@class, "disabled")]')->length === (substr($day, 0, 7) === gmdate('Y-m') ? 1 : 0),
        'Month navigation uses arrow buttons with accessible names, and the current month has no way back into the past');
    $xpath = $show($eventIds[1], $freeSlot);
    $check($xpath->query('//input[@id="booking-phone"]')->length === 0, 'Without a telephone location the form has no fixed telephone field');
    $check($xpath->query('//label[@for="booking-consent"]//a')->length === 0
        && trim($xpath->query('//label[@for="booking-consent"]')->item(0)->textContent) === 'I agree to the processing of my data for this appointment.',
        'Without a configured privacy notice the consent label is plain text');

    // The privacy notice option: the consent label links to it, and the calling-code option preselects a code.
    $componentParams = (string) $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_row()[0];
    $configured = json_encode(array_merge(json_decode($componentParams, true) ?: [], ['privacy_url' => '/privacy-fixture#booking', 'phone_default_country' => '41']));
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($configured) . "' WHERE element='com_appointments' AND type='component'");
    $xpath = $show($eventIds[1], $freeSlot);
    $privacy = $xpath->query('//label[@for="booking-consent"]//a')->item(0);
    $check($privacy instanceof DOMElement && $privacy->getAttribute('href') === '/privacy-fixture#booking'
        && $privacy->getAttribute('target') === '_blank' && str_contains($privacy->getAttribute('rel'), 'noopener')
        && str_starts_with(trim($xpath->query('//label[@for="booking-consent"]')->item(0)->textContent), 'I have read the privacy notice'),
        'The configured privacy notice is a link in the consent label that opens in a new window');

    // A privacy menu item takes precedence over the URL and carries its anchor; a menu item a guest
    // cannot open is never linked and the URL applies again.
    $component = (int) $db->query("SELECT extension_id FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_row()[0];
    $menuAlias = $tag . '-privacy';
    $db->query("INSERT INTO {$prefix}menu (menutype,title,alias,note,path,link,type,published,parent_id,level,component_id,browserNav,access,img,template_style_id,params,lft,rgt,home,language,client_id)"
        . " VALUES ('mainmenu','{$menuAlias}','{$menuAlias}','','{$menuAlias}','index.php?option=com_appointments&view=eventtypes','component',1,1,1,{$component},0,1,'',0,'{}',0,0,0,'*',0)");
    $privacyMenu = (int) $db->insert_id;
    $configured = json_encode(array_merge(json_decode($componentParams, true) ?: [], ['privacy_url' => '/privacy-fixture#booking',
        'phone_default_country' => '41', 'privacy_menuitem' => (string) $privacyMenu, 'privacy_anchor' => 'privacy-section']));
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($configured) . "' WHERE element='com_appointments' AND type='component'");
    $privacy = $show($eventIds[1], $freeSlot)->query('//label[@for="booking-consent"]//a')->item(0);
    $check($privacy instanceof DOMElement && (str_contains($privacy->getAttribute('href'), $menuAlias) || str_contains($privacy->getAttribute('href'), 'Itemid=' . $privacyMenu))
        && str_ends_with($privacy->getAttribute('href'), '#privacy-section') && !str_contains($privacy->getAttribute('href'), '&amp;'),
        'The privacy menu item takes precedence over the URL and keeps its section anchor');
    $db->query("UPDATE {$prefix}menu SET access=3 WHERE id={$privacyMenu}");
    $privacy = $show($eventIds[1], $freeSlot)->query('//label[@for="booking-consent"]//a')->item(0);
    $check($privacy instanceof DOMElement && $privacy->getAttribute('href') === '/privacy-fixture#booking',
        'A privacy menu item the visitor cannot open is not linked, the URL applies instead');
    // One host serves both events, so each books its own hour.
    foreach (['phone_host_calls' => $day . ' 11:00:00', 'phone_invitee_calls' => $day . ' 13:00:00'] as $location => $phoneSlot) {
        $title = $tag . '-' . $location;
        $db->query("INSERT INTO {$prefix}appointments_schedules (title,timezone,owner_user_id,published,created_utc,modified_utc) VALUES ('{$title}','UTC',{$host},1,'{$time}','{$time}')");
        $schedule = (int) $db->insert_id;
        $scheduleIds[] = $schedule;
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $db->query("INSERT INTO {$prefix}appointments_schedule_rules (schedule_id,weekday,start_time,end_time) VALUES ({$schedule},{$weekday},'09:00:00','17:00:00')");
        }
        $token = bin2hex(random_bytes(32));
        $db->query("INSERT INTO {$prefix}appointments_eventtypes (title,alias,schedule_id,host_user_id,duration_minutes,slot_step_minutes,location_type,feed_token,created_utc,modified_utc) VALUES ('{$title}','{$title}',{$schedule},{$host},30,30,'{$location}','{$token}','{$time}','{$time}')");
        $eventIds[] = $phoneEvent = (int) $db->insert_id;

        $xpath = $show($phoneEvent, $phoneSlot);
        $number = $xpath->query('//input[@id="booking-phone"]')->item(0);
        $selected = $xpath->query('//select[@id="booking-phone-country"][@name="booking[phone][country]"]/option[@selected]')->item(0);
        $check($number instanceof DOMElement && $number->getAttribute('name') === 'booking[phone][number]'
            && $number->getAttribute('type') === 'tel' && $number->hasAttribute('required')
            && $selected instanceof DOMElement && $selected->getAttribute('value') === '41',
            $location . ': the telephone number is a fixed required field with the configured calling code preselected');

        $request = $prepare($phoneEvent, $phoneSlot);
        sleep(2);
        $body = $request[0]->request($request[1], $request[2]);
        $check($fieldError($dom($body), 'booking-phone', 'Please fill in this field.') && $bookings($phoneEvent) === 0,
            $location . ': a booking without a telephone number is refused at the telephone field');

        $request = $prepare($phoneEvent, $phoneSlot);
        $request[2]['booking']['phone'] = ['country' => '41', 'number' => '12'];
        sleep(2);
        $body = $request[0]->request($request[1], $request[2]);
        $check($fieldError($dom($body), 'booking-phone', 'Please enter a valid telephone number with its country calling code.')
            && $bookings($phoneEvent) === 0, $location . ': an invalid telephone number is refused at the telephone field');

        $request = $prepare($phoneEvent, $phoneSlot);
        $request[2]['booking']['phone'] = ['country' => '41', 'number' => '079 123 45 67'];
        $before = AppointmentsMail::snapshot();
        sleep(2);
        $body = $request[0]->request($request[1], $request[2]);
        $stored = $db->query("SELECT id,invitee_phone,custom_fields FROM {$prefix}appointments_bookings WHERE eventtype_id={$phoneEvent} AND state IN (0,1)")->fetch_assoc();
        $check(str_contains($body, 'Appointment confirmed') && $stored && $stored['invitee_phone'] === '+41791234567'
            && $stored['custom_fields'] === '[]', $location . ': the number is stored in invitee_phone in international form');
        $mailed = array_filter(AppointmentsMail::messagesSince($before), static fn (string $mime): bool => str_contains(AppointmentsMail::decoded($mime), '+41791234567'));
        $check($mailed !== [], $location . ': the notification mail states the telephone number');
        $detail = $admin->request('/administrator/index.php?option=com_appointments&view=booking&id=' . (int) ($stored['id'] ?? 0));
        $check($admin->status === 200 && str_contains($detail, '+41791234567'), $location . ': the administrator detail shows the telephone number');
    }
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failures++;
} finally {
    if ($privacyMenu !== null) {
        $db->query("DELETE FROM {$prefix}menu WHERE id={$privacyMenu}");
    }
    if ($componentParams !== null) {
        $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($componentParams) . "' WHERE element='com_appointments' AND type='component'");
    }
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
