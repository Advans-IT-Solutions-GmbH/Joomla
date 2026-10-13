<?php
/**
 * Structure tests for the DMARC MTA-STS policy system plugin: the key source
 * files shipped in the installed package and the manifest metadata.
 */

class StructureTest
{
    private $passed = 0;
    private $failed = 0;
    private $base = '/var/www/html/plugins/system/dmarcmtasts';

    public function run(): bool
    {
        echo "=== Structure Tests ===\n\n";

        $lang = $this->base . '/language';

        $files = [
            'Plugin manifest'       => $this->base . '/dmarcmtasts.xml',
            'Installer script'      => $this->base . '/script.php',
            'Service provider'      => $this->base . '/services/provider.php',
            'Plugin extension'      => $this->base . '/src/Extension/DmarcMtaSts.php',
            'PolicyRequest helper'  => $this->base . '/src/Support/PolicyRequest.php',
            'LICENSE.txt'           => $this->base . '/LICENSE.txt',
            'Language en-GB (.ini)'     => $lang . '/en-GB/plg_system_dmarcmtasts.ini',
            'Language en-GB (.sys.ini)' => $lang . '/en-GB/plg_system_dmarcmtasts.sys.ini',
            'Language de-DE (.ini)'     => $lang . '/de-DE/plg_system_dmarcmtasts.ini',
            'Language de-DE (.sys.ini)' => $lang . '/de-DE/plg_system_dmarcmtasts.sys.ini',
            'Language fr-FR (.ini)'     => $lang . '/fr-FR/plg_system_dmarcmtasts.ini',
            'Language fr-FR (.sys.ini)' => $lang . '/fr-FR/plg_system_dmarcmtasts.sys.ini',
        ];

        foreach ($files as $label => $path) {
            $this->test("{$label} is installed", function () use ($path) {
                return file_exists($path);
            });
        }

        $this->test('Manifest declares a system plugin element', function () {
            $xml = @simplexml_load_file($this->base . '/dmarcmtasts.xml');

            if ($xml === false) {
                return false;
            }

            return (string) $xml['type'] === 'plugin'
                && (string) $xml['group'] === 'system'
                && (string) $xml->element === 'dmarcmtasts';
        });

        $this->test('Manifest registers the plugin namespace', function () {
            $xml = @simplexml_load_file($this->base . '/dmarcmtasts.xml');

            if ($xml === false) {
                return false;
            }

            return strpos((string) $xml->namespace, 'Advans\\Plugin\\System\\DmarcMtaSts') !== false;
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
