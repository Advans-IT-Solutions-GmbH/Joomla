<?php

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-joomla.php';

$fixture = new AppointmentsFixture('administration');
$admin = new AppointmentsHttp('http://localhost', 'administration-admin');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$groupId = 0;
$userId = 0;
$events = [];
$templateIds = [];
$tag = 'suite-admin-' . bin2hex(random_bytes(6));
$componentRules = $db->query("SELECT rules FROM {$prefix}assets WHERE name='com_appointments'")->fetch_assoc()['rules'];
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$listing = static function (AppointmentsHttp $client, array $filters = []): string {
    return $client->request('/administrator/index.php?' . http_build_query(array_replace([
        'option' => 'com_appointments', 'view' => 'bookings', 'filter_eventtype_id' => '',
        'filter_state' => '', 'filter_search' => '', 'filter_from' => '', 'filter_to' => '',
    ], $filters)));
};
$export = static function (AppointmentsHttp $client, array $filters = []) use ($listing): string {
    $token = $client->token($listing($client, $filters));
    return $client->request('/administrator/index.php?option=com_appointments', array_replace([
        'task' => 'bookings.exportCsv', $token => 1, 'filter_eventtype_id' => '',
        'filter_state' => '', 'filter_search' => '', 'filter_from' => '', 'filter_to' => '',
    ], $filters));
};

try {
    $admin->login();
    foreach (['dashboard', 'bookings', 'eventtypes', 'schedules', 'blockeds', 'fields', 'templates', 'feeds', 'system',
        'calendars', 'invitations', 'devices', 'teamlog'] as $view) {
        $html = $admin->request('/administrator/index.php?option=com_appointments&view=' . $view);
        $check($admin->status === 200 && str_contains($html, 'class="header-title')
            && str_contains($html, '/media/com_appointments/css/admin-layout.css'),
            'Administrator page loads the layout stylesheet that keeps the title and tables inside the screen: ' . $view);
    }
    $options = $admin->request('/administrator/index.php?option=com_config&view=component&component=com_appointments');
    $document = new DOMDocument();
    @$document->loadHTML($options);
    $heading = (new DOMXPath($document))->query('//h1[contains(@class,"page-title")]')->item(0);
    $check($admin->status === 200 && $heading !== null
        && trim($heading->textContent) === 'Appointments: Options',
        'Native component Options uses its translated runtime heading, not a raw generated key');
    $check((new DOMXPath($document))->query('//fieldset[@id="jform_phone_country_choice"]//label[contains(@class,"form-check-label")]')->length === 2,
        'Long calling-code choices use native in-flow radio labels rather than fixed-height switcher labels');
    foreach (['schedule', 'blocked'] as $formView) {
        $html = $admin->request('/administrator/index.php?option=com_appointments&task=' . $formView . '.add');
        $document = new DOMDocument();
        @$document->loadHTML($html);
        $check((new DOMXPath($document))->query('//script[contains(@src, "/validate")]')->length > 0,
            'Save & Close has its required Joomla form validator: ' . $formView);
        $admin->request('/administrator/index.php?option=com_appointments', [
            'task' => $formView . '.cancel', $admin->token($html) => 1,
        ]);
    }
    foreach ([0, 1] as $index) {
        $id = $fixture->event();
        $events[] = $id;
        $row = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$id}")->fetch_assoc();
        $row['conference_params'] = [];
        $admin->submit('eventtype', $row);
        $asset = $db->query("SELECT id FROM {$prefix}assets WHERE name='com_appointments.eventtype.{$id}'")->fetch_assoc();
        $check($asset && (int) $asset['id'] > 0, 'Real event administration creates its permission asset');
    }
    [$eventA, $eventB] = $events;
    foreach ([$eventA => 'allowed', $eventB => 'denied'] as $event => $scope) {
        $db->query("INSERT INTO {$prefix}appointments_fields (eventtype_id,label,type,options) VALUES ({$event},'{$tag}-{$scope}-field','text','[]')");
    }
    foreach ([$eventA => 'allowed', $eventB => 'denied', 0 => 'sitewide'] as $event => $scope) {
        $db->query("INSERT INTO {$prefix}appointments_templates (eventtype_id,purpose,language,subject,body) VALUES ({$event},'confirmation','en-US','{$tag}-{$scope}-template','Fixture')");
        $templateIds[] = (int) $db->insert_id;
    }
    $day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+2 days')->format('Y-m-d');
    // The last names sort opposite to the start times, so a list that still sorts by time fails the checks below.
    $first = $fixture->booking($eventA, $day . ' 10:00:00', ['first_name' => '=1+1', 'last_name' => 'zzz-' . $tag])['booking'];
    $second = $fixture->booking($eventA, $day . ' 11:00:00', ['first_name' => $tag . '-allowed', 'last_name' => 'aaa-' . $tag . '-surname'])['booking'];
    $third = $fixture->booking($eventB, $day . ' 12:00:00', ['first_name' => $tag . '-denied', 'email' => 'other@example.test'])['booking'];
    $id = (int) $first['id'];
    $db->query("UPDATE {$prefix}appointments_bookings SET state=0 WHERE id={$id}");
    $html = $listing($admin, ['filter_state' => '0']);
    $check($admin->status === 200 && str_contains($html, '=1+1') && !str_contains($html, $tag . '-allowed'),
        'Pending status is an explicit filter, not an empty all-status value');
    $html = $listing($admin, ['filter_eventtype_id' => $eventA, 'filter_search' => $tag . '-allowed', 'filter_from' => $day, 'filter_to' => $day]);
    $check($admin->status === 200 && str_contains($html, $tag . '-allowed')
        && !str_contains($html, '=1+1') && !str_contains($html, $tag . '-denied'), 'Event, UTC date and contact search filters combine');
    $check($second['invitee_first_name'] === $tag . '-allowed' && $second['invitee_last_name'] === 'aaa-' . $tag . '-surname'
        && $second['invitee_name'] === $tag . '-allowed aaa-' . $tag . '-surname', 'First and last name are stored separately and as the full name');
    $html = $listing($admin, ['filter_search' => $tag . '-surname']);
    $check($admin->status === 200 && str_contains($html, $tag . '-allowed') && !str_contains($html, '=1+1'),
        'The contact search finds a booking by its last name');
    $order = static function (string $html) use ($tag): ?bool {
        $a = strpos($html, $tag . '-allowed');
        $b = strpos($html, '=1+1');

        return $a === false || $b === false ? null : $a < $b;
    };
    $asc = $order($listing($admin, ['filter_eventtype_id' => $eventA, 'list' => ['fullordering' => 'b.invitee_last_name ASC']]));
    $desc = $order($listing($admin, ['filter_eventtype_id' => $eventA, 'list' => ['fullordering' => 'b.invitee_last_name DESC']]));
    $byTime = $order($listing($admin, ['filter_eventtype_id' => $eventA, 'list' => ['fullordering' => 'b.start_utc ASC']]));
    $check($admin->status === 200 && $asc === true && $desc === false && $byTime === false,
        'The booking list sorts by last name in both directions, against the order of the start times');
    $csv = $export($admin, ['filter_eventtype_id' => $eventA]);
    $check(str_contains($csv, 'invitee_name,invitee_first_name,invitee_last_name,invitee_email')
        && str_contains($csv, $tag . '-surname'), 'CSV exports first and last name next to the full name');
    $listing($admin, ['list' => ['fullordering' => 'b.start_utc DESC']]);
    $check($admin->status === 200 && str_starts_with($csv, 'id,eventtype_title,')
        && str_contains($csv, "'=1+1") && str_contains($csv, $tag . '-allowed') && !str_contains($csv, $tag . '-denied'),
        'CSV uses the selected scope, neutralises spreadsheet formulas and is not an HTML response');
    $admin->request('/administrator/index.php?option=com_appointments&task=bookings.exportCsv');
    $check($admin->status === 403, 'CSV requires a token-protected POST');
    $detail = $admin->request('/administrator/index.php?option=com_appointments&view=booking&id=' . $id);
    $check($admin->status === 200 && str_contains($detail, $first['uid']) && str_contains($detail, 'bookings.reschedule')
        && str_contains($detail, 'name="start"'), 'Booking detail displays its stable calendar identity and reschedule form');
    $token = $admin->token($detail);
    $admin->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.reschedule', 'id' => $id, 'start' => $day . 'T14:00', $token => 1,
    ]);
    $moved = $fixture->row($id);
    $check($admin->status === 200 && $moved['uid'] === $first['uid'] && (int) $moved['sequence'] === 1
        && $moved['start_utc'] === $day . ' 14:00:00' && (int) $moved['state'] === 0,
        'Administrator reschedule preserves UID and pending state, raises sequence and moves in place');
    $detail = $admin->request('/administrator/index.php?option=com_appointments&view=booking&id=' . $id);
    $html = $admin->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.reschedule', 'id' => $id, 'start' => $day . 'T11:00', $admin->token($detail) => 1,
    ]);
    $unchanged = $fixture->row($id);
    $check($admin->status === 200 && str_contains($html, 'unavailable or was just taken')
        && $unchanged['start_utc'] === $moved['start_utc'] && $unchanged['uid'] === $moved['uid']
        && $unchanged['sequence'] === $moved['sequence'], 'Occupied administrator reschedule is a friendly refusal with no calendar revision');
    $admin->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.reschedule', 'id' => $id, 'start' => $day . 'T15:00',
    ]);
    $check($admin->status === 403 && $fixture->row($id)['start_utc'] === $day . ' 14:00:00', 'Tokenless administrator reschedule changes nothing');
    $detail = $admin->request('/administrator/index.php?option=com_appointments&view=booking&id=' . $id);
    $admin->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.approve', 'cid' => [$id], $admin->token($detail) => 1,
    ]);
    $check((int) $fixture->row($id)['state'] === 1, 'Detail action approves a pending booking through the same locked lifecycle');
    $dashboard = $admin->request('/administrator/index.php?option=com_appointments&view=dashboard');
    $check($admin->status === 200 && str_contains($dashboard, 'Upcoming active appointments')
        && str_contains($dashboard, 'Bookings by authorised event type'), 'Dashboard renders time-aware and per-event statistics');
    $document = new DOMDocument();
    @$document->loadHTML($dashboard);
    $check((new DOMXPath($document))->query('//link[contains(@href,"/com_appointments/css/admin.css")]')->length === 1,
        'Administrator navigation loads its local responsive styles outside event-type forms too');
    $styles = $admin->request('/media/com_appointments/css/admin.css');
    $check($admin->status === 200
        && preg_match('~#calendar-form\s+\.switcher\s*\{[^}]*max-inline-size:\s*100%~', $styles) === 1,
        'The installed stylesheet bounds native calendar switchers to their settings column without clipping');
    $check(preg_match('~#appointments-help\s*>\s*section\[id\]\s*\{[^}]*scroll-margin-block-start:\s*8rem~', $styles) === 1,
        'The installed stylesheet reserves native sticky-toolbar clearance for every Help topic fragment');
    $check(preg_match('~html:has\(\#appointments-help\)[^{]*\{[^}]*scroll-behavior:\s*auto\s*;~', $styles) === 1,
        'The installed stylesheet keeps native Help navigation from moving targets during pointer activation');
    $componentParams = $db->query("SELECT params FROM {$prefix}extensions WHERE element='com_appointments' AND type='component'")->fetch_assoc()['params'];
    try {
        foreach ([0, 1] as $enabled) {
            $params = json_decode($componentParams, true, 512, JSON_THROW_ON_ERROR);
            $params['teamcal_enabled'] = $enabled;
            $encoded = $db->real_escape_string(json_encode($params, JSON_THROW_ON_ERROR));
            $db->query("UPDATE {$prefix}extensions SET params='{$encoded}' WHERE element='com_appointments' AND type='component'");
            $keyRows = (int) $db->query("SELECT COUNT(*) FROM {$prefix}appointments_user_keys")->fetch_row()[0];
            $help = $admin->request('/administrator/index.php?option=com_appointments&view=help');
            $document = new DOMDocument();
            @$document->loadHTML($help);
            $helpXpath = new DOMXPath($document);
            $check($admin->status === 200 && $helpXpath->query('//div[@id="appointments-help"]//section')->length === 9
                && $helpXpath->query('//a[@id="toolbar-back"]')->length === 1,
                'Central help has all generic topics and a real overview return, enabled=' . $enabled);
            $topicLinks = $helpXpath->query('//div[@id="appointments-help"]/nav/a[starts-with(@href,"#help-")]');
            $topicTargetsValid = $topicLinks->length === 9;
            foreach ($topicLinks as $topicLink) {
                $target = substr($topicLink->getAttribute('href'), 1);
                $topicTargetsValid = $topicTargetsValid && preg_match('/^help-[a-z]+$/D', $target) === 1
                    && $helpXpath->query('//div[@id="appointments-help"]/section[@id="' . $target . '"]')->length === 1;
            }
            $check($topicTargetsValid
                && $helpXpath->query('//link[contains(@href,"/com_appointments/css/admin.css")]')->length === 1,
                'All nine native Help fragments target sections with the installed scroll-clearance stylesheet, enabled=' . $enabled);
            $check(str_contains($help, 'id="help-removal"') && str_contains($help, 'PROPFIND and REPORT')
                && str_contains($help, 'main Outlook calendar'),
                'Central help explains removing devices and why Outlook with the main calendar uses «Block only», enabled=' . $enabled);
            $overviewButton = $helpXpath->query('//div[@id="appointments-help"]/nav/a[contains(@class,"btn-primary")]')->item(0);
            $check($overviewButton !== null && trim($overviewButton->textContent) === 'Overview',
                'Help overview button uses the runtime language catalogue, enabled=' . $enabled);
            $check(str_contains($help, 'THISANDFUTURE') && str_contains($help, 'does not mean unlimited retention')
                && str_contains($help, 'fixed 12-month period') && !str_contains($help, 'COM_APPOINTMENTS_HELP_'),
                'Central help renders the separate translated retention contracts, enabled=' . $enabled);
            $check((int) $db->query("SELECT COUNT(*) FROM {$prefix}appointments_user_keys")->fetch_row()[0] === $keyRows,
                'Reading central help never initialises personal keys, enabled=' . $enabled);
            $calendars = $admin->request('/administrator/index.php?option=com_appointments&view=calendars');
            $document = new DOMDocument();
            @$document->loadHTML($calendars);
            $xpath = new DOMXPath($document);
            $back = $xpath->query('//a[@id="toolbar-dashboard"]')->item(0);
            $check($xpath->query('//a[@id="toolbar-invitations"]')->length === 1
                && $xpath->query('//a[@id="toolbar-devices"]')->length === 1
                && $xpath->query('//a[@id="toolbar-teamlog"]')->length === 1,
                'Calendar hub retains authorised branch links, enabled=' . $enabled);
            $check($admin->status === 200 && $back !== null,
                'Calendar list has an overview toolbar link when internet calendars are ' . ($enabled ? 'enabled' : 'disabled'));
            if ($back !== null) {
                $link = html_entity_decode($back->getAttribute('href'));
                $overview = $admin->request(str_starts_with($link, '/') ? $link : '/administrator/' . $link);
                $check($admin->status === 200 && str_contains($overview, 'com-appointments-dashboard'),
                    'Calendar overview link reaches the existing navigation');
            }
            foreach (['view=invitations', 'view=invitations&layout=revoke', 'view=calendar', 'view=devices', 'view=teamlog'] as $query) {
                if ($enabled && $query === 'view=calendar') {
                    continue;
                }
                $html = $admin->request('/administrator/index.php?option=com_appointments&' . $query);
                $return = $query === 'view=calendar' ? 'id="toolbar-cancel"' : 'id="toolbar-calendars"';
                $check($admin->status === 200 && str_contains($html, $return),
                    'Calendar child view keeps its return toolbar: ' . $query . ', enabled=' . $enabled);
                if ($query === 'view=calendar') {
                    $check(str_contains($html, 'id="calendar-form" class="form-validate"'),
                        'The disabled calendar Close button can find its form through Joomla core');
                    $admin->request('/administrator/index.php?option=com_appointments', [
                        'task' => 'calendars.cancel', $admin->token($html) => 1,
                    ]);
                    $check($admin->status === 200, 'A disabled calendar editor closes through its token-protected cancel task');
                }
            }
        }
    } finally {
        $encoded = $db->real_escape_string($componentParams);
        $db->query("UPDATE {$prefix}extensions SET params='{$encoded}' WHERE element='com_appointments' AND type='component'");
    }
    $options = $admin->request('/administrator/index.php?option=com_config&view=component&component=com_appointments');
    $check(str_contains($dashboard, 'view=help') && str_contains($options, 'view=help')
        && (int) $db->query("SELECT COUNT(*) FROM {$prefix}menu WHERE client_id=1 AND link='index.php?option=com_appointments&view=help'")->fetch_row()[0] === 1,
        'Dashboard, native component options and installed administrator menu expose central help');
    $check($admin->status === 200 && preg_match('/>\\s*General\\s*</', $options) === 1
        && !str_contains($options, 'COM_CONFIG_basic_FIELDSET_LABEL'),
        'Component options resolve the first fieldset label');
    $system = $admin->request('/administrator/index.php?option=com_appointments&view=system');
    $config = new JConfig();
    $check($admin->status === 200 && str_contains($system, 'Oldest accountability audit record')
        && str_contains($system, 'Latest enabled retention task') && !str_contains($system, $config->secret),
        'System checks report task/audit health without exposing signing credentials');

    // Without a valid public site URL or live_site the component sends no mail and takes no booking (fail closed).
    $siteUrlRow = static fn (string $html, string $status): bool => preg_match('#Public site URL for e-mail links</th>\s*<td class="text-'
        . ($status === 'ok' ? 'success">OK' : 'danger fw-bold">Error') . '#', $html) === 1;
    $withSiteUrl = static function (string $url) use ($db, $prefix, $componentParams): void {
        $params = json_decode($componentParams, true) ?: [];
        $params['public_site_url'] = $url;
        $encoded = $db->real_escape_string(json_encode($params));
        $db->query("UPDATE {$prefix}extensions SET params='{$encoded}' WHERE element='com_appointments' AND type='component'");
    };
    try {
        if (trim((string) ($config->live_site ?? '')) === '') {
            $withSiteUrl('');
            $system = $admin->request('/administrator/index.php?option=com_appointments&view=system');
            $check($admin->status === 200 && $siteUrlRow($system, 'attention') && str_contains($system, 'Not set')
                && str_contains($system, 'Host header'),
                'Without a public site URL and live_site, the system checks report an error naming the public site URL');
        }
        if (trim((string) ($config->live_site ?? '')) === '') {
            $dashboard = $admin->request('/administrator/index.php?option=com_appointments');
            $check($admin->status === 200 && str_contains($dashboard, 'id="appointments-site-url-missing"') && str_contains($dashboard, 'alert-danger'),
                'Without a public site URL the dashboard shows an error that no mails are sent and no bookings taken');
        }
        $withSiteUrl('https://www.example.test/site');
        $dashboard = $admin->request('/administrator/index.php?option=com_appointments');
        $check($admin->status === 200 && !str_contains($dashboard, 'id="appointments-site-url-missing"'),
            'With a valid public site URL the dashboard shows no error');
        $system = $admin->request('/administrator/index.php?option=com_appointments&view=system');
        $check($admin->status === 200 && $siteUrlRow($system, 'ok') && str_contains($system, 'https://www.example.test/site'),
            'A configured public site URL satisfies the system check and is shown');
        // The same values that NotificationService::linkBase() refuses: a query, an upper-case scheme,
        // surrounding spaces, and an option of spaces only (which mail links use instead of live_site).
        foreach (['https://www.example.test/?x=1', 'HTTPS://www.example.test', ' https://www.example.test ', '   '] as $refused) {
            $withSiteUrl($refused);
            $system = $admin->request('/administrator/index.php?option=com_appointments&view=system');
            $check($admin->status === 200 && $siteUrlRow($system, 'attention'),
                'A public site URL that mail links refuse is an error: ' . json_encode($refused));
        }
    } finally {
        $encoded = $db->real_escape_string($componentParams);
        $db->query("UPDATE {$prefix}extensions SET params='{$encoded}' WHERE element='com_appointments' AND type='component'");
    }

    $parent = $db->query("SELECT rgt FROM {$prefix}usergroups WHERE id=6")->fetch_assoc();
    $right = (int) $parent['rgt'];
    $db->begin_transaction();
    try {
        $db->query("UPDATE {$prefix}usergroups SET rgt=rgt+2 WHERE rgt>={$right}");
        $db->query("UPDATE {$prefix}usergroups SET lft=lft+2 WHERE lft>{$right}");
        $db->query("INSERT INTO {$prefix}usergroups (parent_id,lft,rgt,title) VALUES (6,{$right}," . ($right + 1) . ",'{$tag}')");
        $groupId = (int) $db->insert_id;
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    $password = 'Fixture-' . bin2hex(random_bytes(12));
    $hash = $db->real_escape_string(password_hash($password, PASSWORD_BCRYPT));
    $db->query("INSERT INTO {$prefix}users (name,username,email,password,block,sendEmail,registerDate,params) VALUES ('{$tag}','{$tag}','{$tag}@example.test','{$hash}',0,0,UTC_TIMESTAMP(),'{}')");
    $userId = (int) $db->insert_id;
    $db->query("INSERT INTO {$prefix}user_usergroup_map (user_id,group_id) VALUES ({$userId},{$groupId})");
    $rules = json_decode($componentRules, true, 512, JSON_THROW_ON_ERROR);
    $rules['core.manage'][(string) $groupId] = 1;
    $rules['core.options'][(string) $groupId] = 0;
    $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string(json_encode($rules, JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR)) . "' WHERE name='com_appointments'");
    foreach ([$eventA => 1, $eventB => 0] as $event => $edit) {
        $rules = $db->real_escape_string(json_encode([
            'core.edit' => [(string) $groupId => $edit], 'core.edit.state' => [(string) $groupId => 0],
            'core.delete' => [(string) $groupId => 0],
        ], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
        $db->query("UPDATE {$prefix}assets SET rules='{$rules}' WHERE name='com_appointments.eventtype.{$event}'");
    }
    $restricted = new AppointmentsHttp('http://localhost', 'administration-restricted');
    $oldUsername = getenv('JOOMLA_ADMIN_USERNAME');
    $oldPassword = getenv('JOOMLA_ADMIN_PASSWORD');
    try {
        putenv('JOOMLA_ADMIN_USERNAME=' . $tag);
        putenv('JOOMLA_ADMIN_PASSWORD=' . $password);
        $restricted->login();
    } finally {
        putenv($oldUsername === false ? 'JOOMLA_ADMIN_USERNAME' : 'JOOMLA_ADMIN_USERNAME=' . $oldUsername);
        putenv($oldPassword === false ? 'JOOMLA_ADMIN_PASSWORD' : 'JOOMLA_ADMIN_PASSWORD=' . $oldPassword);
    }
    $html = $listing($restricted);
    $check($restricted->status === 200 && str_contains($html, $tag . '-allowed') && !str_contains($html, $tag . '-denied'),
        'Per-event editor sees only permitted booking contacts');
    $calendarHub = $restricted->request('/administrator/index.php?option=com_appointments&view=calendars');
    $check($restricted->status === 200 && !str_contains($calendarHub, 'id="toolbar-devices"')
        && !str_contains($calendarHub, 'id="toolbar-teamlog"') && !str_contains($calendarHub, 'id="toolbar-invitations"'),
        'Calendar navigation does not advertise administrator-only branch pages to an event editor');
    foreach (['devices', 'teamlog', 'invitations'] as $view) {
        $restricted->request('/administrator/index.php?option=com_appointments&view=' . $view);
        $check($restricted->status === 403, 'Direct calendar branch access retains its permission boundary: ' . $view);
    }
    $csv = $export($restricted);
    $check(str_contains($csv, $tag . '-allowed') && !str_contains($csv, 'other@example.test'),
        'CSV cannot widen the editor scope');
    $dashboard = $restricted->request('/administrator/index.php?option=com_appointments&view=dashboard');
    $deniedTitle = $db->query("SELECT title FROM {$prefix}appointments_eventtypes WHERE id={$eventB}")->fetch_assoc()['title'];
    $check($restricted->status === 200 && !str_contains($dashboard, $deniedTitle), 'Dashboard statistics exclude denied event types');
    $allowedTitle = $db->query("SELECT title FROM {$prefix}appointments_eventtypes WHERE id={$eventA}")->fetch_assoc()['title'];
    foreach (['eventtypes' => [$allowedTitle, $deniedTitle], 'fields' => [$tag . '-allowed-field', $tag . '-denied-field'],
        'templates' => [$tag . '-allowed-template', $tag . '-denied-template']] as $view => [$allowed, $denied]) {
        $html = $restricted->request('/administrator/index.php?option=com_appointments&view=' . $view . '&limit=100&filter_eventtype_id=-1');
        $check($restricted->status === 200 && str_contains($html, $allowed) && !str_contains($html, $denied)
            && ($view !== 'templates' || !str_contains($html, $tag . '-sitewide-template')),
            'Administrator list hides denied event metadata and site-wide template content: ' . $view);
    }
    foreach (['field', 'template'] as $view) {
        $html = $restricted->request('/administrator/index.php?option=com_appointments&task=' . $view . '.add');
        $check($restricted->status === 200 && str_contains($html, $allowedTitle) && !str_contains($html, $deniedTitle),
            'Event selector exposes only authorised titles: ' . $view);
    }
    $denyAll = $db->real_escape_string(json_encode(['core.edit' => [(string) $groupId => 0]], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
    $db->query("UPDATE {$prefix}assets SET rules='{$denyAll}' WHERE name='com_appointments.eventtype.{$eventA}'");
    foreach (['eventtypes', 'fields', 'templates'] as $view) {
        $html = $restricted->request('/administrator/index.php?option=com_appointments&view=' . $view . '&limit=100&filter_eventtype_id=-1');
        $check($restricted->status === 200 && !str_contains($html, $allowedTitle) && !str_contains($html, $deniedTitle)
            && !str_contains($html, $tag . '-allowed-field') && !str_contains($html, $tag . '-allowed-template')
            && !str_contains($html, $tag . '-sitewide-template'), 'An empty event ACL scope returns no list rows: ' . $view);
    }
    $restricted->request('/administrator/index.php?option=com_appointments&view=booking&id=' . (int) $third['id']);
    $check($restricted->status === 403, 'Direct booking details require the event permission');
    $token = $restricted->token($listing($restricted));
    $restricted->request('/administrator/index.php?option=com_appointments', [
        'task' => 'bookings.reschedule', 'id' => $third['id'], 'start' => $day . 'T15:00', $token => 1,
    ]);
    $check($restricted->status === 403 && $fixture->row((int) $third['id'])['start_utc'] === $third['start_utc'],
        'Direct administrator reschedule cannot bypass event permission');
    $restricted->request('/administrator/index.php?option=com_appointments&task=feeds.export&id=' . (int) $third['id']);
    $check($restricted->status === 403, 'Single-booking ICS export also enforces event permission');
    $restricted->request('/administrator/index.php?option=com_appointments&view=system');
    $check($restricted->status === 403, 'System configuration diagnostics require options permission');

    $before = $fixture->row($id);
    $eventRow = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$eventA}")->fetch_assoc();
    $eventRow['host_user_id'] = $userId;
    $eventRow['conference_params'] = [];
    $html = $admin->submit('eventtype', $eventRow);
    $host = (int) $db->query("SELECT host_user_id FROM {$prefix}appointments_eventtypes WHERE id={$eventA}")->fetch_assoc()['host_user_id'];
    $check($admin->status === 200 && str_contains($html, 'host cannot be changed')
        && $host === $fixture->host && $fixture->row($id) === $before,
        'Host reassignment with retained bookings is explicitly refused without changing ownership or calendar data');
    $emptyEvent = $fixture->event();
    $events[] = $emptyEvent;
    $eventRow = $db->query("SELECT * FROM {$prefix}appointments_eventtypes WHERE id={$emptyEvent}")->fetch_assoc();
    $eventRow['host_user_id'] = $userId;
    $eventRow['conference_params'] = [];
    $admin->submit('eventtype', $eventRow);
    $host = (int) $db->query("SELECT host_user_id FROM {$prefix}appointments_eventtypes WHERE id={$emptyEvent}")->fetch_assoc()['host_user_id'];
    $check($admin->status === 200 && $host === $userId, 'An event type without bookings may still be assigned to another valid host');
} catch (Throwable $e) {
    echo 'FAIL ' . $e->getMessage() . "\n";
    $failed++;
} finally {
    $db->query("UPDATE {$prefix}assets SET rules='" . $db->real_escape_string($componentRules) . "' WHERE name='com_appointments'");
    foreach ($templateIds as $templateId) {
        $db->query("DELETE FROM {$prefix}appointments_templates WHERE id={$templateId}");
    }
    foreach ($events as $event) {
        $db->query("DELETE FROM {$prefix}appointments_fields WHERE eventtype_id={$event}");
    }
    foreach ($events as $event) {
        $row = $db->query("SELECT id FROM {$prefix}assets WHERE name='com_appointments.eventtype.{$event}'")->fetch_assoc();
        if ($row) {
            $asset = new \Joomla\CMS\Table\Asset($joomlaDb);
            if (!$asset->delete((int) $row['id'])) {
                echo "FAIL Permission asset cleanup failed.\n";
                $failed++;
            }
        }
    }
    if ($userId) {
        $db->query("DELETE FROM {$prefix}session WHERE userid={$userId}");
        $db->query("DELETE FROM {$prefix}user_usergroup_map WHERE user_id={$userId}");
        $db->query("DELETE FROM {$prefix}users WHERE id={$userId}");
    }
    if ($groupId) {
        $row = $db->query("SELECT rgt FROM {$prefix}usergroups WHERE id={$groupId}")->fetch_assoc();
        $right = (int) $row['rgt'];
        $db->query("DELETE FROM {$prefix}usergroups WHERE id={$groupId}");
        $db->query("UPDATE {$prefix}usergroups SET lft=lft-2 WHERE lft>{$right}");
        $db->query("UPDATE {$prefix}usergroups SET rgt=rgt-2 WHERE rgt>{$right}");
    }
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
