<?php
/**
 * Work package B: encryption and keys, against the real database of the test
 * site. The key service runs in this process with Joomla booted; the sign-in
 * with password and second factor runs over HTTP like a browser.
 *
 * Data: two fixture accounts, one calendar row, the recovery key. Everything is
 * removed at the end; the recovery key only when this part created it.
 */

use Advans\Component\Appointments\Administrator\Helper\Team\AppPassword;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarKey;
use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Helper\Team\StoredObject;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamException;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarService;
use Advans\Component\Appointments\Administrator\Service\Team\EpochAnchor;
use Advans\Component\Appointments\Administrator\Service\Team\EpochAnchorInterface;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Advans\Component\Appointments\Administrator\Service\Team\KeyService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;

return static function (TeamSuite $suite): void {
    $suite->test('No recovery key and no user key exist after installation', static function () use ($suite) {
        return $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_keyring") === 0
            && $suite->count("SELECT COUNT(*) FROM {$suite->prefix}appointments_user_keys") === 0;
    });

    $suite->test('User keys store only a public key and a wrapped secret', static function () use ($suite) {
        return $suite->columns('appointments_user_keys') === ['user_id', 'key_version', 'public_key', 'password_wrapped', 'password_salt', 'pwhash_opslimit', 'pwhash_memlimit', 'device_epoch', 'row_mac', 'created_utc', 'modified_utc'];
    });

    $suite->test('libsodium is available on the test server', static function () {
        return function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt') && function_exists('sodium_crypto_box_seal') && function_exists('sodium_crypto_pwhash');
    });

    require_once __DIR__ . '/appointments-joomla.php';
    require_once __DIR__ . '/appointments-http.php';

    $db = $suite->db;
    $p = $suite->prefix;
    $tag = 'teamb' . bin2hex(random_bytes(4));
    // The key service reads the epoch anchor below the component folder. This
    // process may run as another system user than the web server, so a file it
    // creates is removed again at the end.
    $anchorFile = JPATH_ADMINISTRATOR . '/components/com_appointments/data/epochs.php';
    $st = ['users' => [], 'calendar' => 0, 'createdRecovery' => false, 'anchorExisted' => is_file($anchorFile), 'anchorDir' => is_dir(\dirname($anchorFile))];
    $services = TeamServices::fromApplication();
    /** @var KeyService $keys */
    $keys = $services->keys();

    $user = static function (string $name, string $password) use ($db, $p, $tag, &$st): int {
        $hash = $db->real_escape_string(password_hash($password, PASSWORD_BCRYPT));
        $login = $tag . $name;
        $db->query("INSERT INTO {$p}users (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('{$login}','{$login}','{$login}@example.test','{$hash}',0,0,UTC_TIMESTAMP(),'{}')");
        $id = (int) $db->insert_id;
        $db->query("INSERT INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$id}, 2)");
        $st['users'][] = $id;

        return $id;
    };
    $hex = static fn (string $bytes): string => "X'" . bin2hex($bytes) . "'";
    $row = static function (string $sql) use ($db): ?array {
        $result = $db->query($sql);

        return $result ? ($result->fetch_assoc() ?: null) : null;
    };
    $refusal = static function (callable $fn): int {
        try {
            $fn();
        } catch (TeamException $e) {
            return $e->getCode();
        }

        return 0;
    };

    $ownerPassword = 'Owner-' . bin2hex(random_bytes(6)) . '!';
    $memberPassword = 'Member-' . bin2hex(random_bytes(6)) . '!';
    $owner = $user('owner', $ownerPassword);
    $member = $user('member', $memberPassword);

    // ------------------------------------------------------------------
    // Recovery key
    // ------------------------------------------------------------------

    $recovery = '';

    $suite->test('B: the recovery key is created once, printed with a typing check, its secret never stored', static function () use ($suite, $keys, $refusal, $row, $p, &$recovery, &$st) {
        if ($suite->count("SELECT COUNT(*) FROM {$p}appointments_keyring") > 0) {
            return false;
        }

        $recovery = $keys->createRecoveryKey(42);
        $st['createdRecovery'] = true;
        $secret = KeyService::parseRecoverySecret($recovery);
        $stored = $row("SELECT public_key, fingerprint, HEX(public_key) AS h FROM {$p}appointments_keyring WHERE purpose = 'recovery'");

        return $secret !== null
            && $stored !== null
            && hash_equals($stored['public_key'], CipherSuite::publicKeyOf($secret))
            && $keys->recoveryFingerprint() === implode('-', str_split(KeyService::fingerprintOf($stored['public_key']), 4))
            && stripos($stored['h'], bin2hex($secret)) === false
            && $refusal(static fn () => $keys->createRecoveryKey(42)) === 409;
    });

    // ------------------------------------------------------------------
    // User keys
    // ------------------------------------------------------------------

    $ownerKeys = null;

    $suite->test('B: a user key opens only with the right password, and the table holds no secret', static function () use ($keys, $owner, $ownerPassword, $row, $p, &$ownerKeys) {
        $ownerKeys = $keys->createUserKey($owner, $ownerPassword);
        $stored = $row("SELECT HEX(password_wrapped) AS w, pwhash_opslimit, pwhash_memlimit FROM {$p}appointments_user_keys WHERE user_id = {$owner}");
        $again = $keys->unlockWithPassword($owner, $ownerPassword);

        return $again !== null
            && hash_equals($again->publicKey(), $ownerKeys->publicKey())
            && $keys->unlockWithPassword($owner, $ownerPassword . 'x') === null
            && stripos($stored['w'], bin2hex($ownerKeys->secretKey())) === false
            && (int) $stored['pwhash_opslimit'] === SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE
            && (int) $stored['pwhash_memlimit'] === SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
            && $keys->hasUserKey($owner);
    });

    $suite->test('B: a second key pair for the same user is refused', static function () use ($keys, $owner, $ownerPassword, $refusal) {
        return $refusal(static fn () => $keys->createUserKey($owner, $ownerPassword)) === 409;
    });

    $suite->test('B: a changed public key or weakened Argon2 limits in the database mean no key', static function () use ($db, $keys, $owner, $ownerPassword, $p) {
        $db->query("UPDATE {$p}appointments_user_keys SET pwhash_opslimit = 1, pwhash_memlimit = 8192 WHERE user_id = {$owner}");
        $downgraded = $keys->unlockWithPassword($owner, $ownerPassword) === null && $keys->userPublicKey($owner) === null
            && $keys->userKeyStatus($owner)['status'] === 'tampered';
        $db->query("UPDATE {$p}appointments_user_keys SET pwhash_opslimit = " . SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE . ', pwhash_memlimit = ' . SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE . " WHERE user_id = {$owner}");

        $db->query("UPDATE {$p}appointments_user_keys SET public_key = CONCAT(LEFT(public_key, 31), CHAR(ASCII(RIGHT(public_key, 1)) ^ 1)) WHERE user_id = {$owner}");
        $swapped = $keys->userPublicKey($owner) === null && !$keys->hasUserKey($owner);
        $db->query("UPDATE {$p}appointments_user_keys SET public_key = CONCAT(LEFT(public_key, 31), CHAR(ASCII(RIGHT(public_key, 1)) ^ 1)) WHERE user_id = {$owner}");

        return $downgraded && $swapped && $keys->userPublicKey($owner) !== null;
    });

    $suite->test('B: a password change in an unlocked session rewraps the same key pair', static function () use ($keys, $owner, &$ownerPassword, &$ownerKeys) {
        $new = $ownerPassword . '-new';
        $keys->rewrapForPassword($ownerKeys, $new);
        $old = $keys->unlockWithPassword($owner, $ownerPassword);
        $ownerPassword = $new;
        $current = $keys->unlockWithPassword($owner, $new);

        return $old === null && $current !== null && hash_equals($current->publicKey(), $ownerKeys->publicKey())
            && $current->userKeyVersion() === $ownerKeys->userKeyVersion();
    });

    $suite->test('B: a device wrap opens only with its app password and its salt', static function () use ($keys, $owner, &$ownerKeys) {
        $app = AppPassword::generate();
        $salt = random_bytes(AppPassword::SALT_BYTES);
        $wrapped = $keys->wrapForDevice($ownerKeys, $app['raw'], $salt);
        $opened = $keys->unlockWithDevice($owner, $app['raw'], $salt, $wrapped);

        return $opened !== null && hash_equals($opened->publicKey(), $ownerKeys->publicKey())
            && $keys->unlockWithDevice($owner, AppPassword::generate()['raw'], $salt, $wrapped) === null
            && $keys->unlockWithDevice($owner, $app['raw'], random_bytes(AppPassword::SALT_BYTES), $wrapped) === null
            && $keys->unlockWithDevice($owner + 1000000, $app['raw'], $salt, $wrapped) === null;
    });

    // ------------------------------------------------------------------
    // Calendar keys, objects, recovery
    // ------------------------------------------------------------------

    $suite->test('B: a calendar whose owner has no key pair cannot get keys', static function () use ($db, $keys, $member, $p, $refusal, $hex, &$st) {
        $uri = bin2hex(random_bytes(16));
        $db->query("INSERT INTO {$p}appointments_calendars (uri, title, owner_user_id, row_mac, created_utc, modified_utc) VALUES ('{$uri}', 'Team B', {$member}, " . $hex(str_repeat("\0", 32)) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        $st['calendar'] = (int) $db->insert_id;

        return $st['calendar'] > 0 && $refusal(static fn () => $keys->createCalendarKeys($st['calendar'], $member)) === 409;
    });

    $suite->test('B: calendar keys: owner wrap, recovery wrap and a sealing pair under the calendar key', static function () use ($keys, $owner, $suite, $p, &$ownerKeys, &$st) {
        $keys->createCalendarKeys($st['calendar'], $owner);
        $ring = $keys->openKeyring($st['calendar'], $ownerKeys);

        return $ring !== null && $ring->current()->version() === 1
            && $suite->count("SELECT COUNT(*) FROM {$p}appointments_calendar_keys WHERE calendar_id = {$st['calendar']}") === 1
            && $suite->count("SELECT COUNT(*) FROM {$p}appointments_calendar_key_wraps WHERE calendar_id = {$st['calendar']} AND user_id = {$owner}") === 1;
    });

    $suite->test('B: objects of both schemes open only in their place', static function () use ($keys, &$ownerKeys, &$st) {
        $ring = $keys->openKeyring($st['calendar'], $ownerKeys);
        $object = static fn (string $uri, int $scheme, int $version, string $payload) => new StoredObject(1, $st['calendar'], $uri, str_repeat('e', 32), \strlen($payload), $scheme, $version, $payload, '', null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $one = $keys->encryptObject($ring->current(), 'a.ics', 'BEGIN:VCALENDAR one');
        $sealed = $keys->sealObject($st['calendar'], 'b.ics', 'BEGIN:VCALENDAR two');

        return $keys->decryptObject($ring, $object('a.ics', TeamStates::SCHEME_CALENDAR_KEY, 1, $one)) === 'BEGIN:VCALENDAR one'
            && $sealed['scheme'] === TeamStates::SCHEME_SEALED && $sealed['version'] === 1
            && $keys->decryptObject($ring, $object('b.ics', TeamStates::SCHEME_SEALED, 1, $sealed['payload'])) === 'BEGIN:VCALENDAR two'
            && $keys->decryptObject($ring, $object('c.ics', TeamStates::SCHEME_SEALED, 1, $sealed['payload'])) === null
            && $keys->decryptObject($ring, $object('c.ics', TeamStates::SCHEME_CALENDAR_KEY, 1, $one)) === null;
    });

    $suite->test('B: a wrap planted in the database is refused (calendar key must open its MAC-protected row)', static function () use ($db, $keys, $member, $memberPassword, $p, $hex, &$st) {
        $memberKeys = $keys->createUserKey($member, $memberPassword);
        $forged = CipherSuite::seal($memberKeys->publicKey(), CipherSuite::randomKey());
        $db->query("INSERT INTO {$p}appointments_calendar_key_wraps (calendar_id, key_version, user_id, user_key_version, wrapped, created_utc) VALUES ({$st['calendar']}, 1, {$member}, {$memberKeys->userKeyVersion()}, " . $hex($forged) . ', UTC_TIMESTAMP())');
        $refused = $keys->openKeyring($st['calendar'], $memberKeys) === null;
        $db->query("DELETE FROM {$p}appointments_calendar_key_wraps WHERE calendar_id = {$st['calendar']} AND user_id = {$member}");

        return $refused;
    });

    $suite->test('B: a changed calendar key row means no keyring and no sealing', static function () use ($db, $keys, $p, $refusal, &$ownerKeys, &$st) {
        $flip = "UPDATE {$p}appointments_calendar_keys SET box_public_key = CONCAT(LEFT(box_public_key, 31), CHAR(ASCII(RIGHT(box_public_key, 1)) ^ 1)) WHERE calendar_id = {$st['calendar']}";
        $db->query($flip);
        $fresh = TeamServices::fromApplication()->keys();
        $refused = $fresh->openKeyring($st['calendar'], $ownerKeys) === null
            && $refusal(static fn () => $fresh->sealObject($st['calendar'], 'x.ics', 'X')) === 409;
        $db->query($flip);

        return $refused && TeamServices::fromApplication()->keys()->openKeyring($st['calendar'], $ownerKeys) !== null;
    });

    $suite->test('B: an object sealed without the server MAC (anybody can seal to the public key) is never read', static function () use ($keys, $row, $p, &$ownerKeys, &$st) {
        $box = $row("SELECT box_public_key FROM {$p}appointments_calendar_keys WHERE calendar_id = {$st['calendar']} AND key_version = 1")['box_public_key'];
        $planted = CipherSuite::seal($box, "ACv1\n" . json_encode(['c' => $st['calendar'], 'u' => 'p.ics']) . "\nBEGIN:VCALENDAR planted");
        $object = new StoredObject(1, $st['calendar'], 'p.ics', str_repeat('e', 32), \strlen($planted), TeamStates::SCHEME_SEALED, 1, $planted, '', null, new \DateTimeImmutable('now', new \DateTimeZone('UTC')));

        return $keys->decryptObject($keys->openKeyring($st['calendar'], $ownerKeys), $object) === null;
    });

    $suite->test('B: the key version of the calendar row is never followed down, and a missing newer key row fails closed', static function () use ($db, $p, &$ownerKeys, &$st) {
        $db->query("UPDATE {$p}appointments_calendars SET key_version = 0 WHERE id = {$st['calendar']}");
        $back = TeamServices::fromApplication()->keys()->openKeyring($st['calendar'], $ownerKeys);
        $db->query("UPDATE {$p}appointments_calendars SET key_version = 7 WHERE id = {$st['calendar']}");
        $forward = TeamServices::fromApplication()->keys()->openKeyring($st['calendar'], $ownerKeys);
        $db->query("UPDATE {$p}appointments_calendars SET key_version = 1 WHERE id = {$st['calendar']}");

        return $back !== null && $back->current()->version() === 1 && $forward === null;
    });

    $suite->test('B: a reset never overwrites a key row that fails its MAC (the old site secret must still find it)', static function () use ($db, $keys, $member, $p, $refusal, $row) {
        $flip = "UPDATE {$p}appointments_user_keys SET public_key = CONCAT(LEFT(public_key, 31), CHAR(ASCII(RIGHT(public_key, 1)) ^ 1)) WHERE user_id = {$member}";
        $db->query($flip);
        $before = $row("SELECT HEX(public_key) AS k, key_version FROM {$p}appointments_user_keys WHERE user_id = {$member}");
        $code = $refusal(static fn () => $keys->resetUserKey($member));
        $after = $row("SELECT HEX(public_key) AS k, key_version FROM {$p}appointments_user_keys WHERE user_id = {$member}");
        $db->query($flip);

        return $code === 409 && $before === $after && $keys->hasUserKey($member);
    });

    $superUser = (int) ($row("SELECT user_id FROM {$p}user_usergroup_map WHERE group_id = 8 ORDER BY user_id LIMIT 1")['user_id'] ?? 0);

    $suite->test('B: discarding a key needs core.admin and is refused for a key row that still verifies', static function () use ($keys, $member, $owner, $refusal, $superUser) {
        return $superUser > 0
            && $refusal(static fn () => $keys->discardUserKey($owner, $member)) === 403
            && $refusal(static fn () => $keys->discardUserKey($superUser, $member)) === 409
            && $keys->hasUserKey($member);
    });

    if ($suite->isStub(GrantService::class)) {
        $suite->pending('B: discarding a damaged key row replaces it by a reset row of the next version, deletes the wraps and raises the device epoch', 'C');
    } else {
        $suite->test('B: discarding a damaged key row replaces it by a reset row of the next version, deletes the wraps and raises the device epoch', static function () use ($db, $keys, $member, $memberPassword, $p, $superUser) {
            $before = $db->query("SELECT key_version, device_epoch FROM {$p}appointments_user_keys WHERE user_id = {$member}")->fetch_row();
            $db->query("UPDATE {$p}appointments_user_keys SET public_key = CONCAT(LEFT(public_key, 31), CHAR(ASCII(RIGHT(public_key, 1)) ^ 1)) WHERE user_id = {$member}");
            $keys->discardUserKey($superUser, $member);
            $status = $keys->userKeyStatus($member);
            $replaced = $status['status'] === 'reset' && $status['version'] > (int) $before[0] && $status['device_epoch'] > (int) $before[1]
                && (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_calendar_key_wraps WHERE user_id = {$member}")->fetch_row()[0] === 0;
            $again = $keys->createUserKey($member, $memberPassword);

            return $replaced && $again->userKeyVersion() > $status['version'] && $keys->userKeyStatus($member)['device_epoch'] === $status['device_epoch'];
        });
    }

    $suite->test('B: the recovery secret opens the calendar, another secret does not', static function () use ($keys, &$recovery, &$ownerKeys, &$st) {
        $ring = $keys->recoverKeyring($st['calendar'], $recovery);
        $own = $keys->openKeyring($st['calendar'], $ownerKeys);
        $wrong = KeyService::formatRecoverySecret(CipherSuite::keyPair()['secret']);

        return $ring !== null && $own !== null && hash_equals($own->current()->key(), $ring->current()->key())
            && $keys->recoverKeyring($st['calendar'], $wrong) === null
            && $keys->recoverKeyring($st['calendar'], 'not a secret') === null;
    });

    $suite->test('B: the recovery key rotates only with the old secret', static function () use ($keys, $refusal, &$recovery, &$st) {
        $wrong = KeyService::formatRecoverySecret(CipherSuite::keyPair()['secret']);

        if ($refusal(static fn () => $keys->rotateRecoveryKey($wrong, 42)) !== 403) {
            return false;
        }

        $old = $recovery;
        $recovery = $keys->rotateRecoveryKey($old, 42);

        return $recovery !== $old
            && $keys->recoverKeyring($st['calendar'], $recovery) !== null
            && $keys->recoverKeyring($st['calendar'], $old) === null;
    });

    // ------------------------------------------------------------------
    // Completion and rotation need the grant service (work package C)
    // ------------------------------------------------------------------

    if ($suite->isStub(GrantService::class) || !method_exists(GrantService::class, 'rowValid') || !method_exists(CalendarService::class, 'bumpEpoch')) {
        $suite->pending('B: pending wraps are completed for effective grants only, never for suspended ones', 'C');
        $suite->pending('B: rotation creates the next version for every remaining holder and the recovery key', 'C');
    } else {
        // The grant service verifies the calendar row too: give the fixture
        // calendar a valid MAC under the current epoch.
        $calendarRow = $row("SELECT * FROM {$p}appointments_calendars WHERE id = {$st['calendar']}");
        $db->query("UPDATE {$p}appointments_calendars SET row_mac = " . $hex($keys->rowMac('calendars', CalendarService::macFields((object) $calendarRow))) . " WHERE id = {$st['calendar']}");
        $grant = static function (int $userId, int $role, int $state) use ($db, $p, $hex, $keys, &$st): void {
            $epoch = GrantService::calendarEpoch(TeamServices::fromApplication(), $st['calendar']);
            $version = GrantService::userKeyVersion(TeamServices::fromApplication(), $userId);
            $fields = GrantService::macFields(['calendar_id' => $st['calendar'], 'user_id' => $userId, 'role' => $role, 'state' => $state, 'audience' => TeamStates::AUDIENCE_TEAM, 'granted_by' => 42, 'confirmed_by' => null], $epoch, $version);
            $mac = $keys->rowMac('calendar_grants', $fields);
            $db->query("REPLACE INTO {$p}appointments_calendar_grants (calendar_id, user_id, role, state, audience, granted_by, row_mac, created_utc, modified_utc) VALUES ({$st['calendar']}, {$userId}, {$role}, {$state}, 'team', 42, " . $hex($mac) . ', UTC_TIMESTAMP(), UTC_TIMESTAMP())');
        };
        $effective = static function (int $userId) use ($db, $p): void {
            $groups = (array) \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->get('team_groups', []);
            $group = (int) ($groups[0] ?? 0);
            $db->query("INSERT IGNORE INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$userId}, {$group})");
            $db->query("INSERT INTO {$p}user_mfa (user_id, title, method, `default`, options, created_on) VALUES ({$userId}, 'B', 'totp', 1, '{}', UTC_TIMESTAMP())");
        };

        $suite->test('B: pending wraps are completed for effective grants only, never for suspended ones', static function () use ($keys, $member, $owner, $grant, $effective, $suite, $p, &$ownerKeys, &$st) {
            $effective($owner);
            $effective($member);
            $grant($owner, 4, TeamStates::GRANT_ACTIVE);
            $grant($member, 2, TeamStates::GRANT_SUSPENDED);
            $ring = TeamServices::fromApplication()->keys()->openKeyring($st['calendar'], $ownerKeys);
            $none = $keys->completePendingWraps($ring) === 0;
            $grant($member, 2, TeamStates::GRANT_PENDING_KEY);
            $written = $keys->completePendingWraps($ring);

            return $none && $written === 1
                && $suite->count("SELECT COUNT(*) FROM {$p}appointments_calendar_key_wraps WHERE calendar_id = {$st['calendar']} AND user_id = {$member}") === 1;
        });

        $suite->test('B: rotation creates the next version for every remaining holder and the recovery key', static function () use ($db, $keys, $member, $memberPassword, $p, &$recovery, &$ownerKeys, &$st) {
            $db->query("UPDATE {$p}appointments_calendars SET rotation_due = 1 WHERE id = {$st['calendar']}");
            $ring = TeamServices::fromApplication()->keys()->openKeyring($st['calendar'], $ownerKeys);
            $memberRing = $keys->openKeyring($st['calendar'], $keys->unlockWithPassword($member, $memberPassword));
            $recovered = $keys->recoverKeyring($st['calendar'], $recovery);

            return $ring !== null && $ring->current()->version() === 2 && $ring->version(1) !== null
                && $memberRing !== null && $memberRing->current()->version() === 2
                && $recovered !== null && $recovered->current()->version() === 2;
        });
    }

    // ------------------------------------------------------------------
    // Reset
    // ------------------------------------------------------------------

    $suite->test('B: a reset makes the old key unusable, deletes every wrap and lets the next password sign-in create a new pair', static function () use ($keys, $owner, $suite, $p, &$ownerPassword, &$ownerKeys, &$st) {
        $app = AppPassword::generate();
        $salt = random_bytes(AppPassword::SALT_BYTES);
        $deviceWrap = $keys->wrapForDevice($ownerKeys, $app['raw'], $salt);
        $before = $keys->userKeyStatus($owner)['version'];
        $keys->resetUserKey($owner);
        $status = $keys->userKeyStatus($owner);
        $gone = $status['status'] === 'reset' && $status['version'] === $before + 1
            && $keys->unlockWithPassword($owner, $ownerPassword) === null
            && $keys->unlockWithDevice($owner, $app['raw'], $salt, $deviceWrap) === null
            && !$keys->hasUserKey($owner)
            && $keys->openKeyring($st['calendar'], $ownerKeys) === null
            && $suite->count("SELECT COUNT(*) FROM {$p}appointments_calendar_key_wraps WHERE user_id = {$owner}") === 0;
        $ownerKeys = $keys->createUserKey($owner, $ownerPassword);

        return $gone && $ownerKeys->userKeyVersion() === $before + 2 && $keys->unlockWithPassword($owner, $ownerPassword) !== null;
    });

    // ------------------------------------------------------------------
    // Sign-in over HTTP with a real second factor
    // ------------------------------------------------------------------

    $totpKey = '';
    $suite->test('B: Joomla marks a password sign-in as passed only after the second factor (com_users.mfa_checked)', static function () use ($suite, $db, $p, $member, $memberPassword, $tag, &$totpKey) {
        $totpKey = '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        for ($i = 0; $i < 32; $i++) {
            $totpKey .= $alphabet[random_int(0, 31)];
        }

        // Joomla stores MFA options encrypted, but reads unencrypted options too.
        $options = $db->real_escape_string(json_encode(['key' => $totpKey]));
        $db->query("DELETE FROM {$p}user_mfa WHERE user_id = {$member}");
        $db->query("INSERT INTO {$p}user_mfa (user_id, title, method, `default`, options, created_on) VALUES ({$member}, 'B', 'totp', 1, '{$options}', UTC_TIMESTAMP())");
        $recordId = (int) $db->insert_id;

        $http = new AppointmentsHttp($suite->base, 'teamb');
        $form = $http->request('/index.php?option=com_users&view=login');
        $http->request('/index.php?option=com_users&task=user.login', [
            'username' => $tag . 'member', 'password' => $memberPassword,
            'return' => base64_encode('index.php'), $http->token($form) => 1,
        ]);
        $pending = teamBSession($db, $p, $member);
        $captive = $http->request('/index.php?option=com_users&view=captive');
        $http->request('/index.php?option=com_users&task=captive.validate&record_id=' . $recordId, [
            'code' => teamBTotp($totpKey, time()), $http->token($captive) => 1,
        ]);
        $passed = teamBSession($db, $p, $member);
        $escrowCookie = str_contains((string) @file_get_contents(teamBCookieJar($http)), 'appointments_keys_');

        echo '  Joomla session after password: mfa_checked=' . var_export($pending?->get('com_users.mfa_checked'), true)
            . ', after second factor: ' . var_export($passed?->get('com_users.mfa_checked'), true) . "\n";

        $https = str_starts_with($suite->base, 'https://');

        return $pending !== null && (int) $pending->get('com_users.mfa_checked', 0) === 0
            && $passed !== null && (int) $passed->get('com_users.mfa_checked', 0) === 1
            // Without HTTPS the key session keeps nothing: no escrow, no cookie.
            && ($https || ($pending->get('com_appointments.team_keys.site') === null && !$escrowCookie));
    });

    $suite->test('B: the factor proof needs a counting method used after the sign-in and no backup or e-mail code used since', static function () use ($db, $p, $member, $services) {
        // Joomla ignores MFA records with empty options, so the codes are real.
        $db->query("INSERT INTO {$p}user_mfa (user_id, title, method, `default`, options, created_on) VALUES ({$member}, 'B codes', 'backupcodes', 0, '[\"40517342\",\"83610277\"]', UTC_TIMESTAMP())");
        $session = new \Advans\Component\Appointments\Administrator\Service\Team\KeySession($services);
        $snapshot = new \ReflectionMethod($session, 'factorSnapshot');
        $counting = new \ReflectionMethod($session, 'countingSnapshot');
        $usedSince = new \ReflectionMethod($session, 'countingFactorUsedSince');
        $atSignIn = $snapshot->invoke($session, $member);
        $countingAtSignIn = $counting->invoke($session, $member);
        // Before any counting factor was used after the sign-in, nothing is proven.
        $notYet = !$usedSince->invoke($session, $member, $countingAtSignIn, time() - 5);
        // This session passed with TOTP: the counting record is stamped.
        $db->query("UPDATE {$p}user_mfa SET last_used = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 SECOND) WHERE user_id = {$member} AND method = 'totp'");
        $withTotp = $snapshot->invoke($session, $member);
        $totpProven = $usedSince->invoke($session, $member, $countingAtSignIn, time() - 5);
        // A backup code instead: the backup code record is stamped.
        $db->query("UPDATE {$p}user_mfa SET last_used = DATE_ADD(UTC_TIMESTAMP(), INTERVAL 2 SECOND) WHERE user_id = {$member} AND method = 'backupcodes'");
        $withBackup = $snapshot->invoke($session, $member);
        $db->query("DELETE FROM {$p}user_mfa WHERE user_id = {$member} AND method = 'backupcodes'");

        return $notYet && $totpProven && hash_equals($atSignIn, $withTotp) && !hash_equals($atSignIn, $withBackup);
    });

    if (!str_starts_with($suite->base, 'https://')) {
        $suite->pending('B: escrow at a password sign-in over HTTPS (cookie attributes, session without key)', 'G');
    } elseif ($suite->isStub(GrantService::class)) {
        $suite->pending('B: escrow at a password sign-in over HTTPS (cookie attributes, session without key)', 'C');
    } else {
        $suite->test('B: escrow at a password sign-in over HTTPS (cookie attributes, session without key)', static function () use ($suite, $db, $p, $member, $memberPassword, $tag, $keys) {
            $groups = (array) \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments')->get('team_groups', []);
            $db->query("INSERT IGNORE INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$member}, " . (int) ($groups[0] ?? 0) . ')');
            // Switch the feature and the user plugin on for this test only.
            $params = (string) $db->query("SELECT params FROM {$p}extensions WHERE type='component' AND element='com_appointments'")->fetch_row()[0];
            $on = json_decode($params ?: '{}', true) ?: [];
            $on['teamcal_enabled'] = 1;
            $db->query("UPDATE {$p}extensions SET params = '" . $db->real_escape_string(json_encode($on)) . "' WHERE type='component' AND element='com_appointments'");
            $db->query("UPDATE {$p}extensions SET enabled = 1 WHERE type='plugin' AND folder='user' AND element='appointments'");
            $headers = '';
            $curl = curl_init($suite->base . '/index.php?option=com_users&view=login');
            $jar = sys_get_temp_dir() . '/teamb-' . bin2hex(random_bytes(6));
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => 0]);
            $form = (string) curl_exec($curl);
            preg_match('/name="([a-f0-9]{32})"\s+value="1"/', $form, $token);
            curl_setopt_array($curl, [
                CURLOPT_URL => $suite->base . '/index.php?option=com_users&task=user.login', CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['username' => $tag . 'member', 'password' => $memberPassword, 'return' => base64_encode('index.php'), ($token[1] ?? 'x') => 1]),
                CURLOPT_HEADERFUNCTION => static function ($c, $line) use (&$headers) { $headers .= $line; return \strlen($line); },
            ]);
            curl_exec($curl);
            curl_close($curl);
            @unlink($jar);
            $db->query("UPDATE {$p}extensions SET params = '" . $db->real_escape_string($params) . "' WHERE type='component' AND element='com_appointments'");
            $db->query("UPDATE {$p}extensions SET enabled = 0 WHERE type='plugin' AND folder='user' AND element='appointments'");

            preg_match('/^Set-Cookie:\s*(?:__Host-)?appointments_keys_site=([0-9a-f]{64});([^\r\n]*)/mi', $headers, $cookie);
            $session = teamBSession($db, $p, $member);
            $escrow = $session?->get('com_appointments.team_keys.site');
            $escrow = \is_object($escrow) ? (array) $escrow : $escrow;
            $raw = (string) $db->query("SELECT data FROM {$p}session WHERE userid = {$member} ORDER BY time DESC LIMIT 1")->fetch_row()[0];
            $secret = $keys->unlockWithPassword($member, $memberPassword)?->secretKey() ?? random_bytes(32);
            $attributes = strtolower($cookie[2] ?? '');

            return isset($cookie[1]) && \is_array($escrow)
                && str_contains($attributes, 'httponly') && str_contains($attributes, 'secure') && str_contains($attributes, 'samesite=strict')
                && !str_contains($attributes, 'expires=')
                && !str_contains($raw, $secret) && stripos($raw, bin2hex($secret)) === false && !str_contains($raw, base64_encode($secret))
                && stripos(base64_decode(preg_match('/joomla\|s:\d+:"([^"]*)"/', $raw, $m) ? $m[1] : ''), bin2hex($secret)) === false
                && !str_contains($raw, $cookie[1]);
        });
    }

    // ------------------------------------------------------------------

    $suite->test('B: the epoch anchor keeps the highest epoch, never lowers it, and rebuilds itself when its signature fails', static function () use ($services) {
        $file = sys_get_temp_dir() . '/teamb-anchor-' . bin2hex(random_bytes(6)) . '/epochs.php';
        $anchor = new EpochAnchor($services, $file);
        $anchor->record(EpochAnchorInterface::KIND_CALENDAR, 5, 3);
        $anchor->record(EpochAnchorInterface::KIND_CALENDAR, 5, 2);
        $anchor->record(EpochAnchorInterface::KIND_USER, 9, 4);
        $fresh = new EpochAnchor($services, $file);
        $kept = $fresh->minimum(EpochAnchorInterface::KIND_CALENDAR, 5) === 3 && $fresh->minimum(EpochAnchorInterface::KIND_USER, 9) === 4
            && !$fresh->accepts(EpochAnchorInterface::KIND_CALENDAR, 5, 2) && $fresh->accepts(EpochAnchorInterface::KIND_CALENDAR, 5, 3)
            && str_starts_with((string) file_get_contents($file), "<?php defined('_JEXEC') or die;");
        // A forged raise of the anchor (without the server key) is not believed.
        file_put_contents($file, str_replace('"5":3', '"5":99', (string) file_get_contents($file)));
        $forged = (new EpochAnchor($services, $file))->minimum(EpochAnchorInterface::KIND_CALENDAR, 5) !== 99;

        foreach (glob(\dirname($file) . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        @rmdir(\dirname($file));

        return $kept && $forged;
    });

    $suite->test('B: recordAfterCommit anchors only what was committed, nothing while a transaction is open, never a rolled-back value', static function () use ($services, &$st) {
        $file = sys_get_temp_dir() . '/teamb-anchor-' . bin2hex(random_bytes(6)) . '/epochs.php';
        $anchor = new EpochAnchor($services, $file);
        $jdb = $services->db();
        $jdb->transactionStart();
        $anchor->recordAfterCommit(EpochAnchorInterface::KIND_CALENDAR_KEY, $st['calendar']);
        $queued = $anchor->minimum(EpochAnchorInterface::KIND_CALENDAR_KEY, $st['calendar']) === 0;
        // The end of a request with a transaction still open (fatal error,
        // timeout) anchors nothing: the connection rolls back when it closes.
        $anchor->flush();
        $skipped = !is_file($file) || (new EpochAnchor($services, $file))->minimum(EpochAnchorInterface::KIND_CALENDAR_KEY, $st['calendar']) === 0;
        $jdb->transactionRollback();
        $anchor->flush();
        $committed = (new EpochAnchor($services, $file))->minimum(EpochAnchorInterface::KIND_CALENDAR_KEY, $st['calendar']) >= 1;

        foreach (glob(\dirname($file) . '/*') ?: [] as $leftover) {
            @unlink($leftover);
        }

        @rmdir(\dirname($file));

        return $queued && $skipped && $committed;
    });

    $suite->test('B: a calendar whose key version is anchored above its newest key row fails closed', static function () use ($services, &$recovery, &$st) {
        $before = TeamServices::fromApplication()->keys()->recoverKeyring($st['calendar'], $recovery) !== null;
        $services->anchor()->record(EpochAnchorInterface::KIND_CALENDAR_KEY, $st['calendar'], 99);

        return $before && (!$services->anchor()->writable()
            || TeamServices::fromApplication()->keys()->recoverKeyring($st['calendar'], $recovery) === null);
    });

    $suite->test('B: without a writable anchor everything works without it', static function () use ($services) {
        $anchor = new EpochAnchor($services, '/proc/teamb-not-writable/epochs.php');

        return !$anchor->writable() && $anchor->minimum(EpochAnchorInterface::KIND_USER, 1) === 0 && $anchor->accepts(EpochAnchorInterface::KIND_USER, 1, 1);
    });

    $suite->test('B: removes its fixture data', static function () use ($db, $p, $anchorFile, &$st) {
        if (!$st['anchorExisted']) {
            foreach ([$anchorFile, $anchorFile . '.lock'] as $file) {
                @unlink($file);
            }

            foreach (glob(\dirname($anchorFile) . '/*.tmp') ?: [] as $leftover) {
                @unlink($leftover);
            }

            if (!$st['anchorDir']) {
                @rmdir(\dirname($anchorFile));
            }
        }
        $users = implode(',', array_map('intval', $st['users'] ?: [0]));

        if ($st['calendar'] > 0) {
            $db->query("DELETE FROM {$p}appointments_calendar_grants WHERE calendar_id = {$st['calendar']}");
            $db->query("DELETE FROM {$p}appointments_calendars WHERE id = {$st['calendar']}");
        }

        $db->query("DELETE FROM {$p}appointments_calendar_key_wraps WHERE user_id IN ({$users})");
        $db->query("DELETE FROM {$p}appointments_user_keys WHERE user_id IN ({$users})");
        $db->query("DELETE FROM {$p}user_mfa WHERE user_id IN ({$users})");
        $db->query("DELETE FROM {$p}session WHERE userid IN ({$users})");
        $db->query("DELETE FROM {$p}user_usergroup_map WHERE user_id IN ({$users})");
        $db->query("DELETE FROM {$p}users WHERE id IN ({$users})");

        if ($st['createdRecovery']) {
            $db->query("DELETE FROM {$p}appointments_keyring WHERE purpose = 'recovery'");
        }

        return (int) $db->query("SELECT COUNT(*) FROM {$p}users WHERE id IN ({$users})")->fetch_row()[0] === 0
            && (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_calendar_keys WHERE calendar_id = " . (int) $st['calendar'])->fetch_row()[0] === 0;
    });
};

/**
 * The Joomla session registry of the newest session of a user, null when none.
 */
function teamBSession(mysqli $db, string $prefix, int $userId): ?\Joomla\Registry\Registry
{
    $result = $db->query("SELECT data FROM {$prefix}session WHERE userid = {$userId} ORDER BY time DESC LIMIT 1");
    $data = $result ? (string) ($result->fetch_row()[0] ?? '') : '';

    if (!preg_match('/joomla\|s:\d+:"([^"]*)"/', $data, $match)) {
        return null;
    }

    $registry = @unserialize((string) base64_decode($match[1]));

    return $registry instanceof \Joomla\Registry\Registry ? $registry : null;
}

/**
 * RFC 6238 code (SHA-1, 30 s, 6 digits) as Joomla's TOTP plugin checks it.
 */
function teamBTotp(string $base32, int $time): string
{
    $bits = '';

    foreach (str_split($base32) as $char) {
        $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $char)), 5, '0', STR_PAD_LEFT);
    }

    $key = '';

    foreach (str_split(substr($bits, 0, intdiv(\strlen($bits), 8) * 8), 8) as $byte) {
        $key .= \chr(bindec($byte));
    }

    $hash = hash_hmac('sha1', pack('J', intdiv($time, 30)), $key, true);
    $offset = \ord($hash[19]) & 0x0f;
    $value = ((\ord($hash[$offset]) & 0x7f) << 24) | (\ord($hash[$offset + 1]) << 16) | (\ord($hash[$offset + 2]) << 8) | \ord($hash[$offset + 3]);

    return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
}

/**
 * The cookie jar file of an AppointmentsHttp client.
 */
function teamBCookieJar(AppointmentsHttp $http): string
{
    return (string) (new \ReflectionProperty($http, 'cookie'))->getValue($http);
}
