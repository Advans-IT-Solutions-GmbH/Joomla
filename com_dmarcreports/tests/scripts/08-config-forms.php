<?php
/**
 * Config + edit-form tests (authenticated HTTP).
 *
 * The other suites prove classes exist and that read-only views render, but
 * nothing drove the write path: the component's com_config Options screen and
 * the two edit forms (domain and source) were never actually loaded or
 * submitted in CI. A dead Options screen, a Save button that silently throws,
 * or a server-side validation gap would all stay green. Issue #203 collected
 * exactly those regressions, so this suite logs into /administrator and:
 *
 *   - opens the component's com_config Options screen and asserts it renders
 *     the migrated option fields (not a Joomla error page),
 *   - opens and submits the domain edit form and the source edit form, proving
 *     Save actually writes a row,
 *   - posts the out-of-bounds values from the issue (duplicate domain, over-long
 *     note, port 0 / 70000 / "abc") and asserts each is rejected with the
 *     component's own localized message and never reaches the database as a raw
 *     SQL error.
 *
 * The suite talks to the live site over HTTP and seeds/cleans its own rows via
 * mysqli; it only touches records it creates.
 */

class ConfigFormsTest
{
    private $db;
    private $dbPrefix;
    private $baseUrl;
    private $adminUser;
    private $adminPass;
    private $cookieJar = '/tmp/dmarc-config-forms-cookies.txt';
    private $strings = [];
    private $token;
    private $passed = 0;
    private $failed = 0;

    /** Unique marker so every row this suite creates is easy to find and purge. */
    private $tag;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix  = $config->dbprefix;
        $this->db        = new mysqli($config->host, $config->user, $config->password, $config->db);
        $this->baseUrl   = getenv('BACKEND_VIEWS_BASE_URL') ?: 'http://localhost';
        $this->adminUser = getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin';
        $this->adminPass = getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123456789!@#';
        $this->tag       = 'citest-' . substr(hash('sha256', (string) getmypid() . microtime()), 0, 8);
        $this->strings   = $this->readStrings();
    }

    public function run(): bool
    {
        echo "=== Config + Edit Form Tests ===\n\n";

        $this->cleanup();

        if (!$this->login()) {
            echo "✗ cannot authenticate against /administrator\n";
            echo "\n=== Config + Edit Form Test Summary ===\nPassed: {$this->passed}, Failed: 1\n";
            return false;
        }
        echo "✓ admin login succeeds\n";

        // --- com_config Options screen renders -------------------------------
        $options = $this->request(
            $this->baseUrl . '/administrator/index.php?option=com_config&view=component&component=com_dmarcreports'
        );
        $this->test('com_config Options screen returns HTTP 200', function () use ($options) {
            return $options['code'] === 200;
        });
        $this->test('com_config Options screen renders without an error page', function () use ($options) {
            return !$this->hasErrorMarker($options['body']);
        });
        $this->test('com_config Options screen binds the component option fields', function () use ($options) {
            // com_config renders each config.xml field as a jform input; the
            // migrated numeric option proves the component's config.xml loaded.
            return strpos($options['body'], 'quarantine_pass_threshold') !== false
                && strpos($options['body'], 'csv_delimiter') !== false;
        });

        // --- Domain edit form renders + saves --------------------------------
        $domainForm = $this->request($this->baseUrl . '/administrator/index.php?option=com_dmarcreports&task=domain.add', [], true);
        $this->token = $this->extractToken($domainForm['body']) ?: $this->token;

        $this->test('Domain edit form renders the domain field and a token', function () use ($domainForm) {
            return $domainForm['code'] === 200
                && strpos($domainForm['body'], 'jform[domain]') !== false
                && $this->extractToken($domainForm['body']) !== null;
        });

        $validDomain = $this->tag . '.example';
        $this->saveDomain(['domain' => $validDomain, 'title' => 'CI valid']);
        $this->test('Save writes a new domain row', function () use ($validDomain) {
            return $this->domainCount($validDomain) === 1;
        });

        // Duplicate domain must be caught with a localized message, not the
        // database unique-key violation (K-E5b).
        $dup = $this->saveDomain(['domain' => $validDomain, 'title' => 'CI dup']);
        $this->test('Duplicate domain shows the localized "already monitored" message', function () use ($dup, $validDomain) {
            return $this->containsString($dup['body'], 'COM_DMARCREPORTS_DOMAIN_ERROR_DUPLICATE')
                && !$this->hasSqlError($dup['body'])
                && $this->domainCount($validDomain) === 1;
        });

        // Over-long note must be caught by validation, not a raw SQL truncation
        // error (K-E5a).
        $longDomain = $this->tag . '-long.example';
        $long = $this->saveDomain(['domain' => $longDomain, 'title' => 'CI long', 'notes' => str_repeat('a', 70000)]);
        $this->test('Over-long domain note is rejected with a localized message', function () use ($long, $longDomain) {
            return $this->containsString($long['body'], 'COM_DMARCREPORTS_DOMAIN_ERROR_NOTES_TOO_LONG')
                && !$this->hasSqlError($long['body'])
                && $this->domainCount($longDomain) === 0;
        });

        // --- Source edit form renders + saves --------------------------------
        $sourceForm = $this->request($this->baseUrl . '/administrator/index.php?option=com_dmarcreports&task=source.add', [], true);
        $this->token = $this->extractToken($sourceForm['body']) ?: $this->token;

        $this->test('Source edit form renders the port field and a token', function () use ($sourceForm) {
            return $sourceForm['code'] === 200
                && strpos($sourceForm['body'], 'jform[port]') !== false
                && $this->extractToken($sourceForm['body']) !== null;
        });

        $validSource = $this->tag . ' source';
        $this->saveSource(['name' => $validSource, 'port' => '993']);
        $this->test('Save writes a new source row', function () use ($validSource) {
            return $this->sourceCount($validSource) === 1;
        });

        // Server-side port bounds: 0, 70000 and "abc" must all be rejected with
        // the localized message and must never create a row (K-E5c).
        foreach (['0', '70000', 'abc'] as $badPort) {
            $name = $this->tag . ' badport ' . $badPort;
            $res  = $this->saveSource(['name' => $name, 'port' => $badPort]);
            $this->test("Source port \"{$badPort}\" is rejected with the localized message", function () use ($res, $name) {
                return $this->containsString($res['body'], 'COM_DMARCREPORTS_SOURCE_ERROR_INVALID_PORT')
                    && !$this->hasSqlError($res['body'])
                    && $this->sourceCount($name) === 0;
            });
        }

        $this->cleanup();

        echo "\n=== Config + Edit Form Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /**
     * Post a domain.save with the given jform overrides and follow the redirect
     * so an enqueued validation message is visible in the returned body.
     *
     * @return array{code:int, body:string}
     */
    private function saveDomain(array $fields): array
    {
        $jform = array_merge([
            'id'              => '0',
            'domain'          => '',
            'title'           => '',
            'expected_policy' => 'reject',
            'notes'           => '',
            'published'       => '1',
        ], $fields);

        return $this->postForm('domain.save', $jform);
    }

    /**
     * Post a source.save with the given jform overrides and follow the redirect.
     *
     * @return array{code:int, body:string}
     */
    private function saveSource(array $fields): array
    {
        $jform = array_merge([
            'id'                  => '0',
            'name'                => '',
            'domain_id'           => '0',
            'delete_after_import' => '0',
            'protocol'            => 'imap',
            'host'                => 'imap.example.org',
            'port'                => '993',
            'security'            => 'ssl',
            'username'            => 'ci@example.org',
            'password'            => 'ci-secret',
            'folder'              => 'INBOX',
            'published'           => '1',
        ], $fields);

        return $this->postForm('source.save', $jform);
    }

    /**
     * @return array{code:int, body:string}
     */
    private function postForm(string $task, array $jform): array
    {
        $post = ['option' => 'com_dmarcreports', 'task' => $task];

        foreach ($jform as $key => $value) {
            $post["jform[{$key}]"] = $value;
        }

        if ($this->token !== null) {
            $post[$this->token] = '1';
        }

        return $this->request($this->baseUrl . '/administrator/index.php', $post, true);
    }

    private function domainCount(string $domain): int
    {
        $escaped = $this->db->real_escape_string($domain);
        $result  = $this->db->query("SELECT COUNT(*) AS c FROM {$this->dbPrefix}dmarc_domains WHERE domain = '{$escaped}'");
        $row     = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['c'] : 0;
    }

    private function sourceCount(string $name): int
    {
        $escaped = $this->db->real_escape_string($name);
        $result  = $this->db->query("SELECT COUNT(*) AS c FROM {$this->dbPrefix}dmarc_sources WHERE name = '{$escaped}'");
        $row     = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['c'] : 0;
    }

    /**
     * True when the page shows the given language key's English text. The key is
     * resolved against the installed en-GB file so the assertion follows the
     * wording without hard-coding it here.
     */
    private function containsString(string $body, string $key): bool
    {
        $text = $this->strings[$key] ?? '';

        if ($text === '') {
            return false;
        }

        // Messages can be HTML-escaped in the alert markup; compare on a
        // decoded, tag-free copy of the page.
        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return strpos($plain, $text) !== false || strpos($body, $key) !== false;
    }

    private function hasSqlError(string $body): bool
    {
        return (bool) preg_match('/Duplicate entry|SQLSTATE|Data too long|1062|1406|You have an error in your SQL/i', $body);
    }

    private function hasErrorMarker(string $body): bool
    {
        $stripped = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $body);

        return (bool) preg_match('/\bFatal error\b|\bParse error\b|Call to undefined|Uncaught\s|View not found|An error has occurred/i', $stripped);
    }

    /**
     * Read the installed en-GB strings so assertions follow the shipped wording.
     *
     * @return array<string,string>
     */
    private function readStrings(): array
    {
        $file = '/var/www/html/administrator/language/en-GB/com_dmarcreports.ini';

        if (!is_readable($file)) {
            return [];
        }

        $parsed = @parse_ini_file($file, false, INI_SCANNER_RAW);

        return \is_array($parsed) ? $parsed : [];
    }

    private function request(string $url, array $post = [], bool $follow = false): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => $follow,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
        ]);

        if ($post) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => $body === false ? '' : $body];
    }

    private function extractToken(string $html): ?string
    {
        if (preg_match('/name="([a-f0-9]{32})" value="1"/', $html, $m)) {
            return $m[1];
        }

        if (preg_match('/"csrf\.token":"([a-f0-9]{32})"/', $html, $m)) {
            return $m[1];
        }

        if (preg_match('/[?&;]([a-f0-9]{32})=1/', $html, $m)) {
            return $m[1];
        }

        return null;
    }

    private function login(): bool
    {
        @unlink($this->cookieJar);

        $token = null;

        for ($i = 0; $i < 5; $i++) {
            $r     = $this->request($this->baseUrl . '/administrator/index.php', [], true);
            $token = $this->extractToken($r['body']);

            if ($token !== null) {
                break;
            }

            sleep(2);
        }

        if ($token === null) {
            return false;
        }

        $r = $this->request($this->baseUrl . '/administrator/index.php', [
            'username' => $this->adminUser,
            'passwd'   => $this->adminPass,
            'option'   => 'com_login',
            'task'     => 'login',
            'return'   => base64_encode('index.php'),
            $token     => '1',
        ], true);

        $this->token = $this->extractToken($r['body']) ?: $token;

        return str_contains($r['body'], 'task=logout') || str_contains($r['body'], 'com_cpanel');
    }

    private function cleanup(): void
    {
        $tag = $this->db->real_escape_string($this->tag);
        $this->db->query("DELETE FROM {$this->dbPrefix}dmarc_sources WHERE name LIKE '{$tag}%'");
        $this->db->query("DELETE FROM {$this->dbPrefix}dmarc_domains WHERE domain LIKE '{$tag}%'");
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Exception $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new ConfigFormsTest();
exit($test->run() ? 0 : 1);
