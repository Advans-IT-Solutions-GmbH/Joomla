<?php
/**
 * Booking mails to the users of a target calendar (CalendarNotice, NotificationService) and the
 * user's own switch for them in «My calendars» (NotificationSwitch, notifications.save).
 *
 * - Every effective user of the target calendar gets a mail without calendar invitation, the
 *   booking person and the guests keep theirs; each role its content, free/busy the time only.
 * - Nobody twice; no target calendar in use: the host gets the mail with invitation as before.
 * - The switch changes only the signed-in user's own setting, never another account's.
 *
 * Captures mail in the test transport only. Cleans up every row it creates and restores the
 * settings and plugins it changes.
 */

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\NotificationSwitch;
use Advans\Component\Appointments\Administrator\Helper\Team\CalendarRole;
use Advans\Component\Appointments\Administrator\Service\BookingStore;
use Advans\Component\Appointments\Administrator\Service\NotificationService;
use Advans\Component\Appointments\Administrator\Service\Team\CalendarService;
use Advans\Component\Appointments\Administrator\Service\Team\GrantService;
use Advans\Component\Appointments\Administrator\Service\Team\KeyService;
use Advans\Component\Appointments\Administrator\Service\Team\TeamMailer;
use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

return static function (TeamSuite $suite): void {
    $db = $suite->db;
    $p = $suite->prefix;

    $suite->test('N: the eight calendar user templates are site-wide defaults in all three languages', static function () use ($suite, $p) {
        $purposes = "'calendar_booked','calendar_approved','calendar_rescheduled','calendar_cancelled','calendar_reminder','calendar_busy','calendar_busy_moved','calendar_free'";

        return $suite->count("SELECT COUNT(*) FROM {$p}appointments_templates WHERE eventtype_id = 0 AND published = 1"
            . " AND purpose IN ({$purposes}) AND language IN ('en-GB','de-DE','fr-FR')") === 24;
    });

    $suite->test('N: the switch of the booking mails refuses a request without form token', static function () use ($suite) {
        $response = $suite->http('POST', '/index.php?option=com_appointments&task=notifications.save', ['Content-Type: application/x-www-form-urlencoded'],
            'calendar=1&enabled=0&user_id=1');

        return $response['status'] === 403;
    });

    $names = [
        'N: every effective calendar user gets a mail without invitation, the booking person and the guest keep theirs',
        'N: the roles read, write and manage get the details, the host also the telephone and the administrator link',
        'N: free/busy gets the time only, in the account language, even from an edited template',
        'N: no grant without second factor, no blocked account and nobody who switched the mails off gets one',
        'N: approval, reschedule, cancellation, erasure and reminder reach the right users with the right template',
        'N: nobody gets a mail twice, also when an address is the booking person or a guest',
        'N: without a target calendar in use the host gets the mail with invitation and the calendar users none',
        'N: the switch in «My calendars» changes only the signed-in user\'s own setting',
        'N: the host is warned before and after switching the mails off',
    ];
    $stubs = [];

    foreach ([KeyService::class => 'B', CalendarService::class => 'C', GrantService::class => 'C', TeamMailer::class => 'D'] as $class => $package) {
        if ($suite->isStub($class)) {
            $stubs[$package] = true;
        }
    }

    if ($stubs !== []) {
        ksort($stubs);

        foreach ($names as $name) {
            $suite->pending($name, implode(', ', array_keys($stubs)));
        }

        return;
    }

    require_once __DIR__ . '/appointments-fixtures.php';
    require_once __DIR__ . '/appointments-joomla.php';
    require_once __DIR__ . '/appointments-mail.php';

    $tag = 'teamn' . bin2hex(random_bytes(4));
    $password = 'Notify-' . bin2hex(random_bytes(6)) . 'A1!';
    $hash = $db->real_escape_string(password_hash($password, PASSWORD_BCRYPT));
    $state = ['users' => [], 'calendars' => [], 'templates' => [], 'plugins' => [], 'recovery' => false];
    $original = (string) $db->query("SELECT params FROM {$p}extensions WHERE type='component' AND element='com_appointments'")->fetch_row()[0];
    $options = json_decode($original, true) ?: [];
    $teamGroup = (int) (($options['team_groups'] ?? [])[0] ?? 0);
    $externalGroup = (int) ($options['external_group'] ?? 0);
    $anchorFile = '/var/www/html/administrator/components/com_appointments-data/epochs.php';
    $anchorExisted = is_file($anchorFile);
    $anchorDir = is_dir(\dirname($anchorFile));
    $fixture = new AppointmentsFixture('team-n');
    $joomla = Factory::getContainer()->get(DatabaseInterface::class);
    $secret = (string) (new JConfig())->secret;
    $team = new TeamServices($joomla, new KeyProvider($secret), new Registry(array_replace($options, ['teamcal_enabled' => 1])),
        static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    $teamOff = new TeamServices($joomla, new KeyProvider($secret), new Registry(array_replace($options, ['teamcal_enabled' => 0])),
        static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));

    $user = static function (string $name, array $groups, bool $mfa, array $params = []) use ($db, $p, $tag, $hash, &$state): int {
        $login = $tag . $name;
        $json = $db->real_escape_string($params === [] ? '{}' : json_encode($params));
        $db->query("INSERT INTO {$p}users (name,username,email,password,block,sendEmail,registerDate,params)"
            . " VALUES ('{$login}','{$login}','{$login}@example.test','{$hash}',0,0,UTC_TIMESTAMP(),'{$json}')");
        $id = (int) $db->insert_id;
        $state['users'][] = $id;

        foreach ($groups as $group) {
            $db->query("INSERT INTO {$p}user_usergroup_map (user_id, group_id) VALUES ({$id}, " . (int) $group . ')');
        }

        if ($mfa) {
            $db->query("INSERT INTO {$p}user_mfa (user_id,title,method,`default`,options,created_on,last_used)"
                . " VALUES ({$id},'fixture','totp',1,'{}',UTC_TIMESTAMP(),NULL)");
        }

        return $id;
    };
    $address = static fn (string $name): string => $tag . $name . '@example.test';

    /**
     * The mails captured since $before: recipient, subject, whether a calendar part is attached, decoded text.
     *
     * @return list<array{to: string, subject: string, ics: bool, body: string}>
     */
    $mails = static function (array $before): array {
        $result = [];

        foreach (AppointmentsMail::messagesSince($before) as $mime) {
            $unfolded = (string) preg_replace('/\r?\n[ \t]+/', ' ', $mime);
            preg_match('/^To:\s*(.*)$/mi', $unfolded, $to);
            preg_match('/[^\s<>,"]+@[^\s<>,"]+/', $to[1] ?? '', $email);
            preg_match('/^Subject:\s*(.*)$/mi', $unfolded, $subject);
            $result[] = [
                'to' => strtolower($email[0] ?? ''),
                'subject' => (string) iconv_mime_decode(trim($subject[1] ?? ''), 0, 'UTF-8'),
                'ics' => preg_match('/Content-Type:\s*(?:text\/calendar|application\/ics)/i', $unfolded) === 1,
                'body' => AppointmentsMail::decoded($mime),
            ];
        }

        return $result;
    };
    $to = static function (array $mails, string $email): array {
        return array_values(array_filter($mails, static fn (array $mail): bool => $mail['to'] === strtolower($email)));
    };
    $expect = static function (bool $condition, string $failure): void {
        if (!$condition) {
            throw new RuntimeException($failure);
        }
    };

    $super = 0;

    try {
        // The feature on for this process (booking bridge) and in the database (web requests).
        $db->query("UPDATE {$p}extensions SET params='" . $db->real_escape_string(json_encode(array_replace($options, ['teamcal_enabled' => 1])))
            . "' WHERE type='component' AND element='com_appointments'");
        ComponentHelper::getParams('com_appointments')->set('teamcal_enabled', '1');

        foreach ([['user', 'appointments'], ['multifactorauth', 'totp']] as [$folder, $element]) {
            $row = $db->query("SELECT extension_id, enabled FROM {$p}extensions WHERE type='plugin' AND folder='{$folder}' AND element='{$element}'")->fetch_assoc();

            if ($row) {
                $state['plugins'][] = [(int) $row['extension_id'], (int) $row['enabled']];
                $db->query("UPDATE {$p}extensions SET enabled=1 WHERE extension_id=" . (int) $row['extension_id']);
            }
        }

        $super = $user('super', [8], true);
        // The host may open the administrator (Manager group), the reader may not.
        $host = $user('host', [$teamGroup, 6], false, ['language' => 'en-GB']);
        $reader = $user('reader', [$teamGroup], false, ['language' => 'fr-FR', 'timezone' => 'Europe/Paris']);
        $freebusy = $user('freebusy', [$teamGroup], true, ['language' => 'de-DE']);
        $external = $user('external', [$externalGroup], true);
        $muted = $user('muted', [$teamGroup], true);
        $nomfa = $user('nomfa', [$teamGroup], false);
        $blocked = $user('blocked', [$teamGroup], true);

        // Host and reader sign in on the website later: a real authenticator app method.
        $totp = [];

        foreach ([$host, $reader] as $id) {
            $key = '';

            for ($i = 0; $i < 32; $i++) {
                $key .= 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'[random_int(0, 31)];
            }

            $totp[$id] = $key;
            $mfa = Factory::getApplication()->bootComponent('com_users')->getMVCFactory()->createTable('Mfa', 'Administrator');
            $mfa->save(['user_id' => $id, 'title' => 'Test', 'method' => 'totp', 'default' => 1, 'options' => ['key' => $key]]);
        }

        $keys = $team->keys();

        if ($keys->recoveryFingerprint() === null) {
            $keys->createRecoveryKey($super);
            $state['recovery'] = true;
        }

        $hostKeys = $keys->createUserKey($host, $password);

        foreach ([$reader, $external, $muted, $nomfa, $blocked] as $id) {
            $keys->createUserKey($id, $password)->wipe();
        }

        $calendar = $team->calendars()->create($super, ['title' => $tag . ' calendar', 'owner_user_id' => $host, 'allow_external' => 1]);
        $state['calendars'][] = $calendar;
        $other = $team->calendars()->create($super, ['title' => $tag . ' other', 'owner_user_id' => $host]);
        $state['calendars'][] = $other;
        $grants = $team->grants();

        foreach ([[$reader, CalendarRole::Read], [$freebusy, CalendarRole::FreeBusy], [$muted, CalendarRole::Read], [$blocked, CalendarRole::Write]] as [$id, $role]) {
            $grants->grant($host, $calendar, $id, $role, $hostKeys);
        }

        try {
            // Not effective without second factor; the grant itself may already be refused.
            $grants->grant($host, $calendar, $nomfa, CalendarRole::Read, $hostKeys);
        } catch (\Throwable $e) {
        }

        // Externals are granted by whoever shares externally; the key holder completes the wrap.
        $grants->grant($super, $calendar, $external, CalendarRole::Write, null);
        $keys->completePendingWraps($keys->openKeyring($calendar, $hostKeys));
        $db->query("UPDATE {$p}users SET block=1 WHERE id={$blocked}");
        NotificationSwitch::set($joomla, $muted, $calendar, false);

        $expect($grants->accessFor($host, $calendar)?->role === CalendarRole::Manage, 'The host must manage the calendar.');
        $expect($grants->accessFor($reader, $calendar)?->role === CalendarRole::Read, 'The reader grant must be effective.');
        $expect($grants->accessFor($freebusy, $calendar)?->role === CalendarRole::FreeBusy, 'The free/busy grant must be effective.');
        $expect($grants->accessFor($external, $calendar)?->role === CalendarRole::Write, 'The external grant must be effective.');
        $expect($grants->accessFor($nomfa, $calendar) === null && $grants->accessFor($blocked, $calendar) === null,
            'Grants without second factor or of a blocked account must not be effective.');

        $event = $fixture->event();
        $db->query("UPDATE {$p}appointments_eventtypes SET host_user_id={$host}, calendar_id={$calendar} WHERE id={$event}");
        $eventRow = $db->query("SELECT * FROM {$p}appointments_eventtypes WHERE id={$event}")->fetch_assoc();
        $plain = $fixture->event();
        $db->query("UPDATE {$p}appointments_eventtypes SET host_user_id={$host}, calendar_id=NULL WHERE id={$plain}");
        $plainRow = $db->query("SELECT * FROM {$p}appointments_eventtypes WHERE id={$plain}")->fetch_assoc();
        $title = (string) $eventRow['title'];
        $store = new BookingStore($joomla);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $day = $now->modify('+6 days')->format('Y-m-d');
        $book = static function (array $row, string $hour, array $fields = []) use ($store, $now, $day): array {
            return $store->create($row, array_replace([
                'invitee_name' => 'Notify Invitee', 'invitee_first_name' => 'Notify', 'invitee_last_name' => 'Invitee',
                'invitee_email' => 'notify-invitee@example.test', 'invitee_phone' => '+41 44 555 01 01',
                'guest_emails' => 'notify-guest@example.test', 'invitee_timezone' => 'UTC', 'invitee_language' => 'en-GB',
            ], $fields), new DateTimeImmutable($day . ' ' . $hour, new DateTimeZone('UTC')), $now, [1, 2, 3, 5, 6], 'en-GB');
        };
        $notifier = new NotificationService($joomla, $team);
        $send = static function (array $booking, string $purpose, bool $hostOnly = false, ?NotificationService $service = null) use ($notifier, $now, $mails): array {
            $before = AppointmentsMail::snapshot();
            ($service ?? $notifier)->send($booking, $purpose, $now, $hostOnly);

            return $mails($before);
        };
        $booking = $book($eventRow, '10:00:00');
        $confirmed = $send($booking, 'confirmation');
        $backendLink = 'administrator/index.php?option=com_appointments&view=booking&id=' . (int) $booking['id'];

        $suite->test($names[0], static function () use ($confirmed, $to, $address, $expect) {
            $recipients = array_column($confirmed, 'to');
            sort($recipients);
            $expected = array_map('strtolower', ['notify-invitee@example.test', 'notify-guest@example.test', $address('host'), $address('reader'),
                $address('freebusy'), $address('external')]);
            sort($expected);
            $expect($recipients === $expected, 'Recipients: ' . implode(', ', $recipients));

            foreach ($confirmed as $mail) {
                $guest = \in_array($mail['to'], ['notify-invitee@example.test', 'notify-guest@example.test'], true);
                $expect($mail['ics'] === $guest, ($guest ? 'Missing' : 'Unexpected') . ' invitation for ' . $mail['to']);
            }

            return true;
        });

        $suite->test($names[1], static function () use ($confirmed, $to, $address, $expect, $title, $tag, $backendLink, $host, $reader) {
            [$hostMail] = $to($confirmed, $address('host'));
            [$readerMail] = $to($confirmed, $address('reader'));
            [$externalMail] = $to($confirmed, $address('external'));
            $hostUser = Factory::getContainer()->get(\Joomla\CMS\User\UserFactoryInterface::class)->loadUserById($host);
            $expect($hostUser->authorise('core.login.admin') && $hostUser->authorise('core.manage', 'com_appointments'),
                'The fixture host must be able to open the administrator.');
            $expect(str_starts_with($hostMail['subject'], 'New appointment in ' . $tag . ' calendar: ' . $title), 'Host subject: ' . $hostMail['subject']);
            $expect(str_contains($hostMail['body'], 'Notify Invitee') && str_contains($hostMail['body'], '+41 44 555 01 01')
                && str_contains($hostMail['body'], $backendLink) && str_contains($hostMail['body'], 'view=mycalendars'), 'Host details');
            // French account language and time zone; what the calendar copy shows, no telephone, no administrator link.
            $expect(str_starts_with($readerMail['subject'], 'Nouveau rendez-vous dans ' . $tag . ' calendar'), 'Reader subject: ' . $readerMail['subject']);
            $expect(str_contains($readerMail['body'], 'Europe/Paris') && str_contains($readerMail['body'], 'notify-invitee@example.test')
                && str_contains($readerMail['body'], 'notify-guest@example.test') && !str_contains($readerMail['body'], '555 01 01')
                && !str_contains($readerMail['body'], 'view=booking&'), 'Reader details');
            $expect(str_contains($externalMail['body'], 'Notify Invitee') && !str_contains($externalMail['body'], '555 01 01')
                && !str_contains($externalMail['body'], 'view=booking&'), 'External details');
            $expect(!preg_match('/action=(?:reschedule|cancel|erase)|task=guest\.confirm/', $hostMail['body'] . $readerMail['body'] . $externalMail['body']),
                'Calendar users never get the signed links of the booking person or a guest');

            return true;
        });

        $suite->test($names[2], static function () use ($confirmed, $to, $address, $expect, $title, $tag, $book, $eventRow, $send, $db, $p, $event, &$state) {
            [$mail] = $to($confirmed, $address('freebusy'));
            $leak = static fn (array $mail): bool => preg_match('/Notify|notify-|555 01 01|' . preg_quote($title, '/') . '|' . preg_quote($tag . 'host', '/') . '/i', $mail['subject'] . $mail['body']) === 1;
            $expect($mail['subject'] === 'Belegte Zeit in ' . $tag . ' calendar', 'Free/busy subject: ' . $mail['subject']);
            $expect(!$leak($mail) && !$mail['ics'] && str_contains($mail['body'], 'Zeitzone'), 'Free/busy body must show the time only');

            // An event type template that tries to show the booking person to free/busy.
            $db->query("INSERT INTO {$p}appointments_templates (eventtype_id,purpose,language,subject,body,published) VALUES ({$event},'calendar_busy','de-DE',"
                . "'Busy {invitee_name} {event_title} {calendar_title}','{invitee_email} {invitee_phone} {guest_emails} {host_name} {event_title} {description} {start}',1)");
            $state['templates'][] = (int) $db->insert_id;
            $edited = $to($send($book($eventRow, '15:00:00'), 'confirmation'), $address('freebusy'));
            $expect(\count($edited) === 1 && !$leak($edited[0]) && str_contains($edited[0]['subject'], 'Busy') && !str_contains($edited[0]['body'] . $edited[0]['subject'], '{'),
                'An edited free/busy template must not reveal anything: ' . json_encode($edited[0] ?? null));

            return true;
        });

        $suite->test($names[3], static function () use ($confirmed, $to, $address, $expect) {
            foreach (['muted', 'nomfa', 'blocked'] as $name) {
                $expect($to($confirmed, $address($name)) === [], $name . ' must get no mail');
            }

            return true;
        });

        $suite->test($names[4], static function () use ($booking, $send, $to, $address, $expect) {
            $templates = static function (array $mails) use ($to, $address): array {
                $result = [];

                foreach (['host', 'reader', 'external', 'freebusy'] as $name) {
                    $found = $to($mails, $address($name));
                    $result[$name] = $found === [] ? null : (\count($found) . ':' . ($found[0]['ics'] ? 'ics:' : '') . $found[0]['subject']);
                }

                return $result;
            };
            $approved = $templates($send($booking, 'approved'));
            $expect(str_starts_with((string) $approved['reader'], '1:Validé dans') && $approved['freebusy'] === null
                && str_starts_with((string) $approved['host'], '1:Approved in'), 'Approval: ' . json_encode($approved));
            $moved = $templates($send($booking, 'reschedule'));
            $expect(str_starts_with((string) $moved['freebusy'], '1:Belegte Zeit verschoben') && str_starts_with((string) $moved['external'], '1:New time in'),
                'Reschedule: ' . json_encode($moved));
            $reminder = $send($booking, 'reminder');
            $reminded = $templates($reminder);
            $expect($reminded['reader'] === null && $reminded['external'] === null && $reminded['freebusy'] === null
                && str_starts_with((string) $reminded['host'], '1:Reminder:'), 'Reminder: ' . json_encode($reminded));
            $expect(\count($to($reminder, 'notify-invitee@example.test')) === 1 && $to($reminder, 'notify-invitee@example.test')[0]['ics'],
                'The booking person keeps the reminder with invitation');
            $cancelled = $booking;
            $cancelled['state'] = 2;
            $gone = $templates($send($cancelled, 'cancellation'));
            $expect(str_starts_with((string) $gone['freebusy'], '1:Zeit wieder frei') && str_starts_with((string) $gone['reader'], '1:Annulé dans'),
                'Cancellation: ' . json_encode($gone));
            $erased = $send($cancelled, 'erasure_notice', true);
            $erasedTemplates = $templates($erased);
            $expect(\count($to($erased, 'notify-invitee@example.test')) === 0 && str_starts_with((string) $erasedTemplates['host'], '1:Cancelled in')
                && str_starts_with((string) $erasedTemplates['freebusy'], '1:Zeit wieder frei'), 'Erasure: ' . json_encode($erasedTemplates));

            foreach ([$approved, $moved, $reminded, $gone, $erasedTemplates] as $round) {
                $expect(!str_contains(json_encode($round), 'ics:'), 'No calendar user mail carries an invitation');
            }

            return true;
        });

        $suite->test($names[5], static function () use ($book, $eventRow, $send, $address, $to, $expect) {
            // The external user books, the reader is a guest: each gets the one mail of that role, with invitation.
            $mails = $send($book($eventRow, '11:00:00', ['invitee_email' => strtoupper($address('external')), 'guest_emails' => $address('reader')]), 'confirmation');
            $counts = array_count_values(array_column($mails, 'to'));
            $expect(max($counts) === 1, 'Duplicates: ' . json_encode($counts));
            $expect(\count($to($mails, $address('external'))) === 1 && $to($mails, $address('external'))[0]['ics']
                && $to($mails, $address('reader'))[0]['ics'] && !$to($mails, $address('host'))[0]['ics'], 'Roles: ' . json_encode($counts));

            return \count($mails) === 4;
        });

        $suite->test($names[6], static function () use ($book, $plainRow, $booking, $send, $to, $address, $expect, $joomla, $teamOff) {
            $mails = $send($book($plainRow, '12:00:00'), 'confirmation');
            [$hostMail] = $to($mails, $address('host'));
            $expect(\count($mails) === 3 && $hostMail['ics'] && str_starts_with($hostMail['subject'], 'New appointment: '),
                'Without target calendar: ' . json_encode(array_column($mails, 'subject', 'to')));
            // The target calendar is set, but the internet calendars are switched off: no copy, so the old way.
            $off = $send($booking, 'approved', false, new NotificationService($joomla, $teamOff));
            $expect(\count($off) === 3 && $to($off, $address('host'))[0]['ics'] && $to($off, $address('reader')) === [],
                'Switched off: ' . json_encode(array_column($off, 'subject', 'to')));

            return true;
        });

        if (!class_exists('TeamDBrowser') || !function_exists('team_d_totp')) {
            $suite->pending($names[7], 'D');
            $suite->pending($names[8], 'D');

            return;
        }

        $signIn = static function (string $username, string $key) use ($suite, $password): ?TeamDBrowser {
            $browser = new TeamDBrowser($suite->base);
            $login = TeamDBrowser::form($browser->request('/index.php?option=com_users&view=login'), 'user.login');

            if ($login === null) {
                return null;
            }

            $html = $browser->request($login['action'], $login['fields'] + ['username' => $username, 'password' => $password]);
            $captive = TeamDBrowser::form($html, 'captive.validate');

            if ($captive === null) {
                return null;
            }

            $browser->request($captive['action'], $captive['fields'] + ['code' => team_d_totp($key, time())]);

            return $browser;
        };
        // «My calendars» opens only through its menu item (appointments-menu.php).
        require_once __DIR__ . '/appointments-menu.php';
        $myCalendars = '/index.php?option=com_appointments&view=mycalendars&Itemid=' . appointments_menu_item('index.php?option=com_appointments&view=mycalendars');
        $page = static fn (TeamDBrowser $browser): string => $browser->request($myCalendars);
        $paramsOf = static fn (int $id): string => (string) $db->query("SELECT params FROM {$p}users WHERE id={$id}")->fetch_row()[0];

        $suite->test($names[7], static function () use ($signIn, $page, $paramsOf, $tag, $totp, $reader, $host, $calendar, $other, $expect, $book, $eventRow, $send, $to, $address) {
            $browser = $signIn($tag . 'reader', $totp[$reader]);
            $expect($browser !== null, 'The reader must sign in with password and second factor.');
            $html = $page($browser);
            $form = TeamDBrowser::form($html, 'notifications.save');
            $expect($form !== null && (int) ($form['fields']['calendar'] ?? 0) === $calendar && ($form['fields']['enabled'] ?? '') === '0'
                && str_contains($html, 'Mails about bookings: <strong>On</strong>'), 'The page must offer switching the mails off.');
            $hostBefore = $paramsOf($host);

            // Another account's id in the request changes nothing: the account is the session's.
            $result = $browser->request($form['action'], $form['fields'] + ['user_id' => $host, 'id' => $host, 'jform' => ['user_id' => $host]]);
            $expect(NotificationSwitch::mutedFrom($paramsOf($reader)) === [$calendar] && $paramsOf($host) === $hostBefore,
                'Only the reader\'s own setting may change.');
            $expect(str_contains($result, 'are switched off') && str_contains($result, 'Mails about bookings: <strong>Off</strong>'), 'The page must show the new state.');
            $expect($to($send($book($eventRow, '13:00:00'), 'confirmation'), $address('reader')) === [], 'The switched-off reader must get no booking mail.');

            // A calendar without the reader's access is «not found», whatever the request says.
            $fields = TeamDBrowser::form($page($browser), 'notifications.save')['fields'];
            $browser->request('/index.php?option=com_appointments&task=notifications.save', ['calendar' => $other, 'enabled' => '0'] + $fields, 0);
            $expect($browser->status === 404 && !\in_array($other, NotificationSwitch::mutedFrom($paramsOf($reader)), true), 'Foreign calendar: HTTP ' . $browser->status);

            $form = TeamDBrowser::form($page($browser), 'notifications.save');
            $browser->request($form['action'], $form['fields']);

            return NotificationSwitch::mutedFrom($paramsOf($reader)) === [] && $paramsOf($host) === $hostBefore;
        });

        $suite->test($names[8], static function () use ($signIn, $page, $paramsOf, $tag, $totp, $host, $calendar, $expect) {
            $browser = $signIn($tag . 'host', $totp[$host]);
            $expect($browser !== null, 'The host must sign in with password and second factor.');
            $html = $page($browser);
            $expect(str_contains($html, 'You are the host of an event type that books into this calendar.'), 'The page must warn the host.');
            $form = TeamDBrowser::form($html, 'notifications.save');
            $result = $browser->request($form['action'], $form['fields']);
            $warned = str_contains($result, 'from now on you learn of new bookings only through the calendar');
            $off = NotificationSwitch::mutedFrom($paramsOf($host)) === [$calendar];
            $form = TeamDBrowser::form($page($browser), 'notifications.save');
            $browser->request($form['action'], $form['fields']);

            return $warned && $off && NotificationSwitch::mutedFrom($paramsOf($host)) === [];
        });
    } finally {
        $db->query("UPDATE {$p}extensions SET params='" . $db->real_escape_string($original) . "' WHERE type='component' AND element='com_appointments'");
        ComponentHelper::getParams('com_appointments')->set('teamcal_enabled', (string) ($options['teamcal_enabled'] ?? '0'));

        foreach ($state['templates'] as $id) {
            $db->query("DELETE FROM {$p}appointments_templates WHERE id=" . (int) $id);
        }

        foreach ($state['calendars'] as $id) {
            try {
                // With its objects, grants and keys, like an administrator would.
                $team->calendars()->delete($super, (int) $id);
            } catch (\Throwable $e) {
                $db->query("UPDATE {$p}appointments_eventtypes SET calendar_id=NULL WHERE calendar_id=" . (int) $id);
                $db->query("DELETE FROM {$p}appointments_calendars WHERE id=" . (int) $id);
            }
        }

        $fixture->cleanup();

        if ($state['users'] !== []) {
            $list = implode(',', array_map('intval', $state['users']));

            foreach (['appointments_calendar_grants', 'appointments_calendar_key_wraps', 'appointments_user_keys', 'appointments_devices',
                'appointments_hosts', 'user_mfa', 'user_usergroup_map'] as $table) {
                $db->query("DELETE FROM {$p}{$table} WHERE user_id IN ({$list})");
            }

            $db->query("DELETE FROM {$p}session WHERE userid IN ({$list})");
            $db->query("DELETE FROM {$p}users WHERE id IN ({$list})");
        }

        if ($state['recovery']) {
            $db->query("DELETE FROM {$p}appointments_keyring WHERE purpose='recovery'");
        }

        foreach ($state['plugins'] as [$id, $was]) {
            $db->query("UPDATE {$p}extensions SET enabled={$was} WHERE extension_id={$id}");
        }

        if (!$anchorExisted) {
            foreach (array_merge([$anchorFile, $anchorFile . '.lock'], glob(\dirname($anchorFile) . '/*.tmp') ?: []) as $file) {
                @unlink($file);
            }

            if (!$anchorDir) {
                @rmdir(\dirname($anchorFile));
            }
        } elseif (\function_exists('posix_getpwnam') && ($web = posix_getpwnam('www-data'))) {
            foreach ([\dirname($anchorFile), $anchorFile, $anchorFile . '.lock'] as $file) {
                if (file_exists($file) && fileowner($file) !== $web['uid']) {
                    @chown($file, $web['uid']);
                    @chgrp($file, $web['gid']);
                }
            }
        }
    }
};
