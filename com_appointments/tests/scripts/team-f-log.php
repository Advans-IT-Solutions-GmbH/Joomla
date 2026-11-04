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

    $suite->test('User plugin installed and disabled after installation', static function () use ($suite) {
        return $suite->count("SELECT COUNT(*) FROM {$suite->prefix}extensions WHERE type='plugin' AND folder='user' AND element='appointments' AND enabled=0") === 1;
    });

    // ---- Log chain, on a copy of the log ------------------------------------
    // The file anchor of the log (work package B) only rises; this part works
    // on a copy of the log, so it sets the anchor file aside as well.
    $anchorFile = '/var/www/html/administrator/components/com_appointments/data/epochs.php';
    $anchorCopy = is_file($anchorFile) ? (string) file_get_contents($anchorFile) : null;
    $earlier = $run('$v = $services->audit()->verify(); return ["intact" => $v->intact, "rows" => $v->checkedRows];');
    $db->query("DROP TABLE IF EXISTS {$p}{$tag}_log");
    $db->query("DROP TABLE IF EXISTS {$p}{$tag}_head");
    $db->query("CREATE TABLE {$p}{$tag}_log AS SELECT * FROM {$p}appointments_teamlog");
    $db->query("CREATE TABLE {$p}{$tag}_head AS SELECT * FROM {$p}appointments_teamlog_head");
    $fresh = static function () use ($db, $p, $anchorFile): void {
        $db->query("DELETE FROM {$p}appointments_teamlog");
        $db->query("DELETE FROM {$p}appointments_teamlog_head");

        if (is_file($anchorFile)) {
            unlink($anchorFile);
        }
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

        $suite->test('The log stores no address and no IP in clear', static function () use ($db, $p) {
            $dump = '';
            $result = $db->query("SELECT details, object_ref, ip_hash FROM {$p}appointments_teamlog");

            while ($result && ($r = $result->fetch_assoc())) {
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
        $suite->test('Repeated CalDAV failures are written once per minute', static function () use ($run, $db, $p) {
            $run('for ($i = 0; $i < 5; $i++) { $services->audit()->log(AuditEvent::DAV_AUTH_FAILURE, ["object_ref" => "dav:x", "ip" => "192.0.2.9", "details" => ["reason" => "bad"]]); }
                $now = $now->modify("+61 seconds");
                $services->audit()->log(AuditEvent::DAV_AUTH_FAILURE, ["object_ref" => "dav:x", "ip" => "192.0.2.9", "details" => ["reason" => "bad"]]);
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
        $lock('foreach ([$u, $o, $u . "-burst", ' . var_export($tag . '-burst', true) . '] as $name) {
            foreach (LockoutService::buckets($services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_AUTH_BUCKET), LockoutService::identityFor(0, $name), "") as $b) {
                $services->db()->setQuery("DELETE FROM #__appointments_auth_failures WHERE bucket = UNHEX(" . $services->db()->quote(bin2hex($b)) . ")")->execute();
            }
        }
        foreach (["198.51.100.7", "203.0.113.50", "203.0.113.9", "2001:db8:7:7::1", "2001:db8:7:8::1"] as $ip) {
            $b = LockoutService::buckets($services->keyProvider()->key(KeyProvider::PURPOSE_TEAM_AUTH_BUCKET), "name:x", $ip)["ip"];
            $services->db()->setQuery("DELETE FROM #__appointments_auth_failures WHERE bucket = UNHEX(" . $services->db()->quote(bin2hex($b)) . ")")->execute();
        }
        return true;');
    }

    // ---- Website and CalDAV sign-in over HTTP ---------------------------------
    $grantsStub = $suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\GrantService::class);

    if ($grantsStub || $suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\KeySession::class)) {
        $suite->pending('Website sign-in while locked fails exactly like a wrong password, also with the correct password', 'B, C');
    } else {
        $suite->pending('Website sign-in while locked fails exactly like a wrong password, also with the correct password (HTTP flow with MFA account)', 'B, C integration');
    }

    if ($suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStore::class)) {
        $suite->pending('CalDAV answers 429 with Retry-After while locked, also for a correct app password', 'A, D');
    } else {
        $suite->pending('CalDAV answers 429 with Retry-After while locked, also for a correct app password (HTTP flow)', 'A, D integration');
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

            return $r === [2, 'empty', [$calendar => 2], 3, 0, 3, 0];
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

        $suite->test('Privacy removal deletes devices, keys, grants and activation links of a staff member', static function () use ($run, $db, $p, $tag) {
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

                return $left === 0 && $rotation === 1 && $logged === 1;
            } finally {
                foreach (['appointments_devices', 'appointments_calendar_grants', 'appointments_calendar_key_wraps', 'appointments_invitations'] as $table) {
                    $db->query("DELETE FROM {$p}{$table} WHERE user_id={$userId}");
                }

                $db->query("DELETE FROM {$p}appointments_calendars WHERE id={$calendar}");
                $db->query("DELETE FROM {$p}users WHERE id={$userId}");
            }
        });

        if ($suite->isStub(\Advans\Component\Appointments\Administrator\Service\Team\CalendarObjectStore::class)) {
            $suite->pending('Privacy export and removal find calendar entries by the address blind index', 'A, E');
        } else {
            $suite->pending('Privacy export and removal find calendar entries by the address blind index (needs objects written through A)', 'A, E integration');
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
