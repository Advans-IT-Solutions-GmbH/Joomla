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
 *     note, port 0 / 70000 / "abc" / "50.5" / "1e2") and asserts each is rejected
 *     with the component's own localized message and never reaches the database
 *     as a raw SQL error.
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

        // --- com_config Options screen actually saves + validates (K-E1c) -----
        // GET-only coverage would stay green even if Save were dead or the
        // server-side bounds never ran, so submit the Options form: a valid
        // change must persist and representative out-of-range / invalid-option
        // values must be rejected without altering the stored params.
        $this->token    = $this->extractToken($options['body']) ?: $this->token;
        $configTask     = $this->extractApplyTask($options['body']) ?: 'component.apply';
        $extensionId    = $this->extensionId();
        $paramsSnapshot = $this->readExtensionParams();

        $validConfig = [
            'default_policy_target'     => 'reject',
            'quarantine_pass_threshold' => '95',
            'reject_pass_threshold'     => '99',
            'imap_timeout'              => '20',
            'max_messages_per_run'      => '200',
            'csv_delimiter'             => 'semicolon',
            'dns_diagnostics'           => '0',
        ];

        foreach ([
            'quarantine_pass_threshold below the minimum (0)'   => ['quarantine_pass_threshold' => '0'],
            'quarantine_pass_threshold above the maximum (101)' => ['quarantine_pass_threshold' => '101'],
            'quarantine_pass_threshold non-numeric ("abc")'     => ['quarantine_pass_threshold' => 'abc'],
            'quarantine_pass_threshold with decimals ("50.5")'  => ['quarantine_pass_threshold' => '50.5'],
            'quarantine_pass_threshold in exponent form ("1e2")' => ['quarantine_pass_threshold' => '1e2'],
            'default_policy_target with an invalid option'      => ['default_policy_target' => 'bogus'],
        ] as $label => $override) {
            $res = $this->saveConfig($configTask, $extensionId, array_merge($validConfig, $override));
            $this->test("Options save rejects {$label} and preserves the stored params", function () use ($res, $paramsSnapshot) {
                return !$this->hasSqlError($res['body'])
                    && $this->readExtensionParams() === $paramsSnapshot;
            });
        }

        // A valid change must actually persist through the Save path.
        $this->saveConfig($configTask, $extensionId, array_merge($validConfig, ['quarantine_pass_threshold' => '77']));
        $this->test('Options save persists a valid quarantine_pass_threshold change', function () {
            $params = json_decode($this->readExtensionParams() ?: '{}', true);

            return \is_array($params) && (string) ($params['quarantine_pass_threshold'] ?? '') === '77';
        });

        $this->restoreExtensionParams($paramsSnapshot);

        // --- Domain edit form renders + saves --------------------------------
        $domainForm = $this->request($this->baseUrl . '/administrator/index.php?option=com_dmarcreports&task=domain.add', [], true);
        $this->token = $this->extractToken($domainForm['body']) ?: $this->token;

        $this->test('Domain edit form renders the domain field and a token', function () use ($domainForm) {
            return $domainForm['code'] === 200
                && strpos($domainForm['body'], 'jform[domain]') !== false
                && $this->extractToken($domainForm['body']) !== null;
        });

        // A legacy/hand-edited default_policy_target that predates the config
        // range rule (e.g. "drop") must not leak into the add form: DomainModel
        // clamps it so the new-domain default is the configured reject fallback,
        // not the first list option. Write the invalid value straight into the
        // stored params (com_config would reject it) and reload the add form.
        $policySnapshot = $this->readExtensionParams();
        $this->writeExtensionParamValue('default_policy_target', 'drop');
        $clampForm   = $this->request($this->baseUrl . '/administrator/index.php?option=com_dmarcreports&task=domain.add', [], true);
        $this->token = $this->extractToken($clampForm['body']) ?: $this->token;
        $this->restoreExtensionParams($policySnapshot);

        $this->test('Invalid persisted default policy falls back to reject on the add form', function () use ($clampForm) {
            return $clampForm['code'] === 200
                && preg_match('/value="reject"[^>]*selected/i', $clampForm['body']) === 1
                && preg_match('/value="none"[^>]*selected/i', $clampForm['body']) !== 1;
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

        // --- CSV export emits a UTF-8 BOM and honours the configured delimiter
        // (K-E6b). The export writes the BOM and the header row regardless of
        // how many records a domain has, so a freshly created domain is enough
        // to prove both without seeding record rows.
        $exportDomainId = $this->domainId($validDomain);

        $origParams = $this->readExtensionParams();

        $this->setCsvDelimiter('semicolon');
        $csvSemicolon = $this->exportRecords($exportDomainId);
        $this->test('CSV export starts with a UTF-8 BOM', function () use ($csvSemicolon) {
            return strncmp($csvSemicolon['body'], "\xEF\xBB\xBF", 3) === 0;
        });
        $this->test('CSV export uses the configured semicolon delimiter', function () use ($csvSemicolon) {
            $header = $this->firstLine($csvSemicolon['body']);
            return strpos($header, 'org_name;date_begin') !== false;
        });

        $this->setCsvDelimiter('tab');
        $csvTab = $this->exportRecords($exportDomainId);
        $this->test('CSV export honours a reconfigured tab delimiter', function () use ($csvTab) {
            $header = $this->firstLine($csvTab['body']);
            return strncmp($csvTab['body'], "\xEF\xBB\xBF", 3) === 0
                && strpos($header, "org_name\tdate_begin") !== false;
        });

        $this->restoreExtensionParams($origParams);

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
        // the localized message and must never create a row (K-E5c). The decimal
        // and exponent forms guard the non-coercing raw filter: with
        // filter="integer" Joomla would round "50.5"/"1e2" into range before
        // validation and silently accept them.
        foreach (['0', '70000', 'abc', '50.5', '1e2'] as $badPort) {
            $name = $this->tag . ' badport ' . $badPort;
            $res  = $this->saveSource(['name' => $name, 'port' => $badPort]);
            $this->test("Source port \"{$badPort}\" is rejected with the localized message", function () use ($res, $name) {
                return $this->containsString($res['body'], 'COM_DMARCREPORTS_SOURCE_ERROR_INVALID_PORT')
                    && !$this->hasSqlError($res['body'])
                    && $this->sourceCount($name) === 0;
            });
        }

        // --- Manual upload surfaces a reason AND counts the rejection --------
        // (K-E4a). A container-level rejection — empty file, wrong format, a
        // DOCTYPE/XXE-class document, or a decompression bomb — imports nothing,
        // but it must still reach the operator with its specific localized
        // reason and count as exactly one failure in the summary. An upload that
        // reported "0 failed" next to a rejection reason would hide that the
        // file was refused, so each case asserts both the reason text and the
        // one-failure summary. Only this authenticated path exercises
        // ingest.upload end to end, so a regression in either is caught here.
        $dashboard   = $this->request($this->baseUrl . '/administrator/index.php?option=com_dmarcreports&view=dashboard', [], true);
        $this->token = $this->extractToken($dashboard['body']) ?: $this->token;

        $this->test('Dashboard view renders the manual upload form', function () use ($dashboard) {
            return $dashboard['code'] === 200
                && !$this->hasErrorMarker($dashboard['body'])
                && strpos($dashboard['body'], 'report_file') !== false;
        });

        $uploads = [
            'empty file'              => ['content' => '', 'name' => 'report.xml', 'reason' => 'COM_DMARCREPORTS_UPLOAD_REASON_EMPTY'],
            'wrong-format file'       => ['content' => 'this is not a dmarc report', 'name' => 'report.xml', 'reason' => 'COM_DMARCREPORTS_UPLOAD_REASON_FORMAT'],
            'malformed DOCTYPE report' => ['content' => '<?xml version="1.0"?><!DOCTYPE foo><feedback></feedback>', 'name' => 'report.xml', 'reason' => 'COM_DMARCREPORTS_UPLOAD_REASON_MALFORMED'],
        ];

        // A gzip whose decompressed size exceeds the archive ceiling must be
        // refused as a bomb. Build it only when the test host can gzip; the
        // Joomla container decodes gzip, so a missing encoder here is a test-host
        // limitation, not a product gap.
        if (\function_exists('gzencode')) {
            $uploads['decompression bomb'] = [
                'content' => gzencode(str_repeat('A', 9 * 1024 * 1024)),
                'name'    => 'report.xml.gz',
                'reason'  => 'COM_DMARCREPORTS_UPLOAD_REASON_BOMB',
            ];
        }

        $oneFailure = $this->expectedSummary(0, 0, 1, 0);

        foreach ($uploads as $label => $upload) {
            $res = $this->uploadReport($upload['content'], $upload['name']);
            $this->test("Upload of an {$label} reports its reason and one failure", function () use ($res, $upload, $oneFailure) {
                return $res['code'] === 200
                    && !$this->hasErrorMarker($res['body'])
                    && !$this->hasSqlError($res['body'])
                    && $this->containsString($res['body'], $upload['reason'])
                    && $oneFailure !== ''
                    && $this->pageShows($res['body'], $oneFailure);
            });
        }

        $this->cleanup();
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

    private function domainId(string $domain): int
    {
        $escaped = $this->db->real_escape_string($domain);
        $result  = $this->db->query("SELECT id FROM {$this->dbPrefix}dmarc_domains WHERE domain = '{$escaped}' LIMIT 1");
        $row     = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['id'] : 0;
    }

    /** Current params JSON of the com_dmarcreports extension row (empty string if none). */
    private function readExtensionParams(): string
    {
        $result = $this->db->query("SELECT params FROM {$this->dbPrefix}extensions WHERE element = 'com_dmarcreports' AND type = 'component' LIMIT 1");
        $row    = $result ? $result->fetch_assoc() : null;

        return $row ? (string) $row['params'] : '';
    }

    /** Numeric extension_id of the com_dmarcreports component row. */
    private function extensionId(): int
    {
        $result = $this->db->query("SELECT extension_id FROM {$this->dbPrefix}extensions WHERE element = 'com_dmarcreports' AND type = 'component' LIMIT 1");
        $row    = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['extension_id'] : 0;
    }

    /**
     * Extract the com_config "apply" toolbar task from the Options page so the
     * save POST uses the exact task the shipped UI wires up, rather than a task
     * name hard-coded to one Joomla version.
     */
    private function extractApplyTask(string $html): ?string
    {
        if (preg_match("/Joomla\\.submitbutton\\('([^']+\\.apply)'\\)/", $html, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Submit the component's com_config Options form and follow the redirect so
     * any validation message is visible in the returned body.
     *
     * com_config renders a component's config.xml fields flat under the jform
     * control (jform[field]) and stores the validated set as the extension's
     * params, so the overrides are posted as jform[field], not jform[params][field].
     *
     * @param   array<string, string>  $params  jform field overrides to post.
     *
     * @return array{code:int, body:string}
     */
    private function saveConfig(string $task, int $extensionId, array $params): array
    {
        $post = [
            'option'    => 'com_config',
            'task'      => $task,
            'id'        => (string) $extensionId,
            'component' => 'com_dmarcreports',
        ];

        foreach ($params as $key => $value) {
            $post["jform[{$key}]"] = $value;
        }

        if ($this->token !== null) {
            $post[$this->token] = '1';
        }

        return $this->request($this->baseUrl . '/administrator/index.php', $post, true);
    }

    /** Set the csv_delimiter component param, preserving any other params. */
    private function setCsvDelimiter(string $value): void
    {
        $params = json_decode($this->readExtensionParams() ?: '{}', true);

        if (!\is_array($params)) {
            $params = [];
        }

        $params['csv_delimiter'] = $value;
        $json                    = $this->db->real_escape_string(json_encode($params));
        $this->db->query("UPDATE {$this->dbPrefix}extensions SET params = '{$json}' WHERE element = 'com_dmarcreports' AND type = 'component'");
    }

    private function restoreExtensionParams(string $params): void
    {
        $json = $this->db->real_escape_string($params);
        $this->db->query("UPDATE {$this->dbPrefix}extensions SET params = '{$json}' WHERE element = 'com_dmarcreports' AND type = 'component'");
    }

    /**
     * Set a single stored param to a value com_config would never persist (to
     * simulate a legacy/hand-edited row), leaving the other params intact.
     */
    private function writeExtensionParamValue(string $key, string $value): void
    {
        $params = json_decode($this->readExtensionParams(), true);

        if (!\is_array($params)) {
            $params = [];
        }

        $params[$key] = $value;
        $this->restoreExtensionParams((string) json_encode($params));
    }

    /** First line of a body, dropping a leading UTF-8 BOM if present. */
    private function firstLine(string $body): string
    {
        if (strncmp($body, "\xEF\xBB\xBF", 3) === 0) {
            $body = substr($body, 3);
        }

        $end = strcspn($body, "\r\n");

        return substr($body, 0, $end);
    }

    /**
     * GET the CSV export for a domain, carrying the CSRF token in the query as
     * export.records enforces it with checkToken('get').
     *
     * @return array{code:int, body:string}
     */
    private function exportRecords(int $domainId): array
    {
        $url = $this->baseUrl . '/administrator/index.php?option=com_dmarcreports&task=export.records&domain_id=' . $domainId;

        if ($this->token !== null) {
            $url .= '&' . $this->token . '=1';
        }

        return $this->request($url, [], false);
    }

    /**
     * True when the page shows the given language key's English text. The key is
     * resolved against the installed en-GB file so the assertion follows the
     * wording without hard-coding it here. The resolved text is required: an
     * untranslated raw key on the page is not accepted, so a language-load
     * regression on the validation redirect fails the test instead of passing on
     * the bare key.
     */
    private function containsString(string $body, string $key): bool
    {
        $text = $this->strings[$key] ?? '';

        if ($text === '') {
            return false;
        }

        return $this->pageShows($body, $text);
    }

    /**
     * True when the given literal text appears on the page. Messages can be
     * HTML-escaped in the alert markup, so compare on a decoded, tag-free copy.
     */
    private function pageShows(string $body, string $text): bool
    {
        if ($text === '') {
            return false;
        }

        $plain = html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return strpos($plain, $text) !== false;
    }

    /**
     * Render the installed ingest-summary string for the given counters so the
     * upload assertions follow the shipped wording instead of hard-coding it.
     */
    private function expectedSummary(int $imported, int $duplicates, int $failed, int $skipped): string
    {
        $template = $this->strings['COM_DMARCREPORTS_INGEST_SUMMARY'] ?? '';

        if ($template === '') {
            return '';
        }

        return sprintf($template, $imported, $duplicates, $failed, $skipped);
    }

    /**
     * POST a manually uploaded report file to ingest.upload as a real multipart
     * upload (so is_uploaded_file() holds server-side) and follow the redirect
     * so the enqueued summary and reason message are visible in the body.
     *
     * @return array{code:int, body:string}
     */
    private function uploadReport(string $content, string $filename): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dmarc-upload-');
        file_put_contents($tmp, $content);

        $post = [
            'option'      => 'com_dmarcreports',
            'task'        => 'ingest.upload',
            'report_file' => new \CURLFile($tmp, 'application/octet-stream', $filename),
        ];

        if ($this->token !== null) {
            $post[$this->token] = '1';
        }

        $res = $this->requestMultipart($this->baseUrl . '/administrator/index.php', $post);
        @unlink($tmp);

        return $res;
    }

    /**
     * Issue a multipart/form-data POST. curl switches to multipart when the
     * post array holds a CURLFile, so the fields are passed as an array rather
     * than url-encoded.
     *
     * @param   array<string, mixed>  $post
     *
     * @return array{code:int, body:string}
     */
    private function requestMultipart(string $url, array $post): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $post,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['code' => $code, 'body' => $body === false ? '' : $body];
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
