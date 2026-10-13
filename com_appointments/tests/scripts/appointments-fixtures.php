<?php

require_once __DIR__ . '/appointments-http.php';
require_once '/var/www/html/configuration.php';
defined('_JEXEC') || define('_JEXEC', 1);
$helper = '/var/www/html/administrator/components/com_appointments/src/Helper/';
require_once $helper . 'KeyProvider.php';
require_once $helper . 'SignedLink.php';

final class AppointmentsFixture
{
    public mysqli $db;
    public string $prefix;
    public int $host;
    private JConfig $config;
    private array $events = [];
    private array $schedules = [];

    public function __construct(private string $suite)
    {
        $this->config = new JConfig();
        $this->db = new mysqli($this->config->host, $this->config->user, $this->config->password, $this->config->db);
        $this->prefix = $this->config->dbprefix;
        $username = $this->db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
        $this->host = (int) $this->db->query("SELECT id FROM {$this->prefix}users WHERE username='{$username}'")->fetch_assoc()['id'];
    }

    public function event(): int
    {
        $db = $this->db;
        $prefix = $this->prefix;
        $title = 'suite-' . $this->suite . '-' . bin2hex(random_bytes(6));
        $time = gmdate('Y-m-d H:i:s');
        $db->query("INSERT INTO {$prefix}appointments_schedules (title,timezone,owner_user_id,published,created_utc,modified_utc) VALUES ('{$title}','UTC',{$this->host},1,'{$time}','{$time}')");
        $schedule = (int) $db->insert_id;
        $this->schedules[] = $schedule;
        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $db->query("INSERT INTO {$prefix}appointments_schedule_rules (schedule_id,weekday,start_time,end_time) VALUES ({$schedule},{$weekday},'09:00:00','17:00:00')");
        }
        $token = bin2hex(random_bytes(32));
        $db->query("INSERT INTO {$prefix}appointments_eventtypes (title,alias,schedule_id,host_user_id,feed_token,created_utc,modified_utc) VALUES ('{$title}','{$title}',{$schedule},{$this->host},'{$token}','{$time}','{$time}')");
        $event = (int) $db->insert_id;
        $this->events[] = $event;

        return $event;
    }

    public function booking(int $event, string $start, array $details = []): array
    {
        $client = new AppointmentsHttp('http://localhost', $this->suite . '-guest');
        [$browser, $path, $form] = $client->bookingForm($event, $start, $details);
        sleep(2);
        $html = $browser->request($path, $form);
        $time = $this->db->real_escape_string($start);
        $booking = $this->db->query("SELECT * FROM {$this->prefix}appointments_bookings WHERE eventtype_id={$event} AND start_utc='{$time}' AND state IN (0,1)")->fetch_assoc();
        if (!$booking || $browser->status !== 200 || !str_contains($html, 'Appointment received')) {
            throw new RuntimeException('The fixture HTTP booking did not succeed.');
        }

        return ['booking' => $booking, 'browser' => $browser, 'html' => $html];
    }

    public function row(int $id): ?array
    {
        return $this->db->query("SELECT * FROM {$this->prefix}appointments_bookings WHERE id={$id}")->fetch_assoc();
    }

    public function link(array $booking, string $action): string
    {
        $links = new \Advans\Component\Appointments\Administrator\Helper\SignedLink(
            new \Advans\Component\Appointments\Administrator\Helper\KeyProvider($this->config->secret)
        );

        return '/index.php?' . http_build_query(['option' => 'com_appointments', 'view' => 'manage',
            'id' => $booking['id'], 'action' => $action,
            'signature' => $links->sign((int) $booking['id'], $action, $booking['modified_utc'])]);
    }

    public function apply(AppointmentsHttp $client, string $link, array $fields = []): string
    {
        $html = $client->request($link);
        $token = $client->token($html);
        parse_str(parse_url($link, PHP_URL_QUERY), $query);

        return $client->request('/index.php?option=com_appointments&task=booking.manage', array_replace($query, [
            $token => 1, 'confirm' => 1,
        ], $fields));
    }

    public function cleanup(): void
    {
        foreach ($this->events as $event) {
            $this->db->query("DELETE FROM {$this->prefix}appointments_audit WHERE booking_id IN (SELECT id FROM {$this->prefix}appointments_bookings WHERE eventtype_id={$event})");
            $this->db->query("DELETE FROM {$this->prefix}appointments_bookings WHERE eventtype_id={$event}");
            $this->db->query("DELETE FROM {$this->prefix}appointments_eventtypes WHERE id={$event}");
        }
        foreach ($this->schedules as $schedule) {
            $this->db->query("DELETE FROM {$this->prefix}appointments_schedule_rules WHERE schedule_id={$schedule}");
            $this->db->query("DELETE FROM {$this->prefix}appointments_schedules WHERE id={$schedule}");
        }
    }
}
