<?php

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-mail.php';
require __DIR__ . '/appointments-joomla.php';
require_once '/var/www/html/administrator/components/com_appointments/src/Service/ConferenceService.php';

use Advans\Component\Appointments\Administrator\Service\ConferenceService;

$fixture = new AppointmentsFixture('conference');
$admin = new AppointmentsHttp('http://localhost', 'conference-admin');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$originalParams = null;
$check = static function (bool $ok, string $label) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . "\n";
    $failed += $ok ? 0 : 1;
};
$day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');

$settings = static function (array $values) use ($db, $prefix, &$originalParams): void {
    $params = json_decode($originalParams, true, 512, JSON_THROW_ON_ERROR);
    $encoded = $db->real_escape_string(json_encode(array_replace($params, $values), JSON_THROW_ON_ERROR));
    $db->query("UPDATE {$prefix}extensions SET params='{$encoded}' WHERE type='component' AND element='com_appointments'");
};
$eventParams = static function (int $event, array $values) use ($db, $prefix): void {
    $encoded = $db->real_escape_string(json_encode($values, JSON_THROW_ON_ERROR));
    $db->query("UPDATE {$prefix}appointments_eventtypes SET conference_params='{$encoded}' WHERE id={$event}");
};
$claims = static function (string $url, ?string $secret = null): ?array {
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    if (empty($query['jwt']) || count($parts = explode('.', $query['jwt'])) !== 3) {
        return null;
    }

    $decode = static fn (string $value) => base64_decode(strtr($value, '-_', '+/'), true);

    if ($secret !== null && !hash_equals(hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true), (string) $decode($parts[2]))) {
        return null;
    }

    return json_decode((string) $decode($parts[1]), true);
};
$calendarMail = static function (array $before, array $booking, int $count, ?string $url = null) use ($check): array {
    $messages = AppointmentsMail::messagesSince($before);
    $check(count($messages) === $count, 'Conference operation sends mail to every expected recipient');

    foreach ($messages as $mime) {
        $decoded = preg_replace("/\r\n[ \t]/", '', AppointmentsMail::decoded($mime));
        $check(str_contains($mime, 'Content-Type: text/calendar;')
            && str_contains($mime, 'Content-Type: application/ics')
            && str_contains($decoded, 'UID:' . $booking['uid'])
            && str_contains($decoded, 'SEQUENCE:' . $booking['sequence']),
            'Conference notification contains both calendar MIME parts with stable UID and current sequence');

        if ($url !== null) {
            $check(str_contains($decoded, $url), 'Conference URL is present in the actual notification/calendar');
        }
    }

    return $messages;
};
$retry = static function (array $booking, bool $withToken = true) use ($admin): string {
    $html = $admin->request('/administrator/index.php?option=com_appointments&view=bookings');
    $data = ['id' => $booking['id'], 'cid' => [$booking['id']], 'task' => 'bookings.retryConference'];

    if ($withToken) {
        $data[$admin->token($html)] = 1;
    }

    return $admin->request('/administrator/index.php?option=com_appointments', $data);
};

try {
    $originalParams = $db->query("SELECT params FROM {$prefix}extensions WHERE type='component' AND element='com_appointments'")->fetch_assoc()['params'];
    $admin->login();
    $options = $admin->request('/administrator/index.php?option=com_config&view=component&component=com_appointments');
    $check($admin->status === 200, 'Administrator can open conferencing options');

    foreach (ConferenceService::OPTION_KEYS as $key) {
        $check(str_contains($options, 'jform[' . $key . ']'), 'Options control exists: ' . $key);
    }

    $check(str_contains(strtolower(strip_tags($options)), 'overlap'), 'Fixed-room options honestly warn about overlapping appointments');
    $config = simplexml_load_file('/var/www/html/administrator/components/com_appointments/config.xml');
    $rangeFields = $config->xpath('//field[@validate="range"]');
    $rangeForm = $container->get(\Joomla\CMS\Form\FormFactoryInterface::class)->createForm('appointments-config-range');
    $rangeForm->setCurrentUser(new \Joomla\CMS\User\User());
    $rangeForm->load('<form addruleprefix="Advans\Component\Appointments\Administrator\Rule">'
        . implode('', array_map(static fn ($field) => $field->asXML(), $rangeFields)) . '</form>');
    $defaults = [];
    foreach ($rangeFields as $field) {
        $defaults[(string) $field['name']] = (string) $field['default'];
    }
    $check($rangeForm->validate($rangeForm->filter($defaults)), 'Joomla accepts every numeric configuration default');
    foreach ($rangeFields as $field) {
        $name = (string) $field['name'];
        foreach (['invalid', '3.5', (int) $field['min'] - 1, (int) $field['max'] + 1] as $invalid) {
            $submitted = array_replace($defaults, [$name => $invalid]);
            $filtered = $rangeForm->filter($submitted);
            $check($filtered[$name] === $invalid && !$rangeForm->validate($filtered),
                'Joomla rejects invalid raw numeric configuration without coercion: ' . $name);
        }
        $filtered = $rangeForm->filter(array_replace($defaults, [$name => '']));
        $check($filtered[$name] === $defaults[$name] && $rangeForm->validate($filtered),
            'Joomla restores the declared numeric default for an empty input: ' . $name);
    }
    $event = $fixture->event();
    $db->query("UPDATE {$prefix}appointments_eventtypes SET location_type='conference',location_text='Fixture fallback location' WHERE id={$event}");
    $form = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
    $originalConferenceParams = $form['conference_params'];
    $form['conference_params'] = ['conference_provider' => 'fixed', 'conference_fixed_url' => 'http://meet.example.test/room'];
    $html = $admin->submit('eventtype', $form);
    $stored = $db->query("SELECT conference_params FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
    $check($admin->status === 200 && $stored['conference_params'] === $originalConferenceParams
        && str_contains($html, 'The conferencing settings are invalid.'),
        'Invalid fixed-room configuration is a translated form refusal, not an uncaught server error');
    $settings([
        'conference_provider' => 'jitsi', 'conference_jitsi_base_url' => 'https://meet.example.com',
        'conference_jitsi_auth' => 'none', 'conference_jitsi_room_prefix' => 'fixture',
        'conference_jitsi_room_pattern' => '{prefix}-{alias}-{random}', 'conference_jitsi_entropy' => 24,
        'conference_generic_template' => 'javascript:unused', 'conference_fixed_url' => 'javascript:unused',
    ]);
    $before = AppointmentsMail::snapshot();
    $created = $fixture->booking($event, $day . ' 10:00:00', ['guests' => 'guest@example.test']);
    $booking = $created['booking'];
    $check((int) $booking['state'] === 1 && $booking['conference_state'] === 'ok'
        && $booking['conference_provider'] === 'jitsi' && $booking['conference_error'] === null,
        'Real HTTP booking remains confirmed and acquires the selected local Jitsi link');
    $check(preg_match('/\Afixture-[a-z0-9_-]+-[a-f0-9]{24}\z/', $booking['conference_room']) === 1
        && !str_contains($booking['conference_room'], $booking['uid'])
        && !str_contains($booking['conference_room'], 'Fixture Invitee'),
        'Jitsi room uses the configured naming pattern and keyed entropy, never invitee data or plain UID');
    $check(str_contains($created['html'], $booking['conference_url']), 'Confirmation page displays the generated guest join link');
    $calendarMail($before, $booking, 3, $booking['conference_url']);

    // Release test 22.10.: only the event type's provider column names Jitsi, the event
    // settings are empty and the global provider is disabled. The booking must still get
    // its link on the confirmation page, in every mail and in the calendar entry.
    $settings([
        'conference_provider' => 'none', 'conference_jitsi_base_url' => 'https://meet.example.com',
        'conference_jitsi_auth' => 'none', 'conference_jitsi_room_prefix' => 'fixture',
        'conference_jitsi_room_pattern' => '{prefix}-{alias}-{random}', 'conference_jitsi_entropy' => 24,
    ]);
    $db->query("UPDATE {$prefix}appointments_eventtypes SET conference_params=NULL,conference_provider='jitsi' WHERE id={$event}");
    $before = AppointmentsMail::snapshot();
    $columnCreated = $fixture->booking($event, $day . ' 09:00:00', ['guests' => 'guest@example.test']);
    $columnBooking = $columnCreated['booking'];
    $check((int) $columnBooking['state'] === 1 && $columnBooking['conference_state'] === 'ok'
        && $columnBooking['conference_provider'] === 'jitsi' && $columnBooking['conference_error'] === null
        && str_starts_with((string) $columnBooking['conference_url'], 'https://meet.example.com/fixture-'),
        'The event type provider column provisions a Jitsi link while the global provider is disabled');
    $check(str_contains($columnCreated['html'], (string) $columnBooking['conference_url']),
        'Confirmation page shows the Jitsi link selected by the event type provider column');
    foreach ($calendarMail($before, $columnBooking, 3, (string) $columnBooking['conference_url']) as $mime) {
        $decoded = preg_replace("/\r\n[ \t]/", '', AppointmentsMail::decoded($mime));
        $check(str_contains($decoded, 'LOCATION:' . $columnBooking['conference_url']),
            'The calendar entry of every mail carries the Jitsi link as its location');
    }

    // Without any provider, a conference event type records the failure instead of
    // leaving every conference column empty, and the mail still goes out.
    $db->query("UPDATE {$prefix}appointments_eventtypes SET conference_provider=NULL WHERE id={$event}");
    $before = AppointmentsMail::snapshot();
    $unconfigured = $fixture->booking($event, $day . ' 09:30:00', ['guests' => 'guest@example.test']);
    $check((int) $unconfigured['booking']['state'] === 1 && $unconfigured['booking']['conference_state'] === 'failed'
        && $unconfigured['booking']['conference_error'] === 'No conference provider is selected for this event type.'
        && $unconfigured['booking']['conference_url'] === null,
        'A conference event type without a provider is flagged as failed with a readable reason');
    $check(substr_count($unconfigured['html'], 'a conference link is not available') === 1,
        'The confirmation page tells the invitee once that the conference link is missing');
    $reloaded = $unconfigured['browser']->request('/index.php?option=com_appointments&view=booking');
    $check(substr_count($reloaded, 'a conference link is not available') === 1,
        'The missing conference link is still stated once after reloading the confirmation');
    $calendarMail($before, $unconfigured['booking'], 3);
    $db->query("UPDATE {$prefix}appointments_eventtypes SET conference_params=" . ($originalConferenceParams === null ? 'NULL'
        : "'" . $db->real_escape_string($originalConferenceParams) . "'") . " WHERE id={$event}");

    // Persist the override through the real administrator form, not only a SQL fixture.
    $form = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
    $form['conference_params'] = [
        'conference_provider' => 'generic', 'conference_room_prefix' => 'event-room', 'conference_fixed_url' => '',
    ];
    $admin->submit('eventtype', $form);
    $persisted = json_decode($db->query("SELECT conference_params FROM {$prefix}appointments_eventtypes WHERE id={$event}")->fetch_assoc()['conference_params'] ?? '', true);
    $check(($persisted['conference_provider'] ?? null) === 'generic'
        && ($persisted['conference_room_prefix'] ?? null) === 'event-room',
        'Event form filtering retains the provider and room-prefix JSON overrides');
    $settings(['conference_provider' => 'none', 'conference_generic_template' => 'https://rooms.example.com/{room}']);
    $generic = $fixture->booking($event, $day . ' 11:00:00')['booking'];
    $check($generic['conference_state'] === 'ok' && $generic['conference_provider'] === 'generic'
        && str_starts_with($generic['conference_room'], 'event-room-')
        && $generic['conference_url'] === 'https://rooms.example.com/' . $generic['conference_room'],
        'Per-event provider/prefix override enables generic rooms while the global provider is disabled');

    $eventParams($event, ['conference_provider' => 'inherit', 'conference_room_prefix' => '', 'conference_fixed_url' => '']);
    $settings([
        'conference_provider' => 'jitsi', 'conference_jitsi_base_url' => 'https://meet.example.com',
        'conference_jitsi_auth' => 'jwt', 'conference_jitsi_jwt_secret' => 'fixture-signing-secret',
        'conference_jitsi_jwt_app_id' => 'fixture-app', 'conference_jitsi_jwt_audience' => 'jitsi',
        'conference_jitsi_jwt_issuer' => '', 'conference_jitsi_jwt_subject' => '',
        'conference_jitsi_host_lead_minutes' => 40, 'conference_jitsi_grace_minutes' => 20,
    ]);
    $before = AppointmentsMail::snapshot();
    $jwtCreated = $fixture->booking($event, $day . ' 12:00:00', ['guests' => 'guest@example.test']);
    $jwtBooking = $jwtCreated['booking'];
    $guestClaims = $claims((string) $jwtBooking['conference_url'], 'fixture-signing-secret');
    $check($guestClaims && $guestClaims['room'] === $jwtBooking['conference_room']
        && $guestClaims['context']['user']['moderator'] === false
        && $guestClaims['nbf'] === strtotime($jwtBooking['start_utc'] . ' UTC') - 900
        && $guestClaims['exp'] === strtotime($jwtBooking['end_utc'] . ' UTC') + 1200,
        'Persisted JWT verifies independently and has the guest role and exact validity window');
    $check(!str_contains($jwtCreated['html'], 'fixture-signing-secret'), 'Public confirmation does not expose the JWT signing secret');
    $messages = $calendarMail($before, $jwtBooking, 3);
    $hostTokens = 0;
    $guestTokens = 0;

    foreach ($messages as $mime) {
        $decoded = preg_replace("/\r\n[ \t]/", '', AppointmentsMail::decoded($mime));
        preg_match_all('/https:\/\/meet\.example\.com\/[A-Za-z0-9_-]+\?jwt=([A-Za-z0-9_.-]+)/', $decoded, $matches);
        $isGuest = preg_match('/^To:.*(?:invitee|guest)@example\.test/mi', preg_replace("/\r\n[ \t]/", '', $mime)) === 1;

        foreach ($matches[0] as $url) {
            $token = $claims($url, 'fixture-signing-secret');
            $check($token !== null, 'Every participant conference JWT verifies with the configured secret');

            if ($token === null) {
                continue;
            }

            if ($isGuest) {
                $check($token['context']['user']['moderator'] === false, 'Invitee and guest MIME parts never contain a moderator token');
                $guestTokens++;
            } elseif ($token['context']['user']['moderator'] === true) {
                $check($token['nbf'] === strtotime($jwtBooking['start_utc'] . ' UTC') - 2400,
                    'Host token uses the configured moderator lead time');
                $hostTokens++;
            }
        }
    }

    $check($hostTokens > 0 && $guestTokens > 0, 'Host notification receives a locally regenerated moderator URL while guests receive guest URLs');
    $before = AppointmentsMail::snapshot();
    $fixture->apply($jwtCreated['browser'], $fixture->link($jwtBooking, 'reschedule'), [
        'start' => $day . ' 12:30:00', 'month' => substr($day, 0, 7), 'timezone' => 'UTC',
    ]);
    $moved = $fixture->row((int) $jwtBooking['id']);
    $movedClaims = $claims((string) $moved['conference_url'], 'fixture-signing-secret');
    $check($moved['uid'] === $jwtBooking['uid'] && $moved['conference_room'] === $jwtBooking['conference_room']
        && $moved['conference_url'] !== $jwtBooking['conference_url']
        && $movedClaims && $movedClaims['nbf'] === strtotime($moved['start_utc'] . ' UTC') - 900,
        'Reschedule preserves the keyed room/UID and renews JWT timing before sending updated mail');
    $calendarMail($before, $moved, 3);

    $settings(['conference_provider' => 'fixed', 'conference_fixed_url' => 'https://meet.example.com/permanent']);
    $eventParams($event, ['conference_provider' => 'inherit', 'conference_fixed_url' => 'https://meet.example.com/event-permanent']);
    $fixedA = $fixture->booking($event, $day . ' 13:00:00')['booking'];
    $fixedB = $fixture->booking($event, $day . ' 14:00:00')['booking'];
    $check($fixedA['conference_url'] === 'https://meet.example.com/event-permanent'
        && $fixedA['conference_url'] === $fixedB['conference_url']
        && $fixedA['conference_room'] === $fixedB['conference_room'],
        'Fixed-room bookings honestly reuse the same event-specific permanent URL and identifier');

    $eventParams($event, []);
    $settings([
        'conference_provider' => 'jitsi', 'conference_jitsi_base_url' => 'javascript:configuration-canary',
        'conference_jitsi_auth' => 'none', 'conference_jitsi_jwt_secret' => 'credential-canary',
    ]);
    $before = AppointmentsMail::snapshot();
    $failedBooking = $fixture->booking($event, $day . ' 15:00:00', ['guests' => 'guest@example.test'])['booking'];
    $check((int) $failedBooking['state'] === 1 && $failedBooking['conference_state'] === 'failed'
        && $failedBooking['conference_url'] === null && $failedBooking['conference_room'] === null
        && !preg_match('/https?:|javascript:|canary|jwt|credential/i', (string) $failedBooking['conference_error']),
        'Provider configuration failure preserves the confirmed booking and stores only a short redacted error');
    $failureMail = $calendarMail($before, $failedBooking, 3);

    foreach ($failureMail as $mime) {
        $decoded = AppointmentsMail::decoded($mime);
        $check(str_contains($decoded, 'Fixture fallback location')
            && !str_contains($decoded, 'credential-canary') && !str_contains($decoded, 'configuration-canary'),
            'Failed conference creation still sends the fallback location and ICS without configuration secrets');
    }

    $retry($failedBooking, false);
    $unchanged = $fixture->row((int) $failedBooking['id']);
    $check((int) $unchanged['sequence'] === (int) $failedBooking['sequence']
        && $unchanged['conference_state'] === 'failed', 'Administrator conference retry requires a CSRF token');
    $settings([
        'conference_provider' => 'jitsi', 'conference_jitsi_base_url' => 'https://meet.example.com',
        'conference_jitsi_auth' => 'none',
    ]);
    $before = AppointmentsMail::snapshot();
    $retry($failedBooking);
    $retried = $fixture->row((int) $failedBooking['id']);
    $check($retried['conference_state'] === 'ok' && $retried['conference_error'] === null
        && (int) $retried['sequence'] === (int) $failedBooking['sequence'] + 1
        && $retried['modified_utc'] > $failedBooking['modified_utc'] && $retried['uid'] === $failedBooking['uid'],
        'Successful real administrator retry clears the flag and advances sequence/revision on the same booking');
    $calendarMail($before, $retried, 3, $retried['conference_url']);
    $before = AppointmentsMail::snapshot();
    $retry($retried);
    $check($fixture->row((int) $retried['id'])['sequence'] === $retried['sequence']
        && AppointmentsMail::messagesSince($before) === [], 'Retry of an already provisioned booking is inert and sends no duplicate notification');

    $fixture->apply($jwtCreated['browser'], $fixture->link($moved, 'cancel'));
    $cancelled = $fixture->row((int) $moved['id']);
    $check((int) $cancelled['state'] === 2 && $cancelled['conference_url'] === null && $cancelled['conference_room'] === null,
        'Real cancellation tears down the implicit room and removes stored conference values');
    $eraseBrowser = new AppointmentsHttp('http://localhost', 'conference-erase');
    $before = AppointmentsMail::snapshot();
    $fixture->apply($eraseBrowser, $fixture->link($fixedB, 'erase'));
    $erased = $fixture->row((int) $fixedB['id']);
    $check($erased['anonymised_utc'] !== null && $erased['invitee_email'] === null
        && $erased['conference_url'] === null && $erased['conference_room'] === null
        && $erased['conference_provider'] === null && $erased['conference_error'] === null,
        'Self-service erasure clears guest conference links, identifiers and diagnostics together with invitee data');

    foreach (AppointmentsMail::messagesSince($before) as $mime) {
        $check(!str_contains(AppointmentsMail::decoded($mime), 'https://meet.example.com/event-permanent'),
            'Erasure host-only calendar cancellation does not retain the removed room link');
    }
} catch (Throwable $error) {
    // Exception text may contain URLs, JWTs or database credentials.
    echo 'FAIL Conferencing acceptance did not complete: ' . get_class($error)
        . ' in ' . basename($error->getFile()) . ':' . $error->getLine() . ".\n";
    $failed++;
} finally {
    if ($originalParams !== null) {
        $restore = $db->real_escape_string($originalParams);
        $db->query("UPDATE {$prefix}extensions SET params='{$restore}' WHERE type='component' AND element='com_appointments'");
    }

    $fixture->cleanup();
}

exit($failed ? 1 : 0);
