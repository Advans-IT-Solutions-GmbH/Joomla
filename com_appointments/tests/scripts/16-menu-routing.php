<?php

/*
 * Menu items and SEF routing.
 *
 * 1. The "Book an appointment" menu item type is set up through the real menu manager form:
 *    the event type picker renders the published event types, refuses an empty choice with a
 *    translated label and stores the request parameter `id`, and an existing item keeps its
 *    target after the event type was unpublished.
 * 2. On a multilingual site (language filter, two content languages, SEF URLs) the booking
 *    pages resolve through menu items in "All" and in a specific language, the component builds
 *    routed links to them, routed and non-SEF addresses without a menu item keep working, and
 *    the signed links of the confirmation mail still open and act on the booking.
 *
 * Every change to the site (content language, language filter, menu items, fixture language)
 * is undone in the finally block, so the suite can run in the same container as the others.
 */

require __DIR__ . '/appointments-fixtures.php';
require __DIR__ . '/appointments-mail.php';

$fixture = new AppointmentsFixture('menu');
$db = $fixture->db;
$prefix = $fixture->prefix;
$failed = 0;
$check = static function (bool $ok, string $message) use (&$failed): void {
    echo ($ok ? 'PASS ' : 'FAIL ') . $message . "\n";
    $failed += $ok ? 0 : 1;
};
$dump = static function (string $label, AppointmentsHttp $client, string $html): void {
    echo '  ' . $label . ': HTTP ' . $client->status . ', '
        . substr(preg_replace('/\s+/', ' ', strip_tags($html)), 0, 1200) . "\n";
};
$escape = static fn (string $value): string => $db->real_escape_string($value);
$scalar = static function (string $sql) use ($db) {
    $row = $db->query($sql)->fetch_row();

    return $row[0] ?? null;
};

/** Every successful field of a form, as name => value, plus its action. */
$formData = static function (string $html, string $xpathForm): array {
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $form = $xpath->query($xpathForm)->item(0);

    if (!$form instanceof DOMElement) {
        throw new RuntimeException('The form ' . $xpathForm . ' was not rendered.');
    }

    $fields = [];
    $add = static function (string $name, string $value) use (&$fields): void {
        if (str_ends_with($name, '[]')) {
            $base = substr($name, 0, -2);
            $index = 0;
            while (array_key_exists($base . '[' . $index . ']', $fields)) {
                $index++;
            }
            $name = $base . '[' . $index . ']';
        }
        $fields[$name] = $value;
    };

    foreach ($xpath->query('.//input[@name]', $form) as $input) {
        $type = strtolower($input->getAttribute('type'));
        if (in_array($type, ['submit', 'button', 'image', 'file', 'reset'], true)) {
            continue;
        }
        if (in_array($type, ['checkbox', 'radio'], true) && !$input->hasAttribute('checked')) {
            continue;
        }
        $add($input->getAttribute('name'), $input->getAttribute('value'));
    }
    foreach ($xpath->query('.//select[@name]', $form) as $select) {
        $selected = $xpath->query('.//option[@selected]', $select);
        $options = $selected->length ? $selected : $xpath->query('.//option', $select);
        if ($options->length) {
            $add($select->getAttribute('name'), $options->item(0)->getAttribute('value'));
        }
    }
    foreach ($xpath->query('.//textarea[@name]', $form) as $textarea) {
        $add($textarea->getAttribute('name'), $textarea->textContent);
    }

    return [html_entity_decode($form->getAttribute('action'), ENT_QUOTES | ENT_HTML5), $fields];
};

/** The options of the event type picker, as value => label; null when it is not rendered. */
$pickerOptions = static function (string $html): ?array {
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $select = $xpath->query('//select[@name="jform[request][id]"]')->item(0);

    if (!$select instanceof DOMElement) {
        return null;
    }

    $options = ['required' => $select->hasAttribute('required') || str_contains($select->getAttribute('class'), 'required')];
    foreach ($xpath->query('.//option', $select) as $option) {
        $options[$option->getAttribute('value')] = trim($option->textContent)
            . ($option->hasAttribute('selected') ? ' {selected}' : '');
    }

    return $options;
};

/** Absolute path (no host) of every link with the given class. */
$links = static function (string $html, string $class): array {
    $document = new DOMDocument();
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);
    $result = [];
    foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]') as $a) {
        $href = html_entity_decode($a->getAttribute('href'), ENT_QUOTES | ENT_HTML5);
        $result[trim($a->textContent)] = preg_replace('#^https?://[^/]+#', '', $href);
    }

    return $result;
};

$admin = new AppointmentsHttp('http://localhost', 'menu-admin');
$state = [
    'filter' => null, 'contentLanguage' => false, 'siteLanguage' => false, 'languageFolder' => false,
    'menuItems' => [], 'configuration' => null, 'pageCache' => null,
];
$tag = 'menu' . bin2hex(random_bytes(4));
$day = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify('+3 days')->format('Y-m-d');

try {
    // ---------------------------------------------------------------- fixtures
    $admin->login();
    $events = [];
    foreach (['star', 'en', 'de', 'list', 'hidden'] as $name) {
        $id = $fixture->event();
        $alias = $tag . '-event-' . $name;
        $db->query("UPDATE {$prefix}appointments_eventtypes SET title='" . $escape($tag . ' Event ' . $name) . "', alias='{$alias}', language='*' WHERE id={$id}");
        $events[$name] = ['id' => $id, 'alias' => $alias, 'title' => $tag . ' Event ' . $name];
    }
    $db->query("UPDATE {$prefix}appointments_eventtypes SET published=0 WHERE id={$events['hidden']['id']}");

    // ------------------------------------------- multilingual site with SEF URLs
    require_once '/var/www/html/configuration.php';
    $config = new JConfig();
    if (empty($config->sef) || !empty($config->sef_rewrite)) {
        $path = '/var/www/html/configuration.php';
        $state['configuration'] = file_get_contents($path);
        $patched = preg_replace(['/public\s+\$sef\s*=\s*[^;]*;/', '/public\s+\$sef_rewrite\s*=\s*[^;]*;/'],
            ['public $sef = true;', 'public $sef_rewrite = false;'], $state['configuration']);
        file_put_contents($path, $patched);
        // Give OPcache time to revalidate the configuration of the web server.
        sleep(5);
    }

    $state['filter'] = (int) $scalar("SELECT enabled FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='languagefilter'");
    $db->query("UPDATE {$prefix}extensions SET enabled=1 WHERE type='plugin' AND folder='system' AND element='languagefilter'");
    $check((int) $scalar("SELECT enabled FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='languagefilter'") === 1,
        'Fixture: the language filter plugin is enabled');
    $check((int) $scalar("SELECT published FROM {$prefix}languages WHERE lang_code='en-GB'") === 1
        && $scalar("SELECT sef FROM {$prefix}languages WHERE lang_code='en-GB'") === 'en', 'Fixture: the en-GB content language is published with the prefix "en"');

    // A second site language: the metadata file and the extension row Joomla needs to list
    // it as installed. Its strings fall back to en-GB, which is all the routing needs.
    if (!is_dir('/var/www/html/language/de-DE')) {
        mkdir('/var/www/html/language/de-DE', 0755);
        $state['languageFolder'] = true;
        $meta = file_get_contents('/var/www/html/language/en-GB/langmetadata.xml');
        $meta = str_replace(['<tag>en-GB</tag>', 'English (en-GB)'], ['<tag>de-DE</tag>', 'German (de-DE)'], $meta);
        file_put_contents('/var/www/html/language/de-DE/langmetadata.xml', $meta);
    }
    if (!$scalar("SELECT extension_id FROM {$prefix}extensions WHERE type='language' AND element='de-DE' AND client_id=0")) {
        $db->query("INSERT INTO {$prefix}extensions (package_id,name,type,element,folder,client_id,enabled,access,protected,locked,manifest_cache,params,custom_data,ordering,state)"
            . " VALUES (0,'German (de-DE)','language','de-DE','',0,1,1,0,0,'{}','{}','',0,0)");
        $state['siteLanguage'] = (int) $db->insert_id;
    }
    if (!$scalar("SELECT lang_id FROM {$prefix}languages WHERE lang_code='de-DE'")) {
        $db->query("INSERT INTO {$prefix}languages (asset_id,lang_code,title,title_native,sef,image,description,metakey,metadesc,sitename,published,access,ordering)"
            . " VALUES (0,'de-DE','German (de-DE)','Deutsch','de','de_de','','','','',1,1,2)");
        $state['contentLanguage'] = (int) $db->insert_id;
    }

    // ------------------------------------------- 1. menu item type through the menu manager
    $menutype = (string) $scalar("SELECT menutype FROM {$prefix}menu WHERE client_id=0 AND home=1 ORDER BY id LIMIT 1");
    $componentId = (int) $scalar("SELECT extension_id FROM {$prefix}extensions WHERE type='component' AND element='com_appointments'");

    $openTypedForm = static function () use ($admin, $menutype, $formData): string {
        $html = $admin->request('/administrator/index.php?option=com_menus&task=item.add&menutype=' . urlencode($menutype));
        [$action, $fields] = $formData($html, '//form[@id="item-form"]');
        $fields['task'] = 'item.setType';
        $fields['fieldtype'] = 'type';
        $fields['jform[menutype]'] = $menutype;
        $fields['jform[type]'] = base64_encode(json_encode([
            'id' => 0, 'title' => 'COM_APPOINTMENTS_MENU_EVENTTYPE',
            'request' => ['option' => 'com_appointments', 'view' => 'eventtype'],
        ]));

        return $admin->request($action, $fields);
    };

    $html = $openTypedForm();
    $options = $pickerOptions($html);
    $check($options !== null, 'Menu manager renders the event type picker of the "Book an appointment" item type');
    if ($options === null) {
        $dump('menu item form', $admin, $html);
        $start = strpos($html, 'jform_request');
        echo '  request markup: ' . ($start === false ? '(no jform_request element)' : substr($html, max(0, $start - 300), 1500)) . "\n";
    }
    $options ??= [];
    $check(!empty($options['required']), 'The event type picker is a required field');
    $check(isset($options[(string) $events['star']['id']]) && str_contains($options[(string) $events['star']['id']], $events['star']['title']),
        'The picker lists a published event type by its title');
    $check(!isset($options[(string) $events['hidden']['id']]), 'The picker does not offer an unpublished event type');
    $check(!str_contains($html, 'COM_APPOINTMENTS_MENU_FIELD'), 'The picker label is translated on the menu item form');

    // A missing, empty or unpublished choice is refused, naming the field by its translated
    // label. With filter="integer" alone an empty choice became 0 and passed "required".
    // "forged" posts the unpublished id in both the link and the request, as a crafted form
    // could: Joomla binds a new item's request from the posted link, the picker must not.
    foreach ([
        'missing' => null, 'empty' => '', 'unpublished' => (string) $events['hidden']['id'],
        'forged' => (string) $events['hidden']['id'],
    ] as $case => $value) {
        $html = $openTypedForm();
        [$action, $fields] = $formData($html, '//form[@id="item-form"]');
        $fields['task'] = 'item.save';
        $fields['jform[title]'] = $tag . ' ' . $case;
        $fields['jform[alias]'] = $tag . '-refused-' . $case;
        unset($fields['jform[request][id]']);
        if ($value !== null) {
            $fields['jform[request][id]'] = $value;
        }
        if ($case === 'forged') {
            $fields['jform[link]'] = 'index.php?option=com_appointments&view=eventtype&id=' . $value;
        }
        $refused = $admin->request($action, $fields);
        $check(!$scalar("SELECT id FROM {$prefix}menu WHERE alias='" . $escape($tag . '-refused-' . $case) . "'"),
            "Saving is refused with the event type {$case}");
        $check(!str_contains($refused, 'COM_APPOINTMENTS_MENU') && preg_match('/(Field required|Invalid field):\s*Event type/', $refused) === 1,
            "The {$case} refusal names the field by its translated label");
        $admin->request('/administrator/index.php?option=com_menus&view=items&menutype=' . urlencode($menutype));
    }

    /** Creates a menu item through the menu manager and returns its row. */
    $createItem = static function (string $view, ?int $event, string $alias, string $language) use (
        $admin, $menutype, $formData, $tag, $db, $prefix, $escape, &$state
    ): ?array {
        $html = $admin->request('/administrator/index.php?option=com_menus&task=item.add&menutype=' . urlencode($menutype));
        [$action, $fields] = $formData($html, '//form[@id="item-form"]');
        $fields['task'] = 'item.setType';
        $fields['fieldtype'] = 'type';
        $fields['jform[menutype]'] = $menutype;
        $fields['jform[type]'] = base64_encode(json_encode([
            'id' => 0, 'title' => $view === 'eventtype' ? 'COM_APPOINTMENTS_MENU_EVENTTYPE' : 'COM_APPOINTMENTS_MENU_EVENTTYPES',
            'request' => ['option' => 'com_appointments', 'view' => $view],
        ]));
        $html = $admin->request($action, $fields);
        [$action, $fields] = $formData($html, '//form[@id="item-form"]');
        $fields['task'] = 'item.save';
        $fields['jform[title]'] = $tag . ' ' . $alias;
        $fields['jform[alias]'] = $alias;
        $fields['jform[language]'] = $language;
        $fields['jform[published]'] = '1';
        $fields['jform[menutype]'] = $menutype;
        if ($event !== null) {
            $fields['jform[request][id]'] = (string) $event;
        }
        $admin->request($action, $fields);
        $row = $db->query("SELECT id, link, component_id, language, published, type FROM {$prefix}menu WHERE alias='" . $escape($alias) . "' AND client_id=0")->fetch_assoc();
        if ($row) {
            $state['menuItems'][] = (int) $row['id'];
        }

        return $row;
    };

    $items = [
        'star' => $createItem('eventtype', $events['star']['id'], $tag . '-all', '*'),
        'en' => $createItem('eventtype', $events['en']['id'], $tag . '-english', 'en-GB'),
        'de' => $createItem('eventtype', $events['de']['id'], $tag . '-deutsch', 'de-DE'),
        'list' => $createItem('eventtypes', null, $tag . '-list', '*'),
    ];
    foreach (['star' => '*', 'en' => 'en-GB', 'de' => 'de-DE'] as $name => $language) {
        $row = $items[$name];
        $check($row !== null && $row['link'] === 'index.php?option=com_appointments&view=eventtype&id=' . $events[$name]['id']
            && (int) $row['component_id'] === $componentId && $row['language'] === $language && (int) $row['published'] === 1,
            "Menu item in language {$language} is saved with the request parameter id and the component");
        if ($row === null) {
            $dump('menu items', $admin, $admin->request('/administrator/index.php?option=com_menus&view=items&menutype=' . urlencode($menutype)));
        }
    }
    $check($items['list'] !== null && $items['list']['link'] === 'index.php?option=com_appointments&view=eventtypes',
        'Event type list menu item is saved');

    // The options of the back button on the menu item form, translated.
    if ($items['star'] !== null) {
        $html = $admin->request('/administrator/index.php?option=com_menus&task=item.edit&id=' . (int) $items['star']['id']);
        $check(str_contains($html, 'name="jform[params][show_back_link]"') && str_contains($html, 'name="jform[params][back_link_itemid]"')
            // Also covers the tab label COM_APPOINTMENTS_MENU_FIELDSET_NAVIGATION.
            && !str_contains($html, 'COM_APPOINTMENTS_MENU_FIELD'),
            'The "Book an appointment" menu item form offers the translated back button options');
        [$action, $fields] = $formData($html, '//form[@id="item-form"]');
        $fields['task'] = 'item.cancel';
        $admin->request($action, $fields);
    }

    // An existing item keeps its target after the event type was unpublished.
    if ($items['star'] !== null) {
        $db->query("UPDATE {$prefix}appointments_eventtypes SET published=0 WHERE id={$events['star']['id']}");
        $html = $admin->request('/administrator/index.php?option=com_menus&task=item.edit&id=' . (int) $items['star']['id']);
        $options = $pickerOptions($html) ?? [];
        $label = $options[(string) $events['star']['id']] ?? '';
        $check(str_contains($label, $events['star']['title']) && str_contains($label, '{selected}') && str_contains($label, 'Unpublished'),
            'An existing menu item keeps its unpublished event type selected and marked');
        // Saving it unchanged keeps working, so its other settings stay editable.
        [$action, $fields] = $formData($html, '//form[@id="item-form"]');
        $fields['task'] = 'item.save';
        $fields['jform[note]'] = $tag . ' resaved';
        $admin->request($action, $fields);
        $row = $db->query("SELECT link, note, checked_out FROM {$prefix}menu WHERE id=" . (int) $items['star']['id'])->fetch_assoc();
        $check($row && $row['note'] === $tag . ' resaved' && (int) $row['checked_out'] === 0
            && $row['link'] === 'index.php?option=com_appointments&view=eventtype&id=' . $events['star']['id'],
            'An existing menu item with an unpublished event type can be saved again unchanged');
        $db->query("UPDATE {$prefix}appointments_eventtypes SET published=1 WHERE id={$events['star']['id']}");
    }

    // An existing item cannot be switched to an unpublished event type.
    if ($items['en'] !== null) {
        $html = $admin->request('/administrator/index.php?option=com_menus&task=item.edit&id=' . (int) $items['en']['id']);
        [$action, $fields] = $formData($html, '//form[@id="item-form"]');
        $fields['task'] = 'item.save';
        $fields['jform[request][id]'] = (string) $events['hidden']['id'];
        $admin->request($action, $fields);
        $check($scalar("SELECT link FROM {$prefix}menu WHERE id=" . (int) $items['en']['id'])
            === 'index.php?option=com_appointments&view=eventtype&id=' . $events['en']['id'],
            'An existing menu item cannot be switched to an unpublished event type');
        $html = $admin->request('/administrator/index.php?option=com_menus&task=item.edit&id=' . (int) $items['en']['id']);
        [$action, $fields] = $formData($html, '//form[@id="item-form"]');
        $fields['task'] = 'item.cancel';
        $admin->request($action, $fields);
    }

    // ------------------------------------------------------ 2. routing on the site
    $site = new AppointmentsHttp('http://localhost', 'menu-site');
    $page = static function (string $path) use ($site): string {
        return $site->request($path);
    };
    $shows = static fn (string $html, array $event): bool => str_contains($html, htmlspecialchars($event['title'], ENT_QUOTES));

    foreach ([
        ['/index.php/en/' . $tag . '-all', 'star', 'menu item in "All", English prefix'],
        ['/index.php/de/' . $tag . '-all', 'star', 'menu item in "All", German prefix'],
        ['/index.php/en/' . $tag . '-english', 'en', 'menu item in en-GB'],
        ['/index.php/de/' . $tag . '-deutsch', 'de', 'menu item in de-DE'],
    ] as [$path, $name, $label]) {
        $html = $page($path);
        $check($site->status === 200 && $shows($html, $events[$name]), 'SEF booking page resolves: ' . $label);
        if ($site->status !== 200 || !$shows($html, $events[$name])) {
            $dump($path, $site, $html);
        }
    }

    // Links the component builds on its own page lead back to the menu item.
    $html = $page('/index.php/en/' . $tag . '-all');
    $nav = $links($html, 'com-appointments-calendar-next');
    $next = (string) (reset($nav) ?: '');
    $check(str_starts_with($next, '/index.php/en/' . $tag . '-all?') && !str_contains($next, 'option=') && !str_contains($next, 'view='),
        'Month navigation is routed through the menu item in "All": ' . $next);
    if ($next !== '') {
        $html = $page($next);
        $check($site->status === 200 && $shows($html, $events['star']), 'Routed month navigation resolves');
    }

    $html = $page('/index.php/de/' . $tag . '-deutsch');
    $nav = $links($html, 'com-appointments-calendar-next');
    $next = (string) (reset($nav) ?: '');
    $check(str_starts_with($next, '/index.php/de/' . $tag . '-deutsch?'), 'Month navigation is routed through the de-DE menu item: ' . $next);

    // The list resolves its entries to their own menu items, otherwise below the list item.
    $html = $page('/index.php/en/' . $tag . '-list');
    $check($site->status === 200 && $shows($html, $events['list']), 'SEF event type list resolves through its menu item in "All"');
    $entries = $links($html, 'com-appointments-list-title');
    $check(($entries[$events['star']['title']] ?? '') === '/index.php/en/' . $tag . '-all',
        'The list links an event type with its own menu item to that item: ' . ($entries[$events['star']['title']] ?? '(missing)'));
    $below = $entries[$events['list']['title']] ?? '';
    $check($below === '/index.php/en/' . $tag . '-list/' . $events['list']['alias'],
        'The list links an event type without a menu item below the list: ' . ($below ?: '(missing)'));
    if ($below !== '') {
        $html = $page($below);
        $check($site->status === 200 && $shows($html, $events['list']), 'Event type below the list item resolves');
    }
    $page('/index.php/en/' . $tag . '-list/no-such-event-type');
    $check($site->status === 404, 'An unknown event type alias below the list item is a 404');

    // Without any menu item: the routed component address, the legacy query form and non-SEF.
    foreach ([
        '/index.php/en/component/appointments/eventtype/' . $events['list']['alias'] => 'routed component address',
        '/index.php/en/component/appointments/?view=eventtype&id=' . $events['list']['id'] => 'component address with query',
        '/index.php?option=com_appointments&view=eventtype&id=' . $events['list']['id'] => 'non-SEF address',
    ] as $path => $label) {
        $html = $page($path);
        $check($site->status === 200 && $shows($html, $events['list']), 'Booking page without a menu item resolves: ' . $label);
        if ($site->status !== 200 || !$shows($html, $events['list'])) {
            $dump($path, $site, $html);
        }
    }

    // A booking through the "All" menu item, then the signed links of its confirmation mail.
    $client = new AppointmentsHttp('http://localhost', 'menu-booking');
    // Without JavaScript: the day, then the GET form of the time with its "Next" button, then the details.
    $html = $client->request('/index.php/en/' . $tag . '-all?timezone=UTC&month=' . substr($day, 0, 7) . '&day=' . $day);
    [$timeAction, $timeFields] = $formData($html, '//form[@id="appointment-time-form"]');
    $timeFields['start'] = $day . ' 10:00:00';
    $timeAction = (string) strtok(preg_replace('#^https?://[^/]+#', '', $timeAction), '#');
    $check($timeAction === '/index.php/en/' . $tag . '-all' && ($timeFields['timezone'] ?? '') === 'UTC',
        'The time form of the SEF page is sent to the menu item in "All" with the displayed zone: ' . $timeAction);
    $html = $client->request($timeAction . '?' . http_build_query($timeFields));
    $check($client->status === 200 && str_contains($html, 'name="booking[start]" value="' . $day . ' 10:00:00"'),
        'The "Next" button of a time opens the details on the SEF page of the menu item in "All"');
    [$action, $fields] = $formData($html, '//form[contains(@class, "com-appointments-booking")]');
    $fields = array_replace($fields, [
        $client->token($html) => 1, 'consent' => 1, 'website' => '',
        'booking[first_name]' => 'Fixture', 'booking[last_name]' => 'Invitee', 'booking[email]' => 'invitee@example.test',
        'booking[timezone]' => 'UTC', 'booking[start]' => $day . ' 10:00:00',
    ]);
    $before = AppointmentsMail::snapshot();
    sleep(2);
    $html = $client->request(preg_replace('#^https?://[^/]+#', '', $action), $fields);
    $booking = $db->query("SELECT * FROM {$prefix}appointments_bookings WHERE eventtype_id={$events['star']['id']} AND state IN (0,1)")->fetch_assoc();
    $check($client->status === 200 && preg_match('/Appointment (?:received|confirmed)/', $html) === 1 && $booking !== null,
        'A booking submitted from the SEF page reaches the confirmation page');
    if (!$booking) {
        $dump('booking submission', $client, $html);
    }

    $mailLinks = [];
    foreach (AppointmentsMail::messagesSince($before) as $mime) {
        $decoded = html_entity_decode(AppointmentsMail::decoded($mime), ENT_QUOTES | ENT_HTML5);
        if (preg_match_all('#https?://[^\s"<>]+view=manage[^\s"<>]+#', $decoded, $matches)) {
            foreach ($matches[0] as $url) {
                parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                $action = (string) ($query['action'] ?? '');
                // Keep the complete link: a folded calendar line or an encoded part can cut one.
                if (strlen($url) > strlen($mailLinks[$action] ?? '')) {
                    $mailLinks[$action] = $url;
                }
            }
        }
    }
    $check(isset($mailLinks['reschedule'], $mailLinks['cancel'], $mailLinks['erase']),
        'The confirmation mail carries the reschedule, cancel and erase links');
    $check(isset($mailLinks['cancel']) && str_starts_with($mailLinks['cancel'], 'http://localhost/index.php?option=com_appointments&view=manage&id='),
        'Mail links stay non-SEF and independent of the router');

    // The signed pages and their receipts run in the frame of the booking page of the event type
    // (its Itemid), not in the frame of the home page. The way back of these pages shows the frame:
    // on a menu item of one event type it leads to that booking page.
    $starItem = (int) ($items['star']['id'] ?? 0);
    $bookingPage = '#^/index\.php/(?:en|de)/' . preg_quote($tag . '-all', '#') . '$#';
    $first = static fn (array $found): string => (string) (reset($found) ?: '');
    $check($starItem > 0 && isset($mailLinks['cancel']) && str_contains($mailLinks['cancel'], '&Itemid=' . $starItem),
        'The mail links carry the Itemid of the booking page of their event type: ' . ($mailLinks['cancel'] ?? '(missing)'));
    $check(str_contains($first($links($html, 'com-appointments-reschedule')), '&Itemid=' . $starItem)
        && preg_match($bookingPage, $first($links($html, 'com-appointments-return'))) === 1,
        'The confirmation page links the signed pages with the Itemid and leads back to the booking page: '
        . $first($links($html, 'com-appointments-return')));

    /** The values of the options of a select, in order. */
    $optionValues = static function (string $html, string $id): array {
        $document = new DOMDocument();
        @$document->loadHTML($html);

        return array_map(static fn (DOMElement $option): string => $option->getAttribute('value'),
            iterator_to_array((new DOMXPath($document))->query('//select[@id="' . $id . '"]/option')));
    };

    // Reschedule with the link of the confirmation mail: the receipt stands at the booking page and
    // gives this browser the new signed links, as the confirmation of a booking does.
    if (isset($mailLinks['reschedule'], $booking['id'])) {
        // With Joomla's page cache on, the receipt with its new signed links must not be cached for
        // another browser: the signed pages switch the page cache off for their responses.
        $state['pageCache'] = (int) $scalar("SELECT enabled FROM {$prefix}extensions WHERE type='plugin' AND folder='system' AND element='cache'");
        $db->query("UPDATE {$prefix}extensions SET enabled=1 WHERE type='plugin' AND folder='system' AND element='cache'");
        $mover = new AppointmentsHttp('http://localhost', 'menu-reschedule');
        $oldReschedule = preg_replace('#^https?://[^/]+#', '', $mailLinks['reschedule']);
        $html = $mover->request($oldReschedule);
        $check($mover->status === 200 && preg_match($bookingPage, $first($links($html, 'com-appointments-return'))) === 1,
            'The reschedule page of the mail link runs in the frame of the booking page: ' . $first($links($html, 'com-appointments-return')));
        [$action, $fields] = $formData($html, '//form[contains(@class, "com-appointments-manage-form")]');
        $current = (string) $fixture->row((int) $booking['id'])['start_utc'];
        $other = array_values(array_filter($optionValues($html, 'new-start'), static fn (string $value): bool => $value !== $current));
        $fields['start'] = $other[0] ?? '';
        $fields[$mover->token($html)] = 1;
        $fields['confirm'] = 1;
        $before = AppointmentsMail::snapshot();
        $html = $mover->request(preg_replace('#^https?://[^/]+#', '', $action), $fields);
        $landing = preg_replace('#^https?://[^/]+#', '', (string) end($mover->locations));
        $check($other !== [] && (string) $fixture->row((int) $booking['id'])['start_utc'] === $other[0],
            'The mail link reschedules the booking');
        $check($mover->landedAt($html, 'appointment-result')
            && preg_match('#^/index\.php/(?:en|de)/' . preg_quote($tag . '-all', '#') . '\?#', $landing) === 1
            && !str_contains($landing, 'component/appointments'),
            'The receipt of the reschedule stands at the booking page, not in the frame of the home page: ' . $landing);
        $newReschedule = $first($links($html, 'com-appointments-reschedule'));
        $newCancel = $first($links($html, 'com-appointments-cancel'));
        $check($newReschedule !== '' && $newCancel !== '' && $newReschedule !== $oldReschedule
            && str_contains($newCancel, '&Itemid=' . $starItem) && str_contains($newCancel, 'action=cancel'),
            'The receipt of the reschedule shows the new signed reschedule and cancel links');
        $check(preg_match($bookingPage, $first($links($html, 'com-appointments-return'))) === 1,
            'The receipt of the reschedule leads back to the booking page');

        $rescheduleMail = [];
        foreach (AppointmentsMail::messagesSince($before) as $mime) {
            $decoded = html_entity_decode(AppointmentsMail::decoded($mime), ENT_QUOTES | ENT_HTML5);
            if (preg_match_all('#https?://[^\s"<>]+view=manage[^\s"<>]+#', $decoded, $matches)) {
                foreach ($matches[0] as $url) {
                    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
                    $linkAction = (string) ($query['action'] ?? '');
                    if (strlen($url) > strlen($rescheduleMail[$linkAction] ?? '')) {
                        $rescheduleMail[$linkAction] = $url;
                    }
                }
            }
        }
        $check(isset($rescheduleMail['cancel']) && preg_replace('#^https?://[^/]+#', '', $rescheduleMail['cancel']) === $newCancel,
            'The receipt shows the same new cancel link as the reschedule mail');

        $html = $mover->request($newCancel);
        $check($mover->status === 200 && str_contains($html, 'booking.manage'), 'The new cancel link of the receipt opens its page');
        $outsider = new AppointmentsHttp('http://localhost', 'menu-reschedule-outsider');
        $html = $outsider->request($landing);
        $check($outsider->status === 404 && !str_contains($html, 'com-appointments-cancel'),
            'Another browser gets neither the receipt nor the new links from its address, also with the page cache on');
        $outsider->request($oldReschedule);
        $check($outsider->status === 404, 'The reschedule link of the confirmation mail is rejected after the reschedule');
        $db->query("UPDATE {$prefix}extensions SET enabled=" . (int) $state['pageCache'] . " WHERE type='plugin' AND folder='system' AND element='cache'");
        $state['pageCache'] = null;

        // The cancellation below uses the links of the newest mail.
        if (isset($rescheduleMail['cancel'], $rescheduleMail['erase'])) {
            $mailLinks = $rescheduleMail;
        }
    }

    if (isset($mailLinks['cancel'], $booking['id'])) {
        $guest = new AppointmentsHttp('http://localhost', 'menu-mail-link');
        $path = preg_replace('#^https?://[^/]+#', '', $mailLinks['cancel']);
        $html = $guest->request($path);
        $check($guest->status === 200 && str_contains($html, 'booking.manage'),
            'The signed cancel link of the mail opens the cancellation page on the multilingual SEF site');
        if ($guest->status !== 200) {
            $dump('cancel link', $guest, $html);
        }
        $erase = $guest->request(preg_replace('#^https?://[^/]+#', '', $mailLinks['erase'] ?? ''));
        $check($guest->status === 200, 'The signed erase link of the mail opens its page');
        $html = $guest->request($path);
        [$action, $fields] = $formData($html, '//form[contains(@class, "com-appointments-manage-form")]');
        $fields[$guest->token($html)] = 1;
        $fields['confirm'] = 1;
        $html = $guest->request(preg_replace('#^https?://[^/]+#', '', $action), $fields);
        $check((int) $fixture->row((int) $booking['id'])['state'] === 2, 'The signed cancel link cancels the booking after confirmation');
        $landing = preg_replace('#^https?://[^/]+#', '', (string) end($guest->locations));
        $check($guest->landedAt($html, 'appointment-result') && str_contains($landing, '/' . $tag . '-all?')
            && !str_contains($landing, 'component/appointments') && $links($html, 'com-appointments-cancel') === []
            && preg_match($bookingPage, $first($links($html, 'com-appointments-return'))) === 1,
            'The cancellation receipt stands at the booking page, without links to the cancelled appointment: ' . $landing);
        // Another browser: the acting session is shown its completion notice instead.
        $outsider = new AppointmentsHttp('http://localhost', 'menu-used-link');
        $outsider->request($path);
        $check($outsider->status === 404, 'The used cancel link is rejected afterwards');
    }

    // The back button of a booking page: hidden on a menu item of one event type, so the list of
    // all event types stays out of its reach; its options show it and choose where it leads.
    $setParams = static function (int $id, array $values) use ($db, $prefix): void {
        $row = $db->query("SELECT params FROM {$prefix}menu WHERE id=" . $id)->fetch_row();
        $params = array_replace(json_decode((string) ($row[0] ?? ''), true) ?: [], $values);
        $db->query("UPDATE {$prefix}menu SET params='" . $db->real_escape_string(json_encode($params)) . "' WHERE id=" . $id);
    };
    $html = $page('/index.php/en/' . $tag . '-all');
    $check($site->status === 200 && $shows($html, $events['star']) && $links($html, 'com-appointments-return') === [],
        'A menu item of one event type hides the back button by default');
    $html = $page('/index.php/en/' . $tag . '-list/' . $events['list']['alias']);
    $check($first($links($html, 'com-appointments-return')) === '/index.php/en/' . $tag . '-list',
        'Below the list the back button still leads to the list: ' . $first($links($html, 'com-appointments-return')));
    if ($starItem > 0 && isset($items['en']['id'])) {
        $setParams($starItem, ['show_back_link' => '1']);
        $html = $page('/index.php/en/' . $tag . '-all');
        $check($first($links($html, 'com-appointments-return')) === '/index.php/en/' . $tag . '-list',
            'Shown without a target, the back button leads to the list of all event types: ' . $first($links($html, 'com-appointments-return')));
        $setParams($starItem, ['show_back_link' => '1', 'back_link_itemid' => (string) $items['en']['id']]);
        $html = $page('/index.php/en/' . $tag . '-all');
        $check($first($links($html, 'com-appointments-return')) === '/index.php/en/' . $tag . '-english',
            'Shown with a target, the back button leads to the chosen menu item: ' . $first($links($html, 'com-appointments-return')));
        $setParams($starItem, ['show_back_link' => '0']);
        $html = $page('/index.php/en/' . $tag . '-all');
        $check($links($html, 'com-appointments-return') === [], 'Hidden again, a chosen target shows no button');
    }
} catch (Throwable $e) {
    echo 'FAIL ' . get_class($e) . ': ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ")\n";
    $failed++;
} finally {
    foreach ($state['menuItems'] as $id) {
        $db->query("DELETE FROM {$prefix}menu WHERE id=" . (int) $id);
    }
    $db->query("DELETE FROM {$prefix}menu WHERE client_id=0 AND alias LIKE '" . $escape($tag) . "-%'");
    if ($state['pageCache'] !== null) {
        $db->query("UPDATE {$prefix}extensions SET enabled=" . (int) $state['pageCache'] . " WHERE type='plugin' AND folder='system' AND element='cache'");
    }
    if ($state['filter'] !== null) {
        $db->query("UPDATE {$prefix}extensions SET enabled=" . (int) $state['filter'] . " WHERE type='plugin' AND folder='system' AND element='languagefilter'");
    }
    if ($state['contentLanguage']) {
        $db->query("DELETE FROM {$prefix}languages WHERE lang_id=" . (int) $state['contentLanguage']);
    }
    if ($state['siteLanguage']) {
        $db->query("DELETE FROM {$prefix}extensions WHERE extension_id=" . (int) $state['siteLanguage']);
    }
    if ($state['languageFolder']) {
        @unlink('/var/www/html/language/de-DE/langmetadata.xml');
        @rmdir('/var/www/html/language/de-DE');
    }
    if ($state['configuration'] !== null) {
        file_put_contents('/var/www/html/configuration.php', $state['configuration']);
    }
    $fixture->cleanup();
}
exit($failed ? 1 : 0);
