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
    $check($client->status === 200 && str_contains($html, 'com-appointments-calendar-grid')
        && str_contains($html, 'com-appointments-day-available')
        && !str_contains($html, 'type="month"') && !str_contains($html, 'id="new-start"'),
        'Signed reschedule page uses the booking calendar without a raw month field or slot dropdown');
    $calendarDocument = new DOMDocument();
    @$calendarDocument->loadHTML($html);
    $calendarXpath = new DOMXPath($calendarDocument);
    $dayLink = $calendarXpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " com-appointments-day-available ")]')->item(0);
    $dayPage = $dayLink instanceof DOMElement ? $client->request(html_entity_decode($dayLink->getAttribute('href'), ENT_QUOTES | ENT_HTML5)) : '';
    $dayDocument = new DOMDocument();
    @$dayDocument->loadHTML($dayPage);
    $check($client->status === 200 && (new DOMXPath($dayDocument))->query('//input[@type="radio"][@name="start"]')->length > 0,
        'Choosing an available calendar day displays selectable reschedule times');
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $cancelExit = (new DOMXPath($document))->query(
        '//a[contains(concat(" ", normalize-space(@class), " "), " com-appointments-return ")]'
    )->item(0);
    $check($cancelExit instanceof DOMElement && trim($cancelExit->textContent) === 'Cancel',
        'A signed management form has a non-mutating Cancel exit to the public overview');
    $firstTime = (new DOMXPath($dayDocument))->query('//input[@type="radio"][@name="start"]')->item(0);
    $token = $client->token($client->request($reschedule . '&start=' . urlencode($firstTime instanceof DOMElement ? $firstTime->getAttribute('value') : '')));
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
    $html = $fixture->apply($client, $reschedule, ['start' => $day . ' 11:00:00', 'month' => substr($day, 0, 7), 'timezone' => 'Europe/Zurich']);
    $check($client->landedAt($html, 'appointment-result') && !str_contains($html, 'appointment-notices'),
        'A completed reschedule lands at #appointment-result with a focusable status region the screen reader announces');
    $moved = $fixture->row((int) $booking['id']);
    $check($moved && $moved['uid'] === $booking['uid'] && (int) $moved['sequence'] === 1 && $moved['start_utc'] === $day . ' 11:00:00'
        && $moved['invitee_timezone'] === 'Europe/Zurich', 'Reschedule updates the same UID/id, sequence and displayed zone');
    $check((int) $fixture->db->query("SELECT COUNT(*) AS n FROM {$fixture->prefix}appointments_bookings WHERE uid='" . $booking['uid'] . "'")->fetch_assoc()['n'] === 1,
        'Exactly one row remains for the calendar UID');
    // The receipt gives this browser the new signed links, like the confirmation of a booking:
    // the earlier links no longer apply, and without the mail the appointment stays manageable.
    $receipt = new DOMXPath((static function (string $html): DOMDocument {
        $document = new DOMDocument();
        @$document->loadHTML($html);

        return $document;
    })($html));
    $receiptLink = static function (string $class) use ($receipt): string {
        $node = $receipt->query('//*[@id="appointment-result"]//a[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]')->item(0);

        return $node instanceof DOMElement ? html_entity_decode($node->getAttribute('href'), ENT_QUOTES | ENT_HTML5) : '';
    };
    $newSignature = static function (string $url): string {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return (string) ($query['signature'] ?? '');
    };
    $check($newSignature($receiptLink('com-appointments-reschedule')) === $newSignature($fixture->link($moved, 'reschedule'))
        && $newSignature($receiptLink('com-appointments-cancel')) === $newSignature($fixture->link($moved, 'cancel'))
        && $newSignature($fixture->link($moved, 'cancel')) !== $newSignature($cancel),
        'The receipt of a reschedule shows the new signed reschedule and cancel links of the moved appointment');
    $reloaded = $client->request($reschedule);
    $check($client->status === 200 && str_contains($reloaded, $newSignature($fixture->link($moved, 'cancel'))),
        'The receipt keeps the new links on reload in the browser that made the change');

    // The mails of the reschedule above, before the next fixture books and reschedules again.
    $messages = AppointmentsMail::messagesSince($before);

    // A completed receipt bypasses the signature check, so its links depend on the version guard:
    // after a later change by someone else, a cancellation, an anonymisation or a deletion, a
    // reload of the same receipt in the same browser shows neither link (another event type and
    // day, so the bookings above stay untouched).
    $laterDay = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+5 days')->format('Y-m-d');
    $guarded = $fixture->booking($fixture->event(), $laterDay . ' 10:00:00');
    $guardedLink = $fixture->link($guarded['booking'], 'reschedule');
    $guardedId = (int) $guarded['booking']['id'];
    $receiptLinks = static function (string $html): int {
        $document = new DOMDocument();
        @$document->loadHTML($html);

        return (new DOMXPath($document))->query('//*[@id="appointment-result"]//a[contains(concat(" ", normalize-space(@class), " "), " com-appointments-reschedule ")'
            . ' or contains(concat(" ", normalize-space(@class), " "), " com-appointments-cancel ")]')->length;
    };
    $html = $fixture->apply($guarded['browser'], $guardedLink, ['start' => $laterDay . ' 11:00:00', 'month' => substr($laterDay, 0, 7), 'timezone' => 'UTC']);
    $check($guarded['browser']->landedAt($html, 'appointment-result') && $receiptLinks($html) === 2,
        'Fixture: the receipt of a second reschedule shows both new links');
    $version = (string) $fixture->stored($guardedId)['modified_utc'];
    $bookings = $fixture->prefix . 'appointments_bookings';
    $escapedVersion = $fixture->db->real_escape_string($version);
    foreach ([
        'a later change by someone else' => "modified_utc=DATE_ADD(modified_utc, INTERVAL 1 SECOND)",
        'a cancellation' => "state=2",
        'an anonymisation' => "anonymised_utc=UTC_TIMESTAMP()",
    ] as $label => $change) {
        $fixture->db->query("UPDATE {$bookings} SET {$change} WHERE id={$guardedId}");
        $html = $guarded['browser']->request($guardedLink);
        $check($guarded['browser']->status === 200 && str_contains($html, 'appointment-result') && $receiptLinks($html) === 0,
            'The receipt shows no links after ' . $label);
        // Back to the version this browser made, so the next case tests its own guard alone.
        $fixture->db->query("UPDATE {$bookings} SET modified_utc='{$escapedVersion}', state=1, anonymised_utc=NULL WHERE id={$guardedId}");
    }
    $html = $guarded['browser']->request($guardedLink);
    $check($receiptLinks($html) === 2, 'Fixture: restored to its own version, the receipt shows the links again');
    // The audit rows stay: they may point at a booking that no longer exists, as after an erasure.
    $fixture->db->query("DELETE FROM {$bookings} WHERE id={$guardedId}");
    $html = $guarded['browser']->request($guardedLink);
    $check($guarded['browser']->status === 200 && str_contains($html, 'appointment-result') && $receiptLinks($html) === 0,
        'The receipt shows no links after the booking was deleted');
    // Test clean-up: the fixture removes audit rows only for bookings that still exist.
    $fixture->db->query("DELETE FROM {$fixture->prefix}appointments_audit WHERE booking_id={$guardedId}");
    $check(count($messages) === 3, 'Reschedule sends actual mail to invitee, guest and host');
    foreach ($messages as $mime) {
        $decoded = AppointmentsMail::decoded($mime);
        $method = AppointmentsMail::expectedMethod($mime, 'REQUEST');
        $check(AppointmentsMail::isInvitation($mime, $method)
            && str_contains($decoded, 'UID:' . $booking['uid']) && str_contains($decoded, "SEQUENCE:1\r\n")
            && str_contains($decoded, 'METHOD:' . $method . "\r\n"), 'Every reschedule recipient receives one invitation with the updated original UID');
    }
    $outsider = new AppointmentsHttp('http://localhost', 'lifecycle-used-link');
    $html = $outsider->request($reschedule);
    $check($outsider->status === 404, 'Used reschedule signature is rejected');
    // Audit 18.11.2026, B7: the link of the mail before the reschedule opens a page that explains
    // what happened and offers a new booking, not a bare error page. Still 404, still nothing of the
    // appointment: no title, date, address or form.
    $invalidPage = static function (string $html): array {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $page = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " com-appointments-invalid-link ")]')->item(0);

        return [
            'text' => $page instanceof DOMElement ? trim((string) preg_replace('/\s+/', ' ', $page->textContent)) : '',
            'again' => $xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " com-appointments-book-again ")]/@href')->item(0)?->nodeValue ?? '',
            'heading' => trim((string) $xpath->query('//h1')->item(0)?->textContent),
        ];
    };
    $stale = $invalidPage($html);
    $eventTitle = (string) $fixture->db->query("SELECT title FROM {$fixture->prefix}appointments_eventtypes WHERE id={$event}")->fetch_row()[0];
    $check(str_contains($stale['text'], 'This link is not valid.') && str_contains($stale['text'], 'most recent email')
        && $stale['heading'] === 'Link no longer valid' && $stale['again'] !== ''
        && !str_contains($html, $eventTitle) && !str_contains($html, 'invitee@example.test') && !str_contains($html, 'com-appointments-summary')
        && !str_contains($html, 'booking.manage') && !str_contains($html, 'name="start"'),
        'The link of the mail before a reschedule explains that it no longer applies and offers a new booking, without the appointment');
    $html = $outsider->request($cancel);
    $check($outsider->status === 404 && $invalidPage($html)['text'] === $stale['text'], 'Pre-reschedule cancel link is also invalidated, with the same page');
    $unknownPage = $invalidPage($outsider->request($fixture->link(['id' => 2147483001, 'modified_utc' => $moved['modified_utc']], 'reschedule')));
    $check($outsider->status === 404 && $unknownPage === $stale,
        'An unknown appointment gets exactly the same page as an outdated link, so the page reveals nothing');
    // A form left open in another tab before the reschedule: submitting it changes nothing and lands
    // on the same page.
    $staleToken = $outsider->token($outsider->request($fixture->link($moved, 'reschedule')));
    parse_str((string) parse_url($reschedule, PHP_URL_QUERY), $staleQuery);
    $html = $outsider->request('/index.php?option=com_appointments&task=booking.manage', array_replace($staleQuery, [
        $staleToken => 1, 'confirm' => 1, 'start' => $day . ' 15:00:00', 'month' => substr($day, 0, 7), 'timezone' => 'UTC',
    ]));
    $check($outsider->status === 404 && $invalidPage($html)['text'] === $stale['text']
        && $fixture->row((int) $booking['id'])['start_utc'] === $day . ' 11:00:00',
        'The form of an outdated link changes nothing and shows the page of the outdated link');

    $fresh = $fixture->link($moved, 'reschedule');
    $form = $client->request($fresh);
    $calendarDocument = new DOMDocument();
    @$calendarDocument->loadHTML($form);
    $calendarXpath = new DOMXPath($calendarDocument);
    $slotLink = '';
    foreach ($calendarXpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " com-appointments-day-available ")]') as $dayLink) {
        if (!$dayLink instanceof DOMElement) {
            continue;
        }

        $href = html_entity_decode($dayLink->getAttribute('href'), ENT_QUOTES | ENT_HTML5);
        parse_str((string) parse_url($href, PHP_URL_QUERY), $dayQuery);

        if (($dayQuery['day'] ?? '') === $day) {
            $slotLink = $href;
            break;
        }
    }
    $slotForm = $slotLink !== '' ? $client->request($slotLink) : '';
    $check($client->status === 200 && str_contains($slotForm, 'value="' . $day . ' 12:00:00"'),
        'Reschedule calendar offers the slot before the competing booking after choosing its day');
    $slotBase = preg_replace('/#.*$/', '', $slotLink);
    $confirmPage = $slotLink !== '' ? $client->request($slotBase . '&start=' . urlencode($day . ' 12:00:00')) : '';
    $token = $client->token($confirmPage);
    $check($client->status === 200 && str_contains($confirmPage, 'id="manage-confirm"') && $token !== ''
        && str_contains($confirmPage, 'name="start" value="' . $day . ' 12:00:00"'),
        'Choosing a time leads to the confirmation step of the reschedule form');
    $check(preg_match('#<title>\s*Reschedule your appointment\s*</title>#', $form) === 1,
        'The manage page sets its own title without a menu item of its own');
    // Release test 22.10.: the manage pages show the appointment in the invitee's zone and
    // language like the booking page, never as raw UTC timestamps.
    $zurich = new DateTimeZone('Europe/Zurich');
    $localStart = (new DateTimeImmutable($moved['start_utc'], new DateTimeZone('UTC')))->setTimezone($zurich);
    $localEnd = (new DateTimeImmutable($moved['end_utc'], new DateTimeZone('UTC')))->setTimezone($zurich);
    $check(str_contains($confirmPage, $localStart->format('l, j F Y'))
        && str_contains($confirmPage, $localStart->format('H:i') . ' – ' . $localEnd->format('H:i'))
        && str_contains($form, 'All times are shown in the Europe/Zurich time zone.')
        && !str_contains($confirmPage, $moved['start_utc'] . ' UTC'),
        'Manage page shows the appointment date and times in the invitee time zone, not in UTC');
    $slotOption = (new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC')))->setTimezone($zurich);
    $slotDocument = new DOMDocument();
    @$slotDocument->loadHTML($slotForm);
    $slotXpath = new DOMXPath($slotDocument);
    $legend = $slotXpath->query('//legend[contains(concat(" ", normalize-space(@class), " "), " com-appointments-slots-legend ")]')->item(0);
    $slotInput = $slotXpath->query('//input[@type="radio"][@name="start"][@value="' . $day . ' 12:00:00"]')->item(0);
    $slotLabel = $slotInput instanceof DOMElement
        ? $slotXpath->query('//label[@for="' . $slotInput->getAttribute('id') . '"]')->item(0)
        : null;
    $check($legend instanceof DOMElement && str_contains($legend->textContent, $slotOption->format('l, j F Y'))
        && $slotLabel instanceof DOMElement && str_contains($slotLabel->textContent, $slotOption->format('H:i')),
        'Reschedule options name the weekday, date and time in the selected zone');
    $check(str_contains($form, 'data-appointments-booking') && str_contains($form, 'id="appointment-time-form"')
        && str_contains($form, 'com-appointments-invitee-email') && str_contains($form, htmlspecialchars((string) $fixture->row((int) $booking['id'])['invitee_email'], ENT_QUOTES)),
        'The reschedule form uses the booking layout and adds the event type title and the invitee e-mail');
    // Night test 23.10.: rescheduling without the confirmation checkbox shows the message
    // at the checkbox, also after the visitor reloads the page, and changes nothing.
    parse_str(parse_url($fresh, PHP_URL_QUERY), $rescheduleQuery);
    $refused = $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($rescheduleQuery, [
        $token => 1, 'start' => $day . ' 12:00:00', 'month' => substr($day, 0, 7), 'day' => $day, 'timezone' => 'Europe/Zurich',
    ]));
    $confirmError = static function (string $html): bool {
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $confirm = $xpath->query('//input[@id="manage-confirm"]')->item(0);
        $message = $xpath->query('//*[@id="manage-confirm-error"]')->item(0);

        return $confirm instanceof DOMElement && $confirm->getAttribute('aria-invalid') === 'true'
            && $confirm->getAttribute('aria-describedby') === 'manage-confirm-error'
            && $message !== null && trim($message->textContent) === 'Please confirm the change with the checkbox.';
    };
    $check($confirmError($refused) && $fixture->row((int) $booking['id'])['start_utc'] === $day . ' 11:00:00',
        'Rescheduling without the confirmation checkbox shows the message at the checkbox and changes nothing');
    $check($client->landedAt($refused, null) && preg_match('#<input[^>]*id="manage-confirm"[^>]*autofocus#', $refused) === 1
        && !str_contains($refused, 'com_appointments.focusSystemMessage'),
        'A refused reschedule has no result fragment or region and the invalid checkbox keeps the focus');
    $reloadedReschedule = $client->request($fresh . '&month=' . substr($day, 0, 7) . '&day=' . $day . '&timezone=Europe%2FZurich&start=' . urlencode($day . ' 12:00:00'));
    $check($confirmError($reloadedReschedule), 'The confirmation message is still at the checkbox after a reload');
    foreach (['', substr($day, 0, 7)] as $invalidZoneMonth) {
        $refused = $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($rescheduleQuery, [
            $token => 1, 'confirm' => 1, 'start' => $day . ' 12:00:00', 'day' => $day,
            'month' => $invalidZoneMonth, 'timezone' => 'not-a-zone',
        ]));
        $document = new DOMDocument();
        @$document->loadHTML($refused);
        $xpath = new DOMXPath($document);
        $locations = $client->locations;
        $location = $locations === [] ? '' : $locations[array_key_last($locations)];
        $check($client->status === 200 && $client->landedAt($refused, null)
            && str_contains($refused, 'com_appointments.focusSystemMessage')
            && preg_match('#/media/com_appointments/js/booking(\.min)?\.js#', $refused) === 1
            && $xpath->query('//*[@id="system-message-container"][@aria-live="polite"]')->length === 1
            && str_contains((string) $xpath->query('//*[@id="system-message-container"]')->item(0)?->textContent, 'Choose a valid time zone.')
            && $xpath->query('//*[@aria-invalid="true"]')->length === 0
            && $location !== '' && !str_contains($location, '#') && !str_contains($location, 'not-a-zone')
            && $fixture->row((int) $booking['id'])['start_utc'] === $day . ' 11:00:00',
            'An invalid manage timezone returns a usable form with a one-shot native-message focus request, month=' . $invalidZoneMonth);
        $reload = $client->request($fresh);
        $check(!str_contains($reload, 'com_appointments.focusSystemMessage'),
            'The manage refusal focus option is consumed');
    }
    $otherEvent = $fixture->event();
    $fixture->booking($otherEvent, $day . ' 12:00:00');
    parse_str(parse_url($fresh, PHP_URL_QUERY), $query);
    $html = $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($query, [
        $token => 1, 'confirm' => 1, 'start' => $day . ' 12:00:00', 'month' => substr($day, 0, 7), 'timezone' => 'UTC',
    ]));
    $check(str_contains($html, 'This time was just taken')
        && str_contains($html, 'com_appointments.focusSystemMessage')
        && $fixture->row((int) $booking['id'])['start_utc'] === $day . ' 11:00:00', 'Overlapping cross-event reschedule is refused without changing the appointment');
    $freshCancel = $fixture->link($moved, 'cancel');
    // Release test 22.10.: without the confirmation checkbox there is a real error message
    // at the checkbox, and nothing changes.
    $page = $client->request($freshCancel);
    parse_str(parse_url($freshCancel, PHP_URL_QUERY), $cancelQuery);
    $html = $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($cancelQuery, [
        $client->token($page) => 1, 'reason' => 'Kept reason',
    ]));
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $confirm = $xpath->query('//input[@id="manage-confirm"]')->item(0);
    $message = $xpath->query('//*[@id="manage-confirm-error"]')->item(0);
    $check($confirm instanceof DOMElement && $confirm->getAttribute('aria-invalid') === 'true'
        && $confirm->getAttribute('aria-describedby') === 'manage-confirm-error'
        && $message !== null && trim($message->textContent) === 'Please confirm the change with the checkbox.'
        && (int) $fixture->row((int) $booking['id'])['state'] === 1,
        'A change without the confirmation checkbox gets a real error message at the checkbox and changes nothing');
    $reason = $xpath->query('//textarea[@id="cancel-reason"]')->item(0);
    $check($reason !== null && trim($reason->textContent) === 'Kept reason', 'The entered cancellation reason is kept');
    // A failed notification is a partial success: its warning is part of the announced result,
    // not a system message above the component, where the landing would leave it out of view.
    // Another browser opens the same cancellation form before the appointment is cancelled and keeps
    // it open (its token is replayed below, after the cancellation).
    $staleToken = $outsider->token($outsider->request($freshCancel));
    $hostEmail = $fixture->db->query("SELECT email FROM {$fixture->prefix}users WHERE id={$fixture->host}")->fetch_assoc()['email'];
    $fixture->db->query("UPDATE {$fixture->prefix}users SET email='not-an-address' WHERE id={$fixture->host}");
    try {
        $html = $fixture->apply($client, $freshCancel, ['reason' => 'Fixture reason']);
    } finally {
        $fixture->db->query("UPDATE {$fixture->prefix}users SET email='" . $fixture->db->real_escape_string($hostEmail) . "' WHERE id={$fixture->host}");
    }
    $check($client->landedAt($html, 'appointment-result'),
        'A completed cancellation lands at #appointment-result with a focusable status region the screen reader announces');
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $warning = 'Your change was saved, but one or more notification emails could not be delivered.';
    $check($xpath->query('//p[@data-appointments-result][@aria-describedby="appointment-notices"]')->length === 1
        && str_contains((string) $xpath->query('//*[@id="appointment-result"]//*[@id="appointment-notices"]')->item(0)?->textContent, $warning)
        && substr_count($html, $warning) === 1 && !str_contains($html, 'com_appointments.focusSystemMessage'),
        'A failed notification is shown once, inside the result, as the description of the focused status');
    $cancelled = $fixture->row((int) $booking['id']);
    $check(str_contains($html, 'Your appointment has been cancelled') && (int) $cancelled['state'] === 2
        && (int) $cancelled['sequence'] === 2 && $cancelled['uid'] === $booking['uid'] && $cancelled['cancel_reason'] === 'Fixture reason',
        'Signed cancellation stores the optional reason and updates the stable calendar entry');
    // Live finding 16.11.2026: the receipt names the cancelled appointment in the invitee's zone. It
    // promises the confirmation mail only when the mails went out (here the host mail failed).
    $zurich = new DateTimeZone('Europe/Zurich');
    $cancelledStart = (new DateTimeImmutable($cancelled['start_utc'], new DateTimeZone('UTC')))->setTimezone($zurich);
    $cancelledEnd = (new DateTimeImmutable($cancelled['end_utc'], new DateTimeZone('UTC')))->setTimezone($zurich);
    $cancelledWhen = static fn (string $html): bool => str_contains($html, $cancelledStart->format('l, j F Y'))
        && str_contains($html, $cancelledStart->format('H:i') . ' – ' . $cancelledEnd->format('H:i'))
        && str_contains($html, '<dd>Europe/Zurich</dd>');
    $check($cancelledWhen($html) && preg_match('#<h1[^>]*>\s*Appointment cancelled\s*</h1>#', $html) === 1
        && !str_contains($html, 'You will receive a confirmation by email.'),
        'The cancellation receipt names the appointment with date, time and zone, without promising a mail that failed');
    $check(str_contains($client->request($freshCancel), 'Your appointment has been cancelled'), 'Repeated same-browser completion is an idempotent receipt');
    // Another browser with the same link: the appointment is cancelled, with its date and time,
    // and no form. The reschedule link of the same version says the same.
    $html = $outsider->request($freshCancel);
    $check($outsider->status === 200 && str_contains($html, 'This appointment has already been cancelled.') && $cancelledWhen($html)
        && !str_contains($html, 'booking.manage') && !str_contains($html, 'manage-confirm'),
        'The used cancellation link reports the cancelled appointment in another browser, without a form');
    $html = $outsider->request($fixture->link($moved, 'reschedule'));
    $check($outsider->status === 200 && str_contains($html, 'This appointment has already been cancelled.')
        && !str_contains($html, 'new-start') && !str_contains($html, 'booking.manage'),
        'The reschedule link of the cancelled version reports the cancellation instead of offering times');
    // A form still open in another tab (opened before the cancellation, its token kept) changes
    // nothing and lands on the same notice.
    parse_str((string) parse_url($freshCancel, PHP_URL_QUERY), $staleQuery);
    $html = $outsider->request('/index.php?option=com_appointments&task=booking.manage', array_replace($staleQuery, [
        $staleToken => 1, 'confirm' => 1,
    ]));
    $check($outsider->status === 200 && str_contains($html, 'This appointment has already been cancelled.')
        && (int) $fixture->row((int) $booking['id'])['sequence'] === 2,
        'Submitting a cancellation for a cancelled appointment changes nothing and shows the notice');
    // Older links, the erase link of the cancelled version and tampered or unknown links stay one
    // indistinguishable refusal: 404, a message for the person and nothing technical.
    $erasePrevious = $fixture->link($moved, 'erase');
    $tampered = preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $freshCancel);
    $unknown = $fixture->link(['id' => 2147483000, 'modified_utc' => $moved['modified_utc']], 'cancel');
    foreach (['an older cancel link' => $cancel, 'the erase link of the cancelled version' => $erasePrevious,
        'a tampered link' => $tampered, 'an unknown appointment' => $unknown] as $label => $link) {
        $html = $outsider->request($link);
        $check($outsider->status === 404 && str_contains($html, 'This link is not valid.')
            && !str_contains($html, 'already been cancelled') && !str_contains($html, '/var/www') && stripos($html, 'stack trace') === false,
            'The refusal of ' . $label . ' is a friendly 404 without technical details');
    }
    // Privacy (review finding): once a booking is erased, an earlier receipt of the same browser
    // shows only the generic outcome, nothing of the appointment or the person: neither date, time,
    // zone nor event title. Self-service erasure with the link of the cancellation mail here, the
    // administrator's erasure of a rescheduled booking below.
    $eventTitle = (string) $fixture->db->query("SELECT title FROM {$fixture->prefix}appointments_eventtypes WHERE id={$event}")->fetch_row()[0];
    $receiptLeaks = static fn (string $html, string $title, DateTimeImmutable $start): array => array_keys(array_filter([
        'date' => str_contains($html, $start->format('l, j F Y')),
        'time' => str_contains($html, $start->format('H:i') . ' – '),
        'zone' => str_contains($html, 'Europe/Zurich'),
        'title' => str_contains($html, $title),
        'summary' => str_contains($html, 'com-appointments-summary'),
    ]));
    $html = $fixture->apply($client, $fixture->link($fixture->row((int) $booking['id']), 'erase'));
    $check($fixture->row((int) $booking['id'])['anonymised_utc'] !== null, 'Fixture: the cancelled booking is erased through its signed link');
    $html = $client->request($freshCancel);
    $leaks = $receiptLeaks($html, $eventTitle, $cancelledStart);
    $check($client->status === 200 && str_contains($html, 'Your appointment has been cancelled') && $leaks === [],
        'After a self-service erasure the earlier cancellation receipt shows only the generic outcome: ' . implode(', ', $leaks));
    $adminEvent = $fixture->event();
    $adminTitle = (string) $fixture->db->query("SELECT title FROM {$fixture->prefix}appointments_eventtypes WHERE id={$adminEvent}")->fetch_row()[0];
    $adminCase = $fixture->booking($adminEvent, $laterDay . ' 13:00:00');
    $adminLink = $fixture->link($adminCase['booking'], 'reschedule');
    $html = $fixture->apply($adminCase['browser'], $adminLink, ['start' => $laterDay . ' 14:00:00', 'month' => substr($laterDay, 0, 7), 'timezone' => 'Europe/Zurich']);
    $movedStart = (new DateTimeImmutable($laterDay . ' 14:00:00', new DateTimeZone('UTC')))->setTimezone($zurich);
    $check($receiptLeaks($html, $adminTitle, $movedStart) !== [], 'Fixture: the reschedule receipt names the appointment before the erasure');
    $operator = new AppointmentsHttp('http://localhost', 'lifecycle-admin');
    $operator->login();
    $operator->request('/administrator/index.php?option=com_appointments&task=bookings.erase', [
        'cid' => [(int) $adminCase['booking']['id']], $operator->token($operator->request('/administrator/index.php?option=com_appointments&view=bookings')) => 1,
    ]);
    $check($fixture->row((int) $adminCase['booking']['id'])['anonymised_utc'] !== null, 'Fixture: the administrator erases the rescheduled booking');
    $html = $adminCase['browser']->request($adminLink);
    $leaks = $receiptLeaks($html, $adminTitle, $movedStart);
    $check($adminCase['browser']->status === 200 && str_contains($html, 'Your appointment has been rescheduled') && $leaks === [],
        'After an administrator erasure the earlier reschedule receipt shows only the generic outcome: ' . implode(', ', $leaks));
    $replacement = $fixture->booking($event, $day . ' 11:00:00')['booking'];
    $check((int) $replacement['id'] !== (int) $booking['id'], 'Cancellation releases the exact slot');
    // Both an older override and a copy of the current result layout keep queued notices.
    $siteTemplate = (string) $fixture->db->query("SELECT template FROM {$fixture->prefix}template_styles WHERE client_id=0 AND home='1'")->fetch_row()[0];
    $overrideDir = '/var/www/html/templates/' . $siteTemplate . '/html/com_appointments/manage';
    foreach (['legacy', 'result'] as $override) {
        if ($override === 'result') {
            $replacement = $fixture->booking($event, $day . ' 11:00:00')['booking'];
        }
        $overrideCancel = $fixture->link($replacement, 'cancel');
        $form = $client->request($overrideCancel);
        $token = $client->token($form);
        parse_str((string) parse_url($overrideCancel, PHP_URL_QUERY), $query);
        $createdDir = !is_dir($overrideDir) && mkdir($overrideDir, 0755, true);
        if ($override === 'result') {
            copy('/var/www/html/components/com_appointments/tmpl/manage/default.php', $overrideDir . '/default.php');
        } else {
            file_put_contents($overrideDir . '/default.php', "<?php defined('_JEXEC') or die; ?>\n<div id=\"appointment-result\"><p>Override without notices</p></div>\n");
        }
        $fixture->db->query("UPDATE {$fixture->prefix}users SET email='not-an-address' WHERE id={$fixture->host}");
        try {
            $html = $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($query, [$token => 1, 'confirm' => 1]));
        } finally {
            $fixture->db->query("UPDATE {$fixture->prefix}users SET email='" . $fixture->db->real_escape_string($hostEmail) . "' WHERE id={$fixture->host}");
            @unlink($overrideDir . '/default.php');
            if ($createdDir) {
                @rmdir($overrideDir);
                @rmdir(dirname($overrideDir));
            }
        }
        // Joomla prints a queued message twice: in its script options and noscript fallback.
        // The warning must appear exactly there and nowhere else.
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $xpath = new DOMXPath($document);
        $system = substr_count((string) $xpath->query('//*[@id="system-message-container"]')->item(0)?->textContent, $warning);
        $options = 0;
        foreach ($xpath->query('//script[contains(concat(" ", normalize-space(@class), " "), " joomla-script-options ")]') as $script) {
            $options += substr_count($script->textContent, $warning);
        }
        $check(!str_contains($html, 'appointment-notices')
            && ($override === 'result' ? $client->landedAt($html, 'appointment-result') : str_contains($html, 'Override without notices'))
            && $system === 1 && $options === 1 && substr_count($html, $warning) === $system + $options
            && str_contains($html, 'com_appointments.focusSystemMessage')
            && (int) $fixture->row((int) $replacement['id'])['state'] === 2,
            'A failed notification remains a requested Joomla message with a ' . $override . ' template override');
    }

    // Audit 18.11.2026, B6 and B8: the booking person cancels with a reason. The host's mail names
    // the reason; the booking person's mail offers only the erase link (no reschedule or cancel link
    // for an appointment that no longer exists); the guest learns neither. Every mail still carries
    // the calendar cancellation of the same entry.
    $reasonEvent = $fixture->event();
    $reasonDay = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+6 days')->format('Y-m-d');
    $reasonCase = $fixture->booking($reasonEvent, $reasonDay . ' 10:00:00', ['guests' => 'guest@example.test']);
    $reasonText = 'Private reason: Ä, comma; semicolon';
    $before = AppointmentsMail::snapshot();
    $fixture->apply($reasonCase['browser'], $fixture->link($reasonCase['booking'], 'cancel'), ['reason' => $reasonText]);
    $cancelledRow = $fixture->row((int) $reasonCase['booking']['id']);
    $check((int) $cancelledRow['state'] === 2 && $cancelledRow['cancel_reason'] === $reasonText, 'Fixture: the booking person cancels with a reason');
    $hostAddress = strtolower((string) $fixture->db->query("SELECT email FROM {$fixture->prefix}users WHERE id={$fixture->host}")->fetch_row()[0]);
    $byRecipient = [];
    foreach (AppointmentsMail::messagesSince($before) as $mime) {
        preg_match('/^To:.*?([a-z0-9.+-]+@[a-z0-9.-]+)/mi', (string) preg_replace('/\r?\n[ \t]+/', ' ', $mime), $to);
        $byRecipient[strtolower($to[1] ?? '')] = $mime;
    }
    $text = static fn (string $mime): string => str_replace("\r\n", "\n", implode("\n", AppointmentsMail::parts($mime, 'text/plain')));
    $hostMail = $byRecipient[$hostAddress] ?? '';
    $inviteeMail = $byRecipient['invitee@example.test'] ?? '';
    $guestMail = $byRecipient['guest@example.test'] ?? '';
    $check(count($byRecipient) === 3 && str_contains($text($hostMail), 'Reason for cancellation: ' . $reasonText . "\n")
        && AppointmentsMail::isInvitation($hostMail, AppointmentsMail::expectedMethod($hostMail, 'CANCEL')),
        'The host cancellation mail names the reason the booking person gave');
    $inviteeText = $text($inviteeMail);
    $check(str_contains($inviteeText, 'action=erase') && !str_contains($inviteeText, 'action=reschedule') && !str_contains($inviteeText, 'action=cancel')
        && !str_contains(AppointmentsMail::decoded($inviteeMail), 'action=reschedule') && AppointmentsMail::isInvitation($inviteeMail, 'CANCEL'),
        'The cancellation mail of the booking person offers only the erase link and cancels the calendar entry');
    $check($guestMail !== '' && !str_contains(AppointmentsMail::decoded($guestMail), 'Private reason')
        && !str_contains(AppointmentsMail::decoded($inviteeMail), 'Private reason'),
        'Neither the guest nor the booking person receives the reason in the mail');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
