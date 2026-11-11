<?php
/**
 * Real Joomla upgrade fixture from the published v1.3.0 package.
 *
 * The old release is installed by the Docker image. This script seeds its
 * schema, invokes Joomla's installer with the exact candidate ZIP, and verifies
 * the schema/data migration on the upgraded installation.
 */

define('JOOMLA_ROOT', '/var/www/html');

require_once __DIR__ . '/shared-test-helpers.php';

$phase = $argv[1] ?? '';
$upgradeFailures = 0;

function upgrade_check(mixed $name, mixed $ok, string $detail = ''): void
{
    global $upgradeFailures;

    if (!is_string($name) || !is_bool($ok)) {
        throw new InvalidArgumentException('Upgrade checks require a name followed by a boolean result.');
    }

    if ($ok) {
        echo "PASS $name\n";
        return;
    }

    $upgradeFailures++;
    echo "FAIL $name" . ($detail !== '' ? " - $detail" : '') . "\n";
}

function upgrade_require(bool $condition, string $name, string $detail = ''): void
{
    upgrade_check($name, $condition, $detail);

    if (!$condition) {
        exit(1);
    }
}

function upgrade_archive_entry(string $package, string $entry): ?string
{
    $zip = new ZipArchive();

    if ($zip->open($package) !== true) {
        return null;
    }

    $contents = $zip->getFromName($entry);
    $zip->close();

    return $contents === false ? null : $contents;
}

function upgrade_install_directory(array $manifest): string
{
    return match ($manifest['type']) {
        'component' => JOOMLA_ROOT . '/administrator/components/' . $manifest['element'],
        'plugin'    => JOOMLA_ROOT . '/plugins/' . $manifest['folder'] . '/' . $manifest['element'],
        default     => '',
    };
}

function upgrade_column(mysqli $db, string $table, string $column): ?array
{
    $table = str_replace('`', '``', $table);
    $name  = $db->real_escape_string($column);
    $result = $db->query("SHOW COLUMNS FROM `$table` LIKE '$name'");

    return $result ? ($result->fetch_assoc() ?: null) : null;
}

function upgrade_table_exists(mysqli $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?'
    );
    $statement->bind_param('s', $table);
    $statement->execute();
    $statement->bind_result($count);
    $statement->fetch();
    $statement->close();

    return (int) $count > 0;
}

function upgrade_index_exists(mysqli $db, string $table, string $index): bool
{
    $table = str_replace('`', '``', $table);
    $name  = $db->real_escape_string($index);
    $result = $db->query("SHOW INDEX FROM `$table` WHERE Key_name = '$name'");

    return $result && $result->num_rows > 0;
}

function upgrade_insert(mysqli $db, string $table, array $values): int
{
    $table = str_replace('`', '``', $table);
    $columns = array_map(
        static fn (string $column): string => '`' . str_replace('`', '``', $column) . '`',
        array_keys($values)
    );
    $quoted = array_map(
        static fn (mixed $value): string => $value === null ? 'NULL' : "'" . $db->real_escape_string((string) $value) . "'",
        array_values($values)
    );

    if (!$db->query("INSERT INTO `$table` (" . implode(',', $columns) . ') VALUES (' . implode(',', $quoted) . ')')) {
        throw new RuntimeException('Could not create an upgrade fixture row.');
    }

    return (int) $db->insert_id;
}

function upgrade_normalize_json(mixed $value): array
{
    if ($value instanceof stdClass) {
        $properties = get_object_vars($value);
        ksort($properties, SORT_STRING);

        foreach ($properties as $key => $property) {
            $properties[$key] = upgrade_normalize_json($property);
        }

        return ['object' => $properties];
    }

    if (is_array($value)) {
        return ['array' => array_map('upgrade_normalize_json', $value)];
    }

    return ['scalar' => [get_debug_type($value), $value]];
}

function upgrade_personal_matches(mixed $actual, mixed $expected, string $column): bool
{
    if ($actual === null || $expected === null) {
        return $actual === $expected;
    }

    if ($column !== 'custom_fields') {
        return $actual === $expected;
    }

    try {
        // The 1.3.0 JSON column may normalize whitespace and object-key order.
        return upgrade_normalize_json(json_decode((string) $actual, false, 512, JSON_THROW_ON_ERROR))
            === upgrade_normalize_json(json_decode((string) $expected, false, 512, JSON_THROW_ON_ERROR));
    } catch (JsonException) {
        return false;
    }
}

function upgrade_runtime_version(): string
{
    $source = (string) @file_get_contents(JOOMLA_ROOT . '/libraries/src/Version.php');
    preg_match_all('/const (MAJOR|MINOR|PATCH)_VERSION = (\d+);/', $source, $parts);
    $versions = array_combine($parts[1] ?: [], $parts[2] ?: []);

    return $versions ? implode('.', [$versions['MAJOR'], $versions['MINOR'], $versions['PATCH']]) : 'unknown';
}

function upgrade_seed(): void
{
    $previousPackage = getenv('PREVIOUS_PACKAGE') ?: '/tmp/extension-previous.zip';
    $candidatePackage = getenv('CANDIDATE_PACKAGE') ?: '/tmp/extension-candidate-reference.zip';
    $expectedPreviousHash = getenv('PREVIOUS_PACKAGE_SHA256') ?: '';
    $expectedCandidateHash = getenv('CANDIDATE_PACKAGE_SHA256') ?: '';
    $previousTag = getenv('PREVIOUS_RELEASE_TAG') ?: '';

    echo 'Tested versions: Joomla ' . upgrade_runtime_version() . ', PHP ' . PHP_VERSION . "\n";

    upgrade_require(is_file($previousPackage), 'published previous package is present');
    upgrade_require(is_file($candidatePackage), 'candidate package is present');

    $previousHash = hash_file('sha256', $previousPackage);
    $candidateHash = hash_file('sha256', $candidatePackage);
    upgrade_require($previousHash !== false && hash_equals($expectedPreviousHash, $previousHash), 'previous package matches the published asset digest');
    upgrade_require($candidateHash !== false && hash_equals($expectedCandidateHash, $candidateHash), 'candidate package matches the downloaded build artifact digest');
    upgrade_require($previousTag === 'appointments-v1.3.0', 'source tag is appointments-v1.3.0', $previousTag);

    $previousManifest = sh_read_package_manifest($previousPackage);
    $candidateManifest = sh_read_package_manifest($candidatePackage);
    upgrade_require($previousManifest !== null && $candidateManifest !== null, 'both package manifests can be read');
    upgrade_require($previousManifest['version'] === '1.3.0', 'previous package manifest is version 1.3.0', $previousManifest['version']);
    upgrade_require($previousManifest['type'] === 'component' && $previousManifest['element'] === 'com_appointments', 'previous package is com_appointments');
    upgrade_require($candidateManifest['type'] === 'component' && $candidateManifest['element'] === 'com_appointments', 'candidate package is com_appointments');

    $db = sh_db();
    $extensionId = sh_find_extension_id($previousManifest);
    upgrade_require($extensionId > 0, 'previous release component is installed');

    $extensions = sh_table('extensions');
    $installed = $db->query("SELECT manifest_cache, params FROM `$extensions` WHERE extension_id = $extensionId")->fetch_assoc();
    $cache = $installed ? json_decode((string) $installed['manifest_cache'], true) : null;
    upgrade_require(is_array($cache) && ($cache['version'] ?? '') === '1.3.0', 'installed component is version 1.3.0');

    $installDirectory = upgrade_install_directory($previousManifest);
    $installedManifest = $installDirectory . '/' . basename($previousManifest['xmlfile']);
    $previousManifestContents = upgrade_archive_entry($previousPackage, $previousManifest['xmlfile']);
    $previousScriptContents = upgrade_archive_entry($previousPackage, 'script.php');
    upgrade_require($previousManifestContents !== null && is_file($installedManifest)
        && hash_file('sha256', $installedManifest) === hash('sha256', $previousManifestContents), 'installed manifest matches the published previous package');
    upgrade_require($previousScriptContents !== null && is_file($installDirectory . '/script.php')
        && hash_file('sha256', $installDirectory . '/script.php') === hash('sha256', $previousScriptContents), 'installed installer script matches the published previous package');

    $prefix = (new JConfig())->dbprefix;
    $bookingsTable = $prefix . 'appointments_bookings';
    $eventtypesTable = $prefix . 'appointments_eventtypes';
    $legacyName = upgrade_column($db, $bookingsTable, 'invitee_name');
    $legacyFields = upgrade_column($db, $bookingsTable, 'custom_fields');
    upgrade_require($legacyName !== null && str_starts_with(strtolower((string) $legacyName['Type']), 'varchar('), 'previous booking name column is still plaintext varchar');
    upgrade_require($legacyFields !== null && strtolower((string) $legacyFields['Type']) === 'json', 'previous custom-fields column is still JSON');
    upgrade_require(upgrade_column($db, $eventtypesTable, 'calendar_id') === null, 'previous schema has no team-calendar event-type column');
    upgrade_require(!upgrade_table_exists($db, $prefix . 'appointments_calendars'), 'previous schema has no team-calendar tables');
    upgrade_require(upgrade_index_exists($db, $bookingsTable, 'idx_invitee_email'), 'previous schema still has the plaintext e-mail index');

    $params = json_decode((string) ($installed['params'] ?? ''), true);
    upgrade_require(is_array($params) && !array_key_exists('legacy_feeds', $params), 'previous installation has no new legacy-feed option');
    upgrade_require((int) $db->query("SELECT COUNT(*) FROM `$bookingsTable`")->fetch_row()[0] === 0, 'previous installation starts with no bookings');

    $username = $db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
    $usersTable = $prefix . 'users';
    $admin = $db->query("SELECT id FROM `$usersTable` WHERE username = '$username'")->fetch_assoc();
    upgrade_require($admin !== null, 'test administrator exists');
    $hostId = (int) $admin['id'];
    $now = gmdate('Y-m-d H:i:s');
    $created = gmdate('Y-m-d H:i:s', time() - 10 * 86400);
    $suffix = bin2hex(random_bytes(6));
    $scheduleTitle = 'Upgrade fixture ' . $suffix;
    $scheduleId = upgrade_insert($db, $prefix . 'appointments_schedules', [
        'title' => $scheduleTitle,
        'timezone' => 'UTC',
        'owner_user_id' => $hostId,
        'published' => 1,
        'created_utc' => $now,
        'modified_utc' => $now,
    ]);

    for ($weekday = 1; $weekday <= 7; $weekday++) {
        upgrade_insert($db, $prefix . 'appointments_schedule_rules', [
            'schedule_id' => $scheduleId,
            'weekday' => $weekday,
            'start_time' => '09:00:00',
            'end_time' => '17:00:00',
        ]);
    }

    $feedToken = bin2hex(random_bytes(32));
    $eventtypeId = upgrade_insert($db, $eventtypesTable, [
        'title' => $scheduleTitle,
        'alias' => 'upgrade-v130-' . $suffix,
        'schedule_id' => $scheduleId,
        'host_user_id' => $hostId,
        'feed_token' => $feedToken,
        'created_utc' => $now,
        'modified_utc' => $now,
    ]);

    $bookings = [];
    foreach ([
        'confirmed' => ['state' => 1, 'start' => '2030-01-15 09:00:00', 'cancel_reason' => null],
        'cancelled' => ['state' => 2, 'start' => '2030-01-15 10:00:00', 'cancel_reason' => 'Cancelled before the upgrade'],
    ] as $kind => $state) {
        $uid = bin2hex(random_bytes(32));
        $personal = [
            'invitee_name' => 'Legacy ' . ucfirst($kind) . ' Invitee',
            'invitee_first_name' => 'Legacy',
            'invitee_last_name' => ucfirst($kind) . ' Invitee',
            'invitee_email' => 'upgrade-' . $kind . '@example.test',
            'invitee_phone' => '555-010' . ($kind === 'confirmed' ? '1' : '2'),
            'guest_emails' => 'guest-' . $kind . '@example.test',
            'custom_fields' => json_encode(['source' => 'published-v1.3.0', 'kind' => $kind, 'numeric_string' => '0'], JSON_THROW_ON_ERROR),
            'cancel_reason' => $state['cancel_reason'],
            'conference_url' => 'https://meeting.example.test/upgrade-' . $kind,
        ];
        $core = [
            'uid' => $uid,
            'sequence' => 3,
            'eventtype_id' => $eventtypeId,
            'host_user_id' => $hostId,
            'start_utc' => $state['start'],
            'end_utc' => gmdate('Y-m-d H:i:s', strtotime($state['start'] . ' UTC') + 1800),
            'state' => $state['state'],
            'invitee_timezone' => 'Europe/Zurich',
            'invitee_language' => 'en-GB',
            'consent_utc' => $created,
            'consent_version' => hash('sha256', 'appointments-upgrade-v1.3.0-consent'),
            'conference_provider' => 'generic',
            'conference_room' => 'upgrade-fixture-room',
            'conference_state' => 'created',
            'created_utc' => $created,
            'modified_utc' => $created,
        ];
        $bookingId = upgrade_insert($db, $bookingsTable, $core + $personal);
        $bookings[$kind] = ['id' => $bookingId, 'core' => $core, 'personal' => $personal];
    }

    // Keep the actual 1.3 bookings untouched during install to test both migration-window reads and encrypt-now.
    $migrationTrigger = 'appointments_upgrade_hold_' . bin2hex(random_bytes(6));
    upgrade_require($db->query("CREATE TRIGGER `$migrationTrigger` BEFORE UPDATE ON `$bookingsTable` FOR EACH ROW"
        . " BEGIN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Upgrade fixture holds automatic migration'; END") === true,
        'automatic migration hold is installed for the upgrade fixture');

    // A page the previous version let «System - Page Cache» store (file cache handler of the image).
    $pageCacheEntry = JOOMLA_ROOT . '/cache/page/upgrade-v130-' . bin2hex(random_bytes(6)) . '.php';
    upgrade_require((is_dir(dirname($pageCacheEntry)) || mkdir(dirname($pageCacheEntry), 0755, true))
        && file_put_contents($pageCacheEntry, "<?php die(\"Direct Access Not Permitted\"); ?>\n#x#cached page") !== false,
        'a page cached by the previous version is present before the update');

    $fixture = [
        'extension_id' => $extensionId,
        'schedule_id' => $scheduleId,
        'eventtype_id' => $eventtypeId,
        'feed_token' => $feedToken,
        'migration_trigger' => $migrationTrigger,
        'page_cache_entry' => $pageCacheEntry,
        'bookings' => $bookings,
    ];
    $fixturePath = sys_get_temp_dir() . '/appointments-upgrade-v1.3.0.json';
    $written = file_put_contents($fixturePath, json_encode($fixture, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    upgrade_require($written !== false, 'fixture state is saved for post-update verification');

    echo "PASS representative v1.3.0 schedule, event type, and confirmed/cancelled plaintext bookings seeded\n";
    echo "Previous release SHA-256: $previousHash\n";
    echo "Candidate package SHA-256: $candidateHash\n";
}

function upgrade_update(): void
{
    $candidatePackage = getenv('CANDIDATE_PACKAGE') ?: '/tmp/extension-candidate-reference.zip';
    upgrade_require(is_file($candidatePackage), 'exact candidate package is present for Joomla CLI update');

    [$code, $output] = sh_cli_install_reviewed_fixture(JOOMLA_ROOT, $candidatePackage);
    $clean = sh_strip_ansi($output);
    echo $clean . "\n";

    upgrade_check('Joomla CLI update exits successfully', $code === 0, 'exit code ' . $code);
    $hasErrors = preg_match(
        '/\[ERROR\]|\b(?:Fatal error|Uncaught (?:Error|Exception)|(?:TypeError|Error|Exception):|Call to undefined (?:method|function))/i',
        $clean
    ) === 1;
    upgrade_check('installer output contains no errors', !$hasErrors);
    $rawKeys = sh_find_raw_language_keys($clean);
    upgrade_check('installer output contains no untranslated language keys', $rawKeys === [], implode(', ', $rawKeys));

    global $upgradeFailures;
    exit($upgradeFailures === 0 ? 0 : 1);
}

function upgrade_verify(): void
{
    global $upgradeFailures;

    $candidatePackage = getenv('CANDIDATE_PACKAGE') ?: '/tmp/extension-candidate-reference.zip';
    $expectedCandidateHash = getenv('CANDIDATE_PACKAGE_SHA256') ?: '';
    $fixturePath = sys_get_temp_dir() . '/appointments-upgrade-v1.3.0.json';
    upgrade_require(is_file($fixturePath), 'previous-release fixture state is present');
    upgrade_require(is_file($candidatePackage), 'exact candidate package is present after installation');

    $fixture = json_decode((string) file_get_contents($fixturePath), true, 512, JSON_THROW_ON_ERROR);
    $db = sh_db();
    $migrationTrigger = (string) ($fixture['migration_trigger'] ?? '');
    upgrade_require(preg_match('/^appointments_upgrade_hold_[a-f0-9]{12}$/D', $migrationTrigger) === 1,
        'upgrade fixture has a valid migration hold trigger');
    upgrade_require($db->query("DROP TRIGGER IF EXISTS `$migrationTrigger`") === true,
        'automatic migration hold is removed before post-upgrade behavior checks');
    $pageCacheEntry = (string) ($fixture['page_cache_entry'] ?? '');
    clearstatcache(true);
    upgrade_check('the update empties the page cache, so no page stored by the previous version is served',
        str_starts_with($pageCacheEntry, JOOMLA_ROOT . '/cache/page/') && !is_file($pageCacheEntry));
    $candidateHash = hash_file('sha256', $candidatePackage);
    upgrade_check('candidate package digest remains unchanged',
        $candidateHash !== false && hash_equals($expectedCandidateHash, $candidateHash));
    $manifest = sh_read_package_manifest($candidatePackage);
    upgrade_require($manifest !== null, 'candidate manifest can be read');

    $extensionId = sh_find_extension_id($manifest);
    upgrade_check('component keeps its installed extension ID', $extensionId === (int) $fixture['extension_id']);
    $installDirectory = upgrade_install_directory($manifest);
    $manifestContents = upgrade_archive_entry($candidatePackage, $manifest['xmlfile']);
    $scriptContents = upgrade_archive_entry($candidatePackage, 'script.php');
    $installedManifest = $installDirectory . '/' . basename($manifest['xmlfile']);
    upgrade_check('installed manifest matches the exact candidate ZIP',
        $manifestContents !== null && is_file($installedManifest)
        && hash_file('sha256', $installedManifest) === hash('sha256', $manifestContents));
    upgrade_check('installed installer script matches the exact candidate ZIP',
        $scriptContents !== null && is_file($installDirectory . '/script.php')
        && hash_file('sha256', $installDirectory . '/script.php') === hash('sha256', $scriptContents));

    $prefix = (new JConfig())->dbprefix;
    $bookingsTable = $prefix . 'appointments_bookings';
    $eventtypesTable = $prefix . 'appointments_eventtypes';
    $nameColumn = upgrade_column($db, $bookingsTable, 'invitee_name');
    $customFieldsColumn = upgrade_column($db, $bookingsTable, 'custom_fields');
    upgrade_check('1.4.0 migration changes personal name storage to TEXT',
        $nameColumn !== null && strtolower((string) $nameColumn['Type']) === 'text');
    upgrade_check('1.4.0 migration changes custom fields from JSON to MEDIUMTEXT',
        $customFieldsColumn !== null && strtolower((string) $customFieldsColumn['Type']) === 'mediumtext');
    upgrade_check('1.4.0 migration removes the plaintext e-mail index',
        !upgrade_index_exists($db, $bookingsTable, 'idx_invitee_email'));
    $calendarColumn = upgrade_column($db, $eventtypesTable, 'calendar_id');
    upgrade_check('1.4.0 migration adds the optional target-calendar column',
        $calendarColumn !== null && $calendarColumn['Null'] === 'YES');

    foreach (['appointments_calendars', 'appointments_calendar_grants', 'appointments_devices', 'appointments_teamlog'] as $table) {
        upgrade_check('team-calendar schema table exists: ' . $table, upgrade_table_exists($db, $prefix . $table));
    }

    $schemasTable = $prefix . 'schemas';
    $schemaVersions = [];
    if (upgrade_table_exists($db, $schemasTable)) {
        $result = $db->query("SELECT version_id FROM `$schemasTable` WHERE extension_id = " . (int) $fixture['extension_id']);
        $schemaVersions = $result ? array_map('strval', array_column($result->fetch_all(MYSQLI_ASSOC), 'version_id')) : [];
    }
    upgrade_check('Joomla records the 1.4.0 database schema update',
        in_array('1.4.0', $schemaVersions, true), implode(', ', $schemaVersions));

    $eventId = (int) $fixture['eventtype_id'];
    $event = $db->query("SELECT schedule_id, host_user_id, feed_token, calendar_id FROM `$eventtypesTable` WHERE id = $eventId")->fetch_assoc();
    upgrade_check('event type, schedule, host, and legacy feed token are preserved',
        $event !== null
        && (int) $event['schedule_id'] === (int) $fixture['schedule_id']
        && (int) $event['host_user_id'] === (int) ($fixture['bookings']['confirmed']['core']['host_user_id'])
        && hash_equals((string) $fixture['feed_token'], (string) $event['feed_token'])
        && $event['calendar_id'] === null);
    $rulesTable = $prefix . 'appointments_schedule_rules';
    $ruleCount = (int) $db->query("SELECT COUNT(*) FROM `$rulesTable` WHERE schedule_id = " . (int) $fixture['schedule_id'])->fetch_row()[0];
    upgrade_check('all seven previous schedule rules are preserved', $ruleCount === 7);

    $extensionsTable = $prefix . 'extensions';
    $paramsRow = $db->query("SELECT params FROM `$extensionsTable` WHERE extension_id = " . (int) $fixture['extension_id'])->fetch_assoc();
    $params = $paramsRow ? json_decode((string) $paramsRow['params'], true) : null;
    upgrade_require(is_array($params), 'component options remain readable after upgrade');
    upgrade_check('existing token feeds remain enabled after upgrade', ($params['legacy_feeds'] ?? null) === '1');

    require_once __DIR__ . '/appointments-bookingdata.php';
    $keys = new \Advans\Component\Appointments\Administrator\Helper\KeyProvider((string) (new JConfig())->secret);
    $token = (string) (is_array($params) ? ($params[\Advans\Component\Appointments\Administrator\Helper\BookingData::MIGRATION_OPTION] ?? '') : '');
    $validCutoff = \Advans\Component\Appointments\Administrator\Helper\BookingData::migrationCutoff($token, $keys, time());
    upgrade_check('unfinished migration retains its authenticated seven-day window', $validCutoff !== null);
    $windowData = \Advans\Component\Appointments\Administrator\Helper\BookingData::forToken($keys, $token);

    foreach ($fixture['bookings'] as $kind => $expected) {
        $bookingId = (int) $expected['id'];
        $row = $db->query("SELECT * FROM `$bookingsTable` WHERE id = $bookingId")->fetch_assoc();
        if ($row === null) {
            upgrade_check("$kind previous booking remains present", false);
            continue;
        }

        $corePreserved = true;
        foreach (['uid', 'sequence', 'eventtype_id', 'host_user_id', 'start_utc', 'end_utc', 'state', 'created_utc', 'modified_utc'] as $column) {
            if ((string) $row[$column] !== (string) $expected['core'][$column]) {
                $corePreserved = false;
                break;
            }
        }
        upgrade_check("$kind booking identity, status, and timing are preserved", $corePreserved);

        $opened = null;
        try {
            $opened = $windowData->open($row);
        } catch (Throwable) {
            // Report the field-level migration assertion below without exposing values.
        }

        $personalPreserved = $opened !== null;
        foreach ($expected['personal'] as $column => $value) {
            if ($value === null) {
                $personalPreserved = $personalPreserved && $row[$column] === null;
                continue;
            }

            $personalPreserved = $personalPreserved
                && upgrade_personal_matches($row[$column] ?? null, $value, $column)
                && !\Advans\Component\Appointments\Administrator\Helper\BookingData::isSealed($row[$column])
                && upgrade_personal_matches($opened[$column] ?? null, $value, $column);
        }
        upgrade_check("$kind previous booking remains plaintext and readable during the migration window", $personalPreserved);
    }

    $expiredCutoff = time() - 8 * 86400;
    $expiredToken = \Advans\Component\Appointments\Administrator\Helper\BookingData::migrationToken(
        $keys,
        $expiredCutoff + \Advans\Component\Appointments\Administrator\Helper\BookingData::MIGRATION_DAYS * 86400,
        $expiredCutoff
    );
    $params[\Advans\Component\Appointments\Administrator\Helper\BookingData::MIGRATION_OPTION] = $expiredToken;
    upgrade_require($db->query("UPDATE `$extensionsTable` SET params = '" . $db->real_escape_string(json_encode($params, JSON_THROW_ON_ERROR))
        . "' WHERE extension_id = " . (int) $fixture['extension_id']) === true, 'expired migration token is installed for the fixture');
    $expiredData = \Advans\Component\Appointments\Administrator\Helper\BookingData::forToken($keys, $expiredToken);
    upgrade_check('the authentic migration token expires after seven days without losing its original cutoff',
        \Advans\Component\Appointments\Administrator\Helper\BookingData::migrationCutoff($expiredToken, $keys, time()) === null
        && \Advans\Component\Appointments\Administrator\Helper\BookingData::tokenCutoff($expiredToken, $keys) === $expiredCutoff);

    foreach ($fixture['bookings'] as $kind => $expected) {
        $bookingId = (int) $expected['id'];
        $row = $db->query("SELECT * FROM `$bookingsTable` WHERE id = $bookingId")->fetch_assoc();
        $opened = null;

        try {
            $opened = $row === null ? null : $expiredData->open($row);
        } catch (Throwable) {
            // Report the field-level assertion below without exposing values.
        }

        $readable = $opened !== null && !empty($opened[\Advans\Component\Appointments\Administrator\Helper\BookingData::UNVERIFIED]);
        foreach ($expected['personal'] as $column => $value) {
            $readable = $readable
                && upgrade_personal_matches($row[$column] ?? null, $value, $column)
                && upgrade_personal_matches($opened[$column] ?? null, $value, $column);
        }
        upgrade_check("$kind previous booking remains readable but unverified after the migration window", $readable);
    }

    require_once __DIR__ . '/appointments-http.php';
    $admin = new AppointmentsHttp('http://localhost', 'upgrade-v130-admin');
    $admin->login();
    $list = $admin->request('/administrator/index.php?option=com_appointments&view=bookings&filter_eventtype_id=' . (int) $fixture['eventtype_id']);
    $bookingVisible = str_contains($list, 'Legacy Confirmed Invitee');
    $unverifiedVisible = str_contains($list, 'Not verified');
    $encryptActionVisible = str_contains($list, 'bookings.encrypt');
    upgrade_require($admin->status === 200 && $bookingVisible && $unverifiedVisible && $encryptActionVisible,
        'administrator sees the upgraded legacy bookings and the encrypt action',
        'HTTP ' . $admin->status . '; booking=' . ($bookingVisible ? 'yes' : 'no')
        . '; unverified=' . ($unverifiedVisible ? 'yes' : 'no') . '; encrypt=' . ($encryptActionVisible ? 'yes' : 'no'));
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.encrypt', ['task' => 'bookings.encrypt']);
    $unchangedAfterRefusal = true;
    foreach ($fixture['bookings'] as $expected) {
        $row = $db->query("SELECT invitee_email FROM `$bookingsTable` WHERE id = " . (int) $expected['id'])->fetch_assoc();
        $unchangedAfterRefusal = $unchangedAfterRefusal && $row !== null
            && $row['invitee_email'] === $expected['personal']['invitee_email'];
    }
    upgrade_check('encrypt action rejects a missing form token without changing legacy bookings',
        $admin->status === 403 && $unchangedAfterRefusal);
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.encrypt', [$admin->token($list) => 1]);
    upgrade_check('authenticated administrator encrypt action completes', $admin->status === 200, 'HTTP ' . $admin->status);

    $bookingData = new \Advans\Component\Appointments\Administrator\Helper\BookingData($keys);
    $auditTable = $prefix . 'appointments_audit';
    foreach ($fixture['bookings'] as $kind => $expected) {
        $bookingId = (int) $expected['id'];
        $row = $db->query("SELECT * FROM `$bookingsTable` WHERE id = $bookingId")->fetch_assoc();
        $opened = null;
        try {
            $opened = $row === null ? null : $bookingData->open($row);
        } catch (Throwable) {
            // Report the field-level assertion below without exposing values.
        }

        $sealed = $opened !== null;
        $allFieldsSealed = true;
        foreach ($expected['personal'] as $column => $value) {
            if ($value === null) {
                $allFieldsSealed = $allFieldsSealed && $row[$column] === null && $opened[$column] === null;
                continue;
            }

            $allFieldsSealed = $allFieldsSealed && is_string($row[$column]) && str_starts_with($row[$column], 'v2:')
                && upgrade_personal_matches($opened[$column] ?? null, $value, $column);
        }
        $auditResult = $db->query("SELECT action, path, COUNT(*) AS row_count FROM `$auditTable`"
            . " WHERE booking_id = $bookingId GROUP BY action, path");
        $auditCounts = $auditResult ? $auditResult->fetch_all(MYSQLI_ASSOC) : [];
        $auditCount = 0;
        $auditSummary = [];
        foreach ($auditCounts as $auditRow) {
            $count = (int) $auditRow['row_count'];
            $auditSummary[] = (string) $auditRow['action'] . '/' . (string) $auditRow['path'] . ':' . $count;
            if ($auditRow['action'] === 'encrypt' && $auditRow['path'] === 'admin') {
                $auditCount = $count;
            }
        }
        $sealed = $sealed && $allFieldsSealed && $auditCount === 1;
        upgrade_check("$kind previous booking is encrypted as v2, remains readable, and has exactly one administrator audit row",
            $sealed,
            'open=' . ($opened !== null ? 'yes' : 'no')
            . '; fields_v2_and_readable=' . ($allFieldsSealed ? 'yes' : 'no')
            . '; admin_encrypt_audit_rows=' . $auditCount
            . '; all_booking_audit_rows=' . implode(',', $auditSummary));
    }

    $finalParams = $db->query("SELECT params FROM `$extensionsTable` WHERE extension_id = " . (int) $fixture['extension_id'])->fetch_assoc();
    $finalOptions = $finalParams ? json_decode((string) $finalParams['params'], true) : null;
    $finalToken = is_array($finalOptions) ? (string) ($finalOptions[\Advans\Component\Appointments\Administrator\Helper\BookingData::MIGRATION_OPTION] ?? '') : '';
    upgrade_check('administrator encryption leaves the keyed migration completion marker',
        \Advans\Component\Appointments\Administrator\Helper\BookingData::migrationDone($finalToken, $keys));

    echo "\n=== v1.3.0 Upgrade Verification Summary ===\n";
    echo $upgradeFailures === 0 ? "All upgrade checks passed\n" : "$upgradeFailures check(s) failed\n";
    exit($upgradeFailures === 0 ? 0 : 1);
}

if ($phase === 'seed') {
    upgrade_seed();
    exit(0);
}

if ($phase === 'update') {
    upgrade_update();
}

if ($phase === 'verify') {
    upgrade_verify();
}

fwrite(STDERR, "Usage: upgrade-from-v1.3.0.php seed|update|verify\n");
exit(2);
