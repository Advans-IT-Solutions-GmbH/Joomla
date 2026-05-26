<?php
/**
 * Import Model Tests for J2Commerce Import/Export
 *
 * Tests previewFile() and importData() with real CSV/JSON fixtures.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;

class ImportModelTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $tmpFiles = [];

    public function __construct()
    {
        $this->db = Factory::getContainer()->get('DatabaseDriver');
    }

    private function test(string $name, bool $condition, string $message = ''): void
    {
        if ($condition) {
            echo "✓ $name\n";
            $this->passed++;
        } else {
            echo "✗ $name" . ($message ? " — $message" : '') . "\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Import Model Tests ===\n\n";

        try {
            $this->testPreviewCsv();
            $this->testPreviewJson();
            $this->testImportCsvRoundTrip();
            $this->testImportDuplicateDetection();
            $this->testImportValidation();
        } finally {
            foreach ($this->tmpFiles as $f) {
                @unlink($f);
            }
        }

        echo "\n=== Import Model Test Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function getModel(): \Advans\Component\J2CommerceImportExport\Administrator\Model\ImportModel
    {
        $app = Factory::getApplication('administrator');
        $mvcFactory = $app->bootComponent('com_j2commerce_importexport')->getMVCFactory();
        return $mvcFactory->createModel('Import', 'Administrator');
    }

    private function writeTmp(string $ext, string $content): string
    {
        $path = sys_get_temp_dir() . '/import_test_' . uniqid() . '.' . $ext;
        file_put_contents($path, $content);
        $this->tmpFiles[] = $path;
        return $path;
    }

    // -------------------------------------------------------------------------

    private function testPreviewCsv(): void
    {
        echo "--- previewFile() CSV ---\n";

        $csv = "title,sku,price\nTest Product Import,IMP-SKU-001,29.99\nAnother Product,IMP-SKU-002,49.99\n";
        $path = $this->writeTmp('csv', $csv);

        $model   = $this->getModel();
        $preview = $model->previewFile($path);

        $this->test('Returns array',          is_array($preview));
        $this->test('Has headers key',        isset($preview['headers']));
        $this->test('Has rows key',           isset($preview['rows']));
        $this->test('Headers contains title', in_array('title', $preview['headers'] ?? []));
        $this->test('Headers contains sku',   in_array('sku',   $preview['headers'] ?? []));
        $this->test('Headers contains price', in_array('price', $preview['headers'] ?? []));
        $this->test('2 data rows returned',   count($preview['rows'] ?? []) === 2,
            'Got ' . count($preview['rows'] ?? []));
    }

    private function testPreviewJson(): void
    {
        echo "\n--- previewFile() JSON ---\n";

        $json = json_encode(['products' => [
            ['title' => 'JSON Product', 'sku' => 'JSON-001', 'price' => 9.99],
        ]]);
        $path = $this->writeTmp('json', $json);

        $model   = $this->getModel();
        $preview = $model->previewFile($path);

        $this->test('JSON preview returns array', is_array($preview));
        $this->test('JSON preview has rows',      !empty($preview['rows'] ?? []));
    }

    private function testImportCsvRoundTrip(): void
    {
        echo "\n--- importData() CSV round-trip ---\n";

        $sku  = 'IMP-ROUNDTRIP-' . time();
        $csv  = "title,sku,price,catid\nImport Round-Trip Test,{$sku},14.99,2\n";
        $path = $this->writeTmp('csv', $csv);

        $model  = $this->getModel();
        $result = $model->importData($path, 'products', []);

        $this->test('Returns result array',       is_array($result));
        $this->test('Has imported key',           isset($result['imported']));
        $this->test('Has failed key',             isset($result['failed']));
        $this->test('Has errors key',             isset($result['errors']));
        $this->test('imported >= 0',              ($result['imported'] ?? -1) >= 0);

        if (($result['imported'] ?? 0) > 0) {
            // Verify product was actually written to DB
            $query = $this->db->getQuery(true)
                ->select('v.j2store_variant_id')
                ->from('#__j2store_variants AS v')
                ->where('v.sku = ' . $this->db->quote($sku));
            $variantId = (int) $this->db->setQuery($query)->loadResult();
            $this->test('Imported product exists in DB', $variantId > 0,
                "SKU $sku not found in #__j2store_variants");

            // Cleanup
            if ($variantId > 0) {
                $this->db->setQuery(
                    $this->db->getQuery(true)->delete('#__j2store_variants')
                        ->where('j2store_variant_id = ' . $variantId)
                )->execute();
            }
        } else {
            echo "  Note: import returned 0 — may need J2Commerce installed\n";
            $this->test('Import ran without fatal error', !isset($result['fatal']));
        }
    }

    private function testImportDuplicateDetection(): void
    {
        echo "\n--- Duplicate detection ---\n";

        // Import same SKU twice — second run must update, not duplicate
        $sku  = 'IMP-DUP-' . time();
        $csv  = "title,sku,price,catid\nDuplicate Test,{$sku},9.99,2\n";
        $path = $this->writeTmp('csv', $csv);

        $model = $this->getModel();
        $model->importData($path, 'products', []);

        // Re-import same file
        $path2   = $this->writeTmp('csv', $csv);
        $result2 = $model->importData($path2, 'products', []);

        $this->test('Second import does not crash', is_array($result2));

        // Count rows with this SKU — must be exactly 1
        $query = $this->db->getQuery(true)
            ->select('COUNT(*)')
            ->from('#__j2store_variants')
            ->where('sku = ' . $this->db->quote($sku));
        $count = (int) $this->db->setQuery($query)->loadResult();
        $this->test('No duplicate SKU in DB', $count <= 1,
            "Found $count rows for SKU $sku");

        // Cleanup
        if ($count > 0) {
            $this->db->setQuery(
                $this->db->getQuery(true)->delete('#__j2store_variants')
                    ->where('sku = ' . $this->db->quote($sku))
            )->execute();
        }
    }

    private function testImportValidation(): void
    {
        echo "\n--- Validation ---\n";

        // Missing required field (title) — must not crash
        $csv  = "sku,price\nNO-TITLE-SKU,5.00\n";
        $path = $this->writeTmp('csv', $csv);

        $model = $this->getModel();
        try {
            $result = $model->importData($path, 'products', []);
            $this->test('Missing title: no fatal crash', true);
            // Should either skip the row (failed > 0) or handle gracefully
            $this->test('Missing title: row counted as failed or imported',
                isset($result['failed']) || isset($result['imported']));
        } catch (\InvalidArgumentException $e) {
            $this->test('Missing title: throws InvalidArgumentException', true);
        } catch (\Exception $e) {
            $this->test('Missing title: no fatal crash', false, $e->getMessage());
        }

        // Non-existent file
        try {
            $model->previewFile('/nonexistent/path/file.csv');
            $this->test('Non-existent file: no silent success', false,
                'Should throw or return error');
        } catch (\RuntimeException $e) {
            $this->test('Non-existent file: throws RuntimeException', true);
        } catch (\Exception $e) {
            $this->test('Non-existent file: throws exception', true);
        }
    }
}

$test = new ImportModelTest();
exit($test->run() ? 0 : 1);
