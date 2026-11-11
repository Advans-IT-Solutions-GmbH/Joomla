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
    $allowed = ['blocked', 'calls', 'erased', 'lock_timeout', 'linked', 'error',
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
        $mock = static function () use (&$calls): \Closure {
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
        $linked = (int) $joomlaDb->setQuery('SELECT COUNT(*) FROM ' . $joomlaDb->quoteName('#__appointments_close_delivery')
            . ' WHERE booking_id = ' . (int) $argv[2])->loadResult();
        echo json_encode(['blocked' => $blocked, 'calls' => $calls, 'erased' => $result['changed'],
            'lock_timeout' => $lockTimeout, 'linked' => $linked > 0], JSON_THROW_ON_ERROR);
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
$params = [];
$save = static function () use ($db, $prefix, &$params): void {
    $db->query("UPDATE {$prefix}extensions SET params='" . $db->real_escape_string(json_encode($params))
        . "' WHERE element='com_appointments' AND type='component'");
    \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->loadArray($params);
};
$requests = [];
$notes = [];
$timeout = false;
$raceId = 0;
$raceProcess = null;
$racePipes = [];
$raceSignal = null;
$raceReadiness = null;
$requestId = random_int(1000000000, 2000000000);
$transport = static function (\Advans\Component\Appointments\Administrator\Service\Close\Profile $profile) use (
    &$requests, &$notes, &$timeout, &$raceId, &$raceProcess, &$racePipes, &$raceSignal, &$raceReadiness, $fixture
): \Closure {
    return static function (string $method, string $path, ?array $payload) use (
        $profile, &$requests, &$notes, &$timeout, &$raceId, &$raceProcess, &$racePipes, &$raceSignal, &$raceReadiness, $fixture
    ): array {
        $requests[] = [$profile->key, $method, $path, $payload];
        if ($method === 'GET' && $path === '/api/v1/me/') {
            return ['code' => 200, 'data' => ['organizations' => [['id' => 'orga_' . $profile->key]]]];
        }
        if ($path === '/api/v1/data/search/') {
            return ['code' => 200, 'data' => ['data' => [['id' => 'cont_' . $profile->key, 'lead_id' => 'lead_' . $profile->key,
                'emails' => [['email' => 'invitee@example.test']]]], 'cursor' => null]];
        }
        if ($method === 'POST' && $path === '/api/v1/activity/note/') {
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
            $id = 'acti_' . $profile->key . count($notes);
            $notes[$id] = $payload + ['id' => $id];
            if ($timeout) {
                throw new \RuntimeException('Synthetic timeout after remote write.');
            }
            return ['code' => 200, 'data' => $notes[$id]];
        }
        if (preg_match('~^/api/v1/activity/note/(acti_\w+)/$~', $path, $match) && isset($notes[$match[1]])) {
            if ($method === 'PUT') {
                $notes[$match[1]] = array_replace($notes[$match[1]], $payload ?? []);
            }
            return ['code' => 200, 'data' => $notes[$match[1]]];
        }
        throw new \RuntimeException('Unexpected mock request.');
    };
};
$sent = static function (string $method, string $path = '') use (&$requests): int {
    return count(array_filter($requests, static fn ($request) => $request[1] === $method && ($path === '' || $request[2] === $path)));
};

try {
    $db->query("DELETE FROM {$prefix}appointments_ratelimit WHERE bucket IN ({$rateBuckets})");
    require __DIR__ . '/appointments-joomla.php';
    $application->bootComponent('com_appointments');
    $event = $fixture->event();
    $eventQr = $fixture->event();
    $eventNone = $fixture->event();
    $db->query("UPDATE {$prefix}appointments_eventtypes SET requires_approval=1 WHERE id={$event}");
    $profile = static fn (string $key, int $eventType): array => ['key' => $key, 'eventtypes' => [(string) $eventType],
        'origin' => 'https://' . $key . '.example.test', 'credential_env' => 'SYNTHETIC_' . strtoupper($key) . '_KEY',
        'delivery' => 'note', 'campaign' => 'Campaign ' . $key, 'source' => 'Website booking'];
    $params = array_replace(json_decode($original, true) ?: [], ['close_enabled' => 0, 'close_profiles' => [
        'close_profiles0' => $profile('main', $event), 'close_profiles1' => $profile('qr', $eventQr)]]);
    $save();
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $from = $now->modify('+2 days')->setTime(0, 0);
    $until = $now->modify('+14 days')->setTime(23, 59);
    $nextSlot = static function (int $eventId) use ($joomlaDb, $db, $prefix, $now, &$from, $until): DateTimeImmutable {
        $eventRow = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc();
        $slots = (new \Advans\Component\Appointments\Administrator\Service\AvailabilityService($joomlaDb))
            ->slots($eventRow, $now, $from, $until);
        if ($slots === []) {
            throw new RuntimeException('No genuine available slot remains for the Close lifecycle fixture.');
        }
        $from = $slots[0]->modify('+' . ((int) $eventRow['duration_minutes']
            + (int) $eventRow['buffer_after_minutes'] + (int) $eventRow['buffer_before_minutes'] + 1) . ' minutes');
        return $slots[0];
    };
    $book = static function (int $eventId) use ($fixture, $nextSlot, &$ids): array {
        $booking = $fixture->booking($eventId, $nextSlot($eventId)->format('Y-m-d H:i:s'))['booking'];
        $ids[] = (int) $booking['id'];
        return $booking;
    };
    $link = static fn (int $id): ?array => $db->query("SELECT * FROM {$prefix}appointments_close_delivery WHERE booking_id={$id}")->fetch_assoc();
    $queue = new \Advans\Component\Appointments\Administrator\Service\Close\Queue(
        $joomlaDb, \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments'), $transport);
    $store = new \Advans\Component\Appointments\Administrator\Service\BookingStore($joomlaDb);

    $local = $book($event);
    $check($queue->run()['disabled'] === 1 && $requests === [] && $link((int) $local['id']) === null,
        'Installed default enrols nothing and performs no external calls');
    $params['close_enabled'] = 1;
    $save();
    $none = $book($eventNone);
    $check($link((int) $none['id']) === null, 'An event type without a profile is never enrolled');

    $booking = $book($event);
    $id = (int) $booking['id'];
    $metadata = $link($id);
    $check($metadata !== null && $metadata['profile'] === 'main' && $metadata['state'] === 'pending'
        && !str_contains(json_encode($metadata), 'invitee@example.test'),
        'Canonical HTTP create atomically enqueues the routed profile, not the invitee payload');
    $check($queue->run()['failed'] === 0 && count($notes) === 1, 'Installed worker delivers the decrypted pending booking as one note');
    $note = $notes[$link($id)['activity_id']];
    $eventTitle = $db->query("SELECT title FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_row()[0];
    $check($note['lead_id'] === 'lead_main' && $note['contact_id'] === 'cont_main' && str_contains($note['title'], $eventTitle)
        && str_contains($note['note'], $booking['uid']) && str_contains($note['note'], 'Campaign main')
        && !str_contains($note['note'], 'COM_APPOINTMENTS_CLOSE_'),
        'The note is written in the site language with event type, campaign and UID on the existing contact');
    $check(array_unique(array_column($requests, 0)) === ['main'], 'Only the routed profile was contacted');
    $pendingTitle = $note['title'];
    $store->changeState($id, 1, $now);
    $queue->run();
    $rescheduledStart = $nextSlot($event);
    $store->reschedule($id, $rescheduledStart, $now);
    $queue->run();
    $note = $notes[$link($id)['activity_id']];
    $check($sent('POST', '/api/v1/activity/note/') === 1 && $sent('PUT') === 2
        && str_contains($note['note'], $rescheduledStart->setTimezone(new DateTimeZone((string) $application->get('offset', 'UTC')) )->format('Y-m-d H:i')),
        'Approval and reschedule update the same note with the new start');
    $store->changeState($id, 2, $now);
    $queue->run();
    $check($notes[$link($id)['activity_id']]['title'] !== $pendingTitle && $sent('POST', '/api/v1/activity/note/') === 1,
        'Cancellation updates the title and status of the same note');

    $qr = $book($eventQr);
    $queue->run();
    $qrRequests = array_filter($requests, static fn ($request) => $request[0] === 'qr');
    $check(count($qrRequests) === 3 && array_values($qrRequests)[0][2] === '/api/v1/me/' && $link((int) $qr['id'])['profile'] === 'qr' && $link((int) $qr['id'])['state'] === 'done',
        'A second event type is delivered through its own profile');

    $before = count($requests);
    $erased = $store->erase($id, $now, 'admin', delete: true, actor: $fixture->host);
    $check($erased['changed'] && !array_key_exists('_crm_cleanup_error', $erased) && $link($id) === null && !$fixture->stored($id),
        'Hard deletion drops only the local link and reports no Close obligation');
    $queue->run(20, true);
    $check(count($requests) === $before, 'Erasure sends nothing to Close and deletes nothing there');

    // A formal privacy removal never waits for Close.
    $db->query("INSERT INTO {$prefix}privacy_requests (id,email,requested_at,status,request_type) VALUES "
        . "({$requestId},'invitee@example.test',UTC_TIMESTAMP(),1,'remove')");
    $nativeSession = $application->getSession();
    $application->setSession(new \Joomla\CMS\Session\Session(new \Joomla\Session\Storage\RuntimeStorage()));
    try {
        $application->bootComponent('com_privacy');
        $privacyPlugin = $application->bootPlugin('appointments', 'privacy');
        $removal = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
        if (!$removal->load($requestId)) {
            throw new RuntimeException('Cannot load the formal privacy request.');
        }
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
    $check($canRemove->getArgument('result')[0]->canRemove && preg_grep('/Close|CRM/', $warnings) === []
        && !\Advans\Component\Appointments\Administrator\Service\PrivacyOutcome::incomplete($joomlaDb, $requestId)
        && $link((int) $qr['id']) === null && $fixture->stored((int) $qr['id'])['anonymised_utc'] !== null
        && count($requests) === $before,
        'Native privacy Remove of a transferred booking completes without Close and leaves Close untouched');

    $booking = $book($event);
    $id = (int) $booking['id'];
    $timeout = true;
    $queue->run();
    $before = count($requests);
    $queue->run(20, true);
    $check($link($id)['state'] === 'uncertain' && count($requests) === $before, 'Installed durable uncertain intent prevents blind POST replay');
    $timeout = false;
    $queue->reconcile($id, (string) array_key_last($notes));
    $queue->run();
    $check($link($id)['state'] === 'done' && $sent('POST', '/api/v1/activity/note/') === 3,
        'Read-only reconciliation links the existing note without another create');

    // Two connections: a snapshot taken before another connection's committed change must not be
    // authenticated next to the new values when the worker saves the record.
    $queueClass = \Advans\Component\Appointments\Administrator\Service\Close\Queue::class;
    $joomlaDb->transactionStart();
    try {
        $joomlaDb->setQuery('SELECT COUNT(*) FROM ' . $joomlaDb->quoteName('#__appointments_close_delivery'))->loadResult();
        $other = $db->query("SELECT * FROM {$prefix}appointments_close_delivery WHERE booking_id={$id}")->fetch_assoc();
        $other['target_sequence'] = (string) ((int) $other['target_sequence'] + 7);
        $other['mac'] = (new ReflectionMethod($queueClass, 'mac'))->invoke(null, $other);
        $db->query("UPDATE {$prefix}appointments_close_delivery SET target_sequence=" . (int) $other['target_sequence']
            . ", mac='" . $db->real_escape_string($other['mac']) . "' WHERE booking_id={$id}");
        (new ReflectionMethod($queueClass, 'save'))->invoke($queue, $id, ['reason' => 'probe']);
        $joomlaDb->transactionCommit();
    } catch (Throwable $error) {
        $joomlaDb->transactionRollback();
        throw $error;
    }
    $authentic = true;
    try {
        $queue->rows();
    } catch (RuntimeException $error) {
        $authentic = false;
    }
    $check($authentic && (int) $link($id)['target_sequence'] === (int) $other['target_sequence'] && $link($id)['reason'] === 'probe',
        'A save after a concurrent committed change authenticates the current record, not an older snapshot');

    $booking = $book($event);
    $id = (int) $booking['id'];
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
    $check($child['blocked'] && $child['calls'] === 0,
        'Actual second-process worker is excluded by the database advisory lock before transport');
    $check($child['lock_timeout'] && $child['erased'] && !$child['linked'] && $fixture->stored($id)['anonymised_utc'] !== null,
        'Canonical erasure waits for the in-flight send, then drops the local link');
    $before = count($requests);
    $queue->run();
    $check(count($requests) === $before && $sent('POST', '/api/v1/activity/note/') === 4,
        'After the race nothing else is sent and nothing is deleted');
    $methods = array_values(array_unique(array_column($requests, 1)));
    sort($methods);
    $check($methods === ['GET', 'POST', 'PUT'], 'The installed lifecycle never issued DELETE or any other method');
    foreach ([0, 3600] as $offset) {
        $rateWindow = gmdate('Y-m-d H:i:s', intdiv(time(), 3600) * 3600 + $offset);
        foreach (explode(',', $rateBuckets) as $bucket) {
            $db->query("INSERT INTO {$prefix}appointments_ratelimit (bucket,window_utc,hits) VALUES ({$bucket},'{$rateWindow}',10)"
                . ' ON DUPLICATE KEY UPDATE hits=10');
        }
    }
    $limitedStart = $nextSlot($event)->format('Y-m-d H:i:s');
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
    $frames = array_map(static fn (array $frame): string => basename((string) ($frame['file'] ?? '?')) . ':' . ($frame['line'] ?? 0),
        array_slice($e->getTrace(), 0, 8));
    $check(false, 'Installed Close mock failed: ' . get_class($e) . ' in ' . basename($e->getFile()) . ' at line ' . $e->getLine()
        . ' (called from ' . implode(' < ', $frames) . ')');
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
        $db->query("DELETE FROM {$prefix}appointments_close_delivery WHERE booking_id IN (" . implode(',', $ids) . ')');
        $db->query("DELETE FROM {$prefix}appointments_audit WHERE booking_id IN (" . implode(',', $ids) . ')');
    }
    $db->query("DELETE FROM {$prefix}appointments_close_limits");
    $db->query("DELETE FROM {$prefix}appointments_privacy_outcomes WHERE request_id={$requestId}");
    $db->query("DELETE FROM {$prefix}appointments_privacy_cleanup WHERE request_id={$requestId}");
    $db->query("DELETE FROM {$prefix}appointments_privacy_staff WHERE request_id={$requestId}");
    $db->query("DELETE FROM {$prefix}privacy_requests WHERE id={$requestId}");
    $db->query("DELETE FROM {$prefix}appointments_ratelimit WHERE bucket IN ({$rateBuckets})");
    foreach ($rateRows as $row) {
        $db->query("INSERT INTO {$prefix}appointments_ratelimit (id,bucket,window_utc,hits) VALUES ("
            . (int) $row['id'] . ",'" . $db->real_escape_string($row['bucket']) . "','" . $db->real_escape_string($row['window_utc']) . "',"
            . (int) $row['hits'] . ')');
    }
    $fixture->cleanup();
}
exit($failed > 0 ? 1 : 0);
