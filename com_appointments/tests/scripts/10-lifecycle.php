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
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $cancelExit = (new DOMXPath($document))->query(
        '//div[contains(concat(" ", normalize-space(@class), " "), " com-appointments-manage ")]//a'
    )->item(0);
    $check($cancelExit instanceof DOMElement && trim($cancelExit->textContent) === 'Cancel',
        'A signed management form has a non-mutating Cancel exit to the public overview');
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
    $check(preg_match('#<title>\s*Reschedule your appointment\s*</title>#', $form) === 1,
        'The manage page sets its own title without a menu item of its own');
    // Release test 22.10.: the manage pages show the appointment in the invitee's zone and
    // language like the booking page, never as raw UTC timestamps.
    $zurich = new DateTimeZone('Europe/Zurich');
    $localStart = (new DateTimeImmutable($moved['start_utc'], new DateTimeZone('UTC')))->setTimezone($zurich);
    $localEnd = (new DateTimeImmutable($moved['end_utc'], new DateTimeZone('UTC')))->setTimezone($zurich);
    $check(str_contains($form, $localStart->format('l, j F Y'))
        && str_contains($form, $localStart->format('H:i') . ' – ' . $localEnd->format('H:i'))
        && str_contains($form, 'All times are shown in the Europe/Zurich time zone.')
        && !str_contains($form, $moved['start_utc'] . ' UTC'),
        'Manage page shows the appointment date and times in the invitee time zone, not in UTC');
    $slotOption = (new DateTimeImmutable($day . ' 12:00:00', new DateTimeZone('UTC')))->setTimezone($zurich);
    $check(str_contains($form, '>' . $slotOption->format('l, j F Y') . ', ' . $slotOption->format('H:i') . '<'),
        'Reschedule options name the weekday, date and time in the selected zone');
    // Night test 23.10.: rescheduling without the confirmation checkbox shows the message
    // at the checkbox, also after the visitor reloads the page, and changes nothing.
    parse_str(parse_url($fresh, PHP_URL_QUERY), $rescheduleQuery);
    $refused = $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($rescheduleQuery, [
        $token => 1, 'start' => $day . ' 12:00:00', 'month' => substr($day, 0, 7), 'timezone' => 'Europe/Zurich',
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
        && !str_contains($refused, 'com_appointments.focusSystemMessage')
        && preg_match('#/media/com_appointments/js/booking(\.min)?\.js#', $refused) !== 1,
        'A refused reschedule has no result fragment or region and the invalid checkbox keeps the focus');
    $reloadedReschedule = $client->request($fresh . '&month=' . substr($day, 0, 7) . '&timezone=Europe%2FZurich');
    $check($confirmError($reloadedReschedule), 'The confirmation message is still at the checkbox after a reload');
    foreach (['', substr($day, 0, 7)] as $invalidZoneMonth) {
        $refused = $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($rescheduleQuery, [
            $token => 1, 'confirm' => 1, 'start' => $day . ' 12:00:00',
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
        $check(!str_contains($reload, 'com_appointments.focusSystemMessage')
            && preg_match('#/media/com_appointments/js/booking(\.min)?\.js#', $reload) !== 1,
            'The manage refusal focus option is consumed and an ordinary form remains script-free');
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
    $check(str_contains($client->request($freshCancel), 'Your appointment has been cancelled'), 'Repeated same-browser completion is an idempotent receipt');
    $outsider->request($freshCancel);
    $check($outsider->status === 404, 'Used cancellation signature is rejected in another browser');
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
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
