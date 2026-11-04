<?php
/**
 * Site page tests for the Appointments component.
 *
 * The structure suite only proves that the site files exist, and the shared
 * backend-views suite covers administrator views only. Nothing would open the
 * public page, so a broken route, a model query against a missing column, an
 * unloaded language file or a mistyped asset name would ship unnoticed. This
 * suite therefore requests the component's public pages over HTTP and checks
 * the response the way the shared backend suite does for the administrator.
 *
 * Temporary event types and their schedule are removed after the requests.
 */

class SitePageTest
{
    private $passed = 0;
    private $failed = 0;
    private $baseUrl;
    private $db;
    private $dbPrefix;
    private int $scheduleId = 0;

    /** @var array<string, string> */
    private $strings = [];

    /**
     * The seeded event types. The visible one carries characters that have to
     * be escaped, so the layout is tested and not only the query.
     */
    private const VISIBLE_TITLE = 'Visible <b>& "quoted"</b> talk';
    private const SINGULAR_TITLE = 'One minute talk';
    private const HIDDEN_TITLES = [
        'unpublished' => 'Unpublished talk',
        'access'      => 'Restricted talk',
        'language'    => 'Wrong language talk',
    ];
    private const SEED_MARKER = 'sitepage-seed-';

    public function __construct()
    {
        $this->baseUrl = rtrim(getenv('SITE_PAGE_BASE_URL') ?: 'http://localhost', '/');

        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);

        $file = '/var/www/html/language/en-GB/com_appointments.ini';

        if (is_file($file)) {
            $this->strings = parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
        }
    }

    public function run(): bool
    {
        echo "=== Site Page Tests ===\n\n";

        $this->test('Site language file is installed and readable', function () {
            return $this->strings !== [];
        });

        $pages = [
            'entry point without a view' => 'index.php?option=com_appointments',
            'event type list view'       => 'index.php?option=com_appointments&view=eventtypes',
        ];

        foreach ($pages as $label => $path) {
            $response = $this->request($path);

            $this->test("{$label}: answers HTTP 200", function () use ($response) {
                if ($response['code'] !== 200) {
                    echo "    HTTP {$response['code']} at {$response['url']}\n";
                }

                return $response['code'] === 200;
            });

            $this->test("{$label}: no PHP error in the output", function () use ($response) {
                if (preg_match('/(?:PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated):[^\n<]*/', $response['body'], $m)) {
                    echo "    {$m[0]}\n";

                    return false;
                }

                return true;
            });

            $this->test("{$label}: no Joomla error page", function () use ($response) {
                $needles = ['An error has occurred', 'Error: 500', 'Error: 404', 'Call to a member function'];

                foreach ($needles as $needle) {
                    if (stripos($response['body'], $needle) !== false) {
                        echo "    found: {$needle}\n";

                        return false;
                    }
                }

                return true;
            });

            $this->test("{$label}: no untranslated language key", function () use ($response) {
                if (preg_match('/COM_APPOINTMENTS_[A-Z0-9_]+/', $response['body'], $m)) {
                    echo "    raw key: {$m[0]}\n";

                    return false;
                }

                return true;
            });

            $this->test("{$label}: shows a string from the component's language file", function () use ($response) {
                return $this->showsOwnString($response['body']);
            });

            $this->test("{$label}: sets its own page title and heading without a menu item of its own", function () use ($response) {
                // Without an Itemid the home menu item is active; its title must not leak.
                $heading = trim((string) ($this->strings['COM_APPOINTMENTS_EVENTTYPES_HEADING'] ?? ''));
                $title = preg_match('#<title>(.*?)</title>#s', $response['body'], $m) ? trim(html_entity_decode($m[1])) : '';

                if ($title !== $heading) {
                    echo "    title: {$title}\n";
                }

                return $heading !== '' && $title === $heading
                    && preg_match('#<h1 class="com-appointments-heading">\s*' . preg_quote($heading, '#') . '\s*</h1>#', $response['body']) === 1;
            });

            $this->test("{$label}: serves the component stylesheet from this installation", function () use ($response) {
                // The asset has to come from this server. A remote URL would
                // break the component's defining constraint, so the path is
                // asserted, not merely the presence of a stylesheet.
                return (bool) preg_match(
                    '#(?:href|src)="(?:/|\.\./)*media/com_appointments/css/appointments\.css#',
                    $response['body']
                );
            });
        }

        $this->runPopulatedChecks();
        $this->runBookingSteps();

        echo "\n=== Site Page Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    /**
     * The checks above run against an empty table, so they only ever exercise
     * the empty-state branch. These seed four event types, one visible and
     * three that each have to be filtered out for a different reason, and then
     * assert what the page shows.
     */
    private function runPopulatedChecks(): void
    {
        echo "\n--- with seeded event types ---\n";

        try {
            $this->seed();
            $response = $this->request('index.php?option=com_appointments&view=eventtypes');
            $body = $response['body'];
        } finally {
            $this->cleanUp();
        }

        $this->test('seeded: answers HTTP 200', function () use ($response) {
            if ($response['code'] !== 200) {
                echo "    HTTP {$response['code']} at {$response['url']}\n";
            }

            return $response['code'] === 200;
        });

        $this->test('seeded: shows the published, accessible, matching event type', function () use ($body) {
            // The layout escapes the title, so the escaped form is what the
            // page has to contain.
            return strpos($body, htmlspecialchars(self::VISIBLE_TITLE, ENT_QUOTES, 'UTF-8')) !== false;
        });

        $this->test('seeded: escapes the title instead of emitting markup', function () use ($body) {
            // The raw tag must not reach the page; its escaped form must.
            return strpos($body, '<b>& "quoted"</b> talk') === false
                && strpos($body, '&lt;b&gt;') !== false;
        });

        $this->test('seeded: shows the duration of the event type', function () use ($body) {
            return strpos($body, '25 minutes') !== false;
        });

        $this->test('seeded: a one-minute event type reads in the singular', function () use ($body) {
            // "1 minute" is a substring of "1 minutes", so the absence of the
            // plural form is the assertion that actually proves the behaviour.
            // No other seeded duration ends in 1, so this is unambiguous.
            if (strpos($body, '1 minutes') !== false) {
                echo "    found the plural form for a one-minute duration\n";

                return false;
            }

            return strpos($body, '1 minute') !== false;
        });

        foreach (self::HIDDEN_TITLES as $reason => $title) {
            $this->test("seeded: hides the {$reason} event type", function () use ($body, $title) {
                return strpos($body, $title) === false
                    && strpos($body, htmlspecialchars($title, ENT_QUOTES, 'UTF-8')) === false;
            });
        }

        $this->test('seeded: the empty-state text is gone', function () use ($body) {
            $empty = trim((string) ($this->strings['COM_APPOINTMENTS_EVENTTYPES_EMPTY'] ?? ''));

            return $empty !== '' && strpos($body, $empty) === false;
        });
    }

    /**
     * The booking page in steps, the way a visitor without JavaScript goes through it:
     * the calendar alone, the times of a chosen day, the GET form of a time with its
     * "Next" button, and the details of that time. Then the ways the address can be
     * wrong: a time that is no longer offered, a malformed time and an unknown layout.
     * Both information-column layouts are rendered, with markup in the name and role.
     */
    private function runBookingSteps(): void
    {
        echo "\n--- booking page in steps ---\n";

        $utc = new \DateTimeZone('UTC');
        $day = (new \DateTimeImmutable('now', $utc))->modify('+2 days')->format('Y-m-d');
        $month = substr($day, 0, 7);
        $person = 'Muster <i>Person</i> & "Co"';

        try {
            $event = $this->seedBookable($person);
            $base = 'index.php?option=com_appointments&view=eventtype&id=' . $event . '&timezone=UTC';

            // 1a: the calendar alone, no times and no form of details yet.
            $page = $this->request($base . '&month=' . $month);
            $xpath = $this->dom($page['body']);
            $this->test('steps: the calendar answers HTTP 200 without a PHP error', function () use ($page) {
                return $page['code'] === 200 && !preg_match('/(Fatal error|Warning|Notice|Deprecated):/', $page['body']);
            });
            $this->test('steps: without a chosen day the calendar stands alone, without times or details', function () use ($xpath) {
                return $xpath->query('//form[@id="appointment-time-form"][@method="get"]')->length === 1
                    && $xpath->query('//table[contains(@class, "com-appointments-calendar-grid")]')->length === 1
                    && $xpath->query('//input[@name="start"]')->length === 0
                    && $xpath->query('//form[@id="appointment-details-form"]')->length === 0
                    && $xpath->query('//*[@id="appointment-picker"][@data-day=""]')->length === 1;
            });
            $this->test('steps: before a day is chosen the times column holds the hint, the zone stands in one line below', function () use ($xpath) {
                $line = '//div[contains(@class, "com-appointments-zone-line")]';

                return trim($xpath->query('//*[@id="appointment-picker"]//*[contains(@class, "com-appointments-times-hint")]')->item(0)?->textContent ?? '') === 'Please choose a day'
                    && trim($xpath->query($line . '/p[@id="appointment-zone-note"]')->item(0)?->textContent ?? '') === 'All times are shown in the UTC time zone.'
                    && $xpath->query('//*[@id="appointment-picker"]//*[@id="appointment-zone-note"]')->length === 0
                    && $xpath->query($line . '/details[contains(@class, "com-appointments-zone")]//label[@for="appointment-zone"][contains(@class, "visually-hidden")]')->length === 1;
            });
            $this->test('steps: the step title names step 1 of 3 and the event type is the main heading', function () use ($xpath) {
                $title = preg_replace('/\s+/', ' ', trim($xpath->query('//*[@id="appointment-step-title"]')->item(0)?->textContent ?? ''));

                return $title === 'Step 1 of 3: Choose a date and time'
                    && trim($xpath->query('//h1[contains(@class, "com-appointments-heading")]')->item(0)?->textContent ?? '') === 'Bookable steps talk';
            });
            $dayLink = '';
            foreach ($xpath->query('//a[contains(@class, "com-appointments-day-available")]') as $link) {
                if (str_contains(html_entity_decode($link->getAttribute('href')), 'day=' . $day)) {
                    $dayLink = html_entity_decode($link->getAttribute('href'));
                }
            }
            $this->test('steps: a bookable day is a plain link that lands at the date and time part', function () use ($dayLink) {
                return $dayLink !== '' && str_ends_with($dayLink, '#appointment-picker');
            });
            $this->test('steps: the company-logo layout shows the logo with an address from the site root', function () use ($xpath) {
                $logo = $xpath->query('//img[contains(@class, "com-appointments-logo")]')->item(0);

                return $logo instanceof \DOMElement && str_starts_with($logo->getAttribute('src'), '/')
                    && str_ends_with($logo->getAttribute('src'), 'images/joomla_black.png') && $logo->getAttribute('alt') === ''
                    && $xpath->query('//*[contains(@class, "com-appointments-host")]')->length === 0;
            });

            // 1b: the chosen day, its times, each with its own "Next" button in the GET form.
            $page = $this->request(preg_replace('#^/#', '', parse_url($dayLink, PHP_URL_PATH)) . '?' . parse_url($dayLink, PHP_URL_QUERY));
            $xpath = $this->dom($page['body']);
            $radios = $xpath->query('//form[@id="appointment-time-form"]//input[@type="radio"][@name="start"]');
            $this->test('steps: the chosen day shows its times as radio buttons named start, each with a "Next" button', function () use ($xpath, $radios, $day) {
                $slots = $xpath->query('//form[@id="appointment-time-form"]//div[contains(concat(" ", normalize-space(@class), " "), " com-appointments-slot ")]');
                $next = $xpath->query('//form[@id="appointment-time-form"]//div[contains(concat(" ", normalize-space(@class), " "), " com-appointments-slot ")]/button[@type="submit"][contains(@class, "com-appointments-slot-next")]');

                return $radios->length > 0 && $slots->length === $radios->length && $next->length === $radios->length
                    && str_starts_with($radios->item(0)->getAttribute('value'), $day . ' ')
                    && trim($next->item(0)->textContent) === 'Next'
                    && $xpath->query('//form[@id="appointment-details-form"]')->length === 0;
            });
            $this->test('steps: the way back to the calendar of a narrow screen leaves the day out', function () use ($xpath) {
                $back = $xpath->query('//a[contains(@class, "com-appointments-times-back")]')->item(0);

                return $back instanceof \DOMElement && !str_contains(html_entity_decode($back->getAttribute('href')), 'day=')
                    && trim($back->textContent) === 'Back to the calendar';
            });

            // The no-JavaScript path: send the GET form with the first time, as its "Next" button does.
            $form = $xpath->query('//form[@id="appointment-time-form"]')->item(0);
            $query = [];
            foreach ($xpath->query('.//input[@type="hidden"]', $form) as $input) {
                $query[$input->getAttribute('name')] = $input->getAttribute('value');
            }
            $chosen = $radios->length > 0 ? $radios->item(0)->getAttribute('value') : '';
            $query['start'] = $chosen;
            $action = html_entity_decode($form instanceof \DOMElement ? $form->getAttribute('action') : '');
            $page = $this->request(ltrim((string) parse_url($action, PHP_URL_PATH), '/') . '?' . http_build_query($query));
            $xpath = $this->dom($page['body']);
            $this->test('steps: the "Next" button opens step 2 with the details form of the chosen time', function () use ($page, $xpath, $chosen) {
                $start = $xpath->query('//form[@id="appointment-details-form"][@method="post"]//input[@type="hidden"][@name="booking[start]"]')->item(0);
                $zone = $xpath->query('//form[@id="appointment-details-form"]//input[@type="hidden"][@name="booking[timezone]"]')->item(0);
                $title = preg_replace('/\s+/', ' ', trim($xpath->query('//*[@id="appointment-step-title"]')->item(0)?->textContent ?? ''));

                return $page['code'] === 200 && $chosen !== '' && $start instanceof \DOMElement && $start->getAttribute('value') === $chosen
                    && $zone instanceof \DOMElement && $zone->getAttribute('value') === 'UTC'
                    && $title === 'Step 2 of 3: Your details'
                    && $xpath->query('//input[@name="start"]')->length === 0
                    && $xpath->query('//input[@id="booking-name"][@required]')->length === 1
                    && $xpath->query('//input[@id="booking-consent"][@required]')->length === 1;
            });
            $this->test('steps: the information column shows the chosen time and its zone', function () use ($xpath, $chosen) {
                $start = new \DateTimeImmutable($chosen, new \DateTimeZone('UTC'));
                $time = trim($xpath->query('//*[contains(@class, "com-appointments-selected-time")]')->item(0)?->textContent ?? '');

                return $time === $start->format('H:i') . ' – ' . $start->modify('+30 minutes')->format('H:i')
                    && trim($xpath->query('//li[contains(@class, "com-appointments-selected-zone")]')->item(0)?->textContent ?? '') === 'UTC';
            });
            $this->test('steps: "Back" leads to the times of the same day, without the chosen time', function () use ($xpath, $day) {
                $back = $xpath->query('//a[contains(@class, "com-appointments-back")][@data-appointments-step]')->item(0);
                $href = $back instanceof \DOMElement ? html_entity_decode($back->getAttribute('href')) : '';

                return str_contains($href, 'day=' . $day) && !str_contains($href, 'start=') && str_ends_with($href, '#appointment-picker')
                    && trim($back->textContent) === 'Back';
            });

            // The person layout, with markup in the name and the role.
            $this->db->query("UPDATE {$this->dbPrefix}appointments_eventtypes SET info_layout='person' WHERE id={$event}");
            $page = $this->request($base . '&start=' . urlencode($chosen));
            $xpath = $this->dom($page['body']);
            $this->test('steps: the person layout shows photo, name and role, escaped', function () use ($page, $xpath, $person) {
                $photo = $xpath->query('//img[contains(@class, "com-appointments-host-photo")]')->item(0);

                return $page['code'] === 200 && $photo instanceof \DOMElement && str_starts_with($photo->getAttribute('src'), '/')
                    && trim($xpath->query('//*[contains(@class, "com-appointments-host-name")]')->item(0)?->textContent ?? '') === $person
                    && trim($xpath->query('//*[contains(@class, "com-appointments-host-role")]')->item(0)?->textContent ?? '') === 'Role <b>&'
                    && !str_contains($page['body'], '<i>Person</i>')
                    && $xpath->query('//img[contains(@class, "com-appointments-logo")]')->length === 0;
            });

            // The "Appointment only" layout: the event details with their heading, no logo and
            // no photo, although both are still stored.
            $this->db->query("UPDATE {$this->dbPrefix}appointments_eventtypes SET info_layout='event' WHERE id={$event}");
            $page = $this->request($base . '&start=' . urlencode($chosen));
            $xpath = $this->dom($page['body']);
            $this->test('steps: the event layout shows the event details alone, without logo or photo', function () use ($page, $xpath) {
                $info = '//header[contains(@class, "com-appointments-info")]';

                return $page['code'] === 200
                    && $xpath->query('//*[contains(@class, "com-appointments-layout")][contains(@class, "com-appointments-info-event")]')->length === 1
                    && $xpath->query($info . '//img')->length === 0
                    && $xpath->query($info . '//*[contains(@class, "com-appointments-info-media")]')->length === 0
                    && $xpath->query($info . '//h1[contains(@class, "com-appointments-heading")]')->length === 1
                    && $xpath->query($info . '//li[contains(@class, "com-appointments-duration")]')->length === 1
                    && $xpath->query($info . '//*[contains(@class, "com-appointments-selected-time")]')->length === 1
                    && $xpath->query($info . '//a[contains(@class, "com-appointments-back")]')->length === 1;
            });

            // The title above the card: still the one main heading of the page, now before the card.
            $this->db->query("UPDATE {$this->dbPrefix}appointments_eventtypes SET info_title='above' WHERE id={$event}");
            $page = $this->request($base . '&start=' . urlencode($chosen));
            $xpath = $this->dom($page['body']);
            $this->test('steps: the title above the card is the only main heading, outside the information column', function () use ($page, $xpath) {
                $above = $xpath->query('//*[@data-appointments-view]/h1[contains(@class, "com-appointments-page-title")]')->item(0);

                return $page['code'] === 200 && $above instanceof \DOMElement && trim($above->textContent) === 'Bookable steps talk'
                    && $xpath->query('//*[@data-appointments-booking]//h1')->length === 1
                    && $xpath->query('//header[contains(@class, "com-appointments-info")]//h1')->length === 0
                    && $xpath->query('following-sibling::*[1][@id="appointment-booking"]', $above)->length === 1;
            });
            $this->db->query("UPDATE {$this->dbPrefix}appointments_eventtypes SET info_title='card' WHERE id={$event}");

            // An unknown layout value falls back to the company-logo layout; an empty logo shows no image.
            $this->db->query("UPDATE {$this->dbPrefix}appointments_eventtypes SET info_layout='bogus', info_logo='' WHERE id={$event}");
            $page = $this->request($base . '&month=' . $month);
            $xpath = $this->dom($page['body']);
            $this->test('steps: an unknown layout falls back to the logo layout, and no logo means no image', function () use ($page, $xpath) {
                return $page['code'] === 200 && $xpath->query('//*[contains(@class, "com-appointments-info-logo")]')->length === 1
                    && $xpath->query('//*[contains(@class, "com-appointments-layout")][contains(@class, "com-appointments-info-logo")]')->length === 1
                    && $xpath->query('//img')->length === $xpath->query('//img[not(ancestor::*[contains(@class, "com-appointments")])]')->length
                    && $xpath->query('//h1[contains(@class, "com-appointments-heading")]')->length === 1;
            });

            // A time that is not offered (here before the schedule opens) returns to the times of its day.
            $page = $this->request($base . '&start=' . urlencode($day . ' 03:00:00'));
            $xpath = $this->dom($page['body']);
            $this->test('steps: a time that is no longer offered shows the times of its day with the reason', function () use ($page, $xpath) {
                $taken = trim((string) ($this->strings['COM_APPOINTMENTS_BOOKING_ERROR_TAKEN'] ?? ''));

                return $page['code'] === 200 && $taken !== '' && trim($xpath->query('//*[@id="booking-start-error"]')->item(0)?->textContent ?? '') === $taken
                    && $xpath->query('//input[@name="start"]')->length > 0
                    && $xpath->query('//form[@id="appointment-details-form"]')->length === 0;
            });

            $page = $this->request($base . '&start=not-a-time');
            $this->test('steps: a malformed time is an explicit 400', function () use ($page) {
                return $page['code'] === 400;
            });

            // The background update of a month: the date-and-time part without times while no day is chosen.
            $page = $this->request($base . '&month=' . $month . '&format=raw');
            $xpath = $this->dom($page['body']);
            $this->test('steps: the slot endpoint of a month without a day returns the calendar without times', function () use ($page, $xpath) {
                return $page['code'] === 200 && $xpath->query('//*[@id="appointment-picker"][@data-day=""]')->length === 1
                    && $xpath->query('//input[@name="start"]')->length === 0
                    && $xpath->query('//*[contains(@class, "com-appointments-day-available")]')->length > 0
                    && trim($xpath->query('//p[@id="appointment-zone-note"]')->item(0)?->textContent ?? '') === 'All times are shown in the UTC time zone.';
            });
        } finally {
            $this->cleanUp();
        }
    }

    /** A published event type with an open UTC schedule every day, 09:00 to 17:00, and both layouts' settings. */
    private function seedBookable(string $person): int
    {
        $this->cleanUp();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->query(
            "INSERT INTO {$this->dbPrefix}appointments_schedules (title, timezone, published, created_utc, modified_utc)"
            . " VALUES ('sitepage-seed-schedule', 'UTC', 1, '{$now}', '{$now}')"
        );
        $this->scheduleId = (int) $this->db->insert_id;

        for ($weekday = 1; $weekday <= 7; $weekday++) {
            $this->db->query(
                "INSERT INTO {$this->dbPrefix}appointments_schedule_rules (schedule_id, weekday, start_time, end_time)"
                . " VALUES ({$this->scheduleId}, {$weekday}, '09:00:00', '17:00:00')"
            );
        }

        $statement = $this->db->prepare(
            "INSERT INTO {$this->dbPrefix}appointments_eventtypes (title, alias, duration_minutes, slot_step_minutes, horizon_days,"
            . " access, language, published, feed_token, created_utc, modified_utc, schedule_id,"
            . " info_layout, info_logo, info_photo, info_name, info_role)"
            . " VALUES ('Bookable steps talk', ?, 30, 30, 30, 1, '*', 1, ?, ?, ?, ?, 'logo',"
            . " 'images/joomla_black.png#joomlaImage://local-images/joomla_black.png?width=225&height=50', 'images/joomla_black.png', ?, 'Role <b>&')"
        );
        $alias = self::SEED_MARKER . 'steps';
        $token = str_repeat('f', 64);
        $statement->bind_param('ssssis', $alias, $token, $now, $now, $this->scheduleId, $person);
        $statement->execute();
        $id = (int) $statement->insert_id;
        $statement->close();

        return $id;
    }

    private function dom(string $html): \DOMXPath
    {
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8"?>' . $html);

        return new \DOMXPath($document);
    }

    /**
     * Insert the four fixtures. `feed_token` has no default on purpose, so each
     * row brings its own, and the aliases are unique as the schema demands.
     */
    private function seed(): void
    {
        $this->cleanUp();

        $table = "{$this->dbPrefix}appointments_eventtypes";
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->db->query(
            "INSERT INTO {$this->dbPrefix}appointments_schedules (title, created_utc, modified_utc)"
            . " VALUES ('sitepage-seed-schedule', '{$now}', '{$now}')"
        );
        $this->scheduleId = (int) $this->db->insert_id;

        // published, access (1 = Public), language, duration, title
        $rows = [
            ['published' => 1, 'access' => 1, 'language' => '*', 'duration' => 25, 'title' => self::VISIBLE_TITLE],
            // A one-minute event type: the layout has to pick the singular form.
            ['published' => 1, 'access' => 1, 'language' => '*', 'duration' => 1, 'title' => self::SINGULAR_TITLE],
            ['published' => 0, 'access' => 1, 'language' => '*', 'duration' => 30, 'title' => self::HIDDEN_TITLES['unpublished']],
            // 3 = Special, which a guest never holds.
            ['published' => 1, 'access' => 3, 'language' => '*', 'duration' => 35, 'title' => self::HIDDEN_TITLES['access']],
            ['published' => 1, 'access' => 1, 'language' => 'de-DE', 'duration' => 40, 'title' => self::HIDDEN_TITLES['language']],
        ];

        foreach ($rows as $index => $row) {
            $alias = self::SEED_MARKER . $index;
            $token = str_pad((string) $index, 64, 'a', STR_PAD_LEFT);
            $statement = $this->db->prepare(
                "INSERT INTO {$table} (title, alias, duration_minutes, slot_step_minutes, access, language,"
                . " published, feed_token, created_utc, modified_utc, schedule_id)"
                . " VALUES (?, ?, ?, 30, ?, ?, ?, ?, ?, ?, ?)"
            );
            $statement->bind_param(
                'ssiisisssi',
                $row['title'],
                $alias,
                $row['duration'],
                $row['access'],
                $row['language'],
                $row['published'],
                $token,
                $now,
                $now,
                $this->scheduleId
            );
            $statement->execute();
            $statement->close();
        }
    }

    private function cleanUp(): void
    {
        $table = "{$this->dbPrefix}appointments_eventtypes";
        $marker = self::SEED_MARKER . '%';
        $statement = $this->db->prepare("DELETE FROM {$table} WHERE alias LIKE ?");
        $statement->bind_param('s', $marker);
        $statement->execute();
        $statement->close();
        if ($this->scheduleId > 0) {
            $this->db->query("DELETE FROM {$this->dbPrefix}appointments_schedule_rules WHERE schedule_id = {$this->scheduleId}");
            $this->db->query("DELETE FROM {$this->dbPrefix}appointments_schedules WHERE id = {$this->scheduleId}");
            $this->scheduleId = 0;
        }
    }

    /**
     * Whether the page shows at least one text of the component's own language
     * file. A page that rendered the layout but loaded no language would pass
     * every other check.
     */
    private function showsOwnString(string $body): bool
    {
        foreach ($this->strings as $value) {
            $value = trim((string) $value);

            // Values with a placeholder are not comparable literally.
            if ($value === '' || strpos($value, '%') !== false) {
                continue;
            }

            if (strpos($body, $value) !== false
                || strpos($body, htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redirects are followed: Joomla answers a bare `option=` request with a
     * permanent redirect to the canonical URL of the view, which is correct
     * behaviour and not something this suite should fail on. Where the redirect
     * ends is still checked, because the two content assertions only pass on the
     * component's own page.
     *
     * @return array{code:int, body:string, url:string}
     */
    private function request(string $path): array
    {
        $handle = curl_init($this->baseUrl . '/' . ltrim($path, '/'));
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $url  = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);

        return ['code' => $code, 'body' => $body === false ? '' : $body, 'url' => $url];
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "✓ {$name}\n";
                $this->passed++;
            } else {
                echo "✗ {$name}\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new SitePageTest();
exit($test->run() ? 0 : 1);
