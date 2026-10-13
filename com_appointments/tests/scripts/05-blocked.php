<?php
/**
 * Blocked period administration tests.
 *
 * The one thing that really has to work on this screen is the time-zone
 * conversion: an operator enters a period in their own zone and the component
 * stores universal time. A unit test cannot see that, because the conversion is
 * Joomla's form filter, and rendering the form with a GET request does not
 * exercise it either. This suite therefore gives the administrator account a
 * non-UTC time zone, submits the form, and checks what reached the database.
 *
 * It also checks the two refusals the table is responsible for: a period that
 * ends before it starts, and a date that does not exist.
 *
 * The suite restores the account's time zone and removes its fixtures.
 */

class BlockedAdminTest
{
    private const TITLE = 'suite-blocked-fixture';
    private const ZONE = 'Europe/Zurich';

    private $db;
    private $dbPrefix;
    private $baseUrl;
    private $cookieJar = '/tmp/suite-blocked-cookies.txt';
    private $adminId = 0;
    private $originalParams = '';
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
        $this->baseUrl = rtrim(getenv('BLOCKED_BASE_URL') ?: 'http://localhost', '/');
    }

    public function run(): bool
    {
        echo "=== Blocked Period Administration Tests ===\n\n";

        $this->cleanUp();

        if (!$this->login()) {
            echo "✗ Could not log into the administrator\n";

            return false;
        }

        echo "✓ Logged into the administrator\n";
        $this->passed++;

        if (!$this->useZoneForAdmin()) {
            echo "✗ Could not set the time zone of the administrator account\n";
            $this->failed++;

            return false;
        }

        echo "✓ The administrator account is on " . self::ZONE . "\n";
        $this->passed++;

        try {
            $token = $this->tokenIn(
                $this->get('/administrator/index.php?option=com_appointments&task=blocked.add')
            );

            $this->test('The new blocked period form renders and carries a token', function () use ($token) {
                return $token !== null;
            });

            if ($token === null) {
                return false;
            }

            // --- The conversion ------------------------------------------------
            // 2 June 2026 is summer time in Zurich, so local 14:00 is 12:00 UTC.
            $this->post('blocked.save', $token, [
                'jform[id]'        => '0',
                'jform[user_id]'   => (string) $this->adminId,
                'jform[title]'     => self::TITLE,
                'jform[start_utc]' => '2026-06-02 14:00:00',
                'jform[end_utc]'   => '2026-06-02 16:00:00',
            ]);

            $row = $this->fixture();

            $this->test('The blocked period was stored', function () use ($row) {
                return $row !== null;
            });

            if ($row === null) {
                return false;
            }

            $this->test('The entered local time was stored as universal time', function () use ($row) {
                return $row['start_utc'] === '2026-06-02 12:00:00'
                    && $row['end_utc'] === '2026-06-02 14:00:00';
            });

            $this->test('The form shows the period in the local zone again', function () use ($row) {
                $html = $this->get(
                    '/administrator/index.php?option=com_appointments&task=blocked.edit&id=' . (int) $row['id']
                );

                return strpos($html, '2026-06-02 14:00:00') !== false;
            });

            // --- Deletion ------------------------------------------------------
            // Done here, on a record that no edit attempt has checked out yet.
            $id = (int) $row['id'];
            $this->post('blocked.cancel', $token, ['jform[id]' => (string) $id]);
            $listToken = $this->tokenIn(
                $this->get('/administrator/index.php?option=com_appointments&view=blockeds')
            ) ?? $token;
            $deleteResponse = $this->post('blockeds.delete', $listToken, ['cid[]' => (string) $id]);

            $this->test('The blocked period can be deleted', function () use ($deleteResponse) {
                if ($this->fixture() === null) {
                    return true;
                }

                echo '    response said: ' . $this->firstMessage($deleteResponse) . "\n";

                return false;
            });

            // --- The refusal ---------------------------------------------------
            // A fresh record, because the one above is gone.
            $this->post('blocked.save', $this->freshToken($token), [
                'jform[id]'        => '0',
                'jform[user_id]'   => (string) $this->adminId,
                'jform[title]'     => self::TITLE,
                'jform[start_utc]' => '2026-06-02 14:00:00',
                'jform[end_utc]'   => '2026-06-02 16:00:00',
            ]);
            $second = $this->fixture();

            $this->test('The second blocked period was stored', function () use ($second) {
                return $second !== null;
            });

            if ($second === null) {
                return false;
            }

            $secondId = (int) $second['id'];
            $editToken = $this->tokenIn(
                $this->get('/administrator/index.php?option=com_appointments&task=blocked.edit&id=' . $secondId)
            ) ?? $token;

            $html = $this->post('blocked.save', $editToken, [
                'jform[id]'        => (string) $secondId,
                'jform[user_id]'   => (string) $this->adminId,
                'jform[title]'     => self::TITLE,
                'jform[start_utc]' => '2026-06-02 16:00:00',
                'jform[end_utc]'   => '2026-06-02 14:00:00',
            ]);

            $this->test('A period that ends before it starts is refused', function () use ($html, $secondId) {
                $stored = $this->fixture();

                return stripos($html, 'after its start') !== false
                    && $stored !== null
                    && (int) $stored['id'] === $secondId
                    // The stored period is unchanged.
                    && $stored['start_utc'] === '2026-06-02 12:00:00';
            });

            $html = $this->post('blocked.save', $editToken, [
                'jform[id]'        => (string) $secondId,
                'jform[user_id]'   => (string) $this->adminId,
                'jform[title]'     => self::TITLE,
                'jform[start_utc]' => '2026-02-31 10:00:00',
                'jform[end_utc]'   => '2026-06-02 16:00:00',
            ]);

            $this->test('A date that does not exist is refused, not normalised', function () use ($html) {
                // 2026-02-31 does not exist. Joomla's own user_utc filter would
                // read it as the 3rd of March, so the controller refuses it on
                // the submitted text, before the conversion, while the
                // operator's own input still exists.
                $stored = $this->fixture();

                return $stored !== null
                    // Untouched: no normalised March date reached the row.
                    && $stored['start_utc'] === '2026-06-02 12:00:00'
                    && stripos($html, 'is not a date and a time that exist') !== false;
            });
        } finally {
            $this->restoreAdminZone();
            $this->cleanUp();
        }

        echo "\n=== Blocked Period Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fixture()
    {
        $table = "{$this->dbPrefix}appointments_blocked";
        $title = $this->db->real_escape_string(self::TITLE);
        $result = $this->db->query("SELECT * FROM {$table} WHERE title = '{$title}' ORDER BY id DESC LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;

        return $row ?: null;
    }

    private function cleanUp(): void
    {
        $table = "{$this->dbPrefix}appointments_blocked";
        $title = $this->db->real_escape_string(self::TITLE);
        $this->db->query("DELETE FROM {$table} WHERE title = '{$title}'");
    }

    /**
     * Give the logged-in account a non-UTC time zone, so the form filter has
     * something to convert.
     */
    private function useZoneForAdmin(): bool
    {
        $users = "{$this->dbPrefix}users";
        $name = $this->db->real_escape_string(getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin');
        $result = $this->db->query("SELECT id, params FROM {$users} WHERE username = '{$name}' LIMIT 1");
        $row = $result ? $result->fetch_assoc() : null;

        if (!$row) {
            return false;
        }

        $this->adminId = (int) $row['id'];
        $this->originalParams = (string) $row['params'];

        $params = json_decode($this->originalParams, true);
        $params = \is_array($params) ? $params : [];
        $params['timezone'] = self::ZONE;
        $encoded = $this->db->real_escape_string((string) json_encode($params));

        $this->db->query("UPDATE {$users} SET params = '{$encoded}' WHERE id = {$this->adminId}");

        // The session caches the user object, so a fresh login is needed for the
        // new zone to take effect.
        return $this->login();
    }

    private function restoreAdminZone(): void
    {
        if ($this->adminId === 0) {
            return;
        }

        $users = "{$this->dbPrefix}users";
        $params = $this->db->real_escape_string($this->originalParams);
        $this->db->query("UPDATE {$users} SET params = '{$params}' WHERE id = {$this->adminId}");
    }

    private function login(): bool
    {
        @unlink($this->cookieJar);
        $token = null;

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = $this->tokenIn($this->get('/administrator/index.php'));

            if ($token !== null) {
                break;
            }

            sleep(2);
        }

        if ($token === null) {
            return false;
        }

        $body = $this->request('/administrator/index.php', [
            'username' => getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin',
            'passwd'   => getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#',
            'option'   => 'com_login',
            'task'     => 'login',
            'return'   => base64_encode('index.php'),
            $token     => '1',
        ]);

        return strpos($body, 'task=logout') !== false || strpos($body, 'com_cpanel') !== false;
    }

    private function tokenIn(string $html): ?string
    {
        return preg_match('/name="([a-f0-9]{32})" value="1"/', $html, $m) ? $m[1] : null;
    }

    /**
     * A token from the list screen, falling back to the one given.
     */
    private function freshToken(string $fallback): string
    {
        return $this->tokenIn($this->get('/administrator/index.php?option=com_appointments&view=blockeds'))
            ?? $fallback;
    }

    /**
     * The first Joomla message of a response, so a failing step says what the
     * application answered instead of only that it failed.
     */
    private function firstMessage(string $html): string
    {
        if (preg_match('#<joomla-alert[^>]*>(.*?)</joomla-alert>#s', $html, $m)) {
            $text = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'));

            return preg_replace('/\s+/', ' ', $text) ?? '';
        }

        return 'no message found in the response';
    }

    /**
     * @param  array<string, string>  $fields
     */
    private function post(string $task, string $token, array $fields): string
    {
        $fields['task'] = $task;
        $fields[$token] = '1';

        return $this->request('/administrator/index.php?option=com_appointments', $fields);
    }

    private function get(string $path): string
    {
        return $this->request($path);
    }

    /**
     * @param  array<string, string>  $post
     */
    private function request(string $path, array $post = []): string
    {
        $handle = curl_init($this->baseUrl . $path);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
        ]);

        if ($post !== []) {
            curl_setopt($handle, CURLOPT_POST, true);
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($handle);
        curl_close($handle);

        return $body === false ? '' : $body;
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

$test = new BlockedAdminTest();
exit($test->run() ? 0 : 1);
