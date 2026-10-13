<?php

require __DIR__ . '/appointments-fixtures.php';

/** Header capture stays local to this suite; credentials are never printed. */
final class AppointmentsFeedHttp
{
    public int $status = 0;
    public array $headers = [];
    private string $cookie;

    public function __construct()
    {
        $this->cookie = __DIR__ . '/feed-' . bin2hex(random_bytes(8)) . '.cookies';
    }

    public function __destruct()
    {
        if (is_file($this->cookie)) {
            unlink($this->cookie);
        }
    }

    public function request(string $path, ?array $data = null, array $headers = []): string
    {
        $this->headers = [];
        $curl = curl_init('http://localhost' . $path);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30, CURLOPT_COOKIEJAR => $this->cookie, CURLOPT_COOKIEFILE => $this->cookie,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $this->headers = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $this->headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($data !== null) {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($data));
        }
        $body = curl_exec($curl);
        $error = curl_error($curl);
        $this->status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        if ($body === false) {
            throw new RuntimeException('Feed HTTP request failed: ' . $error);
        }

        return $body;
    }

    public function token(string $html): string
    {
        if (!preg_match('/name="([a-f0-9]{32})"\s+value="1"/', $html, $match)) {
            throw new RuntimeException('Feed form did not render a CSRF token.');
        }
        return $match[1];
    }

    public function login(?string $username = null, ?string $password = null): void
    {
        $token = $this->token($this->request('/administrator/index.php'));
        $body = $this->request('/administrator/index.php', [
            'username' => $username ?? (getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin'),
            'passwd' => $password ?? (getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#'),
            'option' => 'com_login', 'task' => 'login', 'return' => base64_encode('index.php'), $token => 1,
        ]);
        if (!str_contains($body, 'task=logout') && !str_contains($body, 'com_cpanel')) {
            throw new RuntimeException('Feed administrator login failed.');
        }
    }
}

$fixture = new AppointmentsFixture('feed');
$failed = 0;
$lowUser = 0;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
};
$http = new AppointmentsFeedHttp();
$admin = new AppointmentsFeedHttp();
$feedPath = static fn (string $scope, int $id, string $token): string => '/index.php?'
    . http_build_query(['option' => 'com_appointments', 'task' => 'feed.download', 'scope' => $scope, 'id' => $id, 'token' => $token]);
$tokens = static function (int $event) use ($fixture): array {
    $eventRow = $fixture->db->query("SELECT feed_token FROM {$fixture->prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
    $hostRow = $fixture->db->query("SELECT feed_token FROM {$fixture->prefix}appointments_hosts WHERE user_id={$fixture->host}")->fetch_assoc();
    return [$eventRow['feed_token'], $hostRow['feed_token']];
};
$noStore = static fn (array $headers): bool => str_contains($headers['cache-control'] ?? '', 'no-store')
    && str_contains($headers['cache-control'] ?? '', 'private')
    && str_contains($headers['x-robots-tag'] ?? '', 'noindex');

try {
    $event = $fixture->event();
    $otherEvent = $fixture->event();
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    $created = $fixture->booking($event, $day . ' 10:00:00', ['guests' => 'guest@example.test']);
    $other = $fixture->booking($otherEvent, $day . ' 12:00:00')['booking'];
    $booking = $created['booking'];
    [$eventToken, $hostToken] = $tokens($event);
    [$otherToken] = $tokens($otherEvent);
    $check(preg_match('/^[a-f0-9]{64}$/D', $hostToken) === 1 && $eventToken !== $hostToken && $otherToken !== $eventToken,
        'Both scopes and different events have independent random credentials');
    $path = $feedPath('event', $event, $eventToken);
    $body = $http->request($path);
    $etag = $http->headers['etag'] ?? '';
    $check($http->status === 200 && str_contains($body, 'UID:' . $booking['uid']) && !str_contains($body, 'UID:' . $other['uid']),
        'Event feed is served by real Joomla HTTP and contains only its own booking');
    $check($noStore($http->headers) && str_contains($http->headers['content-type'] ?? '', 'text/calendar')
        && str_contains($http->headers['content-disposition'] ?? '', '.ics"'), 'Feed has no-index, private no-store and calendar download headers');
    $check(str_contains($body, "METHOD:PUBLISH\r\n") && str_contains($body, 'X-WR-CALNAME:')
        && str_contains($body, "X-PUBLISHED-TTL:PT15M\r\n") && str_contains($body, "REFRESH-INTERVAL;VALUE=DURATION:PT15M\r\n")
        && str_contains($body, 'mailto:invitee@example.test') && str_contains($body, 'mailto:guest@example.test'),
        'Calendar publishes its name, both 15-minute refresh properties and authorised participants');
    $body304 = $http->request($path, null, ['If-None-Match: ' . $etag]);
    $check(preg_match('/^"[a-f0-9]{64}"$/D', $etag) === 1 && $http->status === 304 && $body304 === ''
        && ($http->headers['etag'] ?? '') === $etag && $noStore($http->headers), 'Exact unchanged ETag yields bodyless 304 retaining safety headers');
    $hostPath = $feedPath('host', $fixture->host, $hostToken);
    $hostBody = $http->request($hostPath);
    $check($http->status === 200 && str_contains($hostBody, 'UID:' . $booking['uid']) && str_contains($hostBody, 'UID:' . $other['uid']),
        'Combined host feed includes bookings from both event types');

    $wrong = $http->request($feedPath('event', $event, str_repeat('0', 64)));
    $check($http->status === 404 && $noStore($http->headers) && !str_contains($wrong, $booking['invitee_email'])
        && !str_contains($wrong, $booking['uid']), 'Wrong credential gets generic 404 with no participant data');
    foreach ([['event', $event, 'bad'], ['host', $fixture->host, $eventToken], ['event', $event, $hostToken],
        ['event', 2147483647, $eventToken], ['invalid', $event, $eventToken]] as [$scope, $id, $token]) {
        $error = $http->request($feedPath($scope, $id, $token));
        $check($http->status === 404 && $error === $wrong, 'Malformed, unknown and cross-scope credentials have the identical generic response');
    }

    $fixture->db->query("UPDATE {$fixture->prefix}appointments_eventtypes SET description='<p>Updated plain description</p>' WHERE id={$event}");
    $changed = $http->request($path, null, ['If-None-Match: ' . $etag]);
    $check($http->status === 200 && ($http->headers['etag'] ?? '') !== $etag
        && str_contains($changed, 'DESCRIPTION:Updated plain description') && !str_contains($changed, '<p>'),
        'Metadata-only changes invalidate ETag without changing the booking timestamp');
    $etag = $http->headers['etag'];
    $fixture->db->query("UPDATE {$fixture->prefix}appointments_eventtypes SET horizon_days=29 WHERE id={$event}");
    $http->request($path, null, ['If-None-Match: ' . $etag]);
    $check($http->status === 200 && ($http->headers['etag'] ?? '') !== $etag, 'Configured horizon changes invalidate ETag');
    $fixture->db->query("UPDATE {$fixture->prefix}appointments_bookings SET state=2,sequence=sequence+1,modified_utc=UTC_TIMESTAMP() WHERE id=" . (int) $other['id']);
    $cancelled = $http->request($hostPath);
    $cancelEtag = $http->headers['etag'] ?? '';
    $check(str_contains($cancelled, 'UID:' . $other['uid']) && str_contains($cancelled, 'STATUS:CANCELLED'), 'Recent cancellation remains in the published host calendar');
    $fixture->db->query("UPDATE {$fixture->prefix}appointments_bookings SET modified_utc=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 31 DAY) WHERE id=" . (int) $other['id']);
    $aged = $http->request($hostPath, null, ['If-None-Match: ' . $cancelEtag]);
    $check($http->status === 200 && !str_contains($aged, 'UID:' . $other['uid']), 'Cancellation older than 30 days disappears and cannot yield false 304');
    $http->request($path);
    $historyEtag = $http->headers['etag'] ?? '';
    $fixture->db->query("UPDATE {$fixture->prefix}appointments_bookings SET start_utc=DATE_SUB(UTC_TIMESTAMP(),INTERVAL 91 DAY),"
        . "end_utc=DATE_ADD(DATE_SUB(UTC_TIMESTAMP(),INTERVAL 91 DAY),INTERVAL 30 MINUTE) WHERE id=" . (int) $booking['id']);
    $history = $http->request($path, null, ['If-None-Match: ' . $historyEtag]);
    $check($http->status === 200 && !str_contains($history, 'UID:' . $booking['uid']), 'Active booking beyond 90 days of history drops from the bounded feed');
    $start = $fixture->db->real_escape_string($booking['start_utc']);
    $end = $fixture->db->real_escape_string($booking['end_utc']);
    $fixture->db->query("UPDATE {$fixture->prefix}appointments_bookings SET start_utc='{$start}',end_utc='{$end}' WHERE id=" . (int) $booking['id']);

    $admin->login();
    $page = $admin->request('/administrator/index.php?option=com_appointments&view=feeds');
    $csrf = $admin->token($page);
    $check($admin->status === 200 && str_contains(html_entity_decode($page), $path)
        && str_contains($page, 'password') && str_contains($page, 'old URL'), 'Privileged feed UI shows URLs and the password/immediate revocation warning');
    $rotate = '/administrator/index.php?option=com_appointments&task=feeds.rotate';
    $admin->request($rotate . '&scope=event&id=' . $event . '&confirm=1&' . $csrf . '=1');
    $check($admin->status === 403 && $tokens($event)[0] === $eventToken, 'Rotation rejects GET even with a form token');
    $admin->request($rotate, ['scope' => 'event', 'id' => $event, 'confirm' => 1]);
    $check($admin->status === 403 && $tokens($event)[0] === $eventToken, 'Rotation rejects a POST without CSRF');
    $admin->request($rotate, ['scope' => 'event', 'id' => $event, 'confirm' => 1, $csrf => 1]);
    [$newEventToken, $sameHostToken] = $tokens($event);
    $check($admin->status === 200 && $newEventToken !== $eventToken && $sameHostToken === $hostToken, 'Event rotation changes only its own credential');
    $http->request($path);
    $check($http->status === 404, 'Old event credential is immediately revoked');
    $http->request($feedPath('event', $event, $newEventToken));
    $check($http->status === 200, 'Replacement event credential works immediately');
    $http->request($hostPath);
    $check($http->status === 200, 'Event rotation leaves the host feed reachable');
    $http->request($feedPath('event', $otherEvent, $otherToken));
    $check($http->status === 200, 'Event rotation leaves other event credentials untouched');
    $page = $admin->request('/administrator/index.php?option=com_appointments&view=feeds');
    $csrf = $admin->token($page);
    $admin->request($rotate, ['scope' => 'host', 'id' => $fixture->host, 'confirm' => 1, $csrf => 1]);
    [$sameEventToken, $newHostToken] = $tokens($event);
    $check($admin->status === 200 && $sameEventToken === $newEventToken && $newHostToken !== $hostToken, 'Host rotation does not alter the event credential');
    $http->request($hostPath);
    $check($http->status === 404, 'Old host credential is immediately revoked');
    $http->request($feedPath('host', $fixture->host, $newHostToken));
    $check($http->status === 200, 'Replacement host credential works immediately');
    $http->request($feedPath('event', $event, $newEventToken));
    $check($http->status === 200, 'Host rotation leaves the event feed reachable');

    $exportPath = '/administrator/index.php?option=com_appointments&task=feeds.export&id=' . (int) $booking['id'];
    $export = $admin->request($exportPath);
    $check($admin->status === 200 && str_contains($export, 'UID:' . $booking['uid'])
        && str_contains($export, "METHOD:REQUEST\r\n") && str_contains($export, "SEQUENCE:0\r\n")
        && str_contains($admin->headers['content-disposition'] ?? '', 'attachment; filename="appointment-')
        && $noStore($admin->headers), 'Administrator exports a private single-booking ICS attachment with stable UID/sequence');
    $cancelExport = $admin->request('/administrator/index.php?option=com_appointments&task=feeds.export&id=' . (int) $other['id']);
    $check($admin->status === 200 && str_contains($cancelExport, "METHOD:CANCEL\r\n")
        && str_contains($cancelExport, "STATUS:CANCELLED\r\n") && str_contains($cancelExport, "SEQUENCE:1\r\n")
        && str_contains($cancelExport, 'UID:' . $other['uid']), 'Cancelled booking export preserves UID and uses CANCEL with the raised sequence');

    $tag = 'suite-feed-low-' . bin2hex(random_bytes(6));
    $password = 'Fixture-' . bin2hex(random_bytes(12));
    $hash = $fixture->db->real_escape_string(password_hash($password, PASSWORD_BCRYPT));
    $fixture->db->query("INSERT INTO {$fixture->prefix}users (name,username,email,password,block,sendEmail,registerDate,params)"
        . " VALUES ('{$tag}','{$tag}','{$tag}@example.test','{$hash}',0,0,UTC_TIMESTAMP(),'{}')");
    $lowUser = (int) $fixture->db->insert_id;
    $fixture->db->query("INSERT INTO {$fixture->prefix}user_usergroup_map (user_id,group_id) VALUES ({$lowUser},6)");
    $low = new AppointmentsFeedHttp();
    $low->login($tag, $password);
    $denied = $low->request('/administrator/index.php?option=com_appointments&view=feeds');
    $check($low->status === 403 && !str_contains($denied, $newEventToken) && !str_contains($denied, $newHostToken),
        'Lower-privileged Manager cannot view credentials even with backend access');
    $token = $low->token($low->request('/administrator/index.php'));
    $low->request($rotate, ['scope' => 'event', 'id' => $event, 'confirm' => 1, $token => 1]);
    $check($low->status === 403 && $tokens($event)[0] === $newEventToken, 'Lower-privileged Manager cannot rotate credentials');
} finally {
    if ($lowUser) {
        $fixture->db->query("DELETE FROM {$fixture->prefix}session WHERE userid={$lowUser}");
        $fixture->db->query("DELETE FROM {$fixture->prefix}user_usergroup_map WHERE user_id={$lowUser}");
        $fixture->db->query("DELETE FROM {$fixture->prefix}users WHERE id={$lowUser}");
    }
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
