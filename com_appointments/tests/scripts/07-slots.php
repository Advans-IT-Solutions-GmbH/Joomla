<?php

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-joomla.php';

use Advans\Component\Appointments\Administrator\Service\AvailabilityService;

$fixture = new AppointmentsFixture('slots');
$client = new AppointmentsHttp('http://localhost', 'slots');
$failed = 0;
$blocked = null;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$compare = static function (int $eventId, string $day, string $name) use ($fixture, $client, $joomlaDb, $check): array {
    $zone = new DateTimeZone($name);
    $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    $event = $fixture->db->query("SELECT * FROM {$fixture->prefix}appointments_eventtypes WHERE id={$eventId}")->fetch_assoc();
    $from = new DateTimeImmutable(substr($day, 0, 7) . '-01', $zone);
    $to = $from->modify('+1 month');
    $context = (new AvailabilityService($joomlaDb))->context($event, $from, $to);
    $expected = array_values(array_map(static fn (DateTimeImmutable $slot): string => $slot->format('Y-m-d H:i:s'),
        array_filter($context['engine']->findSlots($now, $from, $to, $context['busy'], $context['caps']),
            static fn (DateTimeImmutable $slot): bool => $slot->setTimezone($zone)->format('Y-m-d') === $day)));
    $html = $client->request('/index.php?' . http_build_query(['option' => 'com_appointments', 'view' => 'eventtype',
        'id' => $eventId, 'month' => substr($day, 0, 7), 'day' => $day, 'timezone' => $name]));
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $actual = [];
    foreach ($xpath->query('//input[@name="booking[start]"]') as $input) {
        $actual[] = $input->getAttribute('value');
    }
    $check($client->status === 200 && $actual === $expected, 'Real no-JavaScript slot page matches the pure engine in ' . $name);
    return $actual;
};

try {
    $event = $fixture->event();
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    $schedule = (int) $fixture->db->query("SELECT schedule_id FROM {$fixture->prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc()['schedule_id'];
    $fixture->db->query("UPDATE {$fixture->prefix}appointments_eventtypes SET duration_minutes=20,slot_step_minutes=30,horizon_days=60 WHERE id={$event}");
    $fixture->db->query("UPDATE {$fixture->prefix}appointments_schedule_rules SET start_time='09:10:00',end_time='16:40:00' WHERE schedule_id={$schedule}");
    $fixture->db->query("INSERT INTO {$fixture->prefix}appointments_schedule_rules (schedule_id,specific_date,start_time,end_time) VALUES ({$schedule},'{$day}','10:10:00','12:10:00')");
    $fixture->db->query("INSERT INTO {$fixture->prefix}appointments_blocked (user_id,start_utc,end_utc,created_utc) VALUES ({$fixture->host},'{$day} 11:00:00','{$day} 11:30:00',UTC_TIMESTAMP())");
    $blocked = (int) $fixture->db->insert_id;
    foreach (['UTC', 'Europe/Zurich', 'Pacific/Apia'] as $zone) {
        $compare($event, $day, $zone);
    }
    $slots = $compare($event, $day, 'UTC');
    $check($slots === [$day . ' 10:10:00', $day . ' 10:40:00', $day . ' 11:40:00'], 'Override owns its anchored grid and blocked occupancy removes only conflicting slots');
    $fixture->booking($event, $day . ' 10:10:00');
    $slots = $compare($event, $day, 'UTC');
    $check(!in_array($day . ' 10:10:00', $slots, true), 'A real booking immediately disappears from the public slot choices');
    $closed = (new DateTimeImmutable($day, new DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');
    $fixture->db->query("INSERT INTO {$fixture->prefix}appointments_schedule_rules (schedule_id,specific_date,is_unavailable,start_time,end_time) VALUES ({$schedule},'{$closed}',1,'00:00:00','00:00:00')");
    $check($compare($event, $closed, 'UTC') === [], 'An unavailable override produces no bookable form slot');
    foreach ([['month' => '2026-99'], ['timezone' => 'not-a-zone']] as $invalid) {
        $client->request('/index.php?' . http_build_query(array_replace(['option' => 'com_appointments', 'view' => 'eventtype',
            'id' => $event, 'day' => $day, 'month' => substr($day, 0, 7), 'timezone' => 'UTC'], $invalid)));
        $check($client->status === 400, 'Invalid public slot input gets an explicit error');
    }
    $fixture->db->query("UPDATE {$fixture->prefix}appointments_eventtypes SET published=0 WHERE id={$event}");
    $client->request('/index.php?option=com_appointments&view=eventtype&id=' . $event);
    $check($client->status === 404, 'Unpublished events never reveal a slot list');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    if ($blocked !== null) {
        $fixture->db->query("DELETE FROM {$fixture->prefix}appointments_blocked WHERE id={$blocked}");
    }
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
