<?php
/**
 * Structure tests for the DMARC Reports dashboard module: the key source files
 * shipped in the installed package and the manifest metadata.
 */

class StructureTest
{
    private $passed = 0;
    private $failed = 0;
    private $base = '/var/www/html/administrator/modules/mod_dmarcreports';

    public function run(): bool
    {
        echo "=== Structure Tests ===\n\n";

        // Administrator modules install their <languages> into the shared
        // administrator language folder, not into the module directory.
        $lang = '/var/www/html/administrator/language';

        $files = [
            'Module manifest'      => $this->base . '/mod_dmarcreports.xml',
            'Installer script'     => $this->base . '/script.php',
            'Service provider'     => $this->base . '/services/provider.php',
            'Dispatcher'           => $this->base . '/src/Dispatcher/Dispatcher.php',
            'DmarcReports helper'  => $this->base . '/src/Helper/DmarcReportsHelper.php',
            'Default layout'       => $this->base . '/tmpl/default.php',
            'LICENSE.txt'          => $this->base . '/LICENSE.txt',
            'Language en-GB (.ini)'     => $lang . '/en-GB/mod_dmarcreports.ini',
            'Language en-GB (.sys.ini)' => $lang . '/en-GB/mod_dmarcreports.sys.ini',
            'Language de-DE (.ini)'     => $lang . '/de-DE/mod_dmarcreports.ini',
            'Language fr-FR (.ini)'     => $lang . '/fr-FR/mod_dmarcreports.ini',
        ];

        foreach ($files as $label => $path) {
            $this->test("{$label} is installed", function () use ($path) {
                return file_exists($path);
            });
        }

        $this->test('Manifest declares an administrator module element', function () {
            $xml = @simplexml_load_file($this->base . '/mod_dmarcreports.xml');

            if ($xml === false) {
                return false;
            }

            return (string) $xml['type'] === 'module'
                && (string) $xml['client'] === 'administrator'
                && (string) $xml->element === 'mod_dmarcreports';
        });

        $this->test('Manifest registers the module namespace', function () {
            $xml = @simplexml_load_file($this->base . '/mod_dmarcreports.xml');

            if ($xml === false) {
                return false;
            }

            return strpos((string) $xml->namespace, 'Advans\\Module\\DmarcReports') !== false;
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
