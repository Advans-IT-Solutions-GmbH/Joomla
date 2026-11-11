<?php
/**
 * Import Model Tests for J2Commerce Import/Export
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/administrator/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
require_once __DIR__ . '/import-test-bootstrap.php';

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
        $testContext = null;

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

        try {
            // --- Runtime: importProductFull() with empty/minimal data does not crash ---
            // newInstanceWithoutConstructor avoids BaseDatabaseModel::__construct() calling Factory::getApplication()
            $db    = Factory::getContainer()->get(DatabaseInterface::class);
            $model = $rc->newInstanceWithoutConstructor();
            $model->setDatabase($db);
            $context = importExportBootAuthorizedAdministrator();
            $testContext = $context;

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
                $data = [
                    'title'          => 'Import Test Product ' . uniqid(),
                    'alias'          => 'import-test-' . uniqid(),
                    'category'       => 'Import Test Category',
                    'sku'            => 'IMP-TEST-' . uniqid(),
                    'price'          => 19.99,
                    'visibility'     => 1,
                    'addtocart_text' => '',
                    'up_sells'       => '',
                    'cross_sells'    => '',
                    'variants'       => [],
                    'product_images' => [],
                    'options'        => [],
                    'filters'        => [],
                    'files'          => [],
                    'tags'            => [],
                    'custom_fields'   => [],
                ];
                $guestData = $data;
                $guestData['title'] = 'Unauthorized Import ' . uniqid();
                $guestData['alias'] = 'unauthorized-import-' . uniqid();
                $guestData['catid'] = 2;
                $userFactory = Factory::getContainer()->get(UserFactoryInterface::class);
                $context['application']->loadIdentity($userFactory->loadUserById(0));

                $this->test('unauthorized model import is refused without article writes', function () use ($model, $db, $guestData) {
                    try {
                        $model->importProductFull($guestData, []);
                        return false;
                    } catch (\RuntimeException $e) {
                        $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
                        $query->select('COUNT(*)')
                            ->from($db->quoteName('#__content'))
                            ->where($db->quoteName('alias') . ' = ' . $db->quote($guestData['alias']));
                        $db->setQuery($query);

                        return $e->getCode() === 403 && (int) $db->loadResult() === 0;
                    }
                });

                $context['application']->loadIdentity($context['identity']);
                $adminArticleId = 0;
                $this->test('importProductFull() with minimal data succeeds', function () use ($model, $data, &$adminArticleId) {
                    $result = $model->importProductFull($data, []);
                    $adminArticleId = (int) ($result['article_id'] ?? 0);
                    return is_array($result)
                        && ($result['success'] ?? false) === true
                        && isset($result['article_id'])
                        && isset($result['product_id'])
                        && (int) $result['article_id'] > 0
                        && (int) $result['product_id'] > 0;
                });

                $this->testRestrictedAuthor($model, $db, $context, $data, $adminArticleId);
            }

            $this->testTranslatedRowErrors($model);
            $this->testImportContract($model);
        } finally {
            if ($testContext !== null) {
                try {
                    importExportRemoveTestAdministrator((int) $testContext['user_id']);
                } finally {
                    importExportRestoreApplication($testContext);
                }
            }
        }

        echo "\n=== Import Model Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo "Total:  " . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }

    /**
     * Real Joomla ACL with a restricted identity: a member of the core "Author"
     * group may create content but not publish it or edit other users' articles.
     */
    private function testRestrictedAuthor(ImportModel $model, object $db, array $context, array $data, int $adminArticleId): void
    {
        $authorId = 0;
        $countAlias = function (string $alias) use ($db): int {
            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select('COUNT(*)')
                ->from($db->quoteName('#__content'))
                ->where($db->quoteName('alias') . ' = ' . $db->quote($alias));
            $db->setQuery($query);

            return (int) $db->loadResult();
        };
        $denied = function (array $row, array $options) use ($model, $countAlias): bool {
            try {
                $model->importProductFull($row, $options);
                return false;
            } catch (\RuntimeException $e) {
                return $e->getCode() === 403 && $countAlias($row['alias']) === 0;
            }
        };
        $row = function (string $label) use ($data): array {
            $suffix = uniqid();
            return array_replace($data, [
                'title' => 'Restricted ' . $label . ' ' . $suffix,
                'alias' => 'restricted-' . strtolower(str_replace(' ', '-', $label)) . '-' . $suffix,
                'catid' => 2,
            ]);
        };

        try {
            $author = importExportCreateTestUser(3, 'Synthetic Import Test Author', $authorId);
            $context['application']->loadIdentity($author);

            $this->test('Precondition: the Author group may create but not publish content in category 2',
                fn () => $author->authorise('core.create', 'com_content.category.2')
                    && !$author->authorise('core.edit.state', 'com_content.category.2')
                    && !$author->authorise('core.edit', 'com_content.category.2'));

            $created = $row('default state');
            $this->test('A restricted author imports a new product as an unpublished article', function () use ($model, $db, $created) {
                $result = $model->importProductFull($created, []);
                $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
                $query->select($db->quoteName('state'))
                    ->from($db->quoteName('#__content'))
                    ->where($db->quoteName('id') . ' = ' . (int) ($result['article_id'] ?? 0));
                $db->setQuery($query);

                return ($result['success'] ?? false) === true && (string) $db->loadResult() === '0';
            });

            $published = $row('publish');
            $published['article_state'] = 1;
            $this->test('A restricted author cannot publish an imported article; the row is rolled back',
                fn () => $denied($published, []));

            $featured = $row('feature');
            $featured['featured'] = 1;
            $this->test('A restricted author cannot feature an imported article; the row is rolled back',
                fn () => $denied($featured, []));

            $this->test('A restricted author cannot update an article created by another user', function () use ($model, $db, $data, $adminArticleId) {
                if ($adminArticleId < 1) {
                    return false;
                }
                $forged = array_replace($data, [
                    'title'      => 'Restricted overwrite ' . uniqid(),
                    'article_id' => $adminArticleId,
                    'catid'      => 2,
                ]);
                try {
                    $model->importProductFull($forged, ['update_existing' => true]);
                    return false;
                } catch (\RuntimeException $e) {
                    $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
                    $query->select($db->quoteName('title'))
                        ->from($db->quoteName('#__content'))
                        ->where($db->quoteName('id') . ' = ' . $adminArticleId);
                    $db->setQuery($query);

                    return $e->getCode() === 403 && (string) $db->loadResult() === $data['title'];
                }
            });

            $query = method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true);
            $query->select($db->quoteName('menutype'))
                ->from($db->quoteName('#__menu_types'))
                ->where($db->quoteName('client_id') . ' = 0')
                ->order($db->quoteName('id'));
            $db->setQuery($query, 0, 1);
            $menutype = (string) $db->loadResult();
            $menuRow = $row('menu');
            $menuRow['menu_item'] = ['title' => $menuRow['title'], 'alias' => $menuRow['alias']];
            $this->test('A restricted author cannot publish a menu item; the whole row is rolled back',
                fn () => $menutype !== '' && $denied($menuRow, [
                    'create_menu' => true, 'menu_published' => true, 'default_menutype' => $menutype,
                ]));
        } finally {
            $context['application']->loadIdentity($context['identity']);
            importExportRemoveTestAdministrator($authorId);
        }
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
            file_put_contents($csv, "\xEF\xBB\xBF# documentation \"with quotes\"\n# comment\ntitle,variants\nCSV,\"[{\"\"sku\"\":\"\"CSV-1\"\",\"\"quantity\"\":0}]\"\n");
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
            foreach (['', '\\'] as $escape) {
                $pairedTitle = 'Paired' . str_repeat('\\', 2);
                $handle = fopen($csv, 'w');
                fputcsv($handle, ['title', 'variants'], ',', '"', $escape);
                fputcsv($handle, [$pairedTitle, json_encode([['sku' => 'A"B']])], ',', '"', $escape);
                fclose($handle);
                $preview = $model->previewFile($csv);
                $this->test('Quoted JSON values survive CSV dialect ' . json_encode($escape), fn () =>
                    $preview['rows'][0]['title'] === $pairedTitle && $preview['rows'][0]['variants'][0]['sku'] === 'A"B');
            }
            foreach (['', '\\'] as $escape) {
                $title = 'A\"B';
                $images = json_encode(['image_intro' => 'images/A"B.jpg']);
                $handle = fopen($csv, 'w');
                fputcsv($handle, ['title', 'article_images'], ',', '"', $escape);
                fputcsv($handle, [$title, $images], ',', '"', $escape);
                fclose($handle);
                $preview = $model->previewFile($csv);
                $this->test('Scalar fields preserve quotes in CSV dialect ' . json_encode($escape), fn () =>
                    $preview['rows'][0]['title'] === $title && $preview['rows'][0]['article_images'] === $images);
            }

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
