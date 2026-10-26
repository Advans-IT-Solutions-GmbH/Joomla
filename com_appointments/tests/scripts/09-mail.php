<?php

require __DIR__ . '/appointments-http.php';
require __DIR__ . '/appointments-mail.php';
require '/var/www/html/configuration.php';
$config = new JConfig();
$db = new mysqli($config->host, $config->user, $config->password, $config->db);
$prefix = $config->dbprefix;
$tag = 'suite-mail-' . bin2hex(random_bytes(6));
$event = 0;
$schedule = 0;
$mailsParams = null;
$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$spool = static fn (): array => glob('/tmp/appointments-mail/*.eml') ?: [];
$rejectDelivery = '/tmp/appointments-mail/reject-delivery';
$utc = new DateTimeZone('UTC');
$now = new DateTimeImmutable('now', $utc);
$day = $now->modify('+2 days')->format('Y-m-d');
$admin = new AppointmentsHttp('http://localhost', 'mail-admin');

$inspect = static function (array $before, array $booking, string $method, string $status, int $expected, ?string $localizedSubject = null) use ($spool, $check): void {
    $messages = array_values(array_diff($spool(), $before));
    $check(count($messages) === $expected, 'Actual MIME delivered to invitee, guest and host');
    $localized = false;
    foreach ($messages as $file) {
        $mime = file_get_contents($file);
        $unfolded = preg_replace('/\r?\n[ \t]+/', ' ', $mime);
        $check((bool) preg_match('/Content-Type:\s*text\/calendar;\s*charset=UTF-8;\s*method=' . $method . '/i', $unfolded)
            && str_contains($unfolded, 'Content-Type: application/ics'), 'Both required calendar MIME parts attached');
        preg_match('/Content-Type:\s*text\/calendar;.*?\r?\n\r?\n(.*?)(?=\r?\n--)/s', $mime, $part);
        $ics = isset($part[1]) ? base64_decode(preg_replace('/\s+/', '', $part[1]), true) : false;
        $check(is_string($ics) && str_contains($ics, 'METHOD:' . $method . "\r\n")
            && str_contains($ics, 'STATUS:' . $status . "\r\n")
            && str_contains($ics, 'UID:' . $booking['uid'] . "\r\n")
            && str_contains($ics, 'SEQUENCE:' . $booking['sequence'] . "\r\n"), 'Actual calendar carries stable UID, state and sequence');
        $decoded = AppointmentsMail::decoded($mime);
        if (preg_match('/^To:.*invitee@example\.test/mi', $unfolded)) {
            $check(str_contains($decoded, 'action=reschedule') && str_contains($decoded, 'action=cancel')
                && str_contains($decoded, 'action=erase'), 'Mandatory action links survive customized template content');
            if ($localizedSubject === 'Validé :') {
                $decoded = AppointmentsMail::decoded($mime);
                $check(str_contains($decoded, "Modifier l'horaire") && str_contains($decoded, 'Supprimer vos données à tout moment'),
                    'French mandatory footer works independently of installed Joomla language packs');
            }
        } else {
            $check(!str_contains($decoded, 'action=reschedule') && !str_contains($decoded, 'action=cancel')
                && !str_contains($decoded, 'action=erase'), 'Guest and host notifications never contain the primary invitee bearer capabilities');
        }
        if ($localizedSubject !== null && preg_match('/^Subject:\s*(.*)$/mi', $unfolded, $subject)) {
            $localized = $localized || str_contains(iconv_mime_decode(trim($subject[1]), 0, 'UTF-8'), $localizedSubject);
        }
    }
    if ($localizedSubject !== null) {
        $check($localized, 'Notification uses the stored invitee language');
    }
};

try {
    $check((int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_templates WHERE eventtype_id=0 AND published=1")->fetch_assoc()['n'] === 24,
        'All eight purposes have editable defaults in all three languages');
    $admin->login();
    $username = $db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
    $host = (int) $db->query("SELECT id FROM {$prefix}users WHERE username='{$username}'")->fetch_assoc()['id'];
    $time = $now->format('Y-m-d H:i:s');
    $db->query("INSERT INTO {$prefix}appointments_schedules (title,timezone,owner_user_id,published,created_utc,modified_utc) VALUES ('{$tag}','UTC',{$host},1,'{$time}','{$time}')");
    $schedule = (int) $db->insert_id;
    for ($weekday = 1; $weekday <= 7; $weekday++) {
        $db->query("INSERT INTO {$prefix}appointments_schedule_rules (schedule_id,weekday,start_time,end_time) VALUES ({$schedule},{$weekday},'09:00:00','17:00:00')");
    }
    $token = bin2hex(random_bytes(32));
    $db->query("INSERT INTO {$prefix}appointments_eventtypes (title,alias,schedule_id,host_user_id,feed_token,created_utc,modified_utc) VALUES ('{$tag}','{$tag}',{$schedule},{$host},'{$token}','{$time}','{$time}')");
    $event = (int) $db->insert_id;
    $db->query("INSERT INTO {$prefix}appointments_templates (eventtype_id,purpose,language,subject,body) VALUES ({$event},'confirmation','en-GB','Fixture override {event_title}','Local time: {start} {timezone}')");

    $client = new AppointmentsHttp('http://localhost', 'mail-confirmed');
    [$browser, $path, $form] = $client->bookingForm($event, $day . ' 10:00:00', ['guests' => 'guest@example.test']);
    sleep(2);
    $before = $spool();
    $html = $browser->request($path, $form);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$day} 10:00:00'")->fetch_assoc();
    $check($booking && str_contains($html, 'Appointment received'), 'Public confirmation sends real email after saving');
    $inspect($before, $booking, 'REQUEST', 'CONFIRMED', 3, 'Fixture override');

    $db->query("UPDATE {$prefix}appointments_bookings SET invitee_language='de-DE' WHERE id=" . (int) $booking['id']);
    $before = $spool();
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.cancel', ['cid' => [$booking['id']], $token => 1]);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE id=" . (int) $booking['id'])->fetch_assoc();
    $inspect($before, $booking, 'CANCEL', 'CANCELLED', 3, 'Abgesagt:');

    $db->query("UPDATE {$prefix}appointments_eventtypes SET requires_approval=1 WHERE id={$event}");
    $client = new AppointmentsHttp('http://localhost', 'mail-pending');
    [$browser, $path, $form] = $client->bookingForm($event, $day . ' 11:00:00', ['guests' => 'guest@example.test']);
    sleep(2);
    $before = $spool();
    $browser->request($path, $form);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$day} 11:00:00'")->fetch_assoc();
    $inspect($before, $booking, 'REQUEST', 'TENTATIVE', 3, 'Awaiting approval:');
    $db->query("UPDATE {$prefix}appointments_bookings SET invitee_language='fr-FR' WHERE id=" . (int) $booking['id']);
    $before = $spool();
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.approve', ['cid' => [$booking['id']], $token => 1]);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE id=" . (int) $booking['id'])->fetch_assoc();
    $inspect($before, $booking, 'REQUEST', 'CONFIRMED', 3, 'Validé :');

    // HTML mail style with Joomla's own mail layout (com_mails): HTML in the layout, text as
    // alternative part, both calendar parts unchanged, management links clickable.
    $mailsParams = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_mails' AND type='component'")->fetch_row()[0];
    $html = json_encode(array_merge(json_decode((string) $mailsParams, true) ?: [], ['mail_style' => 'both', 'disable_htmllayout' => '1', 'mail_htmllayout' => 'mailtemplate']));
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($html) . "' WHERE element='com_mails' AND type='component'");
    $db->query("UPDATE {$prefix}appointments_eventtypes SET requires_approval=0 WHERE id={$event}");
    $client = new AppointmentsHttp('http://localhost', 'mail-html');
    [$browser, $path, $form] = $client->bookingForm($event, $day . ' 13:00:00', ['guests' => 'guest@example.test']);
    sleep(2);
    $before = $spool();
    $browser->request($path, $form);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$day} 13:00:00'")->fetch_assoc();
    $inspect($before, $booking, 'REQUEST', 'CONFIRMED', 3);
    $htmlMails = 0;
    foreach (array_values(array_diff($spool(), $before)) as $file) {
        $mime = file_get_contents($file);
        $multipart = (bool) preg_match('/Content-Type:\s*text\/html/i', $mime) && (bool) preg_match('/Content-Type:\s*text\/plain/i', $mime);
        $decoded = AppointmentsMail::decoded($mime);
        // Guest and host notices carry no links, so only the layout and the two parts are required here.
        if ($multipart && stripos($decoded, '<html') !== false && stripos($decoded, '</html>') !== false) {
            $htmlMails++;
        }
        if (preg_match('/^To:.*invitee@example\.test/mi', preg_replace('/\r?\n[ \t]+/', ' ', $mime))) {
            $check((bool) preg_match('/<a href="[^"]*action=cancel[^"]*">/', $decoded), 'HTML mail links the signed cancel URL as a whole');
        }
    }
    $check($htmlMails === 3, 'With com_mails HTML every notification is HTML in the mail layout with a plain-text alternative');
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string((string) $mailsParams) . "' WHERE element='com_mails' AND type='component'");
    $mailsParams = null;
    $client = new AppointmentsHttp('http://localhost', 'mail-failure');
    [$browser, $path, $form] = $client->bookingForm($event, $day . ' 12:00:00');
    sleep(2);
    if (file_put_contents($rejectDelivery, 'reject') === false) {
        throw new RuntimeException('Cannot configure the test transport to reject delivery.');
    }
    $before = $spool();
    $html = $browser->request($path, $form);
    $saved = $db->query("SELECT COUNT(*) FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$day} 12:00:00'")->fetch_row()[0];
    $check((int) $saved === 1 && $before === $spool()
        && str_contains($html, 'Appointment received') && str_contains($html, 'Your appointment was saved, but'),
        'Mail failure explicitly preserves the saved booking and warns against duplicate booking');
    // The booking is saved, so the message is a warning, like the missing conference link:
    // messages of one kind look the same in every template.
    $at = strpos($html, 'Your appointment was saved, but');
    $check($at !== false && str_contains(substr($html, max(0, $at - 400), 400), 'warning')
        && !preg_match('/"(?:error|danger)":\[[^\]]*Your appointment was saved, but/', $html)
        && !preg_match('/(?:type="(?:error|danger)"|alert-(?:error|danger))[^>]*>(?:(?!<\/).)*Your appointment was saved, but/s', $html),
        'The mail failure after a saved booking is shown as a warning, not as an error');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    if ($mailsParams !== null) {
        $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string((string) $mailsParams) . "' WHERE element='com_mails' AND type='component'");
    }
    if (is_file($rejectDelivery) && !unlink($rejectDelivery)) {
        throw new RuntimeException('Cannot restore the test mail transport.');
    }
    if ($event) {
        $db->query("DELETE FROM {$prefix}appointments_bookings WHERE eventtype_id={$event}");
        $db->query("DELETE FROM {$prefix}appointments_templates WHERE eventtype_id={$event}");
        $db->query("DELETE FROM {$prefix}appointments_eventtypes WHERE id={$event}");
    }
    if ($schedule) {
        $db->query("DELETE FROM {$prefix}appointments_schedule_rules WHERE schedule_id={$schedule}");
        $db->query("DELETE FROM {$prefix}appointments_schedules WHERE id={$schedule}");
    }
}
exit($failed ? 1 : 0);
