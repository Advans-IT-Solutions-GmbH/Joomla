<?php
require __DIR__ . '/bootstrap.php';

// Core refuses creation when mailonline is off. Leave that setting unchanged;
// a supported pre-render listener stops every template before transport.
privacyAssert((bool) $app->get('mailonline', 1), 'Native fixture permits Core request creation without changing its configuration');
$intercepted = 0;
$app->getDispatcher()->addListener('onMailBeforeRendering', static function () use (&$intercepted): void {
    $intercepted++;
    throw new RuntimeException('Deliberately unavailable isolated test transport.');
});
$plugin = $app->bootPlugin('privacyrequests', 'system');
privacyAssert($plugin instanceof \Advans\Plugin\System\PrivacyRequests\Extension\PrivacyRequests,
    'Native dependency injection boots the installed plugin');
$service = new \Advans\Plugin\System\PrivacyRequests\Service\GuestRequests(
    $db, $app, new \Advans\Plugin\System\PrivacyRequests\Service\Admission($db, (string) $app->get('secret'))
);
$email = 'ci-native-' . bin2hex(random_bytes(8)) . '@example.test';
privacyAssert($service->request($email), 'Public native administrator RequestModel accepts a pending guest receipt');
privacyAssert($intercepted > 0, 'Native mail-template pre-render hook prevents transport in this test process');
$read = static function () use ($db, $email): array {
    return $db->setQuery('SELECT id,email,requested_at,status,confirm_token FROM #__privacy_requests WHERE email='
        . $db->quote($email))->loadAssocList();
};
$rows = $read();
privacyAssert(count($rows) === 1 && (int) $rows[0]['status'] === 0, 'One genuine Core pending receipt is persisted');
$receipt = $rows[0];
privacyAssert((int) $db->setQuery('SELECT delivery_failed FROM #__privacy_guest_subjects WHERE request_id='
    . (int) $receipt['id'])->loadResult() === 1, 'Unavailable delivery is persisted, not represented as sent');
privacyAssert((int) $db->setQuery('SELECT COUNT(*) FROM #__users WHERE email=' . $db->quote($email))->loadResult() === 0,
    'The guest pathway creates no account');
privacyAssert((int) $db->setQuery('SELECT COUNT(*) FROM #__action_logs WHERE user_id=0 AND item_id='
    . (int) $receipt['id'] . " AND extension='com_privacy.request'"
    . " AND message_language_key='COM_PRIVACY_ACTION_LOG_CREATED_REQUEST'")->loadResult() === 1,
    'Native Core audit persists the guest creation with actor zero');
privacyAssert($service->request($email), 'Renewal uses the same Core request');
$renewed = $read();
privacyAssert(count($renewed) === 1 && $renewed[0]['id'] === $receipt['id']
    && $renewed[0]['requested_at'] === $receipt['requested_at']
    && (int) $renewed[0]['status'] === 0 && $renewed[0]['confirm_token'] !== $receipt['confirm_token'],
    'Failed delivery renewal preserves original receipt and date while rotating its password hash');
$service->confirm($email, '0123456789abcdef0123456789abcdef');
privacyAssert($read() === $renewed, 'An undelivered substitute token cannot confirm or alter the pending request');
