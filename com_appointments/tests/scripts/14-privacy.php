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
    $db->query("UPDATE {$prefix}appointments_bookings SET custom_fields='{\"12\":\"PRIVATE-ANSWER\"}' WHERE id={$firstId}");
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
    $check(count($domains) === 1 && $domains[0]->name === 'appointments_bookings' && count($domains[0]->getItems()) === 2,
        'Real privacy event exports all matching guest bookings, without another address or the audit domain');
    $exported = serialize($domains);
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
    $db->query("UPDATE {$prefix}appointments_bookings SET invitee_email='müller@example.test' WHERE id={$unicodeId}");
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
} catch (Throwable $e) {
    $check(false, 'Privacy integration failed: ' . $e->getMessage());
} finally {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($originalParams) . "' WHERE element='com_appointments' AND type='component'");
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
