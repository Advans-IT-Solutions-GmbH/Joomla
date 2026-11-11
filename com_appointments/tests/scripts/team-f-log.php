<?php
/**
 * Work package F: log chain, lock-out, clean-up, Scheduler routines, privacy.
 *
 * Service calls run in a separate PHP process with Joomla booted
 * (appointments-joomla.php) and a clock the test sets, so this part never
 * changes the state of the suite's own process. The log is tested on a copy:
 * the rows the earlier parts wrote are moved aside first and put back at the
 * end, so tampering here never touches their chain. Every row this part
 * creates (users, devices, calendars, counters, Scheduler tasks) is removed
 * again.
 */

return static function (TeamSuite $suite): void {
    $db = $suite->db;
    $p = $suite->prefix;
    $tag = 'teamf' . bin2hex(random_bytes(4));

    /**
     * Run PHP code with Joomla and TeamServices; `$now` (DateTimeImmutable,
     * UTC) is the services' clock and may be changed by the code. The code
     * returns a JSON-encodable value.
     */
    $run = static function (string $code, string $now = 'now') use ($tag): mixed {
        $file = sys_get_temp_dir() . '/' . $tag . '-' . bin2hex(random_bytes(4)) . '.php';
        $prelude = <<<'PHP'
<?php
require '/var/www/html/tests/scripts/appointments-joomla.php';
use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AuditEvent;
use Advans\Component\Appointments\Administrator\Helper\Team\TeamStates;
use Advans\Component\Appointments\Administrator\Service\Team\AuditService;
use Advans\Component\Appointments\Administrator\Service\Team\LockoutService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
$now = new DateTimeImmutable(getenv('TEAMF_NOW') ?: 'now', new DateTimeZone('UTC'));
$services = new TeamServices($joomlaDb, new KeyProvider((string) $application->get('secret')),
    \Joomla\CMS\Component\ComponentHelper::getParams('com_appointments'),
    static function () use (&$now): DateTimeImmutable { return $now; });
$forget = static function (): void {
    // A new request: the reservations of the previous one are gone.
    foreach (['attempts', 'refused'] as $name) {
        (new ReflectionProperty(LockoutService::class, $name))->setValue(null, []);
    }
};
$result = (static function () use (&$now, $services, $forget, $joomlaDb, $application) {
PHP;
        file_put_contents($file, $prelude . "\n" . $code . "\n})();\necho \"\\n@@TEAMF@@\" . json_encode(\$result);\n");
        $output = [];
        exec('cd /var/www/html && TEAMF_NOW=' . escapeshellarg($now) . ' php ' . escapeshellarg($file) . ' 2>&1', $output);
        unlink($file);
        $text = implode("\n", $output);
        $at = strrpos($text, '@@TEAMF@@');

        if ($at === false) {
            throw new RuntimeException('Service process failed: ' . substr($text, 0, 2000));
        }

        return json_decode(substr($text, $at + 9), true);
    };

    $suite->test('The team calendar log has exactly the specified columns', static function () use ($suite) {
        return $suite->columns('appointments_teamlog') === ['id', 'occurred_utc', 'event', 'actor_user_id', 'actor_device_id', 'subject_user_id', 'calendar_id', 'object_ref', 'details', 'ip_hash', 'prev_hash', 'row_hash'];
    });

    $suite->test('Failed sign-ins are counted per keyed bucket only', static function () use ($suite) {
        return $suite->columns('appointments_auth_failures') === ['bucket', 'failures', 'locked_until_utc', 'last_failure_utc'];
    });

    $suite->test('The log can tell whether a transaction is open (Joomla keeps its depth where the log reads it)', static function () use ($run) {
        return $run('$d = new ReflectionProperty($joomlaDb, "transactionDepth"); $outside = $d->getValue($joomlaDb) === 0;
            $joomlaDb->transactionStart(true); $inside = $d->getValue($joomlaDb) > 0; $joomlaDb->transactionRollback(true);
            return $outside && $inside;') === true;
    });

    $suite->test('User plugin installed and disabled after installation', static function () use ($suite) {
        return $suite->count("SELECT COUNT(*) FROM {$suite->prefix}extensions WHERE type='plugin' AND folder='user' AND element='appointments' AND enabled=0") === 1;
    });

    // ---- Log chain, on a copy of the log ------------------------------------
    // The file anchor of the log (work package B) only rises; this part works
    // on a copy of the log, so it sets the anchor file aside as well.
    $anchorFile = '/var/www/html/administrator/components/com_appointments-data/epochs.php';
    $anchorCopy = is_file($anchorFile) ? (string) file_get_contents($anchorFile) : null;
    $earlier = $run('$v = $services->audit()->verify(); return ["intact" => $v->intact, "rows" => $v->checkedRows];');
    $db->query("DROP TABLE IF EXISTS {$p}{$tag}_log");
    $db->query("DROP TABLE IF EXISTS {$p}{$tag}_head");
    $db->query("CREATE TABLE {$p}{$tag}_log AS SELECT * FROM {$p}appointments_teamlog");
    $db->query("CREATE TABLE {$p}{$tag}_head AS SELECT * FROM {$p}appointments_teamlog_head");
    $fresh = static function () use ($db, $p, $anchorFile, $run): void {
        if (is_file($anchorFile)) {
            unlink($anchorFile);
        }

        // Rebuilding a missing anchor logs a MAC failure. Let B rebuild it
        // before clearing the copied chain, not during the first tested append.
        $run('return $services->anchor()->minimum("log", AuditService::HEAD_ID);');
        $db->query("DELETE FROM {$p}appointments_teamlog");
        $db->query("DELETE FROM {$p}appointments_teamlog_head");
    };
    $verify = static fn (): array => $run('$v = $services->audit()->verify(); return ["intact" => $v->intact, "rows" => $v->checkedRows, "broken" => $v->firstBrokenId, "head" => $v->headHash];');
    $row = static function (int $id) use ($db, $p): array {
        return $db->query("SELECT *, HEX(prev_hash) AS prev_hex, HEX(row_hash) AS row_hex FROM {$p}appointments_teamlog WHERE id={$id}")->fetch_assoc() ?: [];
    };
    $ids = static function () use ($db, $p): array {
        $all = [];
        $result = $db->query("SELECT id FROM {$p}appointments_teamlog ORDER BY id");

        while ($result && ($r = $result->fetch_row())) {
            $all[] = (int) $r[0];
        }

        return $all;
    };

    try {
        $fresh();
        $run('foreach ([AuditEvent::LOGIN_SUCCESS, AuditEvent::DEVICE_CREATED, AuditEvent::GRANT_CREATED, AuditEvent::OBJECT_DELETED] as $i => $e) {
            $services->audit()->log($e, ["actor_user_id" => 42, "calendar_id" => 7, "ip" => "192.0.2.10",
                "details" => ["n" => $i, "role" => "write", "email" => "someone@example.test"]]);
        } return true;');

        $suite->test('Logging appends a verifiable chain', static function () use ($verify, $ids) {
            $v = $verify();

            return $v['intact'] === true && $v['rows'] === 4 && count($ids()) === 4 && strlen($v['head']) === 64;
        });

        $suite->test('The log stores no address and no IP in clear', static function () use ($db, $p, $run) {
            $dump = '';
            $expected = $run('return bin2hex(AuditService::addressHash($services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_LOG), "192.0.2.10"));');
            // Binary HMACs may contain "@": validate their exact keyed bytes
            // separately instead of mistaking one random byte for an address.
            $result = $db->query("SELECT details, object_ref, HEX(ip_hash) AS ip_hash FROM {$p}appointments_teamlog");

            while ($result && ($r = $result->fetch_assoc())) {
                if ($r['ip_hash'] !== strtoupper($expected)) {
                    return false;
                }

                $dump .= implode('|', array_map('strval', $r));
            }

            return $dump !== '' && !str_contains($dump, '@') && !str_contains($dump, '192.0.2.10') && str_contains($dump, '[redacted]');
        });

        $all = $ids();
        $second = $row($all[1]);
        $suite->test('A changed row is detected by «verify chain»', static function () use ($db, $p, $all, $second, $verify) {
            $db->query("UPDATE {$p}appointments_teamlog SET details='{\"n\": 1, \"role\": \"manage\"}' WHERE id={$all[1]}");
            $broken = $verify();
            $db->query("UPDATE {$p}appointments_teamlog SET details='" . $db->real_escape_string((string) $second['details']) . "' WHERE id={$all[1]}");

            return $broken['intact'] === false && $broken['broken'] === $all[1] && $verify()['intact'] === true;
        });

        $suite->test('A deleted row is detected by «verify chain»', static function () use ($db, $p, $all, $verify) {
            $db->query("CREATE TEMPORARY TABLE {$p}teamf_one AS SELECT * FROM {$p}appointments_teamlog WHERE id={$all[1]}");
            $db->query("DELETE FROM {$p}appointments_teamlog WHERE id={$all[1]}");
            $broken = $verify();
            $db->query("INSERT INTO {$p}appointments_teamlog SELECT * FROM {$p}teamf_one");
            $db->query("DROP TEMPORARY TABLE {$p}teamf_one");

            return $broken['intact'] === false && $broken['broken'] === $all[2] && $verify()['intact'] === true;
        });

        $suite->test('An inserted row is detected by «verify chain»', static function () use ($db, $p, $all, $verify) {
            $forged = $all[1] + 1000;
            $db->query("INSERT INTO {$p}appointments_teamlog (id, occurred_utc, event, actor_user_id, prev_hash, row_hash)
                SELECT {$forged}, occurred_utc, 'grant.revoked', actor_user_id, row_hash, row_hash FROM {$p}appointments_teamlog WHERE id={$all[3]}");
            $broken = $verify();
            $db->query("DELETE FROM {$p}appointments_teamlog WHERE id={$forged}");

            return $broken['intact'] === false && $broken['broken'] === $forged && $verify()['intact'] === true;
        });

        $suite->test('Cutting off the newest rows is detected, also with an adjusted head', static function () use ($db, $p, $all, $verify) {
            $last = $all[3];
            $db->query("CREATE TEMPORARY TABLE {$p}teamf_one AS SELECT * FROM {$p}appointments_teamlog WHERE id={$last}");
            $db->query("CREATE TEMPORARY TABLE {$p}teamf_head AS SELECT * FROM {$p}appointments_teamlog_head");
            $db->query("DELETE FROM {$p}appointments_teamlog WHERE id={$last}");
            $plain = $verify();
            // The strongest copy without the key: the link the deleted row carried.
            $db->query("UPDATE {$p}appointments_teamlog_head h JOIN {$p}teamf_one l SET h.last_id={$all[2]}, h.last_hash=l.prev_hash");
            $adjusted = $verify();
            $db->query("INSERT INTO {$p}appointments_teamlog SELECT * FROM {$p}teamf_one");
            $db->query("DELETE FROM {$p}appointments_teamlog_head");
            $db->query("INSERT INTO {$p}appointments_teamlog_head SELECT * FROM {$p}teamf_head");
            $db->query("DROP TEMPORARY TABLE {$p}teamf_one");
            $db->query("DROP TEMPORARY TABLE {$p}teamf_head");

            return $plain['intact'] === false && $adjusted['intact'] === false && $verify()['intact'] === true;
        });

        $suite->test('Parallel appends never share a predecessor (head locked FOR UPDATE)', static function () use ($tag, $verify, $ids) {
            $before = count($ids());
            // The real burst: three service processes started together.
            $script = sys_get_temp_dir() . '/' . $tag . '-burst.php';
            file_put_contents($script, '<?php
require "/var/www/html/tests/scripts/appointments-joomla.php";
$services = Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication();
for ($i = 0; $i < 15; $i++) { $services->audit()->log("object.updated", ["calendar_id" => 7]); }
');
            $burst = [];

            for ($n = 0; $n < 3; $n++) {
                $burst[] = proc_open(['php', $script], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, '/var/www/html');
            }

            foreach ($burst as $proc) {
                proc_close($proc);
            }

            unlink($script);
            $v = $verify();

            return $v['intact'] === true && count($ids()) === $before + 45;
        });

        $suite->test('A cut end stays detected after the next event (the head is not sealed over)', static function () use ($db, $p, $ids, $run, $verify) {
            $all = $ids();
            $db->query("DELETE FROM {$p}appointments_teamlog WHERE id=" . end($all));
            $db->query("UPDATE {$p}appointments_teamlog_head SET last_id=" . prev($all));
            $run('$services->audit()->log(AuditEvent::LOGIN_FAILURE, ["subject_user_id" => 42]); return true;');

            return $verify()['intact'] === false;
        });

        $fresh();
        $run('$services->audit()->log(AuditEvent::LOGIN_SUCCESS, ["actor_user_id" => 42]); return true;');
        $suite->test('A head reset to the empty state stays detected after the next event', static function () use ($db, $p, $run, $verify, $tag) {
            $db->query("INSERT INTO {$p}appointments_calendars (uri, title, owner_user_id, row_mac, created_utc, modified_utc) VALUES ('" . md5($tag . 'w') . "', 'F wipe', 0, REPEAT(CHAR(0), 32), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $calendar = (int) $db->insert_id;

            try {
                $empty = $run('return bin2hex(AuditService::emptyHead($services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_LOG)));');
                $db->query("DELETE FROM {$p}appointments_teamlog");
                $db->query("UPDATE {$p}appointments_teamlog_head SET last_id=0, last_hash=UNHEX('{$empty}'), pruned_through_id=0, pruned_through_hash=NULL");
                $run('$services->audit()->log(AuditEvent::LOGIN_FAILURE, ["subject_user_id" => 42]); return true;');

                return $verify()['intact'] === false;
            } finally {
                $db->query("DELETE FROM {$p}appointments_calendars WHERE id={$calendar}");
            }
        });

        $fresh();
        $suite->test('Repeated CalDAV failures are written once per minute, also from changing IPv6 addresses of one /64', static function () use ($run, $db, $p) {
            $run('for ($i = 0; $i < 5; $i++) { $services->audit()->log(AuditEvent::DAV_AUTH_FAILURE, ["object_ref" => "dav:x", "ip" => "2001:db8:5:5::" . dechex($i + 1), "details" => ["reason" => "bad"]]); }
                $now = $now->modify("+61 seconds");
                $services->audit()->log(AuditEvent::DAV_AUTH_FAILURE, ["object_ref" => "dav:x", "ip" => "2001:db8:5:5::99", "details" => ["reason" => "bad"]]);
                return true;');

            return (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_teamlog WHERE event='dav.auth_failure'")->fetch_row()[0] === 2;
        });

        $fresh();
        $suite->test('Pruning removes rows older than twelve months and the rest still verifies', static function () use ($run, $verify, $db, $p, $ids) {
            $run('$services->audit()->log(AuditEvent::LOGIN_SUCCESS, ["actor_user_id" => 42]);
                $services->audit()->log(AuditEvent::LOGIN_FAILURE, ["subject_user_id" => 42]); return true;', '-13 months');
            $run('$services->audit()->log(AuditEvent::DEVICE_RENAMED, ["actor_user_id" => 42]); return true;', '-1 month');
            $deleted = $run('return $services->audit()->prune($now);');
            $v = $verify();
            $head = $db->query("SELECT pruned_through_id FROM {$p}appointments_teamlog_head")->fetch_assoc();
            $events = [];
            $result = $db->query("SELECT event FROM {$p}appointments_teamlog ORDER BY id");

            while ($result && ($r = $result->fetch_row())) {
                $events[] = $r[0];
            }

            return $deleted === 2 && $v['intact'] === true && (int) $head['pruned_through_id'] > 0
                && $events === ['device.renamed', 'log.pruned'] && $run('return $services->audit()->prune($now);') === 0;
        });

        $fresh();
        $suite->test('Pruning a log that is entirely older than twelve months removes every expired row and still verifies', static function () use ($run, $verify, $ids) {
            $run('foreach ([AuditEvent::LOGIN_SUCCESS, AuditEvent::LOGIN_FAILURE, AuditEvent::DEVICE_RENAMED] as $e) { $services->audit()->log($e, ["actor_user_id" => 42]); } return true;', '-14 months');
            $deleted = $run('return $services->audit()->prune($now);');
            $after = $verify();
            $run('$services->audit()->log(AuditEvent::LOGIN_SUCCESS, ["actor_user_id" => 42]); return true;');

            return $deleted === 3 && $after['intact'] === true && count($ids()) === 2 && $verify()['intact'] === true;
        });

        $suite->test('Every MAC failure is written, also many within a day', static function () use ($run, $db, $p) {
            $run('foreach (["grants", "devices", "user_keys"] as $t) { $services->audit()->log(AuditEvent::ROW_MAC_FAILURE, ["details" => ["table" => $t, "reason" => "mismatch"]]); $services->audit()->log(AuditEvent::ROW_MAC_FAILURE, ["details" => ["table" => $t, "reason" => "mismatch"]]); } return true;');

            return (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_teamlog WHERE event='key.mac_failure'")->fetch_row()[0] === 6;
        });

        $fresh();
        $run('$services->audit()->log(AuditEvent::LOGIN_SUCCESS, ["actor_user_id" => 42], ); return true;', '-13 months');
        $run('$services->audit()->log(AuditEvent::LOGIN_SUCCESS, ["actor_user_id" => 42]); $services->audit()->log(AuditEvent::LOGIN_SUCCESS, ["actor_user_id" => 42]); $services->audit()->prune($now); return true;');
        $suite->test('Removing the oldest rows without pruning is detected', static function () use ($db, $p, $ids, $verify) {
            $first = $ids()[0];
            $db->query("DELETE FROM {$p}appointments_teamlog WHERE id={$first}");
            $db->query("UPDATE {$p}appointments_teamlog_head h JOIN {$p}appointments_teamlog l ON l.id=(SELECT MIN(id) FROM {$p}appointments_teamlog) SET h.pruned_through_id={$first}, h.pruned_through_hash=l.prev_hash");

            return $verify()['intact'] === false;
        });

        $fresh();
        $suite->test('A log restarted after its head was deleted stays reported as broken', static function () use ($run, $verify, $db, $p, $tag, $fresh) {
            $db->query("INSERT INTO {$p}appointments_calendars (uri, title, owner_user_id, row_mac, created_utc, modified_utc) VALUES ('" . md5($tag . 'x') . "', 'F restart', 0, REPEAT(CHAR(0), 32), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $calendar = (int) $db->insert_id;

            try {
                $run('$services->audit()->log(AuditEvent::LOGIN_FAILURE, ["subject_user_id" => 42]); return true;');
                $restarted = $verify();
                $marker = $db->query("SELECT pruned_through_hash IS NOT NULL FROM {$p}appointments_teamlog_head")->fetch_row()[0];
            } finally {
                $db->query("DELETE FROM {$p}appointments_calendars WHERE id={$calendar}");
                $fresh();
            }

            return $restarted['intact'] === false && (int) $marker === 1;
        });

        $suite->test('An emptied log with its head removed is detected while calendar data exists', static function () use ($verify, $db, $p) {
            $empty = $verify();
            $hasData = (int) $db->query("SELECT (SELECT COUNT(*) FROM {$p}appointments_calendars) + (SELECT COUNT(*) FROM {$p}appointments_devices) + (SELECT COUNT(*) FROM {$p}appointments_calendar_grants)")->fetch_row()[0] > 0;

            return $empty['intact'] === !$hasData;
        });
    } finally {
        $db->query("DELETE FROM {$p}appointments_teamlog");
        $db->query("DELETE FROM {$p}appointments_teamlog_head");
        $db->query("INSERT INTO {$p}appointments_teamlog SELECT * FROM {$p}{$tag}_log");
        $db->query("INSERT INTO {$p}appointments_teamlog_head SELECT * FROM {$p}{$tag}_head");
        $db->query("DROP TABLE IF EXISTS {$p}{$tag}_log");
        $db->query("DROP TABLE IF EXISTS {$p}{$tag}_head");

        if ($anchorCopy !== null) {
            file_put_contents($anchorFile, $anchorCopy);
        } elseif (is_file($anchorFile)) {
            unlink($anchorFile);
        }
    }

    $suite->test('The earlier parts\' log is back in the state it had', static function () use ($verify, $earlier) {
        $now = $verify();

        return $now['intact'] === $earlier['intact'] && $now['rows'] === $earlier['rows'];
    });

    // ---- Lock-out, with the service's clock -----------------------------------
    $user = $tag . '-lock';
    $other = $tag . '-other';
    $lock = static fn (string $code, string $now = 'now'): mixed => $run(
        '$u = ' . var_export($user, true) . '; $o = ' . var_export($other, true) . '; $l = $services->lockout();' . "\n" . $code, $now);

    try {
        $suite->test('Authenticated lock checks neither consume attempts nor clear existing account or address locks', static function () use ($lock) {
            $r = $lock('$subject = $u . "-read-only";
                for ($i = 0; $i < 20; $i++) {
                    if ($l->remainingLock($subject, "203.0.113.201") !== 0) { return false; }
                }
                for ($i = 0; $i < 9; $i++) { $l->recordFailure($subject, "203.0.113.201"); }
                $forget();
                for ($i = 0; $i < 20; $i++) {
                    if ($l->remainingLock($subject, "203.0.113.201") !== 0) { return false; }
                }
                $l->recordFailure($subject, "203.0.113.201"); $forget();
                $userLock = $l->remainingLock($subject, "203.0.113.202");
                $addressLock = $l->remainingLock($subject . "-other", "203.0.113.201");
                return $userLock > 0 && $addressLock > 0
                    && $l->remainingLock($subject, "203.0.113.202") === $userLock;');

            return $r === true;
        });

        $suite->test('Nine failures do not lock, the tenth locks for one minute', static function () use ($lock) {
            $r = $lock('for ($i = 0; $i < 9; $i++) { $l->recordFailure($u, "198.51.100.7"); }
                $nine = $l->retryAfter($u, "198.51.100.7");
                $l->recordFailure($u, "198.51.100.7"); $forget();
                return [$nine, $l->retryAfter($u, "198.51.100.7"), $l->retryAfter(strtoupper($u), "203.0.113.9")];');

            return $r[0] === 0 && $r[1] > 50 && $r[1] <= 60 && $r[2] > 50;
        });

        $suite->test('The delay doubles per further failure up to 60 minutes, never permanent', static function () use ($lock) {
            $r = $lock('$waits = [];
                for ($i = 0; $i < 8; $i++) { $now = $now->modify("+2 hours"); $forget(); $l->retryAfter($u, "198.51.100.7"); $l->recordFailure($u, "198.51.100.7"); $forget(); $waits[] = $l->retryAfter($u, "198.51.100.7"); }
                $now = $now->modify("+61 minutes"); $forget(); $waits[] = $l->retryAfter($u, "198.51.100.7");
                return $waits;');
            // Failures 11 to 18: 2, 4, 8, 16, 32, 60, 60, 60 minutes; an hour later the next attempt is allowed.
            $expected = [120, 240, 480, 960, 1920, 3600, 3600, 3600];

            foreach ($expected as $i => $seconds) {
                if ($r[$i] > $seconds || $r[$i] < $seconds - 5) {
                    return false;
                }
            }

            return $r[8] === 0;
        });

        $suite->test('Changing the address does not escape the user lock, and the address locks other names', static function () use ($lock) {
            $r = $lock('$l->recordSuccess($u, "198.51.100.7");
                for ($i = 0; $i < 10; $i++) { $l->recordFailure($u, "2001:db8:7:7::" . dechex($i + 1)); }
                $forget();
                return [$l->retryAfter($u, "203.0.113.50"), $l->retryAfter($o, "2001:db8:7:7:ffff::1"), $l->retryAfter($o, "2001:db8:7:8::1")];');

            return $r[0] > 0 && $r[1] > 0 && $r[2] === 0;
        });

        $suite->test('Parallel attempts at the threshold get one try, the others wait', static function () use ($lock, $tag) {
            $name = $tag . '-burst';
            $lock('for ($i = 0; $i < 9; $i++) { $l->recordFailure(' . var_export($name, true) . ', ""); } return true;');
            $script = sys_get_temp_dir() . '/' . $tag . '-claim.php';
            file_put_contents($script, '<?php
require "/var/www/html/tests/scripts/appointments-joomla.php";
echo Advans\Component\Appointments\Administrator\Service\Team\TeamServices::fromApplication()->lockout()->retryAfter(' . var_export($name, true) . ', "");
// Hold the reservation like a request that is still checking the password.
flush();
sleep(3);
');
            $procs = [];
            $pipes = [];

            for ($n = 0; $n < 5; $n++) {
                $procs[$n] = proc_open(['php', $script], [1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes[$n], '/var/www/html');
            }

            $waits = [];

            foreach ($procs as $n => $proc) {
                $waits[] = (int) trim((string) stream_get_contents($pipes[$n][1]));
                proc_close($proc);
            }

            unlink($script);
            sort($waits);

            return $waits[0] === 0 && $waits[1] > 0 && $waits[4] <= 30;
        });

        $suite->test('A success releases the user, counters are forgotten after 24 hours', static function () use ($lock, $db, $p) {
            $r = $lock('$l->recordSuccess($u, ""); $forget(); $a = $l->retryAfter($u, "");
                $now = $now->modify("+25 hours"); $forget(); return [$a, $l->retryAfter($o, "2001:db8:7:7::1"), $l->purge($now)];');

            return $r[0] === 0 && $r[1] === 0 && $r[2] >= 2;
        });

        $suite->test('A refused website sign-in counts no second failure', static function () use ($lock) {
            $r = $lock('$_SERVER["REQUEST_METHOD"] = "POST";
                for ($i = 0; $i < 10; $i++) { $l->recordFailure($u, ""); }
                $p = new ReflectionProperty(LockoutService::class, "refused");
                $key = (new ReflectionMethod(LockoutService::class, "refusalKey"))->invoke($l, $u, "");
                $p->setValue(null, [$key => true]);
                $l->recordFailure($u, "");
                $forget();
                $b = LockoutService::buckets($services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_AUTH_BUCKET), LockoutService::identityFor(0, $u), "")["user"];
                return $services->db()->setQuery("SELECT failures FROM #__appointments_auth_failures WHERE bucket = UNHEX(" . $services->db()->quote(bin2hex($b)) . ")")->loadResult();');

            return (int) $r === 10;
        });
    } finally {
        $lock('foreach ([$u, $o, $u . "-read-only", $u . "-burst", ' . var_export($tag . '-burst', true) . '] as $name) {
            foreach (LockoutService::buckets($services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_AUTH_BUCKET), LockoutService::identityFor(0, $name), "") as $b) {
                $services->db()->setQuery("DELETE FROM #__appointments_auth_failures WHERE bucket = UNHEX(" . $services->db()->quote(bin2hex($b)) . ")")->execute();
            }
        }
        foreach (["198.51.100.7", "203.0.113.50", "203.0.113.9", "203.0.113.201", "2001:db8:7:7::1", "2001:db8:7:8::1"] as $ip) {
            $b = LockoutService::buckets($services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_AUTH_BUCKET), "name:x", $ip)["ip"];
            $services->db()->setQuery("DELETE FROM #__appointments_auth_failures WHERE bucket = UNHEX(" . $services->db()->quote(bin2hex($b)) . ")")->execute();
        }
        return true;');
    }

    // ---- The sign-in guard on every core sign-in form --------------------------
    $grantsStub = $suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\GrantService::class);
    // A locked calendar account posts its correct password through the forms
    // Joomla itself offers: the site login (also what the login module posts)
    // and the administrator login (field passwd). The guard must replace the
    // password before Joomla compares it.
    $guard = static fn (string $client, string $option, string $task, string $field): string => '
        $db = $services->db();
        $group = (int) (((array) $services->params()->get("team_groups", []))[0] ?? 0);
        $name = "teamfguard" . bin2hex(random_bytes(3));
        $db->setQuery("INSERT INTO #__users (name, username, email, password, block, registerDate, params) VALUES (\"F guard\", \"$name\", \"$name@example.test\", \"\", 0, UTC_TIMESTAMP(), \"{}\")")->execute();
        $uid = (int) $db->insertid();
        $db->setQuery("INSERT INTO #__user_usergroup_map (user_id, group_id) VALUES ($uid, $group)")->execute();

        try {
            for ($i = 0; $i < 10; $i++) { $services->lockout()->recordFailure($name, "192.0.2.77"); }
            $forget();
            $input = $application->getInput();
            (new ReflectionProperty($application, "name"))->setValue($application, ' . var_export($client, true) . ');
            $input->server->set("REQUEST_METHOD", "POST");
            $input->server->set("REMOTE_ADDR", "192.0.2.77");
            foreach (["option" => ' . var_export($option, true) . ', "task" => ' . var_export($task, true) . ', "username" => $name, ' . var_export($field, true) . ' => "the-correct-password"] as $k => $v) {
                $input->set($k, $v);
                $input->post->set($k, $v);
            }
            $input->post->set(\Joomla\CMS\Session\Session::getFormToken(), "1");
            $services->lockout()->guardLogin($application);
            $replaced = $input->post->get(' . var_export($field, true) . ', "", "raw");

            return $replaced !== "the-correct-password" && strlen($replaced) === 64 && $services->lockout()->wasRefused($name, "192.0.2.77");
        } finally {
            $db->setQuery("DELETE FROM #__user_usergroup_map WHERE user_id = $uid")->execute();
            $db->setQuery("DELETE FROM #__users WHERE id = $uid")->execute();
        }';

    foreach ([['site', 'com_users', 'user.login', 'password', 'site sign-in form and login module'],
        ['administrator', 'com_login', 'login', 'passwd', 'administrator sign-in form']] as [$client, $option, $task, $field, $label]) {
        if ($grantsStub) {
            $suite->pending('A locked account\'s correct password never reaches Joomla through the ' . $label, 'C');
        } else {
            $suite->test('A locked account\'s correct password never reaches Joomla through the ' . $label, static function () use ($run, $guard, $client, $option, $task, $field) {
                return $run($guard($client, $option, $task, $field)) === true;
            });
        }
    }

    // ---- HTTP integration: revoke, website lock-out and CalDAV lock-out ------
    $httpNeeds = [
        'A' => [
            \Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStore::class,
        ],
        'B' => [
            \Advans\Component\Appointments\Administrator\Service\Team\EpochAnchor::class,
            \Advans\Component\Appointments\Administrator\Service\Team\KeyService::class,
            \Advans\Component\Appointments\Administrator\Service\Team\KeySession::class,
        ],
        'C' => [
            \Advans\Component\Appointments\Administrator\Service\Team\CalendarService::class,
            \Advans\Component\Appointments\Administrator\Service\Team\GrantService::class,
        ],
        'D' => [
            \Advans\Component\Appointments\Administrator\Service\Team\DeviceService::class,
        ],
        'E' => [
            \Advans\Component\Appointments\Administrator\Service\Team\TimingIndex::class,
        ],
        'F' => [
            \Advans\Component\Appointments\Administrator\Service\Team\AuditService::class,
            \Advans\Component\Appointments\Administrator\Service\Team\CleanupService::class,
            \Advans\Component\Appointments\Administrator\Service\Team\LockoutService::class,
        ],
    ];
    $openPackages = static function (array $packages) use ($suite, $httpNeeds): string {
        $open = [];

        foreach ($packages as $package) {
            foreach ($httpNeeds[$package] as $class) {
                if ($suite->isStub($class)) {
                    $open[] = $package;
                    break;
                }
            }
        }

        if (!str_starts_with($suite->base, 'https://')) {
            $open[] = 'G';
        }

        return implode(', ', array_unique($open));
    };
    $startHttpFixture = static function () use ($db, $p, $suite): array {
        $paramsRow = $db->query("SELECT params FROM {$p}extensions WHERE type='component' AND element='com_appointments'")->fetch_assoc();
        $originalParams = (string) ($paramsRow['params'] ?? '{}');
        $params = json_decode($originalParams, true) ?: [];
        $params['teamcal_enabled'] = 1;
        $statement = $db->prepare("UPDATE {$p}extensions SET params=? WHERE type='component' AND element='com_appointments'");
        $encoded = json_encode($params);
        $statement->bind_param('s', $encoded);
        $statement->execute();
        $plugins = [];

        foreach ([['system', 'appointmentsdav'], ['user', 'appointments'], ['multifactorauth', 'totp'], ['privacy', 'appointments']] as [$folder, $element]) {
            $result = $db->query("SELECT extension_id, enabled FROM {$p}extensions WHERE type='plugin' AND folder='{$folder}' AND element='{$element}'");

            while ($result && ($row = $result->fetch_assoc())) {
                $plugins[] = [(int) $row['extension_id'], (int) $row['enabled']];
                $db->query("UPDATE {$p}extensions SET enabled=1 WHERE extension_id=" . (int) $row['extension_id']);
            }
        }

        $fixture = null;
        try {
            $fixture = new TeamDavFixture($suite);
            // Earlier parts loaded ComponentHelper's feature-off options in this process.
            $fixture->services = new \Advans\Component\Appointments\Administrator\Service\Team\TeamServices(
                $fixture->services->db(),
                $fixture->services->keyProvider(),
                new \Joomla\Registry\Registry($params),
                static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'))
            );
            $fixture->create();
        } catch (Throwable $e) {
            $cleanupError = null;

            if ($fixture !== null && !$fixture->cleanup()) {
                $cleanupError = implode('; ', $fixture->cleanupErrors);
            }

            $statement = $db->prepare("UPDATE {$p}extensions SET params=? WHERE type='component' AND element='com_appointments'");
            $statement->bind_param('s', $originalParams);
            $statement->execute();

            foreach ($plugins as [$extensionId, $enabled]) {
                $db->query("UPDATE {$p}extensions SET enabled={$enabled} WHERE extension_id={$extensionId}");
            }

            if ($cleanupError !== null) {
                throw new RuntimeException('Fixture creation failed and cleanup failed: ' . $cleanupError, 0, $e);
            }

            throw $e;
        }

        return [$fixture, $originalParams, $plugins];
    };
    $stopHttpFixture = static function (?TeamDavFixture $fixture, ?string $originalParams, array $plugins) use ($db, $p): void {
        $cleanupError = null;

        if ($fixture !== null) {
            if (!$fixture->cleanup()) {
                $cleanupError = implode('; ', $fixture->cleanupErrors);
            }
        }

        if ($originalParams !== null) {
            $statement = $db->prepare("UPDATE {$p}extensions SET params=? WHERE type='component' AND element='com_appointments'");
            $statement->bind_param('s', $originalParams);
            $statement->execute();
        }

        foreach ($plugins as [$extensionId, $enabled]) {
            $db->query("UPDATE {$p}extensions SET enabled={$enabled} WHERE extension_id={$extensionId}");
        }

        if ($cleanupError !== null) {
            throw new RuntimeException($cleanupError);
        }
    };
    $installTotp = static function (TeamDavFixture $fixture, string $account) use ($run): string {
        $secret = '';
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

        for ($i = 0; $i < 32; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }

        $userId = (int) $fixture->accounts[$account]['id'];
        $saved = $run('$userId = ' . $userId . ';
            $db = $services->db();
            $db->setQuery("DELETE FROM #__user_mfa WHERE user_id = $userId")->execute();
            $mfa = $application->bootComponent("com_users")->getMVCFactory()->createTable("Mfa", "Administrator");
            $mfa->save(["user_id" => $userId, "title" => "F HTTP integration factor", "method" => "totp", "default" => 1,
                "options" => ["key" => ' . var_export($secret, true) . ']]);
            return (int) $db->setQuery("SELECT COUNT(*) FROM #__user_mfa WHERE user_id = $userId AND method = \"totp\"")->loadResult() === 1;');

        if ($saved !== true) {
            throw new RuntimeException('Could not configure a real TOTP factor for the HTTP fixture.');
        }

        return $secret;
    };
    $siteLoginWithTotp = static function (TeamDBrowser $browser, array $account, string $secret): bool {
        $form = TeamDBrowser::form($browser->request('/index.php?option=com_users&view=login'), 'user.login');

        if ($form === null) {
            return false;
        }

        $html = $browser->request($form['action'], $form['fields'] + [
            'username' => $account['username'],
            'password' => $account['password'],
        ]);
        $captive = TeamDBrowser::form($html, 'captive.validate');

        if ($captive === null) {
            return false;
        }

        $browser->request($captive['action'], $captive['fields'] + ['code' => team_d_totp($secret, time())]);

        return $browser->status >= 200 && $browser->status < 400;
    };

    $httpFixture = null;
    $httpFixtureParams = null;
    $httpFixturePlugins = [];
    $httpFixtureError = null;
    $httpPackages = ['A', 'B', 'C', 'D', 'F'];

    if ($openPackages($httpPackages) === '') {
        try {
            [$httpFixture, $httpFixtureParams, $httpFixturePlugins] = $startHttpFixture();
        } catch (Throwable $e) {
            $httpFixtureError = $e;
        }
    }

    $revokeOpen = $openPackages(['A', 'B', 'C', 'D', 'F']);
    if ($revokeOpen !== '') {
        $suite->pending('«Revoke now» in the devices view refuses the device with 401 from the next request on (HTTP flow)', $revokeOpen);
    } else {
        $suite->test('«Revoke now» in the devices view refuses the device with 401 from the next request on (HTTP flow)', static function () use ($suite, &$httpFixture, $httpFixtureError, $p) {
            if (!$httpFixture) {
                throw new RuntimeException('HTTP fixture setup failed: ' . ($httpFixtureError?->getMessage() ?? 'not available'));
            }

            $account = $httpFixture->accounts['reader'];
            $path = $httpFixture->home('reader');
            $basic = 'Authorization: Basic ' . base64_encode($account['username'] . ':' . $account['app_password']);
            $before = $suite->http('PROPFIND', $path, ['Depth: 0', $basic]);

            if ($before['status'] !== 207) {
                return false;
            }

            $admin = new TeamDBrowser($suite->base);
            $login = TeamDBrowser::form($admin->request('/administrator/index.php'), 'login');

            if ($login === null) {
                return false;
            }

            $admin->request($login['action'], $login['fields'] + [
                'username' => getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin',
                'passwd' => getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#',
            ]);
            $html = $admin->request('/administrator/index.php?option=com_appointments&view=devices');
            $form = TeamDBrowser::form($html, 'view=devices');

            if ($admin->status !== 200 || $form === null || !str_contains($html, $account['username'])) {
                return false;
            }

            $admin->request($form['action'], array_merge($form['fields'], [
                'cid' => [(string) $account['device_id']],
                'task' => 'devices.revoke',
            ]), 0);

            if (!in_array($admin->status, [200, 303, 302], true)) {
                return false;
            }

            $after = $suite->http('PROPFIND', $path, ['Depth: 0', $basic]);
            $state = $suite->count('SELECT COUNT(*) FROM ' . $p . 'appointments_devices WHERE id=' . (int) $account['device_id'] . ' AND state=0');

            return $after['status'] === 401 && $state === 1;
        });
    }

    // ---- Website and CalDAV sign-in over HTTP ---------------------------------
    $websiteOpen = $openPackages(['A', 'B', 'C', 'D', 'F']);
    if ($websiteOpen !== '') {
        $suite->pending('Website sign-in while locked fails exactly like a wrong password, also with the correct password (HTTP flow with MFA account)', $websiteOpen);
    } else {
        $suite->test('Website sign-in while locked fails exactly like a wrong password, also with the correct password (HTTP flow with MFA account)', static function () use ($suite, &$httpFixture, $httpFixtureError, $run, $installTotp, $p) {
            if (!$httpFixture) {
                throw new RuntimeException('HTTP fixture setup failed: ' . ($httpFixtureError?->getMessage() ?? 'not available'));
            }

            $account = $httpFixture->accounts['lockout'];
            $totpSecret = $installTotp($httpFixture, 'lockout');
            $run('$u = ' . var_export($account['username'], true) . ';
                for ($i = 0; $i < 10; $i++) { $forget(); $services->lockout()->recordFailure($u, "198.51.100.77"); }
                return true;');
            $submit = static function (string $password) use ($suite, $account): array {
                $browser = new TeamDBrowser($suite->base);
                $form = TeamDBrowser::form($browser->request('/index.php?option=com_users&view=login'), 'user.login');

                if ($form === null) {
                    throw new RuntimeException('The Joomla website login form was not rendered.');
                }

                $body = $browser->request($form['action'], $form['fields'] + [
                    'username' => $account['username'],
                    'password' => $password,
                ]);
                $document = new DOMDocument();
                @$document->loadHTML('<?xml encoding="utf-8"?>' . $body);
                $messages = [];

                foreach ((new DOMXPath($document))->query('//*[contains(concat(" ", normalize-space(@class), " "), " alert ")]') as $node) {
                    $messages[] = trim((string) preg_replace('/\s+/u', ' ', $node->textContent));
                }

                return ['status' => $browser->status, 'body' => $body, 'messages' => $messages];
            };
            $wrong = $submit('wrong-' . bin2hex(random_bytes(12)));
            $correct = $submit($account['password']);

            return $wrong['status'] === $correct['status']
                && $wrong['messages'] !== []
                && $wrong['messages'] === $correct['messages']
                && preg_match('/password|username|credentials|sign.?in/i', implode(' ', $correct['messages'])) === 1
                && TeamDBrowser::form($wrong['body'], 'captive.validate') === null
                && TeamDBrowser::form($correct['body'], 'captive.validate') === null
                && $suite->count("SELECT COUNT(*) FROM {$p}user_mfa WHERE user_id=" . (int) $account['id'] . " AND method='totp'") === 1
                && $totpSecret !== '';
        });
    }

    $davOpen = $openPackages(['A', 'B', 'C', 'D', 'F']);
    if ($davOpen !== '') {
        $suite->pending('CalDAV answers 429 with Retry-After while locked, also for a correct app password (HTTP flow)', $davOpen);
    } else {
        $suite->test('CalDAV answers 429 with Retry-After while locked, also for a correct app password (HTTP flow)', static function () use ($suite, &$httpFixture, $httpFixtureError, $run) {
            if (!$httpFixture) {
                throw new RuntimeException('HTTP fixture setup failed: ' . ($httpFixtureError?->getMessage() ?? 'not available'));
            }

            $account = $httpFixture->accounts['lockout'];
            $path = $httpFixture->home('lockout');
            $header = static function (array $response, string $name): string {
                return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $response['headers'], $m) === 1 ? $m[1] : '';
            };

            $request = static function (string $password) use ($suite, $path, $account): array {
                $ch = curl_init(rtrim($suite->base, '/') . $path);
                curl_setopt_array($ch, [
                    CURLOPT_CUSTOMREQUEST => 'PROPFIND',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HEADER => true,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_TIMEOUT => 30,
                    CURLOPT_INTERFACE => TeamDavFixture::LOCKOUT_SOURCE_IP,
                    CURLOPT_HTTPHEADER => [
                        'Depth: 0',
                        'Authorization: Basic ' . base64_encode($account['username'] . ':' . $password),
                    ],
                    CURLOPT_CAINFO => '/etc/ssl/appointments-test/ca.pem',
                ]);
                $raw = (string) curl_exec($ch);
                $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                return ['status' => $status, 'headers' => $raw];
            };

            for ($attempt = 0; $attempt < 10; $attempt++) {
                $wrong = $request(str_repeat('A', 32));

                if ($wrong['status'] !== 401) {
                    return false;
                }
            }

            $correct = $request($account['app_password']);

            return $correct['status'] === 429 && (int) $header($correct, 'Retry-After') > 0;
        });
    }

    try {
        if ($httpFixture !== null) {
            // The website bucket is distinct from CalDAV's account bucket, but the
            // source-address bucket is shared and must not survive this fixture.
            $websiteUserId = (int) ($httpFixture->accounts['lockout']['id'] ?? 0);
            if ($websiteUserId > 0) {
                $run('$id = ' . $websiteUserId . '; $ip = "198.51.100.77";
                    $key = $services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_AUTH_BUCKET);
                    try {
                        foreach (LockoutService::buckets($key, LockoutService::identityFor($id, ""), $ip) as $bucket) {
                            $services->db()->setQuery("DELETE FROM #__appointments_auth_failures WHERE bucket = UNHEX(" . $services->db()->quote(bin2hex($bucket)) . ")")->execute();
                        }
                    } finally { \Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite::wipe($key); }
                    return true;');
            }
        }
    } finally {
        if ($httpFixtureParams !== null || $httpFixture !== null) {
            try {
                $stopHttpFixture($httpFixture, $httpFixtureParams, $httpFixturePlugins);
            } catch (Throwable $e) {
                $suite->test('HTTP fixture cleanup restored all test rows and settings', static function () use ($e): bool {
                    throw new RuntimeException($e->getMessage(), 0, $e);
                });
            }
        }
    }

    // ---- Clean-up phases ------------------------------------------------------
    if ($suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\DeviceService::class)) {
        $suite->pending('Clean-up phases empty, hidden, revoked by delivery and by the Scheduler timeout', 'D');
    } else {
        $suite->test('Clean-up phases empty, hidden, revoked by delivery and by the Scheduler timeout', static function () use ($run, $db, $p, $tag) {
            $db->query("INSERT INTO {$p}appointments_calendars (uri, title, owner_user_id, row_mac, created_utc, modified_utc) VALUES ('" . md5($tag) . "', 'F clean-up', 0, REPEAT(CHAR(0), 32), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $calendar = (int) $db->insert_id;

            try {
                $r = $run('$c = ' . $calendar . '; $db = $services->db(); $out = [];
                    foreach ([1, 2] as $n) {
                        $db->setQuery("INSERT INTO #__appointments_devices (user_id, name, client, secret_hash, kek_salt, user_key_version, state, rule_version, rule_accepted_utc, created_utc, row_mac) VALUES (999999, \"F device $n\", \"other\", UNHEX(\"" . bin2hex(random_bytes(32)) . "\"), UNHEX(\"" . bin2hex(random_bytes(16)) . "\"), 1, 1, \"1\", UTC_TIMESTAMP(), UTC_TIMESTAMP(), REPEAT(CHAR(0), 32))")->execute();
                        $ids[$n] = (int) $db->insertid();
                        $db->setQuery("INSERT INTO #__appointments_device_calendars (device_id, calendar_id, role, last_sync_utc) VALUES ($ids[$n], $c, 2, UTC_TIMESTAMP())")->execute();
                        $services->cleanup()->startForDevice($ids[$n], "test", null);
                    }
                    $state = static fn (int $id): int => (int) $db->setQuery("SELECT state FROM #__appointments_devices WHERE id = $id")->loadResult();
                    $session = new Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession($ids[1], 999999, "x", TeamStates::DEVICE_CLEANUP_EMPTY, null);
                    $out[] = $state($ids[1]);
                    $out[] = $services->cleanup()->viewFor($session, $c);
                    $out[] = $services->cleanup()->calendarsInCleanup($session);
                    $services->cleanup()->markDelivered($session, $c);
                    $out[] = $state($ids[1]);
                    $services->cleanup()->markHomeSeen(new Advans\Component\Appointments\Administrator\Helper\Team\DeviceSession($ids[1], 999999, "x", TeamStates::DEVICE_CLEANUP_HIDDEN, null));
                    $out[] = $state($ids[1]);
                    $now = $now->modify("+15 days"); $services->cleanup()->advance($now); $out[] = $state($ids[2]);
                    $now = $now->modify("+15 days"); $services->cleanup()->advance($now); $out[] = $state($ids[2]);
                    $db->setQuery("DELETE FROM #__appointments_devices WHERE user_id = 999999")->execute();
                    return $out;');
            } finally {
                $db->query("DELETE FROM {$p}appointments_calendars WHERE id={$calendar}");
            }

            return $r === [2, 'empty', [$calendar => ['role' => 2, 'synctoken' => 0]], 3, 0, 3, 0];
        });
    }

    // ---- Scheduler routines over the real CLI ---------------------------------
    $params = $db->query("SELECT params FROM {$p}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc()['params'];
    $tasks = [];
    $routine = static function (string $name) use ($db, $p, &$tasks): array {
        $type = $db->real_escape_string('plg_task_appointments.' . $name);
        $db->query("INSERT INTO {$p}scheduler_tasks (title,type,execution_rules,cron_rules,state,last_exit_code,times_executed,times_failed,priority,ordering,params,note,created,created_by) VALUES ('Appointments F test','{$type}','{\"rule-type\":\"manual\"}','{\"type\":\"manual\",\"exp\":\"\"}',1,0,0,0,0,0,'{}','',UTC_TIMESTAMP(),0)");
        $id = (int) $db->insert_id;
        $tasks[] = $id;
        exec('cd /var/www/html && HTTP_HOST=localhost php cli/joomla.php scheduler:run --id=' . $id . ' 2>&1', $output, $exit);
        $row = $db->query("SELECT times_executed, last_exit_code FROM {$p}scheduler_tasks WHERE id={$id}")->fetch_assoc();

        return ['exit' => $exit, 'executed' => (int) $row['times_executed'], 'code' => (int) $row['last_exit_code']];
    };

    try {
        $bridgeStub = $suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\BookingCalendarBridge::class);
        $suite->test('The internet calendar routines run and do nothing while the feature is off', static function () use ($routine) {
            foreach (['teamcleanup', 'teamlog', 'teaminvitations'] as $name) {
                $r = $routine($name);

                if ($r['executed'] !== 1 || $r['code'] !== 0) {
                    return false;
                }
            }

            return true;
        });

        if ($bridgeStub) {
            $suite->pending('The calendar retention routine removes orphaned booking copies, also with the feature off', 'E');
        } else {
            $suite->test('The calendar retention routine removes orphaned booking copies, also with the feature off', static function () use ($routine) {
                $r = $routine('teamretention');

                return $r['executed'] === 1 && $r['code'] === 0;
            });
        }

        $on = json_decode((string) $params, true) ?: [];
        $on['teamcal_enabled'] = 1;
        $db->query("UPDATE {$p}extensions SET params='" . $db->real_escape_string(json_encode($on)) . "' WHERE element='com_appointments' AND type='component'");

        $suite->test('With the feature on, the clean-up and log routines succeed', static function () use ($routine) {
            foreach (['teamcleanup', 'teamlog'] as $name) {
                $r = $routine($name);

                if ($r['executed'] !== 1 || $r['code'] !== 0) {
                    return false;
                }
            }

            return true;
        });

        if ($grantsStub || $suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\InvitationService::class)) {
            $suite->pending('With the feature on, the expired invitation routine succeeds', 'C');
        } else {
            $suite->test('With the feature on, the expired invitation routine succeeds', static function () use ($routine) {
                $r = $routine('teaminvitations');

                return $r['executed'] === 1 && $r['code'] === 0;
            });
        }

        $suite->test('A privacy export of a person with many log rows stays within its limit and says so', static function () use ($run, $db, $p, $tag) {
            $db->query("INSERT INTO {$p}users (name, username, email, password, block, registerDate, params) VALUES ('F many', '{$tag}many', '{$tag}many@example.test', '', 0, UTC_TIMESTAMP(), '{}')");
            $userId = (int) $db->insert_id;

            try {
                $r = $run('$userId = ' . $userId . ';
                    for ($i = 0; $i < 5010; $i++) { $services->audit()->log(AuditEvent::LOGIN_SUCCESS, ["actor_user_id" => $userId]); }
                    $application->bootComponent("com_privacy");
                    $plugin = $application->bootPlugin("appointments", "privacy");
                    $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
                    $request->email = ' . var_export($tag . 'many@example.test', true) . ';
                    $request->status = 1;
                    $before = memory_get_peak_usage(true);
                    $event = new \Joomla\CMS\Event\Privacy\ExportRequestEvent("onPrivacyExportRequest", ["subject" => $request, "user" => \Joomla\CMS\User\User::getInstance($userId)]);
                    $plugin->onPrivacyExportRequest($event);
                    $rows = 0; $note = false;
                    foreach ($event->getArgument("result") as $set) { foreach ($set as $domain) { if ($domain->name === "appointments_staff_log") { foreach ($domain->getItems() as $item) { $rows++; foreach ($item->getFields() as $f) { $note = $note || $f->name === "note"; } } } } }
                    return ["rows" => $rows, "note" => $note, "mb" => (memory_get_peak_usage(true) - $before) / 1048576];');

                return $r['rows'] === 5001 && $r['note'] === true && $r['mb'] < 64;
            } finally {
                $db->query("DELETE FROM {$p}users WHERE id={$userId}");
            }
        });

        // ---- Privacy: staff data through the real privacy plugin --------------
        $suite->test('Privacy export lists devices, grants and log rows of a staff member, without secrets', static function () use ($run, $db, $p, $tag) {
            $db->query("INSERT INTO {$p}users (name, username, email, password, block, registerDate, params) VALUES ('F staff', '{$tag}staff', '{$tag}@example.test', '', 0, UTC_TIMESTAMP(), '{}')");
            $userId = (int) $db->insert_id;
            $db->query("INSERT INTO {$p}appointments_calendars (uri, title, owner_user_id, row_mac, created_utc, modified_utc) VALUES ('" . md5($tag . 'p') . "', 'F privacy', 0, REPEAT(CHAR(0), 32), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $calendar = (int) $db->insert_id;
            $secret = bin2hex(random_bytes(32));

            try {
                $db->query("INSERT INTO {$p}appointments_devices (user_id, name, client, secret_hash, kek_salt, user_key_version, state, rule_version, rule_accepted_utc, created_utc, row_mac) VALUES ({$userId}, 'F phone', 'davx5', UNHEX('{$secret}'), UNHEX('" . bin2hex(random_bytes(16)) . "'), 1, 1, '1', UTC_TIMESTAMP(), UTC_TIMESTAMP(), REPEAT(CHAR(0), 32))");
                $db->query("INSERT INTO {$p}appointments_calendar_grants (calendar_id, user_id, role, state, audience, granted_by, row_mac, created_utc, modified_utc) VALUES ({$calendar}, {$userId}, 3, 1, 'team', 1, REPEAT(CHAR(0), 32), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
                $r = $run('$userId = ' . $userId . ';
                    $services->audit()->log(AuditEvent::DEVICE_CREATED, ["actor_user_id" => $userId, "ip" => "192.0.2.44", "details" => ["rule_version" => "1"]]);
                    $application->bootComponent("com_privacy");
                    $plugin = $application->bootPlugin("appointments", "privacy");
                    $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
                    $request->email = ' . var_export($tag . '@example.test', true) . ';
                    $request->status = 1;
                    $user = \Joomla\CMS\User\User::getInstance($userId);
                    $event = new \Joomla\CMS\Event\Privacy\ExportRequestEvent("onPrivacyExportRequest", ["subject" => $request, "user" => $user]);
                    $plugin->onPrivacyExportRequest($event);
                    $names = []; $dump = "";
                    foreach ($event->getArgument("result") as $set) { foreach ($set as $domain) { $names[] = $domain->name; $dump .= serialize($domain); } }
                    $logged = (int) $joomlaDb->setQuery("SELECT COUNT(*) FROM #__appointments_teamlog WHERE event = \"privacy.export\" AND subject_user_id = $userId")->loadResult();
                    return ["names" => $names, "dump" => base64_encode($dump), "logged" => $logged];');
                $dump = (string) base64_decode((string) $r['dump']);

                return in_array('appointments_staff_devices', $r['names'], true) && in_array('appointments_staff_grants', $r['names'], true)
                    && in_array('appointments_staff_log', $r['names'], true)
                    && str_contains($dump, 'F phone') && str_contains($dump, 'device.created')
                    && !str_contains($dump, $secret) && !str_contains($dump, '192.0.2.44') && !str_contains($dump, 'ip_hash')
                    && $r['logged'] === 1;
            } finally {
                $db->query("DELETE FROM {$p}appointments_devices WHERE user_id={$userId}");
                $db->query("DELETE FROM {$p}appointments_calendars WHERE id={$calendar}");
                $db->query("DELETE FROM {$p}users WHERE id={$userId}");
            }
        });

        $teardownStub = $suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\DeviceService::class)
            || $suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\GrantService::class);
        $suite->test($teardownStub ? 'Privacy removal keeps the rows while their revocation cannot be recorded (fail closed)'
            : 'Privacy removal deletes devices, keys, grants and activation links of a staff member', static function () use ($run, $db, $p, $tag, $teardownStub) {
            $db->query("INSERT INTO {$p}users (name, username, email, password, block, registerDate, params) VALUES ('F gone', '{$tag}gone', '{$tag}gone@example.test', '', 0, UTC_TIMESTAMP(), '{}')");
            $userId = (int) $db->insert_id;
            $db->query("INSERT INTO {$p}appointments_calendars (uri, title, owner_user_id, row_mac, created_utc, modified_utc) VALUES ('" . md5($tag . 'r') . "', 'F removal', 0, REPEAT(CHAR(0), 32), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
            $calendar = (int) $db->insert_id;

            try {
                $db->query("INSERT INTO {$p}appointments_devices (user_id, name, client, secret_hash, kek_salt, user_key_version, state, rule_version, rule_accepted_utc, created_utc, row_mac) VALUES ({$userId}, 'F tablet', 'apple', UNHEX('" . bin2hex(random_bytes(32)) . "'), UNHEX('" . bin2hex(random_bytes(16)) . "'), 1, 1, '1', UTC_TIMESTAMP(), UTC_TIMESTAMP(), REPEAT(CHAR(0), 32))");
                $db->query("INSERT INTO {$p}appointments_calendar_grants (calendar_id, user_id, role, state, audience, granted_by, row_mac, created_utc, modified_utc) VALUES ({$calendar}, {$userId}, 2, 1, 'team', 1, REPEAT(CHAR(0), 32), UTC_TIMESTAMP(), UTC_TIMESTAMP())");
                $db->query("INSERT INTO {$p}appointments_calendar_key_wraps (calendar_id, key_version, user_id, user_key_version, wrapped, created_utc) VALUES ({$calendar}, 1, {$userId}, 1, REPEAT(CHAR(1), 80), UTC_TIMESTAMP())");
                $db->query("INSERT INTO {$p}appointments_invitations (token_hash, user_id, email_hash, state, invited_by, created_utc, expires_utc, row_mac) VALUES (UNHEX('" . bin2hex(random_bytes(32)) . "'), {$userId}, REPEAT(CHAR(2), 32), 2, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), REPEAT(CHAR(0), 32))");
                $run('$application->bootComponent("com_privacy");
                    $plugin = $application->bootPlugin("appointments", "privacy");
                    $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
                    $request->email = ' . var_export($tag . 'gone@example.test', true) . ';
                    $request->status = 1;
                    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent("onPrivacyRemoveData", ["subject" => $request, "user" => \Joomla\CMS\User\User::getInstance(' . $userId . ')]));
                    return true;');
                $left = 0;

                foreach (['appointments_devices', 'appointments_calendar_grants', 'appointments_calendar_key_wraps', 'appointments_invitations', 'appointments_user_keys'] as $table) {
                    $left += (int) $db->query("SELECT COUNT(*) FROM {$p}{$table} WHERE user_id={$userId}")->fetch_row()[0];
                }

                $rotation = (int) $db->query("SELECT rotation_due FROM {$p}appointments_calendars WHERE id={$calendar}")->fetch_row()[0];
                $logged = (int) $db->query("SELECT COUNT(*) FROM {$p}appointments_teamlog WHERE event='privacy.delete' AND subject_user_id={$userId}")->fetch_row()[0];

                // With the device and grant services still stubs, the epochs cannot be raised,
                // so nothing may be deleted except the activation links.
                return $teardownStub ? $left === 3 && $logged === 1 : $left === 0 && $rotation === 1 && $logged === 1;
            } finally {
                foreach (['appointments_devices', 'appointments_calendar_grants', 'appointments_calendar_key_wraps', 'appointments_invitations'] as $table) {
                    $db->query("DELETE FROM {$p}{$table} WHERE user_id={$userId}");
                }

                $db->query("DELETE FROM {$p}appointments_calendars WHERE id={$calendar}");
                $db->query("DELETE FROM {$p}users WHERE id={$userId}");
            }
        });

        $suite->test('A removal for a team member keeps their calendar entries, also on a retry after the account was pseudonymised and for a request without a known scope', static function () use ($run) {
            $result = $run('
                $db = $services->db();
                $group = (int) (((array) $services->params()->get("team_groups", []))[0] ?? 0);
                $name = "teamfprivteam" . bin2hex(random_bytes(3));
                $db->setQuery("INSERT INTO #__users (name, username, email, password, block, registerDate, params) VALUES (\"F team\", \"$name\", \"$name@example.test\", \"\", 0, UTC_TIMESTAMP(), \"{}\")")->execute();
                $uid = (int) $db->insertid();
                $db->setQuery("INSERT INTO #__user_usergroup_map (user_id, group_id) VALUES ($uid, $group)")->execute();

                try {
                    $application->bootComponent("com_privacy");
                    $plugin = $application->bootPlugin("appointments", "privacy");
                    $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
                    $request->id = 800000 + random_int(1, 99999);
                    $request->email = "$name@example.test";
                    $request->status = 1;
                    // The removal check captures the scope, then the first run with the account and a
                    // retry in which Joomla no longer finds it.
                    $plugin->onPrivacyCanRemoveData(new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent("onPrivacyCanRemoveData", ["subject" => $request, "user" => \Joomla\CMS\User\User::getInstance($uid)]));
                    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent("onPrivacyRemoveData", ["subject" => $request, "user" => \Joomla\CMS\User\User::getInstance($uid)]));
                    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent("onPrivacyRemoveData", ["subject" => $request, "user" => null]));
                    // A request checked before the scope existed, retried after the pseudonymisation:
                    // neither a scope nor an account, so its entries are kept, never guessed.
                    $unknown = clone $request;
                    $unknown->id = $request->id + 1;
                    $plugin->onPrivacyRemoveData(new \Joomla\CMS\Event\Privacy\RemoveDataEvent("onPrivacyRemoveData", ["subject" => $unknown, "user" => null]));
                    $rows = $db->setQuery("SELECT details FROM #__appointments_teamlog WHERE event = " . $db->quote(AuditEvent::PRIVACY_DELETE) . " ORDER BY id DESC", 0, 3)->loadColumn();

                    return ["request" => (int) $request->id, "rows" => array_map(static fn ($d): ?array => AuditService::decodeDetails($d), $rows)];
                } finally {
                    $db->setQuery("DELETE FROM #__appointments_privacy_scope WHERE user_id = $uid")->execute();
                    $db->setQuery("DELETE FROM #__user_usergroup_map WHERE user_id = $uid")->execute();
                    $db->setQuery("DELETE FROM #__users WHERE id = $uid")->execute();
                }');
            $rows = $result['rows'] ?? [];

            return \count($rows) === 3
                && (int) ($rows[0]['request'] ?? 0) === $result['request'] + 1 && !empty($rows[0]['team_member'])
                && (int) ($rows[1]['request'] ?? 0) === $result['request'] && !empty($rows[1]['team_member'])
                && (int) ($rows[2]['request'] ?? 0) === $result['request'] && !empty($rows[2]['team_member']);
        });

        $privacyOpen = $openPackages(['A', 'B', 'C', 'D', 'E', 'F']);

        if ($privacyOpen !== '') {
            $suite->pending('Privacy export and removal find calendar entries by the address blind index (needs objects written through A)', $privacyOpen);
        } else {
            $suite->test('Privacy export and removal find calendar entries by the address blind index (needs objects written through A)', static function () use (
                $suite,
                $startHttpFixture,
                $stopHttpFixture,
                $installTotp,
                $siteLoginWithTotp,
                $run,
                $p,
                $tag
            ) {
                [$fixture, $originalParams, $plugins] = $startHttpFixture();

                try {
                    $account = $fixture->accounts['owner'];
                    $secret = $installTotp($fixture, 'owner');
                    $browser = new TeamDBrowser($suite->base);

                    if (!$siteLoginWithTotp($browser, $account, $secret)) {
                        throw new RuntimeException('The calendar owner could not complete the HTTPS password and TOTP sign-in.');
                    }

                    $email = $tag . '-privacy@example.test';
                    $uri = 'privacy-' . bin2hex(random_bytes(5)) . '.ics';
                    $ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Appointments Privacy Test//EN\r\n"
                        . "BEGIN:VEVENT\r\nUID:" . bin2hex(random_bytes(12)) . "@example.test\r\n"
                        . "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\nDTSTART:20270112T090000Z\r\n"
                        . "DTEND:20270112T100000Z\r\nSUMMARY:Privacy calendar fixture\r\n"
                        . "ATTENDEE;CN=Privacy Guest:mailto:{$email}\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
                    $created = $suite->http('PUT', $fixture->calendar('owner') . $uri, [
                        'Content-Type: text/calendar',
                        'Authorization: Basic ' . base64_encode($account['username'] . ':' . $account['app_password']),
                    ], $ics);

                    if ($created['status'] !== 201) {
                        throw new RuntimeException('CalDAV did not create the privacy fixture object (HTTP ' . $created['status'] . ').');
                    }

                    $jarProperty = new ReflectionProperty(TeamDBrowser::class, 'jar');
                    $jarProperty->setAccessible(true);
                    $jar = (string) $jarProperty->getValue($browser);
                    $cookies = [];

                    foreach (file($jar, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                        if (str_starts_with($line, '#HttpOnly_')) {
                            $line = substr($line, 10);
                        } elseif (str_starts_with($line, '#')) {
                            continue;
                        }

                        $fields = explode("\t", $line);
                        if (count($fields) >= 7) {
                            $cookies[$fields[5]] = $fields[6];
                        }
                    }

                    if ($cookies === []) {
                        throw new RuntimeException('The HTTPS sign-in did not leave a Joomla session cookie for the privacy flow.');
                    }

                    $cookieHeader = implode('; ', array_map(
                        static fn (string $name, string $value): string => $name . '=' . $value,
                        array_keys($cookies),
                        array_values($cookies)
                    ));
                    $php = '$_COOKIE = ' . var_export($cookies, true) . ';
                        $_SERVER["HTTP_COOKIE"] = ' . var_export($cookieHeader, true) . ';
                        $_SERVER["HTTPS"] = "on"; $_SERVER["SERVER_PORT"] = "443"; $_SERVER["HTTP_HOST"] = "localhost";
                        require "/var/www/html/tests/scripts/appointments-joomla.php";
                        $session = $application->getSession();
                        $session->start();
                        $identity = $session->get("user");
                        if (!$identity instanceof \Joomla\CMS\User\User || (int) $identity->id !== ' . (int) $account['id'] . ') {
                            throw new \RuntimeException("The real HTTPS browser session did not restore its signed-in owner.");
                        }
                        $application->loadIdentity($identity);
                        if ((int) $session->get("com_users.mfa_checked", 0) !== 1) {
                            throw new \RuntimeException("The restored browser session has not passed Joomla MFA.");
                        }
                        $application->bootComponent("com_privacy");
                        $plugin = $application->bootPlugin("appointments", "privacy");
                        $request = new \Joomla\Component\Privacy\Administrator\Table\RequestTable($joomlaDb);
                        $request->email = ' . var_export($email, true) . ';
                        $request->status = 1;
                        $user = \Joomla\CMS\User\User::getInstance(0);
                        $export = new \Joomla\CMS\Event\Privacy\ExportRequestEvent("onPrivacyExportRequest", ["subject" => $request, "user" => $user]);
                        $plugin->onPrivacyExportRequest($export);
                        $items = 0; $content = ""; $domainFound = false;
                        foreach ($export->getArgument("result") as $set) {
                            foreach ($set as $domain) {
                                if ($domain->name !== "appointments_calendar_entries") { continue; }
                                $domainFound = true;
                                foreach ($domain->getItems() as $item) {
                                    $items++;
                                    foreach ($item->getFields() as $field) {
                                        if ($field->name === "content") { $content .= (string) $field->value; }
                                    }
                                }
                            }
                        }
                        // The removal check records that this request has no account.
                        $request->id = 700000 + random_int(1, 99999);
                        $plugin->onPrivacyCanRemoveData(new \Joomla\CMS\Event\Privacy\CanRemoveDataEvent("onPrivacyCanRemoveData", ["subject" => $request, "user" => null]));
                        $remove = new \Joomla\CMS\Event\Privacy\RemoveDataEvent("onPrivacyRemoveData", ["subject" => $request, "user" => $user]);
                        $plugin->onPrivacyRemoveData($remove);
                        $remaining = (int) $joomlaDb->setQuery("SELECT COUNT(*) FROM #__appointments_calendar_objects WHERE calendar_id = ' . (int) $fixture->calendarId . ' AND uri = " . $joomlaDb->quote(' . var_export($uri, true) . '))->loadResult();
                        $result = ["domain" => $domainFound, "items" => $items, "content" => $content, "remaining" => $remaining,
                            "actor" => (int) $application->getIdentity()->id];
                        echo "\n@@TEAMFPRIV@@".json_encode($result);';
                    token_get_all("<?php\n" . $php, TOKEN_PARSE);
                    exec('cd /var/www/html && php -r ' . escapeshellarg($php) . ' 2>&1', $output, $exit);
                    $text = implode("\n", $output);
                    $marker = strrpos($text, '@@TEAMFPRIV@@');

                    if ($exit !== 0 || $marker === false) {
                        throw new RuntimeException('The HTTPS-session privacy request failed: ' . substr($text, 0, 2000));
                    }

                    $result = json_decode(substr($text, $marker + 13), true);
                    $removed = $suite->http('GET', $fixture->calendar('owner') . $uri, [
                        'Authorization: Basic ' . base64_encode($account['username'] . ':' . $account['app_password']),
                    ]);

                    return is_array($result)
                        && $result['domain'] === true
                        && $result['items'] === 1
                        && str_contains($result['content'], $email)
                        && $result['remaining'] === 0
                        && $result['actor'] === (int) $account['id']
                        && $removed['status'] === 404;
                } finally {
                    $stopHttpFixture($fixture, $originalParams, $plugins);
                }
            });
        }
    } finally {
        $db->query("UPDATE {$p}extensions SET params='" . $db->real_escape_string((string) $params) . "' WHERE element='com_appointments' AND type='component'");

        foreach ($tasks as $id) {
            $db->query("DELETE FROM {$p}scheduler_tasks WHERE id={$id}");
        }
    }

    // ---- Backend views --------------------------------------------------------
    $suite->test('Log and devices views exist and need core.admin', static function () {
        $base = '/var/www/html/administrator/components/com_appointments';

        return is_file($base . '/src/Model/TeamlogModel.php') && is_file($base . '/src/Model/DevicesModel.php')
            && str_contains((string) file_get_contents($base . '/src/View/Teamlog/HtmlView.php'), "authorise('core.admin'")
            && str_contains((string) file_get_contents($base . '/src/View/Devices/HtmlView.php'), "authorise('core.admin'")
            && !str_contains((string) file_get_contents($base . '/tmpl/teamlog/default.php'), 'ip_hash')
            && !str_contains((string) file_get_contents($base . '/src/Model/DevicesModel.php'), 'secret_hash');
    });
};
