<?php
/**
 * Update / data-preservation tests for the DMARC Reports package.
 *
 * Reinstalling the package over an existing installation must behave as an
 * update: it must not drop the component tables and must not lose the stored
 * domains or the encrypted IMAP source credentials, and it must not create
 * duplicate extension registrations. Before the update each bundled extension
 * is installed standalone with its own update server; the package update must
 * leave only the package's update site.
 */

require_once __DIR__ . '/shared-database-check-lib.php';

class UpdateTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;
    private $marker = 'pkg-update-test.example';
    private $secret = 'ENC::preserve-me-across-update';

    /**
     * The bundled extensions: their archive inside the package, the standalone
     * update server their own repository manifest declares and the
     * #__extensions filter that identifies them.
     */
    private const CHILDREN = [
        'component' => [
            'archive' => 'packages/com_dmarcreports.zip',
            'url'     => 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/com_dmarcreports/updates/update.xml',
            'where'   => "type = 'component' AND element = 'com_dmarcreports'",
        ],
        'module' => [
            'archive' => 'packages/mod_dmarcreports.zip',
            'url'     => 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/mod_dmarcreports/updates/update.xml',
            'where'   => "type = 'module' AND element = 'mod_dmarcreports'",
        ],
        'task plugin' => [
            'archive' => 'packages/plg_task_dmarcreports.zip',
            'url'     => 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/plg_task_dmarcreports/updates/update.xml',
            'where'   => "type = 'plugin' AND folder = 'task' AND element = 'dmarcreports'",
        ],
        'system plugin' => [
            'archive' => 'packages/plg_system_dmarcmtasts.zip',
            'url'     => 'https://raw.githubusercontent.com/Advans-IT-Solutions-GmbH/Joomla/main/plg_system_dmarcmtasts/updates/update.xml',
            'where'   => "type = 'plugin' AND folder = 'system' AND element = 'dmarcmtasts'",
        ],
    ];

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Package Update Tests ===\n\n";

        // Seed data that must survive an update.
        $domain = $this->db->real_escape_string($this->marker);
        $secret = $this->db->real_escape_string($this->secret);
        $this->db->query("INSERT INTO {$this->dbPrefix}dmarc_domains (domain, title, published, created) VALUES ('$domain', 'Update marker', 1, NOW())");
        $domainId = (int) $this->db->insert_id;
        $this->db->query("INSERT INTO {$this->dbPrefix}dmarc_sources (name, domain_id, host, username, password_enc, published, created) VALUES ('Update marker source', $domainId, 'imap.example', 'reporter@example', '$secret', 1, NOW())");

        $this->test('Seed data inserted', function () use ($domainId) {
            return $domainId > 0;
        });

        $zip = file_exists('/var/www/html/tmp/extension.zip') ? '/var/www/html/tmp/extension.zip' : '/tmp/extension.zip';

        // Reproduce a site that used the extensions on their own before the
        // package: install each bundled extension standalone, from a manifest
        // that carries its own update server, so Joomla links it to that
        // independent update stream.
        foreach (self::CHILDREN as $label => $child) {
            $this->test("Standalone $label installed with its own update server", function () use ($zip, $child) {
                return $this->installStandaloneChild($zip, $child['archive'], $child['url']);
            });
            $this->test("Standalone $label linked to its own update site", function () use ($child) {
                return $this->linkCount($child['where']) > 0 && $this->siteCount($child['url']) > 0;
            });
        }

        // A site installed before the manifest rename still has com_dmarcreports.xml,
        // and the maintenance:database --fix workaround emptied the registered version.
        $admin = '/var/www/html/administrator/components/com_dmarcreports';
        copy($admin . '/dmarcreports.xml', $admin . '/com_dmarcreports.xml');
        $this->db->query("UPDATE {$this->dbPrefix}extensions SET manifest_cache = JSON_SET(manifest_cache, '$.version', '')"
            . " WHERE type = 'component' AND element = 'com_dmarcreports'");
        // Reinstall the same package archive (update path).
        $output = [];
        $exitCode = 0;
        exec("HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=$zip 2>&1", $output, $exitCode);

        $this->test('Reinstall (update) command executed', function () use ($exitCode, $output) {
            if ($exitCode !== 0) {
                echo '    ' . implode("\n    ", $output) . "\n";
            }
            return $exitCode === 0;
        });

        $this->test('Component tables still present after update', function () {
            foreach (['dmarc_domains', 'dmarc_sources', 'dmarc_reports', 'dmarc_tlsrpt_reports'] as $table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");
                if (!$result || $result->num_rows === 0) {
                    return false;
                }
            }
            return true;
        });

        $this->test('Stored domain preserved after update', function () {
            $domain = $this->db->real_escape_string($this->marker);
            $result = $this->db->query("SELECT id FROM {$this->dbPrefix}dmarc_domains WHERE domain = '$domain'");
            return $result && $result->num_rows === 1;
        });

        $this->test('Encrypted source credentials preserved after update', function () {
            $secret = $this->db->real_escape_string($this->secret);
            $result = $this->db->query("SELECT id FROM {$this->dbPrefix}dmarc_sources WHERE password_enc = '$secret'");
            return $result && $result->num_rows === 1;
        });

        foreach ([
            'package' => "type = 'package' AND element = 'pkg_dmarcreports'",
            'component' => "type = 'component' AND element = 'com_dmarcreports'",
            'module' => "type = 'module' AND element = 'mod_dmarcreports'",
            'task plugin' => "type = 'plugin' AND folder = 'task' AND element = 'dmarcreports'",
            'system plugin' => "type = 'plugin' AND folder = 'system' AND element = 'dmarcmtasts'",
        ] as $label => $where) {
            $this->test("Exactly one $label registration (no duplicate)", function () use ($where) {
                $result = $this->db->query("SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}extensions WHERE $where");
                $row = $result ? $result->fetch_assoc() : null;
                return $row && (int) $row['cnt'] === 1;
            });
        }

        // The package is the only update channel: every standalone link and the
        // update sites left orphaned by removing them are gone, and the package
        // keeps exactly its own update site.
        foreach (self::CHILDREN as $label => $child) {
            $this->test("No update-site link left on the $label", function () use ($child) {
                return $this->linkCount($child['where']) === 0;
            });
            $this->test("Standalone $label update site removed", function () use ($child) {
                return $this->siteCount($child['url']) === 0;
            });
        }

        $this->test('Package keeps exactly its own update site', function () {
            $result = $this->db->query(
                "SELECT us.location FROM {$this->dbPrefix}update_sites_extensions AS ue"
                . " INNER JOIN {$this->dbPrefix}update_sites AS us ON us.update_site_id = ue.update_site_id"
                . " INNER JOIN {$this->dbPrefix}extensions AS e ON e.extension_id = ue.extension_id"
                . " WHERE e.type = 'package' AND e.element = 'pkg_dmarcreports'"
            );
            $rows = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

            return count($rows) === 1
                && str_ends_with(trim($rows[0]['location']), '/pkg_dmarcreports/updates/update.xml');
        });

        $this->test('Joomla database check reports DMARC Reports as up to date after the update', function () {
            $problems = joomla_component_database_problems($this->db, $this->dbPrefix, 'com_dmarcreports');
            foreach ($problems as $problem) {
                echo "    {$problem}\n";
            }

            return $problems === [];
        });
        echo "\n=== Package Update Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /**
     * Build a standalone archive of one bundled extension (the package ships it
     * without <updateservers>), give its manifest back the standalone update
     * server and install it through the Joomla CLI.
     */
    private function installStandaloneChild(string $packageZip, string $archive, string $url): bool
    {
        // Stage inside the site's tmp folder, where the package itself is
        // installed from, so the Joomla CLI can read the archive.
        $work = '/var/www/html/tmp/pkg-standalone-' . bin2hex(random_bytes(4));
        if (!mkdir($work) && !is_dir($work)) {
            return false;
        }
        $childZip = $work . '/child.zip';

        $package = new ZipArchive();
        if ($package->open($packageZip) !== true) {
            return false;
        }
        $bytes = $package->getFromName($archive);
        $package->close();
        if ($bytes === false || file_put_contents($childZip, $bytes) === false) {
            return false;
        }

        $child = new ZipArchive();
        if ($child->open($childZip) !== true) {
            return false;
        }

        $patched = false;
        for ($i = 0; $i < $child->numFiles; $i++) {
            $name = (string) $child->getNameIndex($i);
            if (strpos($name, '/') !== false || substr($name, -4) !== '.xml') {
                continue;
            }
            $xml = (string) $child->getFromIndex($i);
            if (strpos($xml, '<extension') === false || strpos($xml, '<updateservers>') !== false) {
                continue;
            }
            $block = "    <updateservers>\n"
                . '        <server type="extension" name="Standalone updates">' . $url . "</server>\n"
                . "    </updateservers>\n";
            $child->addFromString($name, str_replace('</extension>', $block . '</extension>', $xml));
            $patched = true;
        }
        $child->close();

        if (!$patched) {
            echo "    no manifest found in $archive\n";
            return false;
        }

        $output = [];
        $exitCode = 0;
        exec('HTTP_HOST=localhost php /var/www/html/cli/joomla.php extension:install --path=' . escapeshellarg($childZip) . ' 2>&1', $output, $exitCode);
        @unlink($childZip);
        @rmdir($work);

        if ($exitCode !== 0) {
            echo '    ' . implode("\n    ", $output) . "\n";
        }

        return $exitCode === 0;
    }

    /**
     * Number of update-site links of the extension matching $where.
     */
    private function linkCount(string $where): int
    {
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}update_sites_extensions"
            . " WHERE extension_id IN (SELECT extension_id FROM {$this->dbPrefix}extensions WHERE $where)"
        );
        $row = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['cnt'] : -1;
    }

    /**
     * Number of update sites registered for the given location.
     */
    private function siteCount(string $url): int
    {
        $url = $this->db->real_escape_string($url);
        $result = $this->db->query(
            "SELECT COUNT(*) AS cnt FROM {$this->dbPrefix}update_sites WHERE TRIM(location) = '$url'"
        );
        $row = $result ? $result->fetch_assoc() : null;

        return $row ? (int) $row['cnt'] : -1;
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

$test = new UpdateTest();
exit($test->run() ? 0 : 1);
