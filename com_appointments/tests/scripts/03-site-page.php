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
