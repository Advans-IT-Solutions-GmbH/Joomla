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
$languageParams = null;
$inviteeUser = 0;
$guestUser = 0;
$publishedTemplateIds = [];
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

$subjectFor = static function (array $before, string $address): string {
    $subjects = [];
    foreach (AppointmentsMail::messagesSince($before) as $mime) {
        $headers = preg_replace('/\r?\n[ \t]+/', ' ', $mime);
        if (preg_match('/^To:.*' . preg_quote($address, '/') . '/mi', $headers)
            && preg_match('/^Subject:\s*(.*)$/mi', $headers, $subject)
        ) {
            $subjects[] = iconv_mime_decode(trim($subject[1]), 0, 'UTF-8');
        }
    }
    if (count($subjects) !== 1 || !is_string($subjects[0])) {
        throw new RuntimeException('Expected exactly one readable subject for the fixture recipient.');
    }

    return $subjects[0];
};

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
            if (preg_match('/^To:.*guest@example\.test/mi', $unfolded)) {
                $check(str_contains($decoded, 'task=guest.confirm') && !str_contains($decoded, 'guest@example.test&')
                    && !str_contains($decoded, 'guest%40example.test'), 'Guest mail carries the signed guest removal link without the address in the URL');
                $check(preg_match('/(?:Hello|Guten Tag|Bonjour) Fixture/', $decoded) === 0,
                    'Guest mail never greets the guest with the name of the booking person');
            } else {
                $check(!str_contains($decoded, 'task=guest.confirm'), 'Host notification carries no guest removal link');
                $check(preg_match('/(?:Hello|Guten Tag|Bonjour) Fixture/', $decoded) === 0,
                    'Host notification uses host wording, never a mail addressed to the booking person');
            }
        }
        if ($localizedSubject !== null && preg_match('/^Subject:\s*(.*)$/mi', $unfolded, $subject)) {
            $localized = $localized || str_contains(iconv_mime_decode(trim($subject[1]), 0, 'UTF-8'), $localizedSubject);
        }
    }
    if ($localizedSubject !== null) {
        $check($localized, 'Notification uses the recipient account language');
    }
};

try {
    $check((int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_templates WHERE eventtype_id=0 AND published=1")->fetch_assoc()['n'] === 75,
        'All twenty-five purposes (eight, six guest, four host and seven internet calendar templates) have editable defaults in all three languages');
    $admin->login();
    $username = $db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
    $host = (int) $db->query("SELECT id FROM {$prefix}users WHERE username='{$username}'")->fetch_assoc()['id'];
    $db->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('Mail fixture','{$tag}','invitee@example.test','',1,0,UTC_TIMESTAMP(),'{\"language\":\"en-GB\"}')");
    $inviteeUser = (int) $db->insert_id;
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
    $check($booking && preg_match('/Appointment (?:received|confirmed)/', $html) === 1, 'Public confirmation sends real email after saving');
    $inspect($before, $booking, 'REQUEST', 'CONFIRMED', 3, 'Fixture override');

    $db->query("UPDATE {$prefix}appointments_bookings SET invitee_language='fr-FR' WHERE id=" . (int) $booking['id']);
    $db->query("UPDATE {$prefix}users SET params='{\"language\":\"de-DE\"}' WHERE id={$inviteeUser}");
    $languageParams = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_languages' AND type='component'")->fetch_row()[0];
    $languages = json_decode((string) $languageParams, true, 512, JSON_THROW_ON_ERROR);
    $languages['site'] = 'de-DE';
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($languages, JSON_THROW_ON_ERROR)) . "' WHERE element='com_languages' AND type='component'");
    $before = $spool();
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.cancel', ['cid' => [$booking['id']], $token => 1]);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE id=" . (int) $booking['id'])->fetch_assoc();
    $inspect($before, $booking, 'CANCEL', 'CANCELLED', 3, 'Abgesagt:');
    $check(str_contains($subjectFor($before, 'guest@example.test'), 'Abgesagt:'),
        'Guest without an account uses the default site language, not the stored booking language');
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string((string) $languageParams) . "' WHERE element='com_languages' AND type='component'");
    $languageParams = null;
    $db->query("UPDATE {$prefix}users SET params='{\"language\":\"en-GB\"}' WHERE id={$inviteeUser}");

    $db->query("UPDATE {$prefix}appointments_eventtypes SET requires_approval=1 WHERE id={$event}");
    $client = new AppointmentsHttp('http://localhost', 'mail-pending');
    [$browser, $path, $form] = $client->bookingForm($event, $day . ' 11:00:00', ['guests' => 'guest@example.test']);
    sleep(2);
    $before = $spool();
    $browser->request($path, $form);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$day} 11:00:00'")->fetch_assoc();
    $inspect($before, $booking, 'REQUEST', 'TENTATIVE', 3, 'Awaiting approval:');
    $db->query("UPDATE {$prefix}users SET params='{\"language\":\"fr-FR\"}' WHERE id={$inviteeUser}");
    $before = $spool();
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.approve', ['cid' => [$booking['id']], $token => 1]);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE id=" . (int) $booking['id'])->fetch_assoc();
    $inspect($before, $booking, 'REQUEST', 'CONFIRMED', 3, 'Validé :');
    $check(str_contains($subjectFor($before, 'guest@example.test'), 'Approved:'),
        'An additional guest does not inherit the French account language of the booking person');
    $db->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('Guest fixture','{$tag}-guest','guest@example.test','',1,0,UTC_TIMESTAMP(),'{\"language\":\"de-DE\"}')");
    $guestUser = (int) $db->insert_id;
    $before = $spool();
    $token = $admin->token($admin->request('/administrator/index.php?option=com_appointments&view=bookings'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.cancel', ['cid' => [$booking['id']], $token => 1]);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE id=" . (int) $booking['id'])->fetch_assoc();
    $inspect($before, $booking, 'CANCEL', 'CANCELLED', 3, 'Annulé :');
    $check(str_contains($subjectFor($before, 'guest@example.test'), 'Abgesagt:'),
        'An additional guest with an account receives their own profile language');
    $db->query("UPDATE {$prefix}users SET params='{\"language\":\"en-GB\"}' WHERE id={$inviteeUser}");

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
            // A signed URL has no break opportunity: as link text it widened the mail beyond the screen.
            $check(!preg_match('/<a [^>]*>[^<]*signature=/', $decoded) && (bool) preg_match('/<a href="[^"]*action=cancel[^"]*">Cancel<\/a>/', $decoded),
                'HTML mail shows the label as link text, never the signed URL');
            $check((bool) preg_match('/^https?:\/\/\S*action=cancel\S*\r?$/m', $decoded),
                'Text part keeps the full signed URL on a line of its own');
        }
    }
    $check($htmlMails === 3, 'With com_mails HTML every notification is HTML in the mail layout with a plain-text alternative');
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string((string) $mailsParams) . "' WHERE element='com_mails' AND type='component'");
    $mailsParams = null;

    $templateRows = $db->query("SELECT id FROM {$prefix}appointments_templates WHERE eventtype_id IN (0,{$event})"
        . " AND purpose='confirmation' AND language IN ('de-DE','fr-FR') AND published=1")->fetch_all(MYSQLI_ASSOC);
    $publishedTemplateIds = array_map('intval', array_column($templateRows, 'id'));
    if ($publishedTemplateIds === []) {
        throw new RuntimeException('Localized confirmation templates are missing before the fallback regression.');
    }
    $db->query("UPDATE {$prefix}appointments_templates SET published=0 WHERE id IN (" . implode(',', $publishedTemplateIds) . ')');
    $check((int) $db->query("SELECT COUNT(*) FROM {$prefix}appointments_templates WHERE eventtype_id IN (0,{$event})"
        . " AND purpose='confirmation' AND language IN ('de-DE','fr-FR') AND published=1")->fetch_row()[0] === 0
        && (int) $db->query("SELECT COUNT(*) FROM {$prefix}appointments_templates WHERE eventtype_id IN (0,{$event})"
            . " AND purpose='confirmation' AND language='en-GB' AND published=1")->fetch_row()[0] > 0,
        'Fallback fixture disables both localized database candidates while English remains published');

    foreach (['de-DE' => 14, 'fr-FR' => 15] as $locale => $hour) {
        $db->query("UPDATE {$prefix}users SET params='{\"language\":\"{$locale}\"}' WHERE id={$inviteeUser}");
        $client = new AppointmentsHttp('http://localhost', 'mail-default-' . $locale);
        $start = $day . ' ' . $hour . ':00:00';
        [$browser, $path, $form] = $client->bookingForm($event, $start, ['first_name' => 'Fallback', 'last_name' => 'Recipient']);
        sleep(2);
        $before = $spool();
        $browser->request($path, $form);
        $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$start}'")->fetch_assoc();
        if (!$booking) {
            throw new RuntimeException('The shipped-default fixture booking was not saved.');
        }
        $inspect($before, $booking, 'REQUEST', 'CONFIRMED', 2);
        $defaults = parse_ini_file('/var/www/html/administrator/components/com_appointments/language/' . $locale . '/com_appointments.ini', false, INI_SCANNER_RAW);
        if (!isset($defaults['COM_APPOINTMENTS_MAIL_CONFIRMATION_SUBJECT'], $defaults['COM_APPOINTMENTS_MAIL_CONFIRMATION_BODY'])) {
            throw new RuntimeException('The shipped confirmation defaults are missing for ' . $locale . '.');
        }
        $expectedSubject = str_replace('{event_title}', $tag, $defaults['COM_APPOINTMENTS_MAIL_CONFIRMATION_SUBJECT']);
        $body = str_replace('\n', "\n", $defaults['COM_APPOINTMENTS_MAIL_CONFIRMATION_BODY']);
        $intro = str_replace('{invitee_name}', 'Fallback Recipient', explode('{event_title}', $body)[0]);
        $paragraphs = explode("\n\n", $body);
        $ending = end($paragraphs);
        $localizedBody = false;
        foreach (AppointmentsMail::messagesSince($before) as $mime) {
            if (!preg_match('/^To:.*invitee@example\.test/mi', preg_replace('/\r?\n[ \t]+/', ' ', $mime))) {
                continue;
            }
            $decoded = str_replace("\r\n", "\n", AppointmentsMail::decoded($mime));
            $localizedBody = str_contains($decoded, $intro) && str_contains($decoded, $ending)
                && preg_match('/COM_APPOINTMENTS_[A-Z_]+/', $decoded) === 0;
        }
        $check($subjectFor($before, 'invitee@example.test') === $expectedSubject && $localizedBody,
            "$locale: booking mail uses the shipped localized subject/body, not the published English template");
    }
    $db->query("UPDATE {$prefix}appointments_templates SET published=1 WHERE id IN (" . implode(',', $publishedTemplateIds) . ')');
    $publishedTemplateIds = [];
    $db->query("UPDATE {$prefix}users SET params='{\"language\":\"en-GB\"}' WHERE id={$inviteeUser}");

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
        && preg_match('/Appointment (?:received|confirmed)/', $html) === 1 && str_contains($html, 'Your appointment was saved, but'),
        'Mail failure explicitly preserves the saved booking and warns against duplicate booking');
    // The booking is saved, so the message is a warning, like the missing conference link:
    // messages of one kind look the same in every template.
    $at = strpos($html, 'Your appointment was saved, but');
    $check($at !== false && str_contains(substr($html, max(0, $at - 400), 400), 'warning')
        && !preg_match('/"(?:error|danger)":\[[^\]]*Your appointment was saved, but/', $html)
        && !preg_match('/(?:type="(?:error|danger)"|alert-(?:error|danger))[^>]*>(?:(?!<\/).)*Your appointment was saved, but/s', $html),
        'The mail failure after a saved booking is shown as a warning, not as an error');
    if (is_file($rejectDelivery) && !unlink($rejectDelivery)) {
        throw new RuntimeException('Cannot restore the test mail transport.');
    }

    // Fail closed on the public site URL: mails take their links only from the configured address,
    // never from the Host header of the request, and without one no mail is sent and no booking taken.
    $componentParams = (string) $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_row()[0];
    $siteUrl = (string) (json_decode($componentParams, true)['public_site_url'] ?? '');
    $resend = static function (string $publicSiteUrl) use ($event, $day): array {
        $script = sys_get_temp_dir() . '/mail-site-url-' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($script, '<?php
            require "/var/www/html/tests/scripts/appointments-joomla.php";
            \Joomla\CMS\Component\ComponentHelper::getParams("com_appointments")->set("public_site_url", ' . var_export($publicSiteUrl, true) . ');
            $row = $joomlaDb->setQuery("SELECT * FROM #__appointments_bookings WHERE eventtype_id = ' . (int) $event . ' AND start_utc = \'' . $day . ' 12:00:00\'")->loadAssoc();
            $service = new \Advans\Component\Appointments\Administrator\Service\NotificationService($joomlaDb);
            $result = ["sent" => false, "links" => ""];
            try { $result["links"] = implode(" ", $service->links($row)); } catch (\Throwable $e) { $result["links"] = "refused"; }
            try { $service->send($row, "confirmation", new DateTimeImmutable("now", new DateTimeZone("UTC"))); $result["sent"] = true; } catch (\Throwable $e) { $result["error"] = get_class($e); }
            echo "\n@@SITEURL@@" . json_encode($result);');
        $output = [];
        exec('cd /var/www/html && HTTP_HOST=attacker.example.test php ' . escapeshellarg($script) . ' 2>&1', $output);
        unlink($script);
        $text = implode("\n", $output);
        $at = strrpos($text, '@@SITEURL@@');

        return $at === false ? ['error' => substr($text, 0, 500)] : (array) json_decode(substr($text, $at + 11), true);
    };
    $before = $spool();
    $result = $resend($siteUrl);
    $mails = implode("\n", array_map([AppointmentsMail::class, 'decoded'], AppointmentsMail::messagesSince($before)));
    $check($siteUrl !== '' && ($result['sent'] ?? false) === true && str_starts_with((string) $result['links'], rtrim($siteUrl, '/') . '/index.php?')
        && str_contains($mails, rtrim($siteUrl, '/') . '/index.php?option=com_appointments') && !str_contains($mails . $result['links'], 'attacker.example.test'),
        'With a public site URL, mails sent during a request with a foreign Host header link only to the configured address');
    $before = $spool();
    $result = $resend('');
    $check(($result['sent'] ?? true) === false && ($result['links'] ?? '') === 'refused' && $before === $spool(),
        'Without a public site URL (and without live_site) no mail is sent and no link is built, also not from the Host header');

    // The booking page and its submission while no public site URL is configured, with «System - Page
    // Cache» on: a page rendered while the address was valid must not be served from the cache afterwards.
    $pageCache = (int) $db->query("SELECT enabled FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='cache'")->fetch_row()[0];
    $db->query("UPDATE {$prefix}extensions SET enabled=1 WHERE type='plugin' AND folder='system' AND element='cache'");
    $client = new AppointmentsHttp('http://localhost', 'mail-site-url');
    [$browser, $path, $form] = $client->bookingForm($event, $day . ' 13:00:00');
    $guestPage = '/index.php?option=com_appointments&view=eventtype&id=' . $event;
    $earlier = new AppointmentsHttp('http://localhost', 'mail-site-url-earlier');
    $earlier->request($guestPage);
    $unset = json_decode($componentParams, true) ?: [];
    $unset['public_site_url'] = '';
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($unset)) . "' WHERE element='com_appointments' AND type='component'");
    try {
        $page = $browser->request($guestPage);
        $check($browser->status === 503 && str_contains($page, 'Booking is currently not possible') && !str_contains($page, 'name="booking[start]"'),
            'Without a public site URL the booking page answers 503 with a neutral notice and no form');
        $later = new AppointmentsHttp('http://localhost', 'mail-site-url-later');
        $page = $later->request($guestPage);
        $check($later->status === 503 && !str_contains($page, 'name="booking[start]"'),
            'With the page cache on, a booking page rendered while the address was valid is not served after it is cleared');
        sleep(2);
        $before = $spool();
        $html = $browser->request($path, $form);
        $saved = (int) $db->query("SELECT COUNT(*) FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$day} 13:00:00'")->fetch_row()[0];
        $check($saved === 0 && $before === $spool() && str_contains($html, 'Booking is currently not possible'),
            'Without a public site URL a submitted booking is not saved and no mail is sent');
    } finally {
        $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($componentParams) . "' WHERE element='com_appointments' AND type='component'");
        $db->query("UPDATE {$prefix}extensions SET enabled={$pageCache} WHERE type='plugin' AND folder='system' AND element='cache'");
    }
    $page = $browser->request($guestPage);
    $check($browser->status === 200 && str_contains($page, 'name="booking[start]"'), 'With a public site URL the booking page shows its form again');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    if ($publishedTemplateIds !== []) {
        $db->query("UPDATE {$prefix}appointments_templates SET published=1 WHERE id IN (" . implode(',', $publishedTemplateIds) . ')');
    }
    if ($languageParams !== null) {
        $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string((string) $languageParams) . "' WHERE element='com_languages' AND type='component'");
    }
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
    foreach ([$inviteeUser, $guestUser] as $userId) {
        if ($userId > 0) {
            $db->query("DELETE FROM {$prefix}users WHERE id={$userId}");
        }
    }
}
exit($failed ? 1 : 0);
