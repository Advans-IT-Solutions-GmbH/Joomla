<?php
/**
 * Import Model Tests for J2Commerce Import/Export
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\Database\DatabaseInterface;

// Register component PSR-4 namespace
spl_autoload_register(function (string $class): void {
    $prefix = 'Advans\\Component\\J2CommerceImportExport\\Administrator\\';
    $base   = '/var/www/html/administrator/components/com_j2commerce_importexport/src/';
    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = $base . $relative . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

use Advans\Component\J2CommerceImportExport\Administrator\Model\ImportModel;

class ImportModelTest
{
    private int $passed = 0;
    private int $failed = 0;

    private function test(string $name, callable $fn): void
    {
        try {
            $result = $fn();
            if ($result) {
                echo "PASS $name\n";
                $this->passed++;
            } else {
                echo "FAIL $name\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "FAIL $name — " . $e->getMessage() . "\n";
            $this->failed++;
        }
    }

    public function run(): bool
    {
        echo "=== Import Model Tests ===\n\n";

        $rc = new ReflectionClass(ImportModel::class);

        // --- Class structure via reflection ---
        $this->test('ImportModel uses J2CommerceAwareTrait', function () use ($rc) {
            foreach (array_keys($rc->getTraits()) as $t) {
                if (str_ends_with($t, 'J2CommerceAwareTrait')) return true;
            }
            return false;
        });

        $this->test('importProductFull() is public', function () use ($rc) {
            return $rc->hasMethod('importProductFull') && $rc->getMethod('importProductFull')->isPublic();
        });

        foreach (['importVariants', 'importTierPrices', 'importProductImages',
                  'importProductOptions', 'importProductFilters', 'importArticleTags',
                  'importCustomFields', 'importMenuItem'] as $method) {
            $this->test("$method() exists", function () use ($rc, $method) {
                return $rc->hasMethod($method);
            });
        }

        // --- Runtime: importProductFull() with empty/minimal data does not crash ---
        // newInstanceWithoutConstructor avoids BaseDatabaseModel::__construct() calling Factory::getApplication()
        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $model = $rc->newInstanceWithoutConstructor();
        $model->setDatabase($db);

        $this->test('importProductFull() without a title throws InvalidArgumentException', function () use ($model) {
            try {
                $model->importProductFull([]);
            } catch (\InvalidArgumentException $e) {
                return true;
            }

            return false;
        });
        $this->test('Malformed nested product data is rejected before writing', function () use ($model) {
            try {
                $model->importProductFull(['title' => 'Invalid nested data', 'variants' => [1]]);
            } catch (\InvalidArgumentException $e) {
                return true;
            }
            return false;
        });

        // --- Runtime: importProductFull() with minimal valid data ---
        // Detect stack
        $db     = Factory::getContainer()->get(DatabaseInterface::class);
        $tables = $db->getTableList();
        $prefix = $db->getPrefix();
        $isJ6   = in_array($prefix . 'j2commerce_products', $tables, true);
        $hasJ4  = in_array($prefix . 'j2store_products',    $tables, true);

        if (!$isJ6 && !$hasJ4) {
            $this->test('J2Commerce tables are present', fn () => false);
        } else {
            $this->test('importProductFull() with minimal data succeeds', function () use ($model) {
                $data = [
                    'title'       => 'Import Test Product ' . uniqid(),
                    'alias'       => 'import-test-' . uniqid(),
                    'category'    => 'Import Test Category',
                    'sku'         => 'IMP-TEST-' . uniqid(),
                    'price'       => 19.99,
                    'visibility'  => 1,
                    'addtocart_text' => '',
                    'up_sells'    => '',
                    'cross_sells' => '',
                    'variants'    => [],
                    'product_images' => [],
                    'options'     => [],
                    'filters'     => [],
                    'files'       => [],
                    'tags'        => [],
                    'custom_fields' => [],
                ];
                $result = $model->importProductFull($data, []);
                return is_array($result)
                    && ($result['success'] ?? false) === true
                    && isset($result['article_id'])
                    && isset($result['product_id'])
                    && (int) $result['article_id'] > 0
                    && (int) $result['product_id'] > 0;
            });
        }

        $this->testTranslatedRowErrors($model);
        $this->testImportContract($model);

        echo "\n=== Import Model Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }

    private function testImportContract(ImportModel $model): void
    {
        $options = $model->normalizeImportOptions([
            'quantity_mode' => 'add', 'update_existing' => '0',
            'create_menu' => '1', 'menu_published' => '0',
            'default_category' => '2', 'menu_access' => '1',
            'default_menutype' => 'mainmenu',
        ]);
        $this->test('Import options have strict normalized types', fn () =>
            $options['quantity_mode'] === 'add'
            && $options['update_existing'] === false
            && $options['create_menu'] === true
            && $options['menu_published'] === false
            && $options['default_category'] === 2 && $options['menu_access'] === 1);

        foreach ([
            ['quantity_mode' => 'ADD'], ['quantity_mode' => []],
            ['update_existing' => 'false'], ['create_menu' => 2],
            ['menu_published' => []], ['default_category' => 0],
            ['default_category' => '2x'], ['menu_access' => -1],
            ['default_menutype' => []], ['unknown' => '1'],
        ] as $invalid) {
            $this->test('Invalid option rejected: ' . array_key_first($invalid), function () use ($model, $invalid) {
                try {
                    $model->normalizeImportOptions($invalid);
                } catch (\InvalidArgumentException $e) {
                    return true;
                }
                return false;
            });
        }

        $base = tempnam(sys_get_temp_dir(), 'j2ie-contract-');
        $json = $base . '.json';
        $csv = $base . '.csv';
        try {
            file_put_contents($json, '{"products":[{"title":"Preview","variants":[{"quantity":0}]}]}');
            $preview = $model->previewFile($json);
            $this->test('Preview exposes actual product records', fn () =>
                $preview['total'] === 1 && $preview['rows'][0]['title'] === 'Preview'
                && $preview['rows'][0]['variants'][0]['quantity'] === 0);

            foreach (['products', 'variants', 'prices', 'categories', 'invalid'] as $type) {
                $this->test('Unsupported import type rejected: ' . $type, function () use ($model, $json, $type) {
                    try {
                        $model->importData($json, $type, []);
                    } catch (\InvalidArgumentException $e) {
                        return true;
                    }
                    return false;
                });
            }
            foreach (['{', 'null', '"text"', '{"title":"Not a list"}', '[1]', '{"products":false}'] as $invalidJson) {
                file_put_contents($json, $invalidJson);
                $this->test('Invalid JSON rejected by preview and process: ' . $invalidJson, function () use ($model, $json) {
                    $rejections = 0;
                    foreach (['preview', 'process'] as $task) {
                        try {
                            $task === 'preview' ? $model->previewFile($json) : $model->importData($json, 'products_full', []);
                        } catch (\InvalidArgumentException $e) {
                            $rejections++;
                        }
                    }
                    return $rejections === 2;
                });
            }
            file_put_contents($csv, "\xEF\xBB\xBF# documentation\n# comment\ntitle,variants\nCSV,\"[{\"\"sku\"\":\"\"CSV-1\"\",\"\"quantity\"\":0}]\"\n");
            $preview = $model->previewFile($csv);
            $this->test('CSV preview skips BOM/comments and decodes nested arrays', fn () =>
                $preview['headers'] === ['title', 'variants']
                && $preview['total'] === 1 && $preview['rows'][0]['variants'][0]['quantity'] === 0);
            foreach ([
                "\xEF\xBB\xBF\"title\",\"alias\"\n\"Quoted\",\"quoted\"\n",
                "\xEF\xBB\xBF\"# Product title\",\"# Article alias\"\n\"title\",\"alias\"\n\"Quoted\",\"quoted\"\n",
            ] as $quotedCsv) {
                file_put_contents($csv, $quotedCsv);
                $preview = $model->previewFile($csv);
                $this->test('BOM-prefixed quoted headers/documentation parse correctly', fn () =>
                    $preview['headers'] === ['title', 'alias'] && $preview['total'] === 1
                    && $preview['rows'][0]['title'] === 'Quoted');
            }
            $handle = fopen($csv, 'w');
            fputcsv($handle, ['title', 'variants'], ',', '"', '');
            fputcsv($handle, ['Quoted JSON', json_encode([['sku' => 'A"B']])], ',', '"', '');
            fclose($handle);
            $preview = $model->previewFile($csv);
            $this->test('Quoted JSON values survive CSV parsing', fn () =>
                $preview['rows'][0]['variants'][0]['sku'] === 'A"B');

            foreach (["title,title\nOne,Two\n", "title,alias\nMissing\n", "title,variants\nBad,broken-json\n"] as $invalidCsv) {
                file_put_contents($csv, $invalidCsv);
                $this->test('Malformed CSV is rejected', function () use ($model, $csv) {
                    try {
                        $model->previewFile($csv);
                    } catch (\InvalidArgumentException $e) {
                        return true;
                    }
                    return false;
                });
            }
        } finally {
            unlink($json);
            unlink($csv);
            unlink($base);
        }
    }

    /**
     * importData() reports a failed row to the administrator in the site
     * language: frame and reason come from the component language file, no
     * English text is built into the model. Runs in de-DE so an English literal
     * would show.
     */
    private function testTranslatedRowErrors(object $model): void
    {
        // Joomla installs an extension's language file only for languages the site
        // has, and the test site has no de-DE pack. Load the de-DE file from the
        // package under test instead, without falling back to en-GB.
        $languageDir = sys_get_temp_dir() . '/import-language-' . uniqid();
        @mkdir($languageDir . '/language/de-DE', 0755, true);
        $zip = new \ZipArchive();

        if ($zip->open('/tmp/extension.zip') === true) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);

                if (str_ends_with($name, 'de-DE/com_j2commerce_importexport.ini')) {
                    file_put_contents($languageDir . '/language/de-DE/com_j2commerce_importexport.ini', (string) $zip->getFromIndex($i));
                    break;
                }
            }

            $zip->close();
        }

        $language = Factory::getContainer()
            ->get(\Joomla\CMS\Language\LanguageFactoryInterface::class)
            ->createLanguage('de-DE');
        $language->load('com_j2commerce_importexport', $languageDir, 'de-DE', true, false);

        $previousApplication = Factory::$application;
        $hasLanguageProperty = property_exists(Factory::class, 'language');
        $previousLanguage    = $hasLanguageProperty ? Factory::$language : null;

        // Text reads the language through Factory; give it the German one.
        Factory::$application = new class ($language) {
            public function __construct(private object $language)
            {
            }

            public function getLanguage(): object
            {
                return $this->language;
            }
        };

        if ($hasLanguageProperty) {
            Factory::$language = $language;
        }

        $file = sys_get_temp_dir() . '/import-language-test-' . uniqid() . '.json';

        try {
            $this->test('Precondition: component language loaded in de-DE', function () {
                return Text::_('COM_J2COMMERCE_IMPORTEXPORT_ERROR_NO_DATA') === 'Die Datei enthält keine Daten.';
            });

            // One row without a title: rejected before anything is written.
            file_put_contents($file, json_encode([['title' => '']]));
            $result = $model->importData($file, 'products_full', []);

            $this->test('A row without a title fails', fn () => ($result['failed'] ?? 0) === 1);
            $this->test('The failed row is reported in the site language',
                fn () => ($result['errors'][0] ?? '') === 'Zeile 1: Der Produkttitel ist erforderlich.');

            file_put_contents($file, json_encode([]));
            $empty = $model->importData($file, 'products_full', []);
            $this->test('An empty file is reported in the site language',
                fn () => ($empty['errors'][0] ?? '') === 'Die Datei enthält keine Daten.');

            $this->test('An unsupported format is reported in the site language', function () use ($model) {
                try {
                    $model->importData('/tmp/products.xls', 'products_full', []);
                } catch (\RuntimeException $e) {
                    return $e->getMessage() === 'Nicht unterstütztes Dateiformat: xls';
                }

                return false;
            });
        } finally {
            Factory::$application = $previousApplication;

            if ($hasLanguageProperty) {
                Factory::$language = $previousLanguage;
            }

            @unlink($file);
            @unlink($languageDir . '/language/de-DE/com_j2commerce_importexport.ini');
            @rmdir($languageDir . '/language/de-DE');
            @rmdir($languageDir . '/language');
            @rmdir($languageDir);
        }
    }
}

$test = new ImportModelTest();
exit($test->run() ? 0 : 1);
