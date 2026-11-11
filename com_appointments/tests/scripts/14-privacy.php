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
    $staffTag = 'export' . bin2hex(random_bytes(5));
    $staffEmail = $staffTag . '-müller@example.test';
    $requestEmail = $staffTag . '-muller@example.test';
    $db->query("INSERT INTO {$prefix}users (name,username,email,password,block,registerDate,params)
        VALUES ('Export staff','{$staffTag}','" . $db->real_escape_string($staffEmail) . "','',0,UTC_TIMESTAMP(),'{}')");
    $staffId = (int) $db->insert_id;
    $calendarId = 0;
    try {
        $db->query("INSERT INTO {$prefix}appointments_calendars (uri,title,owner_user_id,row_mac,created_utc,modified_utc)
            VALUES ('{$staffTag}','Export bystander calendar',0,REPEAT(CHAR(0),32),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $calendarId = (int) $db->insert_id;
        $db->query("INSERT INTO {$prefix}appointments_devices
            (user_id,name,client,secret_hash,kek_salt,user_key_version,state,rule_version,rule_accepted_utc,created_utc,row_mac)
            VALUES ({$staffId},'Export bystander phone','davx5',UNHEX('" . bin2hex(random_bytes(32)) . "'),
                UNHEX('" . bin2hex(random_bytes(16)) . "'),1,1,'1',UTC_TIMESTAMP(),UTC_TIMESTAMP(),REPEAT(CHAR(0),32))");
        $db->query("INSERT INTO {$prefix}appointments_calendar_grants
            (calendar_id,user_id,role,state,audience,granted_by,row_mac,created_utc,modified_utc)
            VALUES ({$calendarId},{$staffId},3,1,'team',1,REPEAT(CHAR(0),32),UTC_TIMESTAMP(),UTC_TIMESTAMP())");
        $db->query("INSERT INTO {$prefix}appointments_invitations
            (token_hash,user_id,email_hash,state,invited_by,created_utc,expires_utc,row_mac)
            VALUES (UNHEX('" . bin2hex(random_bytes(32)) . "'),{$staffId},REPEAT(CHAR(2),32),2,1,
                UTC_TIMESTAMP(),UTC_TIMESTAMP(),REPEAT(CHAR(0),32))");
        $teamParams = array_replace(json_decode($originalParams, true) ?: [], ['teamcal_enabled' => 1]);
        $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($teamParams)) . "' WHERE element='com_appointments' AND type='component'");
        \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->loadArray($teamParams);
        $team = \Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication();
        $team->audit()->log(\Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent::DEVICE_CREATED,
            ['subject_user_id' => $staffId]);
        $staffUser = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($staffId);
        $fixture->update($firstId, ['invitee_email' => $requestEmail]);
        foreach ([
            ['matching staff address', $staffEmail, $staffUser, true, []],
            ['trimmed Unicode case match', ' ' . mb_strtoupper($staffEmail, 'UTF-8') . ' ', $staffUser, true, []],
            ['distinct accent-equivalent address', $requestEmail, $staffUser, false, [$firstId]],
            ['unrelated address with its own booking', 'invitee@example.test', $staffUser, false, [$secondId]],
            ['null account with its own booking', $requestEmail, null, false, [$firstId]],
        ] as [$label, $address, $account, $staffExpected, $bookingIds]) {
            $request->email = $address;
            $beforeLog = (int) $joomlaDb->setQuery('SELECT COALESCE(MAX(id),0) FROM #__appointments_teamlog')->loadResult();
            $scopeExport = new \Joomla\CMS\Event\Privacy\ExportRequestEvent('onPrivacyExportRequest', ['subject' => $request, 'user' => $account]);
            $plugin->onPrivacyExportRequest($scopeExport);
            $staffDomains = [];
            $exportBookingIds = [];
            $guestItems = 0;
            foreach ($scopeExport->getArgument('result') as $set) {
                foreach ($set as $scopeDomain) {
                    if (str_starts_with($scopeDomain->name, 'appointments_staff_')) {
                        $staffDomains[$scopeDomain->name] = count($scopeDomain->getItems());
                    } elseif ($scopeDomain->name === 'appointments_bookings') {
                        $exportBookingIds = array_map(static fn ($item): int => (int) $item->id, $scopeDomain->getItems());
                    } elseif ($scopeDomain->name === 'appointments_guest_invitations') {
                        $guestItems += count($scopeDomain->getItems());
                    }
                }
            }
            $subjects = $joomlaDb->setQuery("SELECT subject_user_id FROM #__appointments_teamlog WHERE id>{$beforeLog} AND event='privacy.export' ORDER BY id DESC")->loadColumn();
            $check(count($subjects) === 1 && ($staffExpected
                ? count($staffDomains) === 4 && min($staffDomains) >= 1 && (int) $subjects[0] === $staffId
                : $staffDomains === [] && $subjects[0] === null
                    && !str_contains(serialize($scopeExport->getArgument('result')), 'Export bystander phone')),
                'Native privacy export scopes staff domains and audit subject for ' . $label);
            $check($exportBookingIds === $bookingIds && ($address !== 'invitee@example.test' || $guestItems === 1),
                'Native privacy export retains independently address-matched bookings and invitations for ' . $label);
        }
    } finally {
        $fixture->update($firstId, ['invitee_email' => 'invitee@example.test']);
        foreach (['appointments_devices', 'appointments_calendar_grants', 'appointments_invitations'] as $table) {
            $db->query("DELETE FROM {$prefix}{$table} WHERE user_id={$staffId}");
        }
        if ($calendarId > 0) {
            $db->query("DELETE FROM {$prefix}appointments_calendars WHERE id={$calendarId}");
        }
        $db->query("DELETE FROM {$prefix}users WHERE id={$staffId}");
        $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($originalParams) . "' WHERE element='com_appointments' AND type='component'");
        \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->set('teamcal_enabled', (int) (json_decode($originalParams, true)['teamcal_enabled'] ?? 0));
        $request->email = ' INVITEE@example.test ';
    }
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
    require __DIR__ . '/privacy-provenance-cases.php';
} catch (Throwable $e) {
    $check(false, 'Privacy integration failed: ' . $e->getMessage());
} finally {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($originalParams) . "' WHERE element='com_appointments' AND type='component'");
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
