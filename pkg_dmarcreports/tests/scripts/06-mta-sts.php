<?php
/**
 * MTA-STS policy endpoint tests for the DMARC Reports package.
 *
 * The bundled system plugin (plg_system_dmarcmtasts) must serve the RFC 8461
 * policy document generated from the component parameters when a request
 * targets /.well-known/mta-sts.txt on an `mta-sts.` host. These tests enable the
 * plugin, configure the component's MTA-STS parameters and fetch the endpoint
 * through the running site to assert the policy is served (and only served) for
 * the right host, path and when the feature is enabled. Requests use the exact
 * RFC 8461 path, with the documented Apache rewrite rule installed first.
 */

class MtaStsEndpointTest
{
    /**
     * The exact path RFC 8461 §3.3 defines; senders request nothing else.
     */
    private const WELL_KNOWN_PATH = '/.well-known/mta-sts.txt';

    /**
     * The Apache rule from plg_system_dmarcmtasts/README.md (section
     * "Web-server rule"), kept identical so the test proves the documented
     * production setup.
     */
    private const REWRITE_COMMENT = '# MTA-STS (RFC 8461): let Joomla answer the policy request.';
    private const REWRITE_RULE    = 'RewriteRule ^\.well-known/mta-sts\.txt$ index.php [L]';

    /**
     * A stale static policy file that must never be served once the rule is in
     * place.
     */
    private const STATIC_FILE   = '/var/www/html/.well-known/mta-sts.txt';
    private const STATIC_MARKER = 'stale-static-policy-file';

    private $db;
    private $dbPrefix;
    private $passed = 0;
    private $failed = 0;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config         = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db       = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Package MTA-STS Endpoint Tests ===\n\n";

        $this->installRewriteRule();
        $this->enableSystemPlugin();
        $this->ensureMonitoredDomain('example.com', 1);
        $this->configureParams('testing', 'mail.example.com', 604800, 1);

        $served = $this->fetch('mta-sts.example.com');
        $this->test('policy served on the mta-sts host', str_contains($served, 'version: STSv1'), $served);
        $this->test('exact well-known path reached Joomla, not the static file', !str_contains($served, self::STATIC_MARKER), $served);
        $this->test('policy reports the configured mode', str_contains($served, 'mode: testing'));
        $this->test('policy reports the configured mx', str_contains($served, 'mx: mail.example.com'));
        $this->test('policy reports the configured max_age', str_contains($served, 'max_age: 604800'));

        $this->configureParams('enforce', 'mail.example.com', 604800, 1);
        $served  = $this->fetch('mta-sts.example.com');
        $this->test('mode change is reflected dynamically', str_contains($served, 'mode: enforce'));

        $wrongHost = $this->fetch('www.example.com');
        $this->test('policy not served on a non mta-sts host', !str_contains($wrongHost, 'version: STSv1'));
        $this->test('non mta-sts host request reached Joomla, not the static file', !str_contains($wrongHost, self::STATIC_MARKER), $wrongHost);

        $this->configureParams('testing', 'mail.example.com', 604800, 0);
        $disabled = $this->fetch('mta-sts.example.com');
        $this->test('policy not served while the feature is disabled', !str_contains($disabled, 'version: STSv1'));

        // The policy is generated from the global component parameters, so it
        // may only be served for a domain the administrator actually monitors
        // and has published. Re-enable the feature and prove the domain gate.
        $this->configureParams('testing', 'mail.example.com', 604800, 1);

        $this->ensureMonitoredDomain('example.com', 0);
        $unpublished = $this->fetch('mta-sts.example.com');
        $this->test('policy not served for an unpublished monitored domain', !str_contains($unpublished, 'version: STSv1'), $unpublished);

        $this->removeMonitoredDomain('example.com');
        $unmonitored = $this->fetch('mta-sts.example.com');
        $this->test('policy not served for a domain absent from the monitored table', !str_contains($unmonitored, 'version: STSv1'), $unmonitored);

        // Restore a clean state for the following suites.
        $this->removeMonitoredDomain('example.com');
        $this->configureParams('testing', '', 604800, 0);
        $this->disableSystemPlugin();
        @unlink(self::STATIC_FILE);

        echo "\n=== Package MTA-STS Endpoint Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    private function enableSystemPlugin(): void
    {
        $this->db->query(
            "UPDATE {$this->dbPrefix}extensions SET enabled = 1 "
            . "WHERE type = 'plugin' AND folder = 'system' AND element = 'dmarcmtasts'"
        );
        $result  = $this->db->query(
            "SELECT enabled FROM {$this->dbPrefix}extensions "
            . "WHERE type = 'plugin' AND folder = 'system' AND element = 'dmarcmtasts'"
        );
        $enabled = $result ? (int) ($result->fetch_assoc()['enabled'] ?? 0) : 0;
        $this->test('System plugin enabled', $enabled === 1);
    }

    private function disableSystemPlugin(): void
    {
        $this->db->query(
            "UPDATE {$this->dbPrefix}extensions SET enabled = 0 "
            . "WHERE type = 'plugin' AND folder = 'system' AND element = 'dmarcmtasts'"
        );
    }

    /**
     * Insert or update a monitored domain with the given published flag so the
     * domain gate in the system plugin can be exercised.
     */
    private function ensureMonitoredDomain(string $domain, int $published): void
    {
        $domain = $this->db->real_escape_string($domain);
        $this->db->query(
            "INSERT INTO {$this->dbPrefix}dmarc_domains (domain, published) "
            . "VALUES ('{$domain}', {$published}) "
            . "ON DUPLICATE KEY UPDATE published = VALUES(published)"
        );
    }

    /**
     * Remove a monitored domain so the "absent from the table" case is clean.
     */
    private function removeMonitoredDomain(string $domain): void
    {
        $domain = $this->db->real_escape_string($domain);
        $this->db->query(
            "DELETE FROM {$this->dbPrefix}dmarc_domains WHERE domain = '{$domain}'"
        );
    }

    /**
     * Merge the MTA-STS keys into the component's stored parameters.
     */
    private function configureParams(string $mode, string $mx, int $maxAge, int $enable): void
    {
        $result = $this->db->query(
            "SELECT params FROM {$this->dbPrefix}extensions "
            . "WHERE type = 'component' AND element = 'com_dmarcreports'"
        );
        $current = $result ? (string) ($result->fetch_assoc()['params'] ?? '') : '';
        $params  = json_decode($current !== '' ? $current : '{}', true);

        if (!is_array($params)) {
            $params = [];
        }

        $params['mtasts_enable']  = $enable;
        $params['mtasts_mode']    = $mode;
        $params['mtasts_mx']      = $mx;
        $params['mtasts_max_age'] = $maxAge;

        $json = $this->db->real_escape_string(json_encode($params));
        $this->db->query(
            "UPDATE {$this->dbPrefix}extensions SET params = '{$json}' "
            . "WHERE type = 'component' AND element = 'com_dmarcreports'"
        );
    }

    /**
     * Put the documented web-server rule (system plugin README) in front of
     * Joomla's own rewrite rules, exactly as production does. A stale static
     * policy file is placed at the well-known path as well: Joomla's generic
     * fallback only rewrites paths that are not existing files, so the static
     * file is served unless the dedicated rule is in effect. Every positive
     * assertion below therefore proves the request reached the plugin through
     * the documented rule, not through a file or an index.php route.
     */
    private function installRewriteRule(): void
    {
        if (!is_dir(dirname(self::STATIC_FILE))) {
            mkdir(dirname(self::STATIC_FILE), 0755, true);
        }
        file_put_contents(self::STATIC_FILE, self::STATIC_MARKER . "\n");

        $htaccess = '/var/www/html/.htaccess';

        if (!is_file($htaccess) && is_file('/var/www/html/htaccess.txt')) {
            copy('/var/www/html/htaccess.txt', $htaccess);
        }

        $content = is_file($htaccess) ? (string) file_get_contents($htaccess) : '';

        if (strpos($content, self::REWRITE_RULE) === false) {
            $block   = "\n" . self::REWRITE_COMMENT . "\n" . self::REWRITE_RULE . "\n";
            $patched = preg_replace_callback(
                '/^[ \t]*RewriteEngine[ \t]+On[ \t]*$/mi',
                static fn (array $m): string => $m[0] . $block,
                $content,
                1,
                $count
            );

            $content = ($count === 1 && is_string($patched))
                ? $patched
                : "RewriteEngine On\n" . $block . $content;

            file_put_contents($htaccess, $content);
        }

        $this->test(
            'Apache rule routes /.well-known/mta-sts.txt to Joomla',
            strpos((string) file_get_contents($htaccess), self::REWRITE_RULE) !== false
        );
    }

    /**
     * Fetch the exact RFC 8461 URL, https://mta-sts.<domain>/.well-known/mta-sts.txt,
     * through the running site for a given host (plain HTTP inside the test
     * container; the TLS step is the operator's). The path is the bare
     * well-known path, never an index.php route, so the test only passes when
     * the web server really hands that path to Joomla.
     */
    private function fetch(string $host): string
    {
        $output = [];
        exec(
            'curl -s -H ' . escapeshellarg('Host: ' . $host)
            . ' ' . escapeshellarg('http://localhost' . self::WELL_KNOWN_PATH),
            $output
        );

        return implode("\n", $output);
    }

    private function test(string $name, bool $condition, string $detail = ''): void
    {
        if ($condition) {
            echo "✓ {$name}\n";
            $this->passed++;
        } else {
            echo "✗ {$name}" . ($detail !== '' ? " — " . substr($detail, 0, 200) : '') . "\n";
            $this->failed++;
        }
    }
}

$test = new MtaStsEndpointTest();
exit($test->run() ? 0 : 1);
