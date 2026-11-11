<?php

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-mail.php';
$fixture = new AppointmentsFixture('privacy');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$originalParams = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc()['params'];
$staffRows = [];

try {
    $event = $fixture->event();
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    $one = $fixture->booking($event, $day . ' 10:00:00');
    $two = $fixture->booking($event, $day . ' 11:00:00');
    $other = $fixture->booking($event, $day . ' 12:00:00', ['email' => 'other@example.test']);
    $firstId = (int) $one['booking']['id'];
    $secondId = (int) $two['booking']['id'];
    $otherId = (int) $other['booking']['id'];
    $fixture->update($firstId, ['custom_fields' => '{"12":"PRIVATE-ANSWER"}']);
    // The request address is also a guest of another person's booking, next to an address that
    // merely contains it as a substring.
    $fixture->update($otherId, ['guest_emails' => "notinvitee@example.test\nInvitee@Example.test\nbystander@example.test",
        'custom_fields' => '{"12":"OTHER-ANSWER"}']);
    require __DIR__ . '/appointments-joomla.php';
    $application->bootComponent('com_privacy');
    $plugin = $application->bootPlugin('appointments', 'privacy');
    $check($plugin instanceof \Advans\Plugin\Privacy\Appointments\Extension\Appointments
        && is_file('/var/www/html/plugins/privacy/appointments/LICENSE.txt'), 'Installed privacy plugin boots with its installed license');
    $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
    $request->email = ' INVITEE@example.test ';
    $request->status = 1;
    $export = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => null]);
    $plugin->onPrivacyExportRequest($export);
    $domains = $export->getArgument('result')[0];
    $check(count($domains) === 2 && $domains[0]->name === 'appointments_bookings' && count($domains[0]->getItems()) === 2,
        'Real privacy event exports all matching guest bookings, without another address or the audit domain');
    $invitations = $domains[1]->getItems();
    $fields = [];
    foreach ($invitations[0]->getFields() as $field) {
        $fields[$field->name] = $field->value;
    }
    $guestExport = serialize($domains[1]);
    $check($domains[1]->name === 'appointments_guest_invitations' && count($invitations) === 1 && $invitations[0]->id === $otherId
        && $fields['guest_email'] === 'Invitee@Example.test' && $fields['start_utc'] === $other['booking']['start_utc']
        && $fields['event_title'] !== '', 'The guest domain lists the booking in which the address is a guest, with time and event type');
    $check(!str_contains($guestExport, 'other@example.test') && !str_contains($guestExport, 'notinvitee') && !str_contains($guestExport, 'bystander')
        && !str_contains($guestExport, 'OTHER-ANSWER') && !str_contains($guestExport, 'Fixture Invitee'),
        'The guest domain contains nothing of the booking person or the other guests');
    $exported = serialize($domains[0]);
    $check(str_contains($exported, 'PRIVATE-ANSWER') && str_contains($exported, 'custom_answer_12')
        && str_contains($exported, $one['booking']['uid']) && !str_contains($exported, 'other@example.test'),
        'Export contains every stored field and readable custom answers, scoped to the request address');
    $params = array_replace(json_decode($originalParams, true) ?: [], ['removal_block_reason' => 'Configured retention obligation']);
    $blockedPlugin = new \Advans\Plugin\Privacy\Appointments\Extension\Appointments(['name' => 'appointments', 'type' => 'privacy']);
    $blockedPlugin->setApplication($application);
    $blockedPlugin->setDatabase($joomlaDb);
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params)) . "' WHERE element='com_appointments' AND type='component'");
    \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->loadArray($params);
    $canRemove = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData', ['subject' => $request, 'user' => null]);
    $blockedPlugin->onPrivacyCanRemoveData($canRemove);
    $status = $canRemove->getArgument('result')[0];
    $check(!$status->canRemove, 'Configured legal reason explicitly blocks removal');
    $check(str_contains($status->reason, 'Configured retention obligation'), 'Removal refusal displays the configured legal reason');
    $check($fixture->row($firstId)['invitee_email'] !== null, 'Checking legal blocking does not silently remove data');
    $params['removal_block_reason'] = '';
    \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->loadArray($params);
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params)) . "' WHERE element='com_appointments' AND type='component'");
    $before = AppointmentsMail::snapshot();
    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', ['subject' => $request, 'user' => null]));
    $check($fixture->row($firstId)['invitee_email'] === null && $fixture->row($secondId)['invitee_email'] === null
        && $fixture->row($otherId)['invitee_email'] === 'other@example.test', 'Formal removal applies the common erasure to every matching booking only');
    $otherRow = $fixture->row($otherId);
    $check($otherRow['guest_emails'] === "notinvitee@example.test\nbystander@example.test" && $otherRow['anonymised_utc'] === null
        && $otherRow['invitee_name'] === 'Fixture Invitee' && $otherRow['custom_fields'] !== null,
        'Formal removal takes the address out of another person\'s guest list by exact line and keeps that booking');
    $guestAudit = $db->query("SELECT action,path,actor_user_id FROM {$prefix}appointments_audit WHERE booking_id={$otherId}")->fetch_all(MYSQLI_ASSOC);
    $check($guestAudit === [['action' => 'remove_guest', 'path' => 'privacy_request', 'actor_user_id' => null]],
        'The guest removal is audited for the other booking without any address');
    $messages = AppointmentsMail::messagesSince($before);
    $check(count($messages) === 2, 'Formal future erasure sends one host-only cancellation per booking');
    foreach ($messages as $mime) {
        $decoded = AppointmentsMail::decoded($mime);
        $check(str_contains($mime, 'Content-Type: text/calendar') && str_contains($decoded, 'METHOD:CANCEL')
            && !str_contains($decoded, 'invitee@example.test') && !str_contains($decoded, 'PRIVATE-ANSWER'),
            'Privacy cancellation has a calendar and no former invitee data');
    }
    $count = (int) $db->query("SELECT COUNT(*) AS n FROM {$prefix}appointments_audit WHERE booking_id IN ({$firstId},{$secondId}) AND path='privacy_request' AND actor_user_id IS NULL")->fetch_assoc()['n'];
    $check($count === 2, 'Privacy audit records the request path without an operator identity');
    $before = AppointmentsMail::snapshot();
    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', ['subject' => $request, 'user' => null]));
    $check(AppointmentsMail::messagesSince($before) === [], 'Repeated formal removal is idempotent');

    $unicode = $fixture->booking($event, $day . ' 13:00:00');
    $unicodeId = (int) $unicode['booking']['id'];
    $fixture->update($unicodeId, ['invitee_email' => 'müller@example.test']);
    $request->email = 'muller@example.test';
    $export = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => null]);
    $plugin->onPrivacyExportRequest($export);
    $check(count($export->getArgument('result')[0][0]->getItems()) === 0, 'Accent-insensitive database collation cannot export another address');
    $request->email = 'MÜLLER@example.test';
    $export = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => null]);
    $plugin->onPrivacyExportRequest($export);
    $check(count($export->getArgument('result')[0][0]->getItems()) === 1, 'Unicode case-insensitive exact address matching remains complete');
    $wrongAccount = new \Joomla\CMS\User\User();
    $wrongAccount->email = 'muller@example.test';
    $canRemove = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData', ['subject' => $request, 'user' => $wrongAccount]);
    $plugin->onPrivacyCanRemoveData($canRemove);
    $check(!$canRemove->getArgument('result')[0]->canRemove, 'Core account collation mismatch is explicitly blocked before another privacy plugin can remove it');

    // Staff data of an account, with the internet calendars off: blocked periods, host record,
    // hosted event types and owned schedules.
    $tag = 'privacy-staff-' . bin2hex(random_bytes(4));
    $db->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,params)"
        . " VALUES ('Staff {$tag}','{$tag}','{$tag}@example.test','',0,0,UTC_TIMESTAMP(),'{}')");
    $staffId = (int) $db->insert_id;
    $staffRows['user'] = $staffId;
    $db->query("INSERT INTO {$prefix}appointments_blocked (user_id,title,start_utc,end_utc,created_utc) VALUES"
        . " ({$staffId},'PAST-REASON-{$tag}',DATE_SUB(UTC_TIMESTAMP(),INTERVAL 10 DAY),DATE_SUB(UTC_TIMESTAMP(),INTERVAL 9 DAY),UTC_TIMESTAMP()),"
        . " ({$staffId},'FUTURE-REASON-{$tag}',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 9 DAY),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 10 DAY),UTC_TIMESTAMP())");
    $db->query("INSERT INTO {$prefix}appointments_hosts (user_id,feed_token,created_utc,modified_utc) VALUES ({$staffId},'"
        . bin2hex(random_bytes(32)) . "',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $db->query("INSERT INTO {$prefix}appointments_schedules (title,timezone,owner_user_id,published,created_utc,modified_utc)"
        . " VALUES ('SCHEDULE-{$tag}','UTC',{$staffId},1,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $staffRows['schedule'] = (int) $db->insert_id;
    $db->query("INSERT INTO {$prefix}appointments_eventtypes (title,alias,schedule_id,host_user_id,info_layout,info_name,feed_token,created_utc,modified_utc)"
        . " VALUES ('EVENT-{$tag}','{$tag}',{$staffRows['schedule']},{$staffId},'person','NAME-{$tag}','" . bin2hex(random_bytes(32)) . "',UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $staffRows['event'] = (int) $db->insert_id;
    $hostToken = (string) $db->query("SELECT feed_token FROM {$prefix}appointments_hosts WHERE user_id={$staffId}")->fetch_row()[0];
    $staffUser = \Joomla\CMS\User\User::getInstance($staffId);
    $request->email = $tag . '@example.test';
    $export = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => $staffUser]);
    $plugin->onPrivacyExportRequest($export);
    $staffDomains = [];
    foreach ($export->getArgument('result') as $set) {
        foreach ($set as $domain) {
            if (str_starts_with($domain->name, 'appointments_staff_')) {
                $staffDomains[$domain->name] = $domain;
            }
        }
    }
    $staffExport = serialize($staffDomains);
    $check(array_keys($staffDomains) === ['appointments_staff_absences', 'appointments_staff_host', 'appointments_staff_event_types', 'appointments_staff_schedules']
        && count($staffDomains['appointments_staff_absences']->getItems()) === 2 && count($staffDomains['appointments_staff_host']->getItems()) === 1
        && str_contains($staffExport, 'PAST-REASON-' . $tag) && str_contains($staffExport, 'FUTURE-REASON-' . $tag)
        && str_contains($staffExport, 'NAME-' . $tag) && str_contains($staffExport, 'SCHEDULE-' . $tag),
        'A staff account exports its blocked periods with reasons, its host record, hosted event types and owned schedules, with the internet calendars off');
    $check(!str_contains($staffExport, $hostToken) && !str_contains($staffExport, 'feed_token'),
        'The staff export never contains the feed credential');
    // Joomla finds the account by database collation (e.g. without accents): only an exactly matching
    // address makes the staff data the requester's.
    $collated = new \Joomla\CMS\User\User();
    $collated->id = $staffId;
    $collated->email = 'privacy-stäff-' . substr($tag, 14) . '@example.test';
    $request->email = 'privacy-staff-' . substr($tag, 14) . '@example.test';
    $export = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => $collated]);
    $plugin->onPrivacyExportRequest($export);
    $leaked = false;
    foreach ($export->getArgument('result') as $set) {
        foreach ($set as $domain) {
            $leaked = $leaked || str_starts_with($domain->name, 'appointments_staff_absences');
        }
    }
    $check(!$leaked, 'An account whose address does not match the request exactly gets no staff export');
    $request->email = $tag . '@example.test';
    // Joomla runs the removal check before every removal, while it still finds the account. The
    // removal itself here gets no account, as a retry after Joomla pseudonymised it: the scope
    // captured by the check decides.
    $request->id = 900000 + random_int(1, 99999);
    $staffRows['request'] = (int) $request->id;
    $canRemove = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData', ['subject' => $request, 'user' => $staffUser]);
    $plugin->onPrivacyCanRemoveData($canRemove);
    $scope = $db->query("SELECT user_id FROM {$prefix}appointments_privacy_scope WHERE request_id=" . (int) $request->id)->fetch_row();
    $check($canRemove->getArgument('result')[0]->canRemove !== false && $scope !== null && (int) $scope[0] === $staffId,
        'The removal check captures the verified account of the request');
    $application->getMessageQueue(true);
    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData', ['subject' => $request, 'user' => null]));
    $blocked = $db->query("SELECT title, end_utc > UTC_TIMESTAMP() AS future FROM {$prefix}appointments_blocked WHERE user_id={$staffId}")->fetch_all(MYSQLI_ASSOC);
    $check(count($blocked) === 1 && $blocked[0]['title'] === '' && (int) $blocked[0]['future'] === 1,
        'Removal without the account (a retry) deletes past blocked periods and keeps future ones without their reason');
    $host = $db->query("SELECT feed_token FROM {$prefix}appointments_hosts WHERE user_id={$staffId}")->fetch_row();
    $check($host !== null && $host[0] !== $hostToken,
        'Removal revokes the host feed credential and keeps the host row, which bookings lock');
    $hosted = $db->query("SELECT host_user_id, info_name FROM {$prefix}appointments_eventtypes WHERE id={$staffRows['event']}")->fetch_assoc();
    $owned = $db->query("SELECT owner_user_id, title FROM {$prefix}appointments_schedules WHERE id={$staffRows['schedule']}")->fetch_assoc();
    $messages = implode("\n", array_column($application->getMessageQueue(true), 'message'));
    $check((int) $hosted['host_user_id'] === $staffId && $hosted['info_name'] === 'NAME-' . $tag
        && (int) $owned['owner_user_id'] === $staffId && $owned['title'] === 'SCHEDULE-' . $tag
        && str_contains($messages, 'EVENT-' . $tag) && str_contains($messages, 'SCHEDULE-' . $tag),
        'Event types and schedules of the staff account are never changed automatically and are named for review');
} catch (Throwable $e) {
    $check(false, 'Privacy integration failed: ' . $e->getMessage());
} finally {
    if (isset($staffRows['request'])) {
        $db->query("DELETE FROM {$prefix}appointments_privacy_scope WHERE request_id=" . (int) $staffRows['request']);
    }
    if (isset($staffRows['event'])) {
        $db->query("DELETE FROM {$prefix}appointments_eventtypes WHERE id=" . (int) $staffRows['event']);
    }
    if (isset($staffRows['schedule'])) {
        $db->query("DELETE FROM {$prefix}appointments_schedules WHERE id=" . (int) $staffRows['schedule']);
    }
    if (isset($staffRows['user'])) {
        $staffId = (int) $staffRows['user'];
        $db->query("DELETE FROM {$prefix}appointments_blocked WHERE user_id={$staffId}");
        $db->query("DELETE FROM {$prefix}appointments_hosts WHERE user_id={$staffId}");
        $db->query("DELETE FROM {$prefix}users WHERE id={$staffId}");
    }
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($originalParams) . "' WHERE element='com_appointments' AND type='component'");
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
