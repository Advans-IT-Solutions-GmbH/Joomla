<?php

function closePhpReadiness(): array
{
    $ini = php_ini_loaded_file();
    $scanned = php_ini_scanned_files();
    $files = $scanned === false || trim($scanned) === '' ? [] : preg_split('/,\s*/', trim($scanned));
    $hashes = [];
    foreach ($files as $file) {
        $hash = hash_file('sha256', $file);
        if ($hash === false) {
            throw new RuntimeException('Cannot fingerprint a scanned PHP configuration file.');
        }
        $hashes[$file] = $hash;
    }
    $loadedHash = $ini === false ? null : hash_file('sha256', $ini);
    if ($loadedHash === false) {
        throw new RuntimeException('Cannot fingerprint the loaded PHP configuration file.');
    }
    return ['version' => PHP_VERSION, 'ini' => $ini, 'ini_sha256' => $loadedHash,
        'scanned_sha256' => hash('sha256', json_encode([$scanned, $hashes], JSON_THROW_ON_ERROR))];
}

function closePhpCommand(): array
{
    $ini = php_ini_loaded_file();
    return array_merge([PHP_BINARY], $ini === false ? [] : ['-c', $ini], [__FILE__]);
}

function closeAssertChildReadiness(string $file): void
{
    $deadline = microtime(true) + 5;
    do {
        clearstatcache(true, $file);
        if (is_file($file)) {
            if (json_decode(file_get_contents($file), true, 16, JSON_THROW_ON_ERROR) !== closePhpReadiness()) {
                throw new RuntimeException('Child PHP configuration does not match the parent.');
            }
            return;
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Child did not report PHP configuration readiness.');
}

function closeChildExcerpt(string $output): string
{
    $allowed = ['blocked', 'calls', 'erased', 'lock_timeout', 'pending', 'error',
        'true', 'false', 'null', 'RuntimeException', 'JsonException', 'Error', 'TypeError'];
    $output = preg_replace_callback('/[A-Za-z0-9_\\\\.\\/:@%+-]+/',
        static fn (array $match): string => in_array($match[0], $allowed, true) ? $match[0] : '[redacted]',
        $output);
    return substr(preg_replace('/[^\x20-\x7E]/', ' ', $output), 0, 250);
}

function closePhpSelfCheck(): void
{
    $differentIni = tempnam(sys_get_temp_dir(), 'appointments-close-ini-');
    $readiness = tempnam(sys_get_temp_dir(), 'appointments-close-ready-');
    if ($differentIni === false || $readiness === false) {
        throw new RuntimeException('Cannot create the negative PHP configuration fixture.');
    }
    unlink($readiness);
    try {
        if (file_put_contents($differentIni, "; Deliberately different test configuration.\n") === false) {
            throw new RuntimeException('Cannot write the negative PHP configuration fixture.');
        }
        foreach ([false, true] as $different) {
            $command = $different ? [PHP_BINARY, '-c', $differentIni, __FILE__] : closePhpCommand();
            $process = proc_open(array_merge($command, ['--php-readiness', $readiness]),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
            if (!is_resource($process)) {
                throw new RuntimeException('PHP readiness self-check process did not start.');
            }
            $mismatch = false;
            try {
                fclose($pipes[0]);
                try {
                    closeAssertChildReadiness($readiness);
                } catch (RuntimeException $error) {
                    if ($error->getMessage() !== 'Child PHP configuration does not match the parent.') {
                        throw $error;
                    }
                    $mismatch = true;
                }
            } finally {
                fclose($pipes[1]);
                fclose($pipes[2]);
                $exit = proc_close($process);
                if (is_file($readiness)) {
                    unlink($readiness);
                }
            }
            if ($mismatch !== $different || $exit !== 0) {
                throw new RuntimeException('PHP readiness self-check did not detect the expected configuration.');
            }
        }
    } finally {
        unlink($differentIni);
        foreach ([$readiness, $readiness . '.tmp'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
}

function closeScanOnlySelfCheck(): void
{
    $directory = tempnam(sys_get_temp_dir(), 'appointments-close-scan-');
    if ($directory === false) {
        throw new RuntimeException('Cannot create scan-only PHP configuration fixture.');
    }
    unlink($directory);
    if (!mkdir($directory)) {
        throw new RuntimeException('Cannot create scan-only PHP configuration directory.');
    }
    $file = $directory . DIRECTORY_SEPARATOR . 'fixture.ini';
    try {
        if (file_put_contents($file, "; Scan-only readiness fixture.\n") === false) {
            throw new RuntimeException('Cannot write scan-only PHP configuration fixture.');
        }
        $environment = getenv();
        $environment['PHPRC'] = $directory;
        $environment['PHP_INI_SCAN_DIR'] = $directory;
        $process = proc_open([PHP_BINARY, '-c', $directory, __FILE__, '--self-check-scan-only', $file],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
        if (!is_resource($process)) {
            throw new RuntimeException('Scan-only PHP configuration self-check did not start.');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0 || trim($stdout) !== 'PASS scan-only PHP configuration') {
            throw new RuntimeException('Scan-only PHP configuration self-check failed: exit=' . $exit
                . ', stdout=' . closeChildExcerpt($stdout) . ', stderr=' . closeChildExcerpt($stderr));
        }
    } finally {
        if (is_file($file)) {
            unlink($file);
        }
        rmdir($directory);
    }
}

if (($argv[1] ?? '') === '--self-check-scan-only') {
    if (php_ini_loaded_file() !== false || trim((string) php_ini_scanned_files()) !== $argv[2]) {
        throw new RuntimeException('Scan-only fixture did not load exactly the expected configuration.');
    }
    closePhpSelfCheck();
    echo "PASS scan-only PHP configuration\n";
    exit(0);
}

if (in_array($argv[1] ?? '', ['--php-readiness', '--race-erase'], true)) {
    $readiness = $argv[$argv[1] === '--php-readiness' ? 2 : 5];
    if (file_put_contents($readiness . '.tmp', json_encode(closePhpReadiness(), JSON_THROW_ON_ERROR)) === false
        || !rename($readiness . '.tmp', $readiness)) {
        throw new RuntimeException('Child could not publish PHP configuration readiness.');
    }
    if ($argv[1] === '--php-readiness') {
        exit(0);
    }
}
if (($argv[1] ?? '') === '--self-check-php') {
    closePhpSelfCheck();
    closeScanOnlySelfCheck();
    echo "PASS pinned PHP configuration and different-ini negative control\n";
    exit(0);
}

if (($argv[1] ?? '') === '--race-erase') {
    try {
        require __DIR__ . '/appointments-joomla.php';
        $blocked = false;
        $calls = 0;
        $mock = static function () use (&$calls): array {
            $calls++;
            throw new RuntimeException('Unexpected child transport.');
        };
        try {
            (new \Advans\Component\Appointments\Administrator\Service\Close\Queue($joomlaDb,
                \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments'), $mock))->run(1);
        } catch (RuntimeException $error) {
            $blocked = $error->getMessage() === 'Close delivery is already running.';
        }
        $store = new \Advans\Component\Appointments\Administrator\Service\BookingStore($joomlaDb);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $joomlaDb->setQuery('SET SESSION innodb_lock_wait_timeout=1')->execute();
        $lockTimeout = false;
        try {
            $store->erase((int) $argv[2], $now, 'admin', actor: (int) $argv[4]);
        } catch (Throwable $error) {
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                if ((int) $cause->getCode() === 1205) {
                    $lockTimeout = true;
                    break;
                }
            }
            if (!$lockTimeout) {
                throw $error;
            }
        }
        if (!$lockTimeout) {
            throw new RuntimeException('Canonical erasure did not encounter the held database lock.');
        }
        file_put_contents($argv[3], 'lock-timeout');
        $joomlaDb->setQuery('SET SESSION innodb_lock_wait_timeout=15')->execute();
        $result = $store->erase((int) $argv[2], $now, 'admin', actor: (int) $argv[4]);
        echo json_encode(['blocked' => $blocked, 'calls' => $calls, 'erased' => $result['changed'],
            'lock_timeout' => $lockTimeout, 'pending' => !empty($result['_crm_cleanup_error'])], JSON_THROW_ON_ERROR);
        exit(0);
    } catch (Throwable $error) {
        echo json_encode(['error' => get_class($error)], JSON_THROW_ON_ERROR);
        exit(1);
    }
}

closePhpSelfCheck();
closeScanOnlySelfCheck();
require __DIR__ . '/appointments-fixtures.php';
$fixture = new AppointmentsFixture('close');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$ids = [];
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
};
$rateKey = (new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((new JConfig())->secret))
    ->key(\Advans\Component\Appointments\Administrator\Helper\KeyProvider::PURPOSE_RATE_LIMIT);
$rateBuckets = implode(',', array_map(static fn (string $ip): string => "'" . hash_hmac('sha256', 'booking:' . $ip, $rateKey) . "'",
    ['127.0.0.1', '::1']));
$rateRows = $db->query("SELECT id,bucket,window_utc,hits FROM {$prefix}appointments_ratelimit WHERE bucket IN ({$rateBuckets})")->fetch_all(MYSQLI_ASSOC);
$original = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc()['params'];
$params = array_replace(json_decode($original, true) ?: [], [
    'close_enabled' => 0, 'close_origin' => 'https://crm.example.test',
    'close_type' => 'actitype_fixture', 'close_credential_env' => 'SYNTHETIC_CLOSE_KEY',
    'close_fields' => '{"uid":"cf_uid","sequence":"cf_seq","start":"cf_start","end":"cf_end","state":"cf_state","source":"cf_source"}',
    'close_questions' => '{}', 'close_delete_activity' => 1,
]);
$save = static function () use ($db, $prefix, &$params): void {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params))
        . "' WHERE element='com_appointments' AND type='component'");
    \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->loadArray($params);
};
$requests = [];
$activity = [];
$timeout = false;
$foreignOrg = false;
$raceId = 0;
$raceProcess = null;
$racePipes = [];
$raceSignal = null;
$raceReadiness = null;
$requestId = random_int(1000000000, 2000000000);
$transport = static function (string $method, string $path, ?array $payload) use (
    &$requests, &$activity, &$timeout, &$foreignOrg, &$raceId, &$raceProcess, &$racePipes, &$raceSignal,
    &$raceReadiness, $fixture
): array {
    $requests[] = [$method, $path, $payload];
    if ($method === 'GET' && $path === '/api/v1/custom_activity/actitype_fixture/') {
        return ['code' => 200, 'data' => ['id' => 'actitype_fixture', 'organization_id' => $foreignOrg ? 'orga_other' : 'orga_fixture',
            'fields' => array_map(static fn ($name) => [
            'id' => 'cf_' . $name, 'type' => 'text', 'custom_activity_type_id' => 'actitype_fixture',
        ], ['uid', 'seq', 'start', 'end', 'state', 'source'])]];
    }
    if ($path === '/api/v1/data/search/') {
        return ['code' => 200, 'data' => ['data' => [['id' => 'cont_fixture', 'lead_id' => 'lead_fixture',
            'emails' => [['email' => 'invitee@example.test']]]], 'cursor' => null]];
    }
    if ($method === 'POST' && $path === '/api/v1/activity/custom/') {
        if ($raceId > 0) {
            $raceSignal = tempnam(sys_get_temp_dir(), 'appointments-close-race-');
            $raceReadiness = tempnam(sys_get_temp_dir(), 'appointments-close-ready-');
            if ($raceReadiness === false) {
                throw new RuntimeException('Cannot create child PHP readiness fixture.');
            }
            unlink($raceReadiness);
            $raceProcess = proc_open(array_merge(closePhpCommand(),
                ['--race-erase', (string) $raceId, $raceSignal, (string) $fixture->host, $raceReadiness]),
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $racePipes, null, getenv());
            if (!is_resource($raceProcess)) {
                throw new RuntimeException('The canonical race process did not start.');
            }
            fclose($racePipes[0]);
            closeAssertChildReadiness($raceReadiness);
            $deadline = microtime(true) + 10;
            do {
                clearstatcache(true, $raceSignal);
                if (file_get_contents($raceSignal) === 'lock-timeout') {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            if (file_get_contents($raceSignal) !== 'lock-timeout') {
                throw new RuntimeException('The canonical race process did not prove a database lock wait.');
            }
            if (!proc_get_status($raceProcess)['running'] || $fixture->stored($raceId)['anonymised_utc'] !== null) {
                throw new RuntimeException('Canonical erasure was not serialized against the remote write.');
            }
            $raceId = 0;
        }
        $activity = $payload + ['id' => 'acti_fixture'];
        if ($timeout) {
            throw new \RuntimeException('Synthetic timeout after remote write.');
        }
        return ['code' => 200, 'data' => $activity];
    }
    if ($method === 'GET' && $path === '/api/v1/activity/custom/acti_fixture/') {
        return ['code' => 200, 'data' => $activity];
    }
    if ($method === 'PUT') {
        $activity = array_replace($activity, $payload ?? []);
        return ['code' => 200, 'data' => $activity];
    }
    if ($method === 'DELETE') {
        $activity = [];
        return ['code' => 204, 'body' => ''];
    }
    throw new \RuntimeException('Unexpected mock request.');
};

try {
    $db->query("DELETE FROM {$prefix}appointments_ratelimit WHERE bucket IN ({$rateBuckets})");
    require __DIR__ . '/appointments-joomla.php';
    $application->bootComponent('com_appointments');
    $save();
    $event = $fixture->event();
    $db->query("UPDATE {$prefix}appointments_eventtypes SET requires_approval=1 WHERE id={$event}");
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $eventRow = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
    $from = $now->modify('+2 days')->setTime(0, 0);
    $until = $now->modify('+14 days')->setTime(23, 59);
    $nextSlot = static function () use ($joomlaDb, $eventRow, $now, &$from, $until): DateTimeImmutable {
        $slots = (new \Advans\Component\Appointments\Administrator\Service\AvailabilityService($joomlaDb))
            ->slots($eventRow, $now, $from, $until);
        if ($slots === []) {
            throw new RuntimeException('No genuine available slot remains for the Close lifecycle fixture.');
        }
        $from = $slots[0]->modify('+' . ((int) $eventRow['duration_minutes']
            + (int) $eventRow['buffer_after_minutes'] + (int) $eventRow['buffer_before_minutes'] + 1) . ' minutes');
        return $slots[0];
    };
    $local = $fixture->booking($event, $nextSlot()->format('Y-m-d H:i:s'))['booking'];
    $ids[] = (int) $local['id'];
    $queue = new \Advans\Component\Appointments\Administrator\Service\Close\Queue(
        $joomlaDb, \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments'), $transport);
    $store = new \Advans\Component\Appointments\Administrator\Service\BookingStore($joomlaDb);
    $check($queue->run()['disabled'] === 1 && $requests === [], 'Installed default performs no external calls');
    $erased = $store->erase((int) $local['id'], $now, 'admin', actor: $fixture->host);
    $check(empty($erased['_crm_cleanup_error']), 'Never-enrolled local erasure invents no CRM obligation');
    $params['close_enabled'] = 1;
    $save();
    $booking = $fixture->booking($event, $nextSlot()->format('Y-m-d H:i:s'))['booking'];
    $id = (int) $booking['id'];
    $ids[] = $id;
    $metadata = $db->query("SELECT * FROM {$prefix}appointments_close WHERE booking_id={$id}")->fetch_assoc();
    $check($metadata['state'] === 'pending' && !str_contains(json_encode($metadata), 'invitee@example.test'),
        'Canonical HTTP create atomically enqueues metadata, not invitee payload');
    $check($queue->run()['failed'] === 0 && $activity['custom.cf_state'] === 'pending',
        'Mock receives decrypted pending booking through installed worker');
    $store->changeState($id, 1, $now);
    $queue->run();
    $check($activity['custom.cf_state'] === 'confirmed', 'Canonical approval updates existing Activity');
    $rescheduledStart = $nextSlot();
    $store->reschedule($id, $rescheduledStart, $now);
    $queue->run();
    $check($activity['custom.cf_start'] === $rescheduledStart->format('Y-m-d\TH:i:s\Z') && $activity['custom.cf_seq'] === '2',
        'Canonical reschedule updates current encrypted booking without another Activity');
    $store->changeState($id, 2, $now);
    $queue->run();
    $check($activity['custom.cf_state'] === 'cancelled', 'Canonical cancellation updates Activity');
    $params['close_enabled'] = 0;
    $save();
    $erased = $store->erase($id, $now, 'admin', delete: true, actor: $fixture->host);
    $check(!empty($erased['_crm_cleanup_error']) && !$fixture->stored($id),
        'Disabled existing CRM obligation survives canonical hard deletion');
    foreach ([$requestId + 1, $requestId + 2] as $formalId) {
        $db->query("INSERT INTO {$prefix}privacy_requests (id,email,requested_at,status,request_type) VALUES "
            . "({$formalId},'invitee@example.test',UTC_TIMESTAMP(),1,'remove')");
    }
    $queue->associatePrivacy('invitee@example.test', $requestId + 1);
    $queue->associatePrivacy('INVITEE@example.test', $requestId + 2);
    $check($queue->pendingPrivacy($requestId + 1) === 1 && $queue->pendingPrivacy($requestId + 2) === 1,
        'Two later formal requests both discover disabled CRM cleanup after canonical hard deletion');
    $generic = $db->query("SELECT extension_id,enabled FROM {$prefix}extensions"
        . " WHERE type='plugin' AND folder='system' AND element='privacyrequests'")->fetch_assoc();
    $operation = null;
    try {
        if ($generic !== null) {
            $db->query("UPDATE {$prefix}extensions SET enabled=0 WHERE extension_id=" . (int) $generic['extension_id']);
        }
        $privacyAdmin = new AppointmentsHttp(appointments_test_base_url(), 'close-privacy-overlap');
        $privacyAdmin->login();
        $formalId = $requestId + 1;
        $privacyHtml = $privacyAdmin->request('/administrator/index.php?option=com_privacy&view=request&id=' . $formalId);
        $privacyToken = $privacyAdmin->token($privacyHtml);
        $operation = \Advans\Component\Appointments\Administrator\Service\PrivacyOperationLock::acquire(
            $joomlaDb, $formalId, (string) (new JConfig())->secret);
        if ($operation === null) {
            throw new RuntimeException('Cannot acquire the synthetic privacy overlap lock.');
        }
        $callsBeforeOverlap = count($requests);
        $complete = static fn (): string => $privacyAdmin->request(
            '/administrator/index.php?option=com_privacy&task=request.complete&id=' . $formalId,
            [$privacyToken => 1, 'id' => $formalId, 'jform' => ['id' => $formalId]]);
        $overlapHtml = $complete();
        $formalState = static fn (): int => (int) $db->query(
            "SELECT status FROM {$prefix}privacy_requests WHERE id={$formalId}")->fetch_row()[0];
        $check($formalState() === 1 && str_contains($overlapHtml, 'Another privacy operation for this request is still running')
            && count($requests) === $callsBeforeOverlap,
            'Native Complete overlapping disabled Close cleanup is refused by the shared request lock without CRM calls');
        $operation->release();
        $operation = null;
        $pendingHtml = $complete();
        $check($formalState() === 1 && str_contains($pendingHtml, 'Appointments reported incomplete removal.')
            && $queue->pendingPrivacy($formalId) === 1 && count($requests) === $callsBeforeOverlap,
            'After overlap release the independent completion guard still sees pending CRM cleanup with the generic adapter disabled');
        // Native Remove of the second request: only the Close cleanup is outstanding.
        $application->bootComponent('com_privacy');
        $privacyPlugin = $application->bootPlugin('appointments', 'privacy');
        $removal = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
        if (!$removal->load($requestId + 2)) {
            throw new RuntimeException('Cannot load the second formal privacy request.');
        }
        // Test output has already been sent, so the native PHP session cannot start. Joomla's
        // message queue uses an in-memory session for this check only.
        $nativeSession = $application->getSession();
        $application->setSession(new \Joomla\CMS\Session\Session(new \Joomla\Session\Storage\RuntimeStorage()));
        try {
            $application->getMessageQueue(true);
            $canRemove = new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent('onPrivacyCanRemoveData', ['subject' => $removal, 'user' => null]);
            $privacyPlugin->onPrivacyCanRemoveData($canRemove);
            $privacyPlugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent('onPrivacyRemoveData',
                ['subject' => $removal, 'user' => null]));
            $warnings = array_column(array_filter($application->getMessageQueue(true),
                static fn (array $message): bool => $message['type'] === 'warning'), 'message');
        } finally {
            $application->setSession($nativeSession);
        }
        $check($canRemove->getArgument('result')[0]->canRemove
            && in_array(\Joomla\CMS\Language\Text::_('PLG_PRIVACY_APPOINTMENTS_CRM_PENDING'), $warnings, true)
            && !in_array(\Joomla\CMS\Language\Text::_('PLG_PRIVACY_APPOINTMENTS_CLEANUP_FAILED'), $warnings, true)
            && \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId + 2)
            && $queue->pendingPrivacy($requestId + 2) === 1 && count($requests) === $callsBeforeOverlap,
            'Native Remove with only Close cleanup pending names the Close cleanup, not calendar or conference cleanup, and stays incomplete');
    } finally {
        $operation?->release();
        if ($generic !== null) {
            $db->query("UPDATE {$prefix}extensions SET enabled=" . (int) $generic['enabled']
                . ' WHERE extension_id=' . (int) $generic['extension_id']);
        }
    }
    $params['close_enabled'] = 1;
    $save();
    $foreignOrg = true;
    $queue->run();
    $check($activity !== [] && $queue->hasPendingErasure($id),
        'Credential organization change cannot clear cleanup against a different organization');
    $foreignOrg = false;
    $queue->run(20, true);
    $check($activity === [] && !$queue->hasPendingErasure($id), 'Mock cleanup resolves own Activity after booking deletion');
    $check($queue->pendingPrivacy($requestId + 1) === 0 && $queue->pendingPrivacy($requestId + 2) === 0,
        'Verified own cleanup releases both later requests without creating another booking');

    $booking = $fixture->booking($event, $nextSlot()->format('Y-m-d H:i:s'))['booking'];
    $id = (int) $booking['id'];
    $ids[] = $id;
    $timeout = true;
    $queue->run();
    $before = count($requests);
    $queue->run(20, true);
    $check(count($requests) === $before, 'Installed durable uncertain intent prevents blind POST replay');
    $timeout = false;
    $queue->reconcile($id, 'acti_fixture');
    $queue->run();
    $check($db->query("SELECT state FROM {$prefix}appointments_close WHERE booking_id={$id}")->fetch_row()[0] === 'done',
        'Read-only remote reconciliation resumes existing Activity');
    $cleanup = new \Advans\Component\Appointments\Administrator\Service\PrivacyCleanup($joomlaDb,
        new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) (new JConfig())->secret),
        $requestId, 'invitee@example.test');
    $staff = new \Advans\Component\Appointments\Administrator\Service\PrivacyStaffCleanup($joomlaDb,
        new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) (new JConfig())->secret), $requestId);
    $staff->remember('invitee@example.test', null);
    $staff->beginAttempt();
    \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, $requestId, false);
    $erased = $store->erase($id, $now, 'privacy_request', privacyCleanup: $cleanup);
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId)
        && $queue->pendingPrivacy($requestId) === 1, 'Native privacy gate sees request-scoped outstanding CRM cleanup');
    // A stale successful flag must not bypass the independently durable cleanup obligation.
    $db->query("UPDATE {$prefix}appointments_privacy_outcomes SET complete=1 WHERE request_id={$requestId}");
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId),
        'Stale successful outcome cannot hide unfinished remote cleanup');
    $queue->run(20, true, $requestId);
    \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, $requestId, true);
    $check(\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId),
        'Resolved CRM cleanup cannot finish an outstanding authenticated removal attempt');
    $staff->finishAttempt();
    \Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::record($joomlaDb, $requestId, true);
    $check(!\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId),
        'Verified own cleanup allows native Complete gate without completing Core automatically');
    $check(!$queue->hasPendingErasure($id), 'Synthetic final cleanup finishes without another booking or mail action');
    $check(count(array_filter($requests, static fn ($request) => $request[0] === 'POST' && $request[1] === '/api/v1/activity/custom/')) === 2,
        'Exactly one Activity create per synthetic booking despite changes and retries');
    $booking = $fixture->booking($event, $nextSlot()->format('Y-m-d H:i:s'))['booking'];
    $id = (int) $booking['id'];
    $ids[] = $id;
    $raceId = $id;
    $queue->run();
    $deadline = microtime(true) + 20;
    while (($childStatus = proc_get_status($raceProcess))['running'] && microtime(true) < $deadline) {
        usleep(20000);
    }
    if ($childStatus['running']) {
        throw new RuntimeException('The canonical race process did not finish.');
    }
    $childStdout = stream_get_contents($racePipes[1]);
    $childStderr = stream_get_contents($racePipes[2]);
    fclose($racePipes[1]);
    fclose($racePipes[2]);
    proc_close($raceProcess);
    $raceProcess = null;
    $diagnostic = 'Race child exit=' . $childStatus['exitcode'] . ', stdout_bytes=' . strlen($childStdout)
        . ', stderr_bytes=' . strlen($childStderr) . ', stdout=' . closeChildExcerpt($childStdout)
        . ', stderr=' . closeChildExcerpt($childStderr);
    try {
        $child = json_decode($childStdout, true, 16, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        throw new RuntimeException($diagnostic, 0, $error);
    }
    if ($childStatus['exitcode'] !== 0) {
        throw new RuntimeException($diagnostic);
    }
    $check($childStatus['exitcode'] === 0 && $child['blocked'] && $child['calls'] === 0,
        'Actual second-process worker is excluded by the database advisory lock before transport');
    $check($child['lock_timeout'] && $child['erased'] && $child['pending'] && $fixture->stored($id)['anonymised_utc'] !== null,
        'Canonical erasure proves a held-lock timeout then retries after remote-write commit with cleanup retained');
    $queue->run();
    $creates = count(array_filter($requests, static fn ($request) =>
        $request[0] === 'POST' && $request[1] === '/api/v1/activity/custom/'));
    $check(!$queue->hasPendingErasure($id) && $activity === [] && $creates === 3,
        'Post-race worker deletes only the saved own Activity without another create');
    foreach ([0, 3600] as $offset) {
        $rateWindow = gmdate('Y-m-d H:i:s', intdiv(time(), 3600) * 3600 + $offset);
        foreach (explode(',', $rateBuckets) as $bucket) {
            $db->query("INSERT INTO {$prefix}appointments_ratelimit (bucket,window_utc,hits) VALUES ({$bucket},'{$rateWindow}',10)"
                . ' ON DUPLICATE KEY UPDATE hits=10');
        }
    }
    $limitedStart = $nextSlot()->format('Y-m-d H:i:s');
    $limitedClient = new AppointmentsHttp(appointments_test_base_url(), 'close-rate-limit');
    [$limitedBrowser, $limitedPath, $limitedForm] = $limitedClient->bookingForm($event, $limitedStart);
    sleep(2);
    $limitedHtml = $limitedBrowser->request($limitedPath, $limitedForm);
    $limitedRow = $db->query("SELECT id FROM {$prefix}appointments_bookings WHERE eventtype_id={$event}"
        . " AND start_utc='" . $db->real_escape_string($limitedStart) . "' AND state IN (0,1)")->fetch_assoc();
    $check($limitedBrowser->status === 200 && $limitedRow === null
        && str_contains($limitedHtml, 'Too many attempts. Please try again later.'),
        'Booking beyond the unchanged loopback rate limit is still refused');
} catch (Throwable $e) {
    if (str_starts_with($e->getMessage(), 'Race child exit=') || in_array($e->getMessage(), [
        'Child PHP configuration does not match the parent.',
        'Child exited before PHP configuration readiness.',
        'Child did not report PHP configuration readiness.',
    ], true)) {
        echo $e->getMessage() . "\n";
    }
    $check(false, 'Installed Close mock failed: ' . get_class($e) . ' in ' . basename($e->getFile()) . ' at line ' . $e->getLine());
} finally {
    if (is_resource($raceProcess)) {
        proc_terminate($raceProcess);
        foreach ($racePipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($raceProcess);
    }
    if ($raceSignal !== null && is_file($raceSignal)) {
        unlink($raceSignal);
    }
    if ($raceReadiness !== null) {
        foreach ([$raceReadiness, $raceReadiness . '.tmp'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string($original) . "' WHERE element='com_appointments' AND type='component'");
    if ($ids) {
        $db->query("DELETE FROM {$prefix}appointments_close WHERE booking_id IN (" . implode(',', $ids) . ')');
        $db->query("DELETE FROM {$prefix}appointments_audit WHERE booking_id IN (" . implode(',', $ids) . ')');
    }
    $privacyIds = $requestId . ',' . ($requestId + 1) . ',' . ($requestId + 2);
    $db->query("DELETE FROM {$prefix}appointments_privacy_outcomes WHERE request_id IN ({$privacyIds})");
    $db->query("DELETE FROM {$prefix}appointments_privacy_cleanup WHERE request_id IN ({$privacyIds})");
    $db->query("DELETE FROM {$prefix}appointments_privacy_staff WHERE request_id IN ({$privacyIds})");
    $db->query("DELETE FROM {$prefix}privacy_requests WHERE id IN (" . ($requestId + 1) . ',' . ($requestId + 2) . ')');
    $db->query("DELETE FROM {$prefix}appointments_ratelimit WHERE bucket IN ({$rateBuckets})");
    foreach ($rateRows as $row) {
        $db->query("INSERT INTO {$prefix}appointments_ratelimit (id,bucket,window_utc,hits) VALUES ("
            . (int) $row['id'] . ",'" . $db->real_escape_string($row['bucket']) . "','" . $db->real_escape_string($row['window_utc']) . "',"
            . (int) $row['hits'] . ')');
    }
    $fixture->cleanup();
}
exit($failed > 0 ? 1 : 0);
