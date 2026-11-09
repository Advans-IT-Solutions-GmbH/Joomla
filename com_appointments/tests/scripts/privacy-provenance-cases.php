<?php

// Focused native plugin-event regressions, not a substitute for Core browser E2E.
$privacyRequests = [];
$staffUserId = 0;
$legacyLogs = [];
$privacyTrigger = null;
$privacyParams = \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments');
$savedTeamEnabled = $privacyParams->get('teamcal_enabled', 0);
$newPrivacyFixture = static function (string $email) use ($joomlaDb, &$privacyRequests) {
    $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
    $request->email = $email;
    $request->request_type = 'remove';
    $request->status = 0;
    $request->requested_at = gmdate('Y-m-d H:i:s');
    if (!$request->store()) {
        throw new RuntimeException('The pending native test receipt could not be stored.');
    }
    $privacyRequests[] = (int) $request->id;
    return $request;
};
$assertIncomplete = static function (callable $remove) use ($application): bool {
    $application->getMessageQueue(true);
    $remove();
    foreach ($application->getMessageQueue() as $message) {
        if ($message['type'] === 'warning'
            && $message['message'] === \Joomla\CMS\Language\Text::_('PLG_PRIVACY_APPOINTMENTS_INCOMPLETE')) {
            return true;
        }
    }
    return false;
};

try {
    $address = 'staff-scope-' . bin2hex(random_bytes(6)) . '@example.test';
    $account = (object) ['name' => 'Privacy scope fixture', 'username' => 'scope-' . bin2hex(random_bytes(8)),
        'email' => $address, 'password' => '', 'block' => 0, 'registerDate' => gmdate('Y-m-d H:i:s'), 'params' => '{}'];
    $joomlaDb->insertObject('#__users', $account, 'id');
    $staffUserId = (int) $account->id;
    $user = \Joomla\CMS\Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($staffUserId);
    $request = $newPrivacyFixture($address);
    $id = (int) $request->id;
    $keys = new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) $application->get('secret'));
    $scope = new \Advans\Component\Appointments\Administrator\Service\PrivacyStaffCleanup($joomlaDb, $keys, $id);
    $privacyParams->set('teamcal_enabled', 1);
    $joomlaDb->setQuery('INSERT INTO #__appointments_user_keys'
        . ' (user_id, public_key, password_salt, pwhash_opslimit, pwhash_memlimit, row_mac, created_utc, modified_utc)'
        . ' VALUES (' . $staffUserId . ", REPEAT(CHAR(1),32), REPEAT(CHAR(2),16),1,1,REPEAT(CHAR(0),32),UTC_TIMESTAMP(),UTC_TIMESTAMP())")->execute();
    $canRemove = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData', ['subject' => $request, 'user' => $user]);
    $plugin->onPrivacyCanRemoveData($canRemove);
    $check($canRemove->getArgument('result')[0]->canRemove && $scope->userId() === $staffUserId,
        'CanRemove captures authenticated request-bound staff scope before the Core user callback');
    $privacyTrigger = $joomlaDb->getPrefix() . 'privacy_scope_hold';
    $joomlaDb->setQuery('CREATE TRIGGER ' . $joomlaDb->quoteName($privacyTrigger)
        . ' BEFORE DELETE ON #__appointments_user_keys FOR EACH ROW BEGIN IF OLD.user_id = ' . $staffUserId
        . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected staff cleanup failure'; END IF; END")->execute();
    $coreUser = $application->bootPlugin('user', 'privacy');
    $coreUser->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData',
        ['subject' => $request, 'user' => $user]));
    $check($user->email !== $address && (int) $joomlaDb->setQuery('SELECT COUNT(*) FROM #__users WHERE email='
        . $joomlaDb->quote($address))->loadResult() === 0,
        'The actual Core privacy user callback pseudonymises the shared User and removes original-email lookup');
    $remove = static function (? \Joomla\CMS\User\User $subject) use ($plugin, $request): void {
        $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData',
            ['subject' => $request, 'user' => $subject]));
    };
    $check($assertIncomplete(static fn () => $remove($user)),
        'Initial enabled removal reports the actual injected staff cleanup failure after Core pseudonymisation');
    foreach ([1, 0] as $enabled) {
        $privacyParams->set('teamcal_enabled', $enabled);
        $retry = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData', ['subject' => $request, 'user' => null]);
        $plugin->onPrivacyCanRemoveData($retry);
        $check($retry->getArgument('result')[0]->canRemove && $scope->userId() === $staffUserId,
            "Repeated CanRemove preserves the original UID with null email lookup, feature=$enabled");
        $check($assertIncomplete(static fn () => $remove(null)),
            "Actual retry cannot forget retained staff work, feature=$enabled");
        \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, $id, true);
        $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $id)
            && (int) $joomlaDb->setQuery('SELECT complete FROM #__appointments_privacy_outcomes WHERE request_id=' . $id)->loadResult() === 0,
            "A caller's stale success cannot overwrite pending staff cleanup, feature=$enabled");
        $joomlaDb->setQuery('UPDATE #__appointments_privacy_outcomes SET complete=1 WHERE request_id=' . $id)->execute();
        $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $id),
            "The completion guard independently refuses a forged outcome flag, feature=$enabled");
    }
    $joomlaDb->setQuery('DROP TRIGGER ' . $joomlaDb->quoteName($privacyTrigger))->execute();
    $privacyTrigger = null;
    $privacyParams->set('teamcal_enabled', 1);
    $remove(null);
    $check(!\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $id)
        && (int) $joomlaDb->setQuery('SELECT COUNT(*) FROM #__appointments_user_keys WHERE user_id=' . $staffUserId)->loadResult() === 0,
        'A genuine repaired native teardown clears staff obligations despite the permanently changed account email');
    $empty = $newPrivacyFixture((string) $user->email);
    $privacyParams->set('teamcal_enabled', 0);
    $emptyCheck = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData',
        ['subject' => $empty, 'user' => $user]);
    $plugin->onPrivacyCanRemoveData($emptyCheck);
    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData',
        ['subject' => $empty, 'user' => $user]));
    $check($emptyCheck->getArgument('result')[0]->canRemove
        && !\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, (int) $empty->id),
        'A registered subject with no staff records acquires no invented cleanup obligation while calendars are disabled');

    $legacy = $newPrivacyFixture('legacy-' . bin2hex(random_bytes(6)) . '@example.test');
    $legacy->status = 1;
    $legacy->store();
    $logsBefore = (int) $joomlaDb->setQuery('SELECT MAX(id) FROM #__action_logs')->loadResult();
    $application->bootComponent('com_privacy')->getMVCFactory()
        ->createModel('Remove', 'Administrator', ['ignore_request' => true])->logRemove($legacy);
    $legacyLogs = $joomlaDb->setQuery('SELECT id FROM #__action_logs WHERE id>' . $logsBefore)->loadColumn();
    $check($legacyLogs !== [], 'The upgrade regression records an actual Core removal log for the legacy request');
    $legacyCheck = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData',
        ['subject' => $legacy, 'user' => null]);
    $plugin->onPrivacyCanRemoveData($legacyCheck);
    $check(!$legacyCheck->getArgument('result')[0]->canRemove
        && $legacyCheck->getArgument('result')[0]->reason === \Joomla\CMS\Language\Text::_('PLG_PRIVACY_APPOINTMENTS_STAFF_SCOPE_FAILED')
        && $joomlaDb->setQuery('SELECT COUNT(*) FROM #__appointments_privacy_staff WHERE request_id=' . (int) $legacy->id)->loadResult() == 0
        && \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, (int) $legacy->id),
        'A legacy removal with neither provenance nor outcome rows requires operator recovery instead of a fresh guest scope');

    $interrupted = $newPrivacyFixture('interrupted-' . bin2hex(random_bytes(6)) . '@example.test');
    $firstAttempt = new \Advans\Component\Appointments\Administrator\Service\PrivacyStaffCleanup($joomlaDb, $keys, (int) $interrupted->id);
    $firstAttempt->remember((string) $interrupted->email, null);
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, (int) $interrupted->id),
        'A captured guest scope without an authenticated removal attempt cannot permit completion');
    $privacyTrigger = $joomlaDb->getPrefix() . 'privacy_initial_outcome_hold';
    $joomlaDb->setQuery('CREATE TRIGGER ' . $joomlaDb->quoteName($privacyTrigger)
        . ' BEFORE INSERT ON #__appointments_privacy_outcomes FOR EACH ROW BEGIN IF NEW.request_id = ' . (int) $interrupted->id
        . " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Injected initial outcome write failure'; END IF; END")->execute();
    $check($assertIncomplete(static function () use ($plugin, $interrupted): void {
        $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData',
            ['subject' => $interrupted, 'user' => null]));
    }) && \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, (int) $interrupted->id)
        && $joomlaDb->setQuery('SELECT complete FROM #__appointments_privacy_outcomes WHERE request_id='
            . (int) $interrupted->id)->loadResult() === null,
        'An actual initial outcome write failure warns and leaves completion blocked without an outcome row');
    $joomlaDb->setQuery('DROP TRIGGER ' . $joomlaDb->quoteName($privacyTrigger))->execute();
    $privacyTrigger = null;
    \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, (int) $interrupted->id, true);
    $joomlaDb->setQuery('UPDATE #__appointments_privacy_outcomes SET complete=1 WHERE request_id=' . (int) $interrupted->id)->execute();
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, (int) $interrupted->id),
        'A forged success cannot replace the missing authenticated removal attempt');
    $firstAttempt->beginAttempt();
    \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, (int) $interrupted->id, true);
    $joomlaDb->setQuery('DELETE FROM #__appointments_privacy_outcomes WHERE request_id=' . (int) $interrupted->id)->execute();
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, (int) $interrupted->id),
        'An authenticated interrupted attempt remains incomplete even with no staff rows and a missing outcome flag');
    $secondAttempt = new \Advans\Component\Appointments\Administrator\Service\PrivacyStaffCleanup($joomlaDb, $keys, (int) $interrupted->id);
    $secondAttempt->beginAttempt();
    try {
        $firstAttempt->finishAttempt();
        $check(false, 'A superseded attempt cannot clear a newer pending attempt');
    } catch (RuntimeException $error) {
        $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, (int) $interrupted->id),
            'A superseded attempt cannot clear a newer pending attempt');
    }
    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData',
        ['subject' => $interrupted, 'user' => null]));
    $check(!\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, (int) $interrupted->id),
        'A genuinely successful current native retry clears the interrupted-attempt obligation');

    $original = $joomlaDb->setQuery('SELECT payload FROM #__appointments_privacy_staff WHERE request_id=' . $id)->loadResult();
    $foreign = $newPrivacyFixture('foreign-' . bin2hex(random_bytes(6)) . '@example.test');
    $joomlaDb->setQuery('INSERT INTO #__appointments_privacy_staff (request_id,payload) VALUES ('
        . (int) $foreign->id . ',' . $joomlaDb->quote($original) . ')')->execute();
    try {
        (new \Advans\Component\Appointments\Administrator\Service\PrivacyStaffCleanup($joomlaDb, $keys, (int) $foreign->id))->userId();
        $check(false, 'Staff provenance cannot be transplanted to a different Core request');
    } catch (UnexpectedValueException $error) {
        $check(true, 'Staff provenance cannot be transplanted to a different Core request');
    }
    $joomlaDb->setQuery('UPDATE #__appointments_privacy_staff SET payload=' . $joomlaDb->quote('tampered')
        . ' WHERE request_id=' . $id)->execute();
    try {
        \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $id);
        $check(false, 'Tampered staff provenance fails closed independently of the success flag');
    } catch (UnexpectedValueException $error) {
        $check(true, 'Tampered staff provenance fails closed independently of the success flag');
    }
    $joomlaDb->setQuery('UPDATE #__appointments_privacy_staff SET payload=' . $joomlaDb->quote($original) . ' WHERE request_id=' . $id)->execute();
    $queue = new \Advans\Component\Appointments\Administrator\Service\PrivacyCleanup($joomlaDb, $keys, $id, $address);
    $values = [];
    for ($i = 1; $i <= 201; $i++) {
        $values[] = '(' . $id . ',' . $i . ',' . $joomlaDb->quote(hash('sha256', 'retry-' . $i)) . ",0,'unreadable')";
    }
    $joomlaDb->setQuery('INSERT INTO #__appointments_privacy_cleanup (request_id,booking_id,cleanup_key,guest,payload) VALUES '
        . implode(',', $values))->execute();
    $queue->retry($address);
    $check((int) $joomlaDb->setQuery('SELECT COUNT(*) FROM #__appointments_privacy_cleanup WHERE request_id=' . $id
        . ' AND retry_order>0')->loadResult() === 200, 'A failed retry claims at most 200 outstanding rows');
    $queue->retry($address);
    $check((int) $joomlaDb->setQuery('SELECT retry_order FROM #__appointments_privacy_cleanup WHERE request_id='
        . $id . ' AND booking_id=201')->loadResult() > 0 && $queue->pending() === 201,
        'The next bounded retry reaches row 201 without discarding any persistent or unreadable obligations');
    \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, $id, true);
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $id),
        'Fair retry progress never turns pending obligations into a success');
} finally {
    if ($privacyTrigger !== null) {
        $joomlaDb->setQuery('DROP TRIGGER ' . $joomlaDb->quoteName($privacyTrigger))->execute();
    }
    $privacyParams->set('teamcal_enabled', $savedTeamEnabled);
    if ($legacyLogs !== []) {
        $joomlaDb->setQuery('DELETE FROM #__action_logs WHERE id IN (' . implode(',', array_map('intval', $legacyLogs)) . ')')->execute();
    }
    foreach ($privacyRequests as $id) {
        foreach (['appointments_privacy_cleanup', 'appointments_privacy_staff', 'appointments_privacy_outcomes', 'privacy_requests'] as $table) {
            $joomlaDb->setQuery('DELETE FROM ' . $joomlaDb->quoteName('#__' . $table)
                . ' WHERE ' . ($table === 'privacy_requests' ? 'id' : 'request_id') . '=' . $id)->execute();
        }
    }
    if ($staffUserId > 0) {
        $joomlaDb->setQuery('DELETE FROM #__appointments_user_keys WHERE user_id=' . $staffUserId)->execute();
        $joomlaDb->setQuery('DELETE FROM #__users WHERE id=' . $staffUserId)->execute();
    }
}
