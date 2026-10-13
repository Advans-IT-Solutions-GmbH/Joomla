<?php
/**
 * Structure tests for the DMARC Reports component: the database schema and the
 * key source files shipped in the installed package.
 */

class StructureTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;
    private $base = '/var/www/html/administrator/components/com_dmarcreports';

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Structure Tests ===\n\n";

        foreach (['domains', 'sources', 'reports', 'records', 'actions'] as $table) {
            $this->test("Table #__dmarc_{$table} exists", function () use ($table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}dmarc_{$table}'");
                return $result && $result->num_rows === 1;
            });
        }

        $this->test('Reports table enforces a UNIQUE report_hash (idempotency)', function () {
            $result = $this->db->query("SHOW INDEX FROM {$this->dbPrefix}dmarc_reports WHERE Column_name = 'report_hash' AND Non_unique = 0");
            return $result && $result->num_rows > 0;
        });

        $this->test('Sources table stores the encrypted password column', function () {
            $result = $this->db->query("SHOW COLUMNS FROM {$this->dbPrefix}dmarc_sources LIKE 'password_enc'");
            return $result && $result->num_rows === 1;
        });

        $files = [
            'DmarcParser helper'   => $this->base . '/src/Helper/DmarcParser.php',
            'ReportArchive helper' => $this->base . '/src/Helper/ReportArchive.php',
            'MimeMessage helper'   => $this->base . '/src/Helper/MimeMessage.php',
            'ActionCenter helper'  => $this->base . '/src/Helper/ActionCenter.php',
            'DnsDiagnostics helper' => $this->base . '/src/Helper/DnsDiagnostics.php',
            'MtaStsPolicy helper'  => $this->base . '/src/Helper/MtaStsPolicy.php',
            'MtaStsDiagnostics helper' => $this->base . '/src/Helper/MtaStsDiagnostics.php',
            'PolicyFetcher iface'  => $this->base . '/src/Helper/PolicyFetcherInterface.php',
            'HttpPolicyFetcher helper' => $this->base . '/src/Helper/HttpPolicyFetcher.php',
            'TlsRptParser helper'  => $this->base . '/src/Helper/TlsRptParser.php',
            'Dashboard view'       => $this->base . '/src/View/Dashboard/HtmlView.php',
            'Install SQL'          => $this->base . '/sql/install.mysql.utf8.sql',
            'Uninstall SQL'        => $this->base . '/sql/uninstall.mysql.utf8.sql',
            'LICENSE.txt'          => $this->base . '/LICENSE.txt',
            'Options config.xml'   => $this->base . '/config.xml',
        ];

        foreach ($files as $label => $path) {
            $this->test("{$label} is installed", function () use ($path) {
                return file_exists($path);
            });
        }

        // config.xml must carry every option field so the com_config Options
        // screen can load them; the manifest <config> block is not read for a
        // component's global options. Assert the complete migrated field set so
        // a dropped field cannot ship an incomplete Options screen undetected.
        // The XPath is intentionally restricted to fields directly under a
        // <config>/<fieldset> so the check also fails if a manifest-style
        // <fields name="params"> wrapper is reintroduced, which com_config
        // cannot bind or persist for a standalone component.
        $this->test('config.xml defines every migrated option field', function () {
            $xml = @simplexml_load_file($this->base . '/config.xml');

            if ($xml === false) {
                return false;
            }

            $names = [];

            foreach ($xml->xpath('/config/fieldset/field[@name]') as $field) {
                $names[] = (string) $field['name'];
            }

            $expected = [
                'default_policy_target',
                'quarantine_pass_threshold',
                'reject_pass_threshold',
                'imap_timeout',
                'max_messages_per_run',
                'csv_delimiter',
                'dns_diagnostics',
                'rules',
            ];

            return \count(array_diff($expected, $names)) === 0;
        });

        // The edit layouts must load the form.validate web asset, otherwise
        // document.formvalidator is undefined and Save/Apply throw silently,
        // leaving the toolbar buttons dead.
        foreach (['source', 'domain'] as $editView) {
            $this->test("{$editView} edit layout loads the form validator script", function () use ($editView) {
                $contents = file_get_contents($this->base . "/tmpl/{$editView}/edit.php");

                return strpos($contents, "useScript('form.validate')") !== false;
            });
        }

        // The source domain picker must map the SQL label column explicitly,
        // otherwise Joomla's SqlField defaults value_field to the field name and
        // renders the stored id instead of the domain name.
        $this->test('source form maps the domain picker label column', function () {
            $contents = file_get_contents($this->base . '/forms/source.xml');

            return strpos($contents, 'value_field="text"') !== false;
        });

        // K-E1d: the domain picker's blank option must be the "Automatic" choice,
        // not Joomla's generic published-status placeholder.
        $this->test('source domain picker drops the published-status header', function () {
            $contents = file_get_contents($this->base . '/forms/source.xml');

            return strpos($contents, 'JOPTION_SELECT_PUBLISHED') === false
                && strpos($contents, 'COM_DMARCREPORTS_FIELD_DOMAIN_ID_AUTO') !== false;
        });

        // K-E1c / K-E5c: numeric options and the source port must carry a real
        // server-side range rule, not just the browser min/max, so an
        // out-of-range value posted directly is rejected before it is stored.
        $this->test('config.xml declares the range rule prefix', function () {
            $contents = file_get_contents($this->base . '/config.xml');

            return strpos($contents, 'addruleprefix="Advans\\Component\\DmarcReports\\Administrator\\Rule"') !== false;
        });

        foreach (['quarantine_pass_threshold', 'reject_pass_threshold', 'imap_timeout', 'max_messages_per_run'] as $numeric) {
            $this->test("config option {$numeric} validates server-side", function () use ($numeric) {
                $xml = @simplexml_load_file($this->base . '/config.xml');

                if ($xml === false) {
                    return false;
                }

                foreach ($xml->xpath('/config/fieldset/field[@name="' . $numeric . '"]') as $field) {
                    // A non-coercing filter is required so the range rule judges
                    // the lexical value the operator typed; filter="integer"
                    // would round "50.5"/"1e2" into range before validation.
                    return (string) $field['validate'] === 'range'
                        && (string) $field['filter'] === 'raw'
                        && (string) $field['message'] !== '';
                }

                return false;
            });
        }

        $this->test('source port validates server-side with a range rule', function () {
            $xml = @simplexml_load_file($this->base . '/forms/source.xml');

            if ($xml === false) {
                return false;
            }

            foreach ($xml->xpath('//field[@name="port"]') as $field) {
                return (string) $field['validate'] === 'range'
                    && (string) $field['filter'] === 'raw'
                    && (string) $field['message'] !== '';
            }

            return false;
        });

        // E1-B: the manifest must register a schema path and ship a baseline
        // schema file so #__schemas gets a row for future sql/updates.
        $this->test('manifest registers an update schema path', function () {
            $manifest = file_get_contents($this->base . '/com_dmarcreports.xml');

            return $manifest !== false
                && strpos($manifest, '<schemas>') !== false
                && strpos($manifest, 'sql/updates/mysql') !== false;
        });

        $this->test('a baseline schema update file ships', function () {
            return is_file($this->base . '/sql/updates/mysql/1.0.0.sql');
        });

        echo "\n=== Structure Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
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

$test = new StructureTest();
exit($test->run() ? 0 : 1);
