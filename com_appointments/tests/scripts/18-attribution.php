<?php

/*
 * The origin of a booking (campaign attribution): recorded in the session from a landing
 * article that is no page of the component, stored encrypted with the booking, shown to the
 * host only (host mail, booking page, CSV export), exported for a privacy request, erased with
 * the booking, and counted without personal data in the campaign aggregate that outlives it.
 */

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-mail.php';
require_once appointments_test_root() . '/administrator/components/com_appointments/src/Helper/Attribution.php';

use Advans\Component\Appointments\Administrator\Helper\Attribution;

require __DIR__ . '/appointments-joomla.php';
// The privacy plugin starts the Joomla session late: the report is held back until the end, so no
// output is sent before it.
ob_start();

$fixture = new AppointmentsFixture('attribution');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$base = appointments_test_base_url();
$originalParams = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc()['params'];
$article = 0;
$event = 0;
$agent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14.6; rv:132.0) Gecko/20100101 Firefox/132.0';
$address = 'attribution-' . bin2hex(random_bytes(4)) . '@example.test';
// Not a <script> tag: Joomla's standard .htaccess refuses such a query string before Joomla runs.
$xss = '"><img src=x onerror=alert(1)>';
$day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
$messageTo = static function (string $mime): string {
    return preg_match('/^To:\s*(.+)$/mi', $mime, $match) === 1 ? strtolower(trim($match[1])) : '';
};
$exportCsv = static function (AppointmentsHttp $client, string $task, int $event): string {
    $event = $event > 0 ? (string) $event : '';
    $html = $client->request('/administrator/index.php?' . http_build_query(['option' => 'com_appointments', 'view' => 'bookings',
        'filter_eventtype_id' => $event, 'filter_state' => '', 'filter_search' => '', 'filter_from' => '', 'filter_to' => '']));

    return $client->request('/administrator/index.php?option=com_appointments', ['task' => 'bookings.' . $task, $client->token($html) => 1,
        'filter_eventtype_id' => $event, 'filter_state' => '', 'filter_search' => '', 'filter_from' => '', 'filter_to' => '']);
};
// Of the origin, mails and calendar entries show the campaign name only (Attribution::text()).
$onlyCampaign = static function (string $content, array $record) use ($agent): array {
    $leaks = [];

    foreach (array_filter([$record['booking_ip'] ?? '', $record['landing_ip'] ?? '', $agent, 'LI-CLICK-1', 'G-CLICK-1', 'MC-1', 'linkedin',
        'mail.example.org', 'utm_source', 'de-CH', 'Origin of the booking', 'onerror']) as $hidden) {
        if (str_contains($content, $hidden)) {
            $leaks[] = $hidden;
        }
    }

    return $leaks;
};
$calendarText = static function (array $mails): string {
    $text = '';

    foreach ($mails as $mime) {
        foreach (AppointmentsMail::calendars($mime) as $calendar) {
            $text .= preg_replace('/\r\n[ \t]/', '', $calendar);
        }
    }

    return $text;
};
$runReminders = static function () use ($db, $prefix): void {
    $time = gmdate('Y-m-d H:i:s');
    $db->query("INSERT INTO {$prefix}scheduler_tasks (title,type,execution_rules,cron_rules,state,last_exit_code,times_executed,times_failed,priority,ordering,params,note,created,created_by)"
        . " VALUES ('Appointments origin test','plg_task_appointments.reminders','{\"rule-type\":\"manual\"}','{\"type\":\"manual\",\"exp\":\"\"}',1,0,0,0,0,0,'{\"batch_size\":100}','','{$time}',0)");
    $task = (int) $db->insert_id;
    exec('cd /var/www/html && HTTP_HOST=localhost php cli/joomla.php scheduler:run --id=' . $task . ' 2>&1', $output);
    $db->query("DELETE FROM {$prefix}scheduler_tasks WHERE id={$task}");
};
$csvRows = static function (string $csv): array {
    $stream = fopen('php://memory', 'w+');
    fwrite($stream, $csv);
    rewind($stream);
    $header = fgetcsv($stream, 0, ',', '"', '');
    $rows = [];

    while (is_array($header) && ($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        if (count($row) === count($header)) {
            $rows[] = array_combine($header, $row);
        }
    }

    fclose($stream);

    return [$header ?: [], $rows];
};

try {
    // Off by default: nothing is recorded until the operator switches the option on.
    $defaults = json_decode($originalParams, true) ?: [];
    $check((int) ($defaults['attribution_capture'] ?? 0) === 0, 'The origin of bookings is off after the installation');
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode(['attribution_capture' => 1] + $defaults))
        . "' WHERE element='com_appointments' AND type='component'");
    $event = $fixture->event();
    $hostEmail = strtolower((string) $db->query("SELECT email FROM {$prefix}users WHERE id={$fixture->host}")->fetch_assoc()['email']);
    $category = (int) $db->query("SELECT id FROM {$prefix}categories WHERE extension='com_content' AND published=1 ORDER BY id LIMIT 1")->fetch_assoc()['id'];
    $now = gmdate('Y-m-d H:i:s');
    $alias = 'attribution-landing-' . bin2hex(random_bytes(4));
    $db->query("INSERT INTO {$prefix}content (title, alias, introtext, `fulltext`, state, catid, created, created_by, modified, modified_by, publish_up,"
        . " images, urls, attribs, version, metakey, metadesc, access, metadata, language, note)"
        . " VALUES ('Attribution landing page', '{$alias}', '<p>ATTRIBUTION-LANDING-ARTICLE</p>', '', 1, {$category}, '{$now}', {$fixture->host}, '{$now}',"
        . " {$fixture->host}, '{$now}', '{}', '{}', '{}', 1, '', '', 1, '{}', '*', '')");
    $article = (int) $db->insert_id;

    // 1. The landing article, reached from LinkedIn with campaign parameters and click IDs.
    $visitor = new AppointmentsHttp($base, 'attribution-visitor');
    $visitor->headers = ['Referer: https://www.linkedin.com/feed/', 'User-Agent: ' . $agent, 'Accept-Language: de-CH,de;q=0.9'];
    $landing = '/index.php?' . http_build_query(['option' => 'com_content', 'view' => 'article', 'id' => $article, 'catid' => $category,
        'utm_source' => 'linkedin', 'utm_medium' => 'paid-social', 'utm_campaign' => 'first-campaign', 'utm_content' => $xss,
        'li_fat_id' => 'LI-CLICK-1', 'gclid' => 'G-CLICK-1', 'token' => 'NEVER-STORED']);
    $html = $visitor->request($landing);
    if ($visitor->status !== 200 || !str_contains($html, 'ATTRIBUTION-LANDING-ARTICLE')) {
        echo '    landing: HTTP ' . $visitor->status . ', redirects ' . json_encode($visitor->locations) . ', title ' . (preg_match('#<title>(.*?)</title>#s', $html, $m) ? trim($m[1]) : '-') . "\n";
    }
    $check($visitor->status === 200 && str_contains($html, 'ATTRIBUTION-LANDING-ARTICLE'), 'The landing article with campaign parameters opens');

    // 2. Internal navigation: no new touch.
    $visitor->headers = ['Referer: ' . $base . $landing, 'User-Agent: ' . $agent, 'Accept-Language: de-CH,de;q=0.9'];
    $visitor->request('/index.php?option=com_content&view=article&id=' . $article . '&catid=' . $category);

    // 3. Back from a newsletter: the last touch.
    $visitor->headers = ['Referer: https://mail.example.org/inbox', 'User-Agent: ' . $agent, 'Accept-Language: de-CH,de;q=0.9'];
    $visitor->request('/index.php?option=com_content&view=article&id=' . $article . '&catid=' . $category
        . '&utm_source=newsletter&utm_medium=email&utm_campaign=last-campaign&mc_cid=MC-1');

    // 4. The booking page and the booking, in the same session.
    $visitor->headers = ['Referer: ' . $base . '/index.php?option=com_content&view=article&id=' . $article, 'User-Agent: ' . $agent, 'Accept-Language: de-CH,de;q=0.9'];
    [$browser, $path, $form] = $visitor->bookingForm($event, $day . ' 10:00:00', ['email' => $address]);
    $form['attribution'] = 'FORGED-BY-FORM';
    $form['booking']['attribution'] = 'FORGED-BY-FORM';
    // Another tab opens an article after the booking form; the form is then sent from the booking page.
    $visitor->request('/index.php?option=com_content&view=article&id=' . $article . '&catid=' . $category . '&other=tab');
    $bookingPage = appointments_page($event, '&timezone=UTC&start=' . urlencode($day . ' 10:00:00'));
    $visitor->headers = ['Referer: ' . $base . $bookingPage, 'User-Agent: ' . $agent, 'Accept-Language: de-CH,de;q=0.9'];
    sleep(2);
    $before = AppointmentsMail::snapshot();
    $html = $browser->request($path, $form);
    $check($browser->status === 200 && preg_match('/Appointment (?:received|confirmed)/', $html) === 1, 'The booking is taken');
    $time = $db->real_escape_string($day . ' 10:00:00');
    $stored = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$time}'")->fetch_assoc();
    $booking = appointments_open_booking($stored);
    $id = (int) ($booking['id'] ?? 0);
    $check(is_string($stored['attribution'] ?? null) && appointments_is_sealed($stored['attribution']) && !str_contains($stored['attribution'], 'linkedin'),
        'The origin is stored encrypted with the personal fields');
    $record = Attribution::decode($booking['attribution'] ?? null);
    $check($record !== null, 'The stored origin opens as a record');
    $record ??= [];
    $check(($record['first_touch']['parameters']['utm_source'] ?? '') === 'linkedin'
        && ($record['first_touch']['parameters']['utm_campaign'] ?? '') === 'first-campaign'
        && ($record['first_touch']['parameters']['li_fat_id'] ?? '') === 'LI-CLICK-1'
        && ($record['first_touch']['parameters']['gclid'] ?? '') === 'G-CLICK-1'
        && ($record['first_touch']['referrer'] ?? '') === 'https://www.linkedin.com/feed/', 'First touch: landing article, campaign, click IDs and referrer');
    $check(($record['last_touch']['parameters']['utm_source'] ?? '') === 'newsletter'
        && ($record['last_touch']['parameters']['mc_cid'] ?? '') === 'MC-1'
        && ($record['last_touch']['referrer_host'] ?? '') === 'mail.example.org', 'Last touch: the newsletter visit, not the internal navigation');
    $check(str_contains($record['landing_url'] ?? '', 'id=' . $article) && str_contains($record['landing_url'] ?? '', 'utm_source=linkedin')
        && !str_contains(json_encode($record), 'NEVER-STORED'), 'The first landing URL keeps path and query, without a credential parameter');
    $check(($record['booking_page_url'] ?? '') === $bookingPage, 'The booking page is the page the form was sent from, not a page of another tab');
    $check((int) ($record['page_views'] ?? 0) >= 5, 'Page views before the booking are counted');
    $check(filter_var($record['booking_ip'] ?? '', FILTER_VALIDATE_IP) !== false && filter_var($record['landing_ip'] ?? '', FILTER_VALIDATE_IP) !== false,
        'The IP address of the first page view and of the booking are stored');
    $check(($record['accept_language'] ?? '') === 'de-CH,de;q=0.9' && ($record['user_agent'] ?? '') === $agent
        && ($record['device'] ?? '') === 'desktop' && ($record['browser'] ?? '') === 'Firefox' && ($record['os'] ?? '') === 'macOS',
        'Browser language, User-Agent and the derived device, browser and operating system are stored');
    $check(!str_contains(json_encode($booking), 'FORGED-BY-FORM'), 'A posted form value never reaches the origin');

    // Mails: the host sees the origin, the invitee never.
    $messages = AppointmentsMail::messagesSince($before);
    $hostMails = array_values(array_filter($messages, static fn (string $mime): bool => str_contains($messageTo($mime), $hostEmail)));
    $inviteeMails = array_values(array_filter($messages, static fn (string $mime): bool => str_contains($messageTo($mime), $address)));
    $hostText = implode("\n", array_map([AppointmentsMail::class, 'decoded'], $hostMails));
    $hostLeaks = $onlyCampaign($hostText, $record);
    if ($hostLeaks !== []) {
        echo '    host mail shows: ' . implode(', ', $hostLeaks) . "\n";
    }
    $check($hostMails !== [] && str_contains($hostText, 'Campaign: last-campaign') && $hostLeaks === [],
        'The host mail shows the campaign name of the last campaign visit and nothing else of the origin');
    $hostCalendar = $calendarText($hostMails);
    $check(str_contains($hostCalendar, 'Campaign: last-campaign') && $onlyCampaign($hostCalendar, $record) === [],
        'The host calendar entry shows the campaign name and nothing else of the origin');
    $hostHtml = implode("\n", array_merge(...array_map(static fn (string $mime): array => AppointmentsMail::parts($mime, 'text/html'), $hostMails)));
    $check(!str_contains($hostHtml, $xss), 'The origin is escaped in the HTML part of the host mail');
    $inviteeText = implode("\n", array_map([AppointmentsMail::class, 'decoded'], $inviteeMails));
    $check($inviteeMails !== [] && !str_contains($inviteeText, 'Origin of the booking') && !str_contains($inviteeText, 'utm_source')
        && !str_contains($inviteeText, 'LI-CLICK-1') && !str_contains($inviteeText, (string) ($record['booking_ip'] ?? 'missing')),
        'The invitee mail carries nothing of the origin');

    // The campaign statistics: the last touch's campaign, the confirmed state, nothing personal.
    $stats = $db->query("SELECT * FROM {$prefix}appointments_campaign_stats WHERE eventtype_id={$event}")->fetch_all(MYSQLI_ASSOC);
    $eventTitle = (string) $db->query("SELECT title FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc()['title'];
    $check(count($stats) === 1 && (int) $stats[0]['booking_id'] === $id && $stats[0]['utm_source'] === 'newsletter'
        && $stats[0]['utm_campaign'] === 'last-campaign' && $stats[0]['referrer_host'] === 'mail.example.org' && (int) $stats[0]['state'] === 1
        && $stats[0]['eventtype_title'] === $eventTitle, 'The campaign statistics count the booking under its last campaign');
    $check(array_keys($stats[0] ?? []) === ['id', 'booking_id', 'day', 'eventtype_id', 'eventtype_title', 'utm_source', 'utm_medium', 'utm_campaign',
        'referrer_host', 'state'], 'The campaign statistics have no column for a person, a URL, a click ID or an address');

    // Administrator: booking page, CSV export, campaign CSV.
    $admin = new AppointmentsHttp($base, 'attribution-admin');
    $admin->login();
    $page = $admin->request('/administrator/index.php?option=com_appointments&view=booking&id=' . $id);
    $check($admin->status === 200 && str_contains($page, 'Origin of the booking') && str_contains($page, 'LI-CLICK-1'),
        'The booking page in the administrator shows the origin');
    $escaped = htmlspecialchars($xss, ENT_QUOTES, 'UTF-8');
    if (str_contains($page, $xss) || !str_contains($page, $escaped)) {
        $at = strpos($page, 'onerror');
        echo '    escape: raw ' . (str_contains($page, $xss) ? 'present' : 'absent') . ', escaped ' . (str_contains($page, $escaped) ? 'present' : 'absent')
            . ', context ' . json_encode($at === false ? '' : substr($page, max(0, $at - 80), 160)) . "\n";
    }
    $check(!str_contains($page, $xss) && str_contains($page, $escaped), 'The booking page escapes the origin');
    [$header, $rows] = $csvRows($exportCsv($admin, 'exportCsv', $event));
    $row = array_values(array_filter($rows, static fn (array $row): bool => (int) $row['id'] === $id))[0] ?? [];
    $check(array_diff(['first_utm_source', 'first_utm_medium', 'first_utm_campaign', 'first_li_fat_id', 'first_gclid', 'last_utm_campaign',
        'first_referrer', 'first_referrer_host', 'landing_url', 'booking_ip', 'landing_ip', 'device', 'browser', 'os', 'user_agent', 'accept_language',
        'booking_page_url', 'first_seen_utc', 'created_utc', 'eventtype_id', 'eventtype_title', 'state'], $header) === []
        && ($row['first_utm_source'] ?? '') === 'linkedin' && ($row['last_utm_campaign'] ?? '') === 'last-campaign'
        && ($row['first_li_fat_id'] ?? '') === 'LI-CLICK-1' && ($row['booking_ip'] ?? '') === ($record['booking_ip'] ?? 'missing')
        && ($row['browser'] ?? '') === 'Firefox', 'The CSV export has every origin field in a column of its own');
    [, $campaignRows] = $csvRows($exportCsv($admin, 'exportCampaigns', $event));
    $check(count($campaignRows) === 1 && $campaignRows[0]['utm_source'] === 'newsletter' && $campaignRows[0]['bookings'] === '1'
        && $campaignRows[0]['confirmed'] === '1' && $campaignRows[0]['cancelled'] === '0',
        'The campaign statistics export counts the event type per campaign and state');

    // The reminder to the host: the campaign name only, also in its calendar entry.
    $params = json_decode($originalParams, true) ?: [];
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode(['attribution_capture' => 1,
        'reminder_offsets' => '120', 'public_site_url' => $base] + $params)) . "' WHERE element='com_appointments' AND type='component'");
    $soon = gmdate('Y-m-d H:i:s', time() + 3600);
    $soonEnd = gmdate('Y-m-d H:i:s', time() + 4800);
    $db->query("UPDATE {$prefix}appointments_bookings SET start_utc='{$soon}',end_utc='{$soonEnd}',reminder_sent_utc=NULL WHERE id={$id}");
    $before = AppointmentsMail::snapshot();
    $runReminders();
    $reminders = array_values(array_filter(AppointmentsMail::messagesSince($before),
        static fn (string $mime): bool => str_contains($messageTo($mime), $hostEmail)));
    $reminderText = implode("\n", array_map([AppointmentsMail::class, 'decoded'], $reminders));
    $check($reminders !== [] && str_contains($reminderText, 'Campaign: last-campaign') && $onlyCampaign($reminderText, $record) === []
        && str_contains($calendarText($reminders), 'Campaign: last-campaign'),
        'The host reminder and its calendar entry show the campaign name and nothing else of the origin');
    $booking = $fixture->row($id);

    // Privacy export of the invitee's address.
    $application->bootComponent('com_privacy');
    $plugin = $application->bootPlugin('appointments', 'privacy');
    $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
    $request->email = $address;
    $request->status = 1;
    $export = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => null]);
    $plugin->onPrivacyExportRequest($export);
    $exported = serialize($export->getArgument('result')[0][0] ?? null);
    $check(str_contains($exported, 'attribution_first_utm_source') && str_contains($exported, 'LI-CLICK-1')
        && str_contains($exported, 'attribution_booking_ip'), 'The privacy export contains the origin, field by field');
    $check(!str_contains(serialize($export->getArgument('result')), 'appointments_bookings_incomplete'), 'A readable origin leaves the export complete');

    // An origin that cannot be opened (another key, a changed byte) is missing from the export: the export says so.
    $sealedOrigin = (string) $db->query("SELECT attribution FROM {$prefix}appointments_bookings WHERE id={$id}")->fetch_assoc()['attribution'];
    $db->query("UPDATE {$prefix}appointments_bookings SET attribution='" . $db->real_escape_string('v2:' . str_repeat('A', 80)) . "' WHERE id={$id}");
    try {
        $broken = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => null]);
        $plugin->onPrivacyExportRequest($broken);
        $check(str_contains(serialize($broken->getArgument('result')), 'appointments_bookings_incomplete'),
            'An unreadable origin marks the privacy export as incomplete');
    } finally {
        $db->query("UPDATE {$prefix}appointments_bookings SET attribution='" . $db->real_escape_string($sealedOrigin) . "' WHERE id={$id}");
    }

    // The largest export: many bookings of the address, each with the largest origin the capture budget
    // allows (Attribution::MAX_RECORD_BYTES). The export reads them in small batches and opens one row at
    // a time, so what it holds besides its result stays small and far below a 128 MiB memory limit.
    $long = str_repeat("\u{1F600}", 5000);
    $query = array_fill_keys(Attribution::PARAMETERS, $long);
    $uri = '/' . str_repeat('p', 9000) . '?' . http_build_query($query);
    $largestAt = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $largestState = Attribution::visit(null, ['uri' => $uri, 'referrer' => 'https://a.example.org/' . $long, 'host' => 'www.example.test',
        'query' => $query, 'ip' => '2001:db8::1'], $largestAt);
    $largest = Attribution::encode(Attribution::record($largestState, ['user_agent' => $long, 'accept_language' => $long, 'ip' => '2001:db8::2',
        'referrer' => 'https://www.example.test/' . $long, 'host' => 'www.example.test'], $largestAt));
    $check(strlen($largest) > Attribution::MAX_RECORD_BYTES / 2 && strlen($largest) <= Attribution::MAX_RECORD_BYTES,
        'The largest origin fills the capture budget');
    $largestIds = [];
    $largestCount = 120;
    try {
        for ($n = 0; $n < $largestCount; $n++) {
            // Cancelled, so the copies take no slot; the same address, a UID of their own.
            $start = gmdate('Y-m-d H:i:s', strtotime($day . ' 00:00:00') - 86400 * ($n + 1));
            $end = gmdate('Y-m-d H:i:s', strtotime($start) + 1800);
            $uid = bin2hex(random_bytes(32));
            $db->query("INSERT INTO {$prefix}appointments_bookings (uid,eventtype_id,host_user_id,start_utc,end_utc,state,created_utc,modified_utc)"
                . " VALUES ('{$uid}',{$event},{$fixture->host},'{$start}','{$end}',2,'{$now}','{$now}')");
            $largestIds[] = (int) $db->insert_id;
            $fixture->update((int) $db->insert_id, ['invitee_name' => 'Largest export', 'invitee_email' => $address, 'attribution' => $largest]);
        }
        gc_collect_cycles();
        $baseline = memory_get_usage();
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $largestExport = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => null]);
        $plugin->onPrivacyExportRequest($largestExport);
        $held = memory_get_usage() - $baseline;
        $working = memory_get_peak_usage() - $baseline - $held;
        $domain = $largestExport->getArgument('result')[0][0] ?? null;
        $items = is_object($domain) && method_exists($domain, 'getItems') ? count($domain->getItems()) : 0;
        echo '    largest export: ' . $items . ' bookings, result ' . round($held / 1048576, 1) . ' MiB, working memory '
            . round($working / 1048576, 1) . " MiB\n";
        $check($items === $largestCount + 1 && !str_contains(serialize($largestExport->getArgument('result')), 'appointments_bookings_incomplete'),
            'The privacy export contains every booking of the address with the largest origin');
        $check(function_exists('memory_reset_peak_usage') && $working < 32 * 1048576 && $held + $working < 96 * 1048576,
            'The largest privacy export opens the rows in small batches, one row at a time, far below 128 MiB');
        unset($largestExport, $domain);
    } finally {
        if ($largestIds !== []) {
            $db->query("DELETE FROM {$prefix}appointments_bookings WHERE id IN (" . implode(',', $largestIds) . ')');
        }
    }

    // Cancelling erases the origin; the booking stays in the statistics as a count only, now as cancelled.
    $html = $fixture->apply($visitor, $fixture->link($booking, 'cancel'));
    $erased = $fixture->row($id);
    $check($erased['anonymised_utc'] !== null && $erased['attribution'] === null, 'Erasure removes the origin with the personal fields');
    $stats = $db->query("SELECT * FROM {$prefix}appointments_campaign_stats WHERE eventtype_id={$event}")->fetch_all(MYSQLI_ASSOC);
    $totals = $db->query("SELECT * FROM {$prefix}appointments_campaign_totals WHERE eventtype_id={$event}")->fetch_all(MYSQLI_ASSOC);
    $check($stats === [] && count($totals) === 1 && (int) $totals[0]['state'] === 2 && (int) $totals[0]['bookings'] === 1
        && $totals[0]['utm_source'] === 'newsletter' && $totals[0]['utm_campaign'] === 'last-campaign',
        'The campaign statistics outlive the erasure as a count of cancelled bookings, without a row of the booking');
    [, $erasedRows] = $csvRows($exportCsv($admin, 'exportCampaigns', 0));
    $erasedRow = array_values(array_filter($erasedRows, static fn (array $row): bool => (int) $row['eventtype_id'] === $event && $row['utm_source'] === 'newsletter'));
    $check(count($erasedRow) === 1 && $erasedRow[0]['bookings'] === '1' && $erasedRow[0]['cancelled'] === '1' && $erasedRow[0]['confirmed'] === '0',
        'The campaign export counts the erased booking');

    // A booking clears the capture data from the session: a second booking of the same visitor carries
    // nothing of the visit before the first one (it is stored encrypted with the first booking only).
    $visitor->headers = ['User-Agent: ' . $agent];
    [$browser, $path, $form] = $visitor->bookingForm($event, $day . ' 14:00:00', ['email' => $address]);
    sleep(2);
    $browser->request($path, $form);
    $time = $db->real_escape_string($day . ' 14:00:00');
    $again = Attribution::decode(appointments_open_booking($db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event}"
        . " AND start_utc='{$time}'")->fetch_assoc())['attribution'] ?? null);
    $check($again !== null && $again['first_touch'] === null && $again['last_touch'] === null && !str_contains(json_encode($again), 'linkedin')
        && !str_contains(json_encode($again), 'newsletter'),
        'After a booking the session holds no capture data: the next booking starts from its own booking page');

    // A booking without a session: the request itself still tells the booking page, the address and the browser.
    $direct = new AppointmentsHttp($base, 'attribution-direct');
    $direct->headers = ['User-Agent: ' . $agent];
    [$browser, $path, $form] = $direct->bookingForm($event, $day . ' 11:00:00', ['email' => $address]);
    sleep(2);
    $before = AppointmentsMail::snapshot();
    $browser->request($path, $form);
    $directHost = array_values(array_filter(AppointmentsMail::messagesSince($before),
        static fn (string $mime): bool => str_contains($messageTo($mime), $hostEmail)));
    $directText = implode("\n", array_map(static fn (string $mime): string => implode("\n", AppointmentsMail::parts($mime, 'text/plain'))
        . implode("\n", AppointmentsMail::parts($mime, 'text/html')), $directHost));
    $check($directHost !== [] && !str_contains($directText, 'Campaign') && !str_contains($directText, '{attribution}')
        && !str_contains($calendarText($directHost), 'Campaign'),
        'Without a campaign the host mail and its calendar entry have no campaign line at all');
    $time = $db->real_escape_string($day . ' 11:00:00');
    $plain = appointments_open_booking($db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$time}'")->fetch_assoc());
    $plainRecord = Attribution::decode($plain['attribution'] ?? null);
    $check($plainRecord !== null && $plainRecord['first_touch'] === null && $plainRecord['browser'] === 'Firefox',
        'A direct booking stores its origin without a campaign touch');

    // Joomla's page cache answers the landing page from its cache: the visit is recorded all the same,
    // because the plugin runs at onAfterInitialise, before the page cache (onAfterRoute) can answer.
    $cacheState = (int) $db->query("SELECT enabled FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='cache'")->fetch_row()[0];
    $db->query("UPDATE {$prefix}extensions SET enabled=1 WHERE type='plugin' AND folder='system' AND element='cache'");
    try {
        $cachedLanding = '/index.php?option=com_content&view=article&id=' . $article . '&catid=' . $category . '&utm_source=cached&utm_campaign=cached-campaign';
        (new AppointmentsHttp($base, 'attribution-cache-fill'))->request($cachedLanding);
        $cached = new AppointmentsHttp($base, 'attribution-cache-visitor');
        $cached->headers = ['Referer: https://www.linkedin.com/', 'User-Agent: ' . $agent];
        $cachedHtml = $cached->request($cachedLanding);
    } finally {
        $db->query("UPDATE {$prefix}extensions SET enabled={$cacheState} WHERE type='plugin' AND folder='system' AND element='cache'");
    }
    [$browser, $path, $form] = $cached->bookingForm($event, $day . ' 13:00:00', ['email' => $address]);
    sleep(2);
    $browser->request($path, $form);
    $time = $db->real_escape_string($day . ' 13:00:00');
    $cachedRecord = Attribution::decode(appointments_open_booking($db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event}"
        . " AND start_utc='{$time}'")->fetch_assoc())['attribution'] ?? null);
    $check($cached->status === 200 && str_contains($cachedHtml, 'ATTRIBUTION-LANDING-ARTICLE')
        && ($cachedRecord['first_touch']['parameters']['utm_campaign'] ?? '') === 'cached-campaign'
        && ($cachedRecord['first_touch']['referrer_host'] ?? '') === 'www.linkedin.com',
        'A landing page answered from the page cache is recorded as the first touch');

    // The option off: nothing is recorded or counted.
    $params = json_decode($originalParams, true) ?: [];
    $params['attribution_capture'] = 0;
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params)) . "' WHERE element='com_appointments' AND type='component'");
    $off = new AppointmentsHttp($base, 'attribution-off');
    $off->headers = ['Referer: https://www.linkedin.com/'];
    $off->request('/index.php?option=com_content&view=article&id=' . $article . '&utm_source=off-campaign');
    [$browser, $path, $form] = $off->bookingForm($event, $day . ' 12:00:00', ['email' => $address]);
    sleep(2);
    $browser->request($path, $form);
    $time = $db->real_escape_string($day . ' 12:00:00');
    $offRow = $db->query("SELECT attribution FROM {$prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$time}'")->fetch_assoc();
    $offStats = (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_campaign_stats WHERE utm_source='off-campaign'")->fetch_assoc()['n'];
    $check($offRow !== null && $offRow['attribution'] === null && $offStats === 0, 'With the option off neither the origin nor the statistics are written');

    // Nothing was kept in the session while the option was off: switched on again, the same visitor's
    // booking has no touch of the campaign visit made while it was off.
    $params['attribution_capture'] = 1;
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params)) . "' WHERE element='com_appointments' AND type='component'");
    // Without a referrer from another site now: a touch could only come from what was kept while off.
    $off->headers = [];
    [$browser, $path, $form] = $off->bookingForm($event, $day . ' 15:00:00', ['email' => $address]);
    sleep(2);
    $browser->request($path, $form);
    $time = $db->real_escape_string($day . ' 15:00:00');
    $afterOff = Attribution::decode(appointments_open_booking($db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$event}"
        . " AND start_utc='{$time}'")->fetch_assoc())['attribution'] ?? null);
    $check($afterOff !== null && $afterOff['first_touch'] === null && !str_contains(json_encode($afterOff), 'off-campaign'),
        'With the option off nothing is kept in the session');
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($originalParams) . "' WHERE element='com_appointments' AND type='component'");

    // A deleted event type: its statistics stay, named by the title at booking time, for a component administrator.
    $db->query("DELETE FROM {$prefix}appointments_eventtypes WHERE id={$event}");
    [, $orphanRows] = $csvRows($exportCsv($admin, 'exportCampaigns', 0));
    $orphan = array_values(array_filter($orphanRows, static fn (array $row): bool => (int) $row['eventtype_id'] === $event && $row['utm_source'] === 'newsletter'));
    $check($orphan !== [] && $orphan[0]['eventtype_title'] === $eventTitle && $orphan[0]['cancelled'] === '1',
        'The statistics of a deleted event type stay exportable for a component administrator');
} catch (Throwable $e) {
    $check(false, 'Unexpected ' . $e::class . ': ' . $e->getMessage());
} finally {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($originalParams) . "' WHERE element='com_appointments' AND type='component'");

    if ($article > 0) {
        $db->query("DELETE FROM {$prefix}content WHERE id={$article}");
    }

    if ($event > 0) {
        $db->query("DELETE FROM {$prefix}appointments_campaign_stats WHERE eventtype_id={$event}");
        $db->query("DELETE FROM {$prefix}appointments_campaign_totals WHERE eventtype_id={$event}");
    }

    $fixture->cleanup();
}

echo "\n" . ($failed === 0 ? 'All attribution tests passed' : $failed . ' attribution test(s) failed') . "\n";
exit($failed === 0 ? 0 : 1);
