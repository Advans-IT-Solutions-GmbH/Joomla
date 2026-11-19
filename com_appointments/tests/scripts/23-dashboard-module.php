<?php

/**
 * The administrator dashboard module «Next appointments» (modules/mod_appointments), rendered on
 * the real Home Dashboard: the next appointments in the site time zone, the number of bookings
 * since the last visit per user and session, the limit setting, and what it leaves out. Who sees
 * it at all is checked in 15-administration.php with a restricted account.
 */

require __DIR__ . '/appointments-fixtures.php';

$fixture = new AppointmentsFixture('dashboard-module');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$moduleParams = null;
$moduleId = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$session = static function (string $name): AppointmentsHttp {
    $client = new AppointmentsHttp(appointments_test_base_url(), 'dashboard-module-' . $name);
    $client->login();

    return $client;
};
$module = static function (string $html): array {
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $root = $xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " mod-appointments ")]')->item(0);
    $times = [];

    foreach ($xpath->query('//ul[contains(@class, "mod-appointments-list")]/li//time') as $time) {
        $times[] = $time->getAttribute('datetime');
    }

    return [
        'present' => $root !== null,
        'text' => $root instanceof DOMElement ? trim((string) preg_replace('/\s+/', ' ', $root->textContent)) : '',
        'new' => trim((string) $xpath->query('//*[contains(@class, "mod-appointments-new")]')->item(0)?->textContent),
        'times' => $times,
    ];
};
$visit = static fn (): ?string => $db->query("SELECT profile_value FROM {$prefix}user_profiles WHERE user_id={$fixture->host}"
    . " AND profile_key='mod_appointments.lastvisit'")->fetch_row()[0] ?? null;

try {
    $row = $db->query("SELECT id, params FROM {$prefix}modules WHERE module='mod_appointments' AND client_id=1 AND position='cpanel' AND published=1")->fetch_assoc();
    $check((bool) $row, 'Fixture: the dashboard module is published at cpanel');
    $moduleId = (int) ($row['id'] ?? 0);
    $moduleParams = (string) ($row['params'] ?? '');
    $db->query("DELETE FROM {$prefix}user_profiles WHERE user_id={$fixture->host} AND profile_key='mod_appointments.lastvisit'");

    $event = $fixture->event();
    $title = (string) $db->query("SELECT title FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_row()[0];
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+3 days')->format('Y-m-d');
    $later = $fixture->booking($event, $day . ' 13:00:00', ['first_name' => 'Later', 'last_name' => 'Person'])['booking'];
    $sooner = $fixture->booking($event, $day . ' 10:00:00', ['first_name' => 'Sooner', 'last_name' => 'Person'])['booking'];
    $erased = $fixture->booking($event, $day . ' 11:00:00', ['first_name' => 'Erased', 'last_name' => 'Person'])['booking'];
    $db->query("UPDATE {$prefix}appointments_bookings SET anonymised_utc=UTC_TIMESTAMP() WHERE id=" . (int) $erased['id']);
    $db->query("UPDATE {$prefix}appointments_bookings SET state=0 WHERE id=" . (int) $later['id']);
    $offset = (string) (new JConfig())->offset;
    $zone = in_array($offset, DateTimeZone::listIdentifiers(), true) ? $offset : 'UTC';

    // First visit ever: the list, no number yet, and the visit is stored for the account.
    $first = $session('first');
    $page = $module($first->request('/administrator/index.php'));
    $check($first->status === 200 && $page['present'] && $page['new'] === '', 'The very first visit shows the module without a number of new bookings');
    $check($page['times'] === [$day . 'T10:00:00Z', $day . 'T13:00:00Z']
        && str_contains($page['text'], 'Sooner Person') && str_contains($page['text'], 'Later Person') && str_contains($page['text'], $title)
        && !str_contains($page['text'], 'Erased'),
        'The module lists the next appointments in order with event type and name, without erased bookings');
    $local = (new DateTimeImmutable($day . ' 10:00:00', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($zone));
    $check(str_contains($page['text'], $local->format('H:i')) && str_contains($page['text'], 'Times in the time zone ' . $zone . '.'),
        'Times are shown in the site time zone ' . $zone);
    $check(str_contains($page['text'], 'Awaiting approval'), 'A pending appointment is marked as awaiting approval');
    $stored = $visit();
    $check($stored !== null && abs(strtotime($stored . ' UTC') - time()) < 120, 'The visit is stored with the account');

    // A later view of the same session keeps its reference (none on the very first visit).
    $page = $module($first->request('/administrator/index.php'));
    $check($page['new'] === '', 'The same session keeps its reference');
    // A visit long ago: a new session counts every booking made since.
    $db->query("UPDATE {$prefix}user_profiles SET profile_value='2000-01-01 00:00:00' WHERE user_id={$fixture->host} AND profile_key='mod_appointments.lastvisit'");
    $expected = (int) $db->query("SELECT COUNT(*) FROM {$prefix}appointments_bookings WHERE anonymised_utc IS NULL AND created_utc > '2000-01-01 00:00:00'")->fetch_row()[0];
    $second = $session('second');
    $page = $module($second->request('/administrator/index.php'));
    $check($expected >= 2 && $page['new'] === $expected . ' new bookings since your last visit',
        'A new session counts the bookings since the stored last visit, without erased ones: ' . $page['new']);
    sleep(1);
    $fixture->booking($event, $day . ' 15:00:00', ['first_name' => 'Newest', 'last_name' => 'Person']);
    $page = $module($second->request('/administrator/index.php'));
    $check($page['new'] === ($expected + 1) . ' new bookings since your last visit', 'A booking during the session is added to its count');
    sleep(1);
    $page = $module($session('third')->request('/administrator/index.php'));
    $check($page['new'] === 'No new bookings since your last visit', 'The next session counts from the previous visit');

    // The setting «Number of appointments».
    $db->query("UPDATE {$prefix}modules SET params='" . $db->real_escape_string(json_encode(['count' => '1'])) . "' WHERE id={$moduleId}");
    $page = $module($second->request('/administrator/index.php'));
    $check($page['times'] === [$day . 'T10:00:00Z'], 'The module lists as many appointments as set');
    $check(str_contains($second->request('/administrator/index.php'), 'option=com_appointments&amp;view=bookings'), 'The module links into the bookings list');

    // Its own help: the «Help» tab of the module edit screen, with the note at the top of the options.
    $edit = $second->request('/administrator/index.php?option=com_modules&task=module.edit&id=' . $moduleId);
    $check($second->status === 200 && str_contains($edit, 'What this module shows') && str_contains($edit, 'Who sees what')
        && str_contains($edit, 'Problems and what to do') && str_contains($edit, 'explains what this module shows')
        && !preg_match('/MOD_APPOINTMENTS_[A-Z_]+/', $edit),
        'The module edit screen has its own translated help tab and the note pointing to it');
    $second->request('/administrator/index.php?option=com_modules', ['task' => 'module.cancel', 'id' => $moduleId, $second->token($edit) => 1]);
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    if ($moduleId > 0 && $moduleParams !== null) {
        $db->query("UPDATE {$prefix}modules SET params='" . $db->real_escape_string($moduleParams) . "' WHERE id={$moduleId}");
    }
    $db->query("DELETE FROM {$prefix}user_profiles WHERE user_id={$fixture->host} AND profile_key='mod_appointments.lastvisit'");
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
