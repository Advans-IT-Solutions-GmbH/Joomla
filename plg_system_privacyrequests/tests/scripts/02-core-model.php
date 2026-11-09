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

privacyAssert(($plugin::getSubscribedEvents()['onBeforeCompileHead'] ?? '') === 'onBeforeCompileHead',
    'The native responsive head hook is subscribed');
$actor = null;
foreach ($db->setQuery('SELECT id FROM #__users WHERE block=0 ORDER BY id')->loadColumn() as $userId) {
    $candidate = $container->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById((int) $userId);
    if ($candidate->authorise('core.admin', 'com_privacy')) {
        $actor = $candidate;
        break;
    }
}
privacyAssert($actor !== null, 'The fixture has a genuine Core-authorized administrator');
$admin = $container->get(\Joomla\CMS\Application\AdministratorApplication::class);
$snapshot = static function () use ($db): string {
    return hash('sha256', serialize([
        hash_file('sha256', JPATH_CONFIGURATION . '/configuration.php'),
        $db->setQuery('SELECT extension_id, enabled, params FROM #__extensions ORDER BY extension_id')->loadAssocList(),
        $db->setQuery('SELECT id, state FROM #__scheduler_tasks ORDER BY id')->loadAssocList(),
        $_COOKIE,
    ]));
};
$before = $snapshot();
$saved = [];
foreach ([$app, $admin] as $application) {
    $input = $application->getInput();
    $saved[] = [$application, $application->getDocument(), $application->getIdentity(),
        $application->getBody(), $input->get('option', null, 'raw'),
        $input->get('view', null, 'raw'), $input->get('id', null, 'raw')];
}
try {
    foreach ([
        ['site', $app, $actor, 'com_privacy', 'request', false, false],
        ['wrong component', $admin, $actor, 'com_users', 'request', false, false],
        ['wrong view', $admin, $actor, 'com_privacy', 'requests', false, false],
        ['unauthorized', $admin, new \Joomla\CMS\User\User(), 'com_privacy', 'request', false, false],
        ['non-HTML', $admin, $actor, 'com_privacy', 'request', true, false],
        ['authorized native request', $admin, $actor, 'com_privacy', 'request', false, true],
    ] as [$label, $application, $identity, $option, $view, $raw, $expected]) {
        $document = $raw ? new \Joomla\CMS\Document\RawDocument() : new \Joomla\CMS\Document\HtmlDocument();
        $application->loadDocument($document);
        $application->loadIdentity($identity);
        $application->getInput()->set('option', $option);
        $application->getInput()->set('view', $view);
        $plugin->setApplication($application);
        $plugin->onBeforeCompileHead();
        $styles = $document->getWebAssetManager()->getAssets('style');
        privacyAssert(isset($styles['plg_system_privacyrequests.native-review']) === $expected,
            "Responsive inline asset guard: $label");
        if ($expected) {
            $css = $styles['plg_system_privacyrequests.native-review']->getOption('content');
            privacyAssert(str_contains($css, '@media (max-width: 991.98px)')
                && substr_count($css, 'body.com_privacy.view-request:has([data-privacyrequests-review])') === 18,
                'Every responsive selector requires the adapter marker, including the measured 768px boundary');
            privacyAssert(str_contains($css, 'th:first-child { inline-size: 45% !important; }'),
                'Narrow Statistics columns override the native 15% width instead of stacking labels letter by letter');
            privacyAssert(!preg_match('/overflow(?:-x)?\s*:\s*hidden|display\s*:\s*none|visibility\s*:\s*hidden/i', $css),
                'Responsive containment does not hide native content or controls');
            $plain = '<form id="item-form"><button type="submit">Native control</button></form>';
            $application->getInput()->set('id', 0);
            $application->setBody($plain);
            $plugin->onAfterRender();
            privacyAssert($application->getBody() === $plain,
                'An authorized view without an eligible request receives no adapter marker');
            $application->getInput()->set('id', (int) $receipt['id']);
            $plugin->onAfterRender();
            privacyAssert(str_contains($application->getBody(), 'data-privacyrequests-review')
                && str_contains($application->getBody(), 'data-privacy-delivery="failed"')
                && str_contains($application->getBody(), '<button type="submit">Native control</button>'),
                'The actual pending-delivery view gains the marker without losing its native controls');
        }
    }
    privacyAssert($snapshot() === $before, 'Head/render hooks leave consent, configuration, mail and Scheduler states unchanged');
} finally {
    foreach ($saved as [$application, $document, $identity, $body, $option, $view, $id]) {
        $application->loadDocument($document);
        $application->loadIdentity($identity);
        $application->setBody($body);
        $application->getInput()->set('option', $option);
        $application->getInput()->set('view', $view);
        $application->getInput()->set('id', $id);
    }
    $plugin->setApplication($app);
}

// Native route admission only: do not dispatch Core Remove or alter its status.
$savedTask = $admin->getInput()->get('task', null, 'raw');
$savedId = $admin->getInput()->get('id', null, 'raw');
$savedOption = $admin->getInput()->get('option', null, 'raw');
$savedForm = $admin->getInput()->post->get('jform', [], 'array');
$savedQueryForm = $admin->getInput()->get('jform', null, 'raw');
$savedHeader = $app->getInput()->server->get('HTTP_X_CSRF_TOKEN', '', 'raw');
$savedIdentity = $admin->getIdentity();
try {
    $app->getInput()->server->set('HTTP_X_CSRF_TOKEN', \Joomla\CMS\Session\Session::getFormToken());
    $admin->loadIdentity($actor);
    $admin->getInput()->set('option', 'com_privacy');
    $admin->getInput()->set('task', 'request.remove');
    $admin->getInput()->set('id', (int) $receipt['id']);
    $plugin->setApplication($admin);
    $db->setQuery('INSERT INTO #__privacy_guest_outcomes (request_id,reviewed_result) VALUES ('
        . (int) $receipt['id'] . ',0)')->execute();
    foreach ([[], ['privacy_remediation_reviewed' => '0']] as $form) {
        $admin->getInput()->set('jform', ['privacy_remediation_reviewed' => '1']);
        $admin->getInput()->post->set('jform', $form);
        $refused = false;
        try {
            $plugin->onAfterRoute();
        } catch (RuntimeException $error) {
            $refused = $error->getCode() === 409
                && $error->getMessage() === \Joomla\CMS\Language\Text::_('PLG_SYSTEM_PRIVACYREQUESTS_REMEDIATION_REQUIRED');
        }
        privacyAssert($refused, 'Missing/false POST remediation acknowledgement cannot be supplied through the query input');
    }
    $admin->getInput()->post->set('jform', ['privacy_remediation_reviewed' => '1']);
    $plugin->onAfterRoute();
    $plugin->onBeforeRespond();
    privacyAssert((int) $db->setQuery('SELECT reviewed_result FROM #__privacy_guest_outcomes WHERE request_id='
        . (int) $receipt['id'])->loadResult() === 0 && $read() === $renewed,
        'Genuine POST acknowledgement admits only retry: no dispatched removal, success, or Core status transition is invented');
} finally {
    $plugin->onBeforeRespond();
    $db->setQuery('DELETE FROM #__privacy_guest_outcomes WHERE request_id=' . (int) $receipt['id'])->execute();
    $admin->loadIdentity($savedIdentity);
    $admin->getInput()->set('task', $savedTask);
    $admin->getInput()->set('id', $savedId);
    $admin->getInput()->set('option', $savedOption);
    $admin->getInput()->set('jform', $savedQueryForm);
    $admin->getInput()->post->set('jform', $savedForm);
    $app->getInput()->server->set('HTTP_X_CSRF_TOKEN', $savedHeader);
    $plugin->setApplication($app);
}

$admission = new \Advans\Plugin\System\PrivacyRequests\Service\Admission($db, (string) $app->get('secret'));
$bucketKeys = [];
$bucketTag = bin2hex(random_bytes(8));
$now = time();
$db->setQuery('CREATE TEMPORARY TABLE ' . $db->quoteName('#__privacy_guest_attempts')
    . ' (subject_hash CHAR(64) PRIMARY KEY, attempts INTEGER NOT NULL, window_start BIGINT NOT NULL)')->execute();
try {
    $values = [];
    for ($i = 0; $i < 101; $i++) {
        $bucketKeys[] = $key = $admission->hash('expiry-test', $bucketTag . ':' . $i);
        $values[] = '(' . $db->quote($key) . ',1,' . ($now - 3600) . ')';
    }
    $db->setQuery('INSERT INTO #__privacy_guest_attempts (subject_hash,attempts,window_start) VALUES '
        . implode(',', $values))->execute();
    $expiredKeys = implode(',', array_map(static fn (string $key): string => $db->quote($key), $bucketKeys));
    $ip = 'expiry-fixture-' . $bucketTag;
    $address = 'expiry-' . $bucketTag . '@example.test';
    $bucketKeys[] = $admission->hash('request:ip', $ip);
    $bucketKeys[] = $admission->hash('request:email', $address);
    privacyAssert($admission->reserve('request', $address, $ip, $now), 'Native atomic admission remains available with expired buckets');
    privacyAssert((int) $db->setQuery('SELECT COUNT(*) FROM #__privacy_guest_attempts WHERE subject_hash IN ('
        . $expiredKeys . ')')->loadResult() >= 1, 'One submission cannot purge more than the bounded 100 expired buckets');
    $admission->reserve('request', $address, $ip, $now);
    privacyAssert((int) $db->setQuery('SELECT COUNT(*) FROM #__privacy_guest_attempts WHERE subject_hash IN ('
        . $expiredKeys . ')')->loadResult() === 0, 'Later submissions can finish the bounded expiry backlog');
} finally {
    $drop = strtolower($db->getServerType()) === 'postgresql'
        ? 'DROP TABLE ' . $db->quoteName('pg_temp.' . $db->replacePrefix('#__privacy_guest_attempts'))
        : 'DROP TEMPORARY TABLE ' . $db->quoteName('#__privacy_guest_attempts');
    $db->setQuery($drop)->execute();
}
