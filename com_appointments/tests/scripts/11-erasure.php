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
        'attribution' => '{"version":1,"landing_url":"/CANARY-ORIGIN","booking_ip":"203.0.113.9","user_agent":"CANARY-AGENT"}',
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
    $check($client->landedAt($html, 'appointment-result'),
        'A completed erasure lands at #appointment-result with a focusable status region the screen reader announces');
    $erased = $fixture->row($id);
    $check(str_contains($html, 'Your data has been erased.') && $erased['anonymised_utc'] !== null && (int) $erased['state'] === 2,
        'Future booking is cancelled and anonymised in the self-service operation');
    // Release test 16.11.: the receipt confirms this erasure, it does not report an earlier one.
    $status = preg_match('/<[^>]*\bdata-appointments-result\b[^>]*>([^<]*)</', $html, $match) ? trim($match[1]) : '';
    $check($status === 'Your data has been erased. No further action is needed.',
        'The receipt of the erasure reads as the confirmation of this erasure: ' . $status);
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
        $check(str_contains($decoded, 'METHOD:' . AppointmentsMail::expectedMethod($mime, 'CANCEL')) && str_contains($decoded, 'STATUS:CANCELLED')
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
    $check(str_contains($html, 'Your data has been erased.'), 'Deleted-row repeat click remains idempotent without exposing data');

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

    // Administrator deletion removes the row, records the operator and still sends the cancellation mail.
    $created = $fixture->booking($event, $day . ' 14:00:00');
    $deleteId = (int) $created['booking']['id'];
    $erasedIds[] = $deleteId;
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.delete', ['cid' => [$deleteId], $token => 1]);
    $check($fixture->row($deleteId) === null, 'Administrator deletion removes the appointment row');
    $deleteAudit = $db->query("SELECT * FROM {$prefix}appointments_audit WHERE booking_id={$deleteId}")->fetch_assoc();
    $check($deleteAudit && $deleteAudit['path'] === 'admin' && (int) $deleteAudit['actor_user_id'] === $fixture->host,
        'Administrator deletion records only the acting operator identity');

    // An already anonymised booking is deleted too.
    $created = $fixture->booking($event, $day . ' 15:00:00');
    $anonId = (int) $created['booking']['id'];
    $erasedIds[] = $anonId;
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.erase', ['cid' => [$anonId], $token => 1]);
    $anonymised = $fixture->row($anonId);
    $check($anonymised !== null && $anonymised['anonymised_utc'] !== null, 'The administrator erasure anonymises the appointment first');
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $deleteResponse = $admin->request('/administrator/index.php?option=com_appointments&task=bookings.delete', ['cid' => [$anonId], $token => 1]);
    $check($fixture->row($anonId) === null, 'Administrator deletion also removes an already anonymised appointment');
    if ($fixture->row($anonId) !== null) {
        // The text of the error page without its stylesheet, scripts and head.
        $errorPage = new DOMDocument();
        @$errorPage->loadHTML($deleteResponse);
        foreach (['style', 'script', 'head'] as $tagName) {
            while (($node = $errorPage->getElementsByTagName($tagName)->item(0)) !== null) {
                $node->parentNode->removeChild($node);
            }
        }
        echo '    HTTP ' . $admin->status . ': ' . substr(trim(preg_replace('/\s+/', ' ', $errorPage->textContent)), 0, 900) . "\n";
    }
    // A batch with an ID that no longer exists deletes the others and is not refused as a whole.
    $created = $fixture->booking($event, $day . ' 16:00:00');
    $batchId = (int) $created['booking']['id'];
    $erasedIds[] = $batchId;
    $staleId = $batchId + 100000;
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.delete', ['cid' => [$staleId, $batchId], $token => 1]);
    $check($fixture->row($batchId) === null, 'Administrator deletion skips a vanished appointment and still deletes the others');

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
    $check(str_contains($html, 'Your address has been removed from this appointment.') && !str_contains($html, 'could not be updated yet')
        && $after['guest_emails'] === 'keep@example.test',
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

    // A mail delivery holds a shared lock; a guest removal must wait until it is released.
    $config = new JConfig();
    $holder = new mysqli($config->host, $config->user, $config->password, $config->db);
    $observer = new mysqli($config->host, $config->user, $config->password, $config->db);
    $holder->query('START TRANSACTION');
    $heldRow = $holder->query("SELECT guest_emails FROM {$prefix}appointments_bookings WHERE id={$gid} LOCK IN SHARE MODE")->fetch_assoc();
    $keepLink = $fixture->guestLink($after, 'keep@example.test');
    parse_str((string) parse_url($keepLink, PHP_URL_QUERY), $keepQuery);
    $keepToken = $keep->token($keep->request($keepLink));
    $started = microtime(true);
    $waited = false;
    $released = false;
    try {
        AppointmentsHttp::parallel([[$keep, '/index.php?option=com_appointments&task=guest.remove',
            ['id' => $gid, 'signature' => $keepQuery['signature'], $keepToken => 1]]], static function (int $running) use (&$waited, &$released, $holder, $observer, $prefix, $gid, $started, $heldRow): void {
                if (!$released && $running && microtime(true) - $started > 2) {
                    $row = $observer->query("SELECT guest_emails FROM {$prefix}appointments_bookings WHERE id={$gid}")->fetch_assoc();
                    // This is ciphertext: compare stored bytes, not a plaintext address.
                    $waited = $row !== null && $heldRow !== null && $row['guest_emails'] === $heldRow['guest_emails'];
                    $holder->query('COMMIT');
                    $released = true;
                }
            });
    } finally {
        if (!$released) {
            $holder->query('ROLLBACK');
        }
        $holder->close();
        $observer->close();
    }
    $check($waited && $released && $keep->status === 200 && (string) $fixture->row($gid)['guest_emails'] === '',
        'A guest removal waits while a mail delivery holds the guest list and lands right after it');

    // Issue #351: erasure outside a web request. A PHP script that boots Joomla like cli/joomla.php
    // (console application, CLI session, extension namespace map) erases a booking without and one
    // with a conference room, and runs the retention routine of the Scheduler for an old booking
    // with a room. None may throw after the commit; the room is released (the fixed provider has
    // nothing to tear down) or the failure is reported, never thrown.
    // The booking form allows 10 bookings per hour and address; this suite has made 8 by now and books 3 more.
    $db->query("DELETE FROM {$prefix}appointments_ratelimit");
    $consoleEvent = $fixture->event();
    // The host already has 10:00 to 13:00 booked above.
    $plain = $fixture->booking($consoleEvent, $day . ' 14:00:00')['booking'];
    $withRoom = $fixture->booking($consoleEvent, $day . ' 15:00:00')['booking'];
    $old = $fixture->booking($consoleEvent, $day . ' 16:00:00')['booking'];
    $erasedIds = array_merge($erasedIds, [(int) $plain['id'], (int) $withRoom['id'], (int) $old['id']]);
    $room = ['conference_provider' => 'fixed', 'conference_url' => 'https://example.test/console-room', 'conference_room' => 'console-room',
        'conference_state' => 'ok', 'conference_error' => null];
    $fixture->update((int) $withRoom['id'], $room);
    $fixture->update((int) $old['id'], $room);
    $db->query("UPDATE {$prefix}appointments_bookings SET start_utc=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 800 DAY),"
        . " end_utc=DATE_ADD(DATE_SUB(UTC_TIMESTAMP(), INTERVAL 800 DAY), INTERVAL 30 MINUTE) WHERE id=" . (int) $old['id']);
    $script = sys_get_temp_dir() . '/appointments-console-erase-' . bin2hex(random_bytes(4)) . '.php';
    file_put_contents($script, '<?php
        const _JEXEC = 1;
        define("JPATH_BASE", "/var/www/html");
        require_once JPATH_BASE . "/includes/defines.php";
        require_once JPATH_BASE . "/includes/framework.php";
        $container = \Joomla\CMS\Factory::getContainer();
        $container->alias("session", "session.cli")->alias("JSession", "session.cli")
            ->alias(\Joomla\CMS\Session\Session::class, "session.cli")->alias(\Joomla\Session\Session::class, "session.cli")
            ->alias(\Joomla\Session\SessionInterface::class, "session.cli");
        $app = $container->get(\Joomla\Console\Application::class);
        \Joomla\CMS\Factory::$application = $app;
        $app->createExtensionNamespaceMap();
        $db = $container->get(\Joomla\Database\DatabaseInterface::class);
        $now = new DateTimeImmutable("now", new DateTimeZone("UTC"));
        $result = [];
        foreach (["plain" => ' . (int) $plain['id'] . ', "room" => ' . (int) $withRoom['id'] . '] as $label => $id) {
            try {
                $erased = (new \Advans\Component\Appointments\Administrator\Service\BookingStore($db))->erase($id, $now, "admin", true, ' . (int) $fixture->host . ');
                $result[$label] = ["ok" => true, "cleanup_error" => !empty($erased["_conference_cleanup_error"])];
            } catch (\Throwable $e) {
                $result[$label] = ["ok" => false, "error" => get_class($e) . ": " . substr($e->getMessage(), 0, 160)];
            }
        }
        try {
            $retention = (new \Advans\Component\Appointments\Administrator\Service\SchedulerService($db,
                \Joomla\CMS\Component\ComponentHelper::getParams("com_appointments")))->retention($now);
            $result["retention"] = ["ok" => true, "failed" => (int) $retention["failed"]];
        } catch (\Throwable $e) {
            $result["retention"] = ["ok" => false, "error" => get_class($e) . ": " . substr($e->getMessage(), 0, 160)];
        }
        echo "\n@@CONSOLE@@" . json_encode($result);');
    $output = [];
    exec('cd /var/www/html && php ' . escapeshellarg($script) . ' 2>&1', $output);
    @unlink($script);
    $text = implode("\n", $output);
    $at = strrpos($text, '@@CONSOLE@@');
    $console = $at === false ? [] : (array) json_decode(substr($text, $at + 11), true);
    if ($at === false) {
        echo '    console: ' . substr($text, 0, 1200) . "\n";
    }
    $check(($console['plain']['ok'] ?? false) === true && ($console['plain']['cleanup_error'] ?? true) === false,
        'Console erasure of a booking without a conference completes silently: ' . json_encode($console['plain'] ?? $text));
    // With a room: released when the component boots here, otherwise reported as a cleanup failure,
    // because a plugin may have created the room under a native name. Never an exception.
    $check(($console['room']['ok'] ?? false) === true && is_bool($console['room']['cleanup_error'] ?? null),
        'Console erasure of a booking with a conference room completes, the room released or the failure reported: ' . json_encode($console['room'] ?? $text));
    $check($fixture->stored((int) $plain['id']) === null && $fixture->stored((int) $withRoom['id']) === null
        && (int) $db->query("SELECT COUNT(*) FROM {$prefix}appointments_audit WHERE booking_id IN (" . (int) $plain['id'] . ',' . (int) $withRoom['id'] . ") AND path='admin'")->fetch_row()[0] === 2,
        'The console erasures removed both bookings and recorded them once each');
    $retained = $fixture->stored((int) $old['id']);
    $check(($console['retention']['ok'] ?? false) === true
        && ($console['retention']['failed'] ?? -1) === (($console['room']['cleanup_error'] ?? false) ? 1 : 0)
        && ($retained === null || $retained['anonymised_utc'] !== null) && ($retained['conference_room'] ?? null) === null,
        'The Scheduler retention outside a web request erases an old booking with a room and counts an unreleased room as failed: '
        . json_encode($console['retention'] ?? $text));
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
