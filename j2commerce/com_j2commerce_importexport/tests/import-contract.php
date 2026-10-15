<?php
/**
 * Standalone boundary tests. No Joomla installation or database is required.
 * The Docker suites cover the same endpoints against the real application.
 */
namespace Joomla\CMS\MVC\Model {
    class BaseDatabaseModel
    {
        public object $database;
        public function getDatabase(): object { return $this->database; }
    }
}
namespace Joomla\CMS\MVC\Controller {
    class BaseController
    {
        public object $model;
        public function getModel(...$args): object { return $this->model; }
    }
}
namespace Joomla\CMS {
    class Factory
    {
        public static object $application;
        public static function getApplication(): object { return self::$application; }
    }
}
namespace Joomla\CMS\Language {
    class Text
    {
        public static function _($key): string { return $key; }
        public static function sprintf($key, ...$args): string { return $key . ': ' . implode(', ', $args); }
    }
}
namespace Joomla\CMS\Session {
    class Session
    {
        public static function checkToken(): bool { return true; }
    }
}
namespace Joomla\CMS\Table {
    class Menu
    {
        private object $row;
        public function __construct(private object $db) {}
        public function setLocation(int $parent, string $position): bool { return true; }
        public function bind(array $data): bool { $this->row = (object) $data; return true; }
        public function check(): bool { $this->row->img = ' '; return true; }
        public function store(): bool { $this->db->insertObject('#__menu', $this->row); return true; }
        public function getError(): string { return 'Menu fixture validation failed'; }
    }
}
namespace Joomla\Database {
    interface QueryInterface {}
    class ParameterType { const INTEGER = 'int'; }
}
namespace {
    define('_JEXEC', 1);
    $src = dirname(__DIR__) . '/administrator/components/com_j2commerce_importexport/src/';
    require $src . 'Model/J2CommerceAwareTrait.php';
    require $src . 'Model/ImportModel.php';
    require $src . 'Controller/ImportController.php';

    use Advans\Component\J2CommerceImportExport\Administrator\Model\ImportModel;
    use Advans\Component\J2CommerceImportExport\Administrator\Controller\ImportController;
    use Joomla\CMS\Factory;

    class RequestClosed extends \Error {}
    class ContractModel extends ImportModel
    {
        public array $received = [];
        public function importProductFull(array $data, array $options = []): array
        {
            $this->received[] = ['data' => $data, 'options' => $options];
            return ['success' => true];
        }
    }
    function check(bool $condition, string $message): void
    {
        if (!$condition) throw new \RuntimeException($message);
        echo "PASS $message\n";
    }
    function response(ImportController $controller, string $task): array
    {
        ob_start();
        try {
            $controller->$task();
            throw new \RuntimeException('Request did not terminate');
        } catch (RequestClosed $e) {
            return json_decode(ob_get_contents(), true, 512, JSON_THROW_ON_ERROR);
        } finally {
            ob_end_clean();
        }
    }

    $session = new class {
        public string $file = '';
        public function get(...$args): string { return $this->file; }
        public function clear(...$args): void { $this->file = ''; }
    };
    $input = new class {
        public array $values = [];
        public object $files;
        public function get($key, $default = null, ...$args) { return $this->values[$key] ?? $default; }
    };
    $input->files = new class { public function get(...$args) { return null; } };
    Factory::$application = new class ($session, $input) {
        public function __construct(private object $session, private object $input) {}
        public function getIdentity(): object {
            return new class { public function authorise(...$args): bool { return true; } };
        }
        public function getSession(): object { return $this->session; }
        public function getInput(): object { return $this->input; }
        public function setHeader(...$args): void {}
        public function sendHeaders(): void {}
        public function close(): void { throw new RequestClosed(); }
    };
    $controller = new ImportController();
    $model = new ContractModel();
    $controller->model = $model;
    $base = tempnam(sys_get_temp_dir(), 'j2ie-contract-');
    $file = $base . '.json';
    $csv = $base . '.csv';
    $otherFile = $base . '-tab-b.json';
    try {
        $upload = response($controller, 'upload');
        check($upload['success'] === false && isset($upload['message']) && !isset($upload['data']), 'Upload errors use flat JSON');
        $missing = response($controller, 'preview');
        check($missing['success'] === false && isset($missing['message']), 'Missing upload returns a JSON error');
        file_put_contents($file, '{"products":[{"title":"Fixture","variants":[{"sku":"CONTRACT","quantity":7}]}]}');
        $session->file = $file;
        check(response($controller, 'preview')['success'] === false, 'Preview requires the upload identifier');
        $input->values = ['file' => basename($file)];
        $preview = response($controller, 'preview');
        check($preview['success'] === true && $preview['preview']['total'] === 1
            && $preview['preview']['rows'][0]['title'] === 'Fixture' && !isset($preview['data']), 'Actual preview response is flat and contains records');
        file_put_contents($otherFile, '[{"title":"Tab B"}]');
        $session->file = $otherFile;
        check(response($controller, 'preview')['success'] === false, 'Tab A cannot preview the replacement upload from tab B');
        check(response($controller, 'process')['success'] === false && $model->received === []
            && is_file($otherFile) && is_file($file), 'Tab A cannot import or delete the replacement upload from tab B');
        $session->file = $file;

        foreach (['products', 'variants', 'prices', 'unknown'] as $type) {
            $input->values = ['type' => $type, 'file' => basename($file)];
            check(response($controller, 'process')['success'] === false && is_file($file)
                && $model->received === [], "Unsupported $type does not import or delete the upload");
        }
        foreach ([
            ['quantity_mode' => 'invalid'], ['quantity_mode' => []], ['update_existing' => 'false'],
            ['create_menu' => 2], ['menu_published' => []], ['default_category' => '1x'],
            ['default_category' => 0], ['menu_access' => -1], ['default_menutype' => []],
        ] as $options) {
            $input->values = ['options' => $options, 'file' => basename($file)];
            check(response($controller, 'process')['success'] === false && is_file($file),
                'Invalid option rejected: ' . array_key_first($options));
        }
        $input->values = ['file' => basename($file), 'options' => [
            'quantity_mode' => 'add', 'create_menu' => '1', 'default_category' => '3',
            'default_menutype' => 'fixture', 'menu_access' => '2',
        ]];
        $result = response($controller, 'process');
        $options = $model->received[0]['options'];
        check($result['success'] === true && $result['imported'] === 1 && $result['errors'] === [],
            'Default import type processes complete products');
        check($options['quantity_mode'] === 'add' && $options['update_existing'] === false
            && $options['create_menu'] === true && $options['menu_published'] === false
            && $options['default_category'] === 3 && $options['default_menutype'] === 'fixture'
            && $options['menu_access'] === 2, 'Every form option reaches the model with normalized checkbox defaults');
        check(!file_exists($file) && $session->file === '', 'Successful process cleans only the owned temporary upload');

        foreach (['{', 'null', '[1]', '{"title":"not a list"}'] as $invalid) {
            file_put_contents($file, $invalid);
            $session->file = $file;
            check(response($controller, 'preview')['success'] === false, 'Malformed input fails as JSON rather than a PHP error');
        }
        file_put_contents($file, '[]');
        check(response($controller, 'preview')['success'] === false, 'Empty preview is rejected');
        check(response($controller, 'process')['success'] === false, 'Zero imported rows are not reported as success');

        file_put_contents($csv, "\xEF\xBB\xBF# help \"with quotes\"\ntitle,variants\nCSV,\"[{\"\"quantity\"\":0}]\"\n");
        $parsed = $model->previewFile($csv);
        check($parsed['total'] === 1 && $parsed['rows'][0]['variants'][0]['quantity'] === 0,
            'CSV parser handles BOM/comments and nested JSON');
        foreach ([
            "\xEF\xBB\xBF\"title\",\"alias\"\n\"Quoted\",\"quoted\"\n",
            "\xEF\xBB\xBF\"# Product title\",\"# Article alias\"\n\"title\",\"alias\"\n\"Quoted\",\"quoted\"\n",
        ] as $quotedCsv) {
            file_put_contents($csv, $quotedCsv);
            $parsed = $model->previewFile($csv);
            check($parsed['headers'] === ['title', 'alias'] && $parsed['rows'][0]['title'] === 'Quoted'
                && $parsed['total'] === 1, 'BOM is consumed before parsing quoted CSV headers/documentation');
        }
        file_put_contents($csv, "title,alias\nMissing\n");
        try {
            $model->previewFile($csv);
            throw new \RuntimeException('Malformed CSV was accepted');
        } catch (\InvalidArgumentException $e) {
            check(true, 'Malformed CSV columns fail explicitly');
        }
        $handle = fopen($csv, 'w');
        fwrite($handle, "title,alias\n");
        for ($row = 0; $row < 100000; $row++) {
            fwrite($handle, "Sample,sample\n");
        }
        fclose($handle);
        $parsed = $model->previewFile($csv);
        check($parsed['total'] === 100000 && count($parsed['rows']) === 10
            && memory_get_peak_usage(true) < 32 * 1024 * 1024, '100000-row CSV preview stays below 32 MiB and retains only ten rows');
        file_put_contents($csv, "bad,extra,column\n", FILE_APPEND);
        try {
            $model->previewFile($csv);
            throw new \RuntimeException('Malformed row after preview sample was accepted');
        } catch (\InvalidArgumentException $e) {
            check(true, 'Streaming preview validates rows beyond the retained sample');
        }

        foreach (['', '\\'] as $escape) {
            $handle = fopen($csv, 'w');
            fputcsv($handle, ['title', 'variants'], ',', '"', $escape);
            fputcsv($handle, ['Quoted JSON', json_encode([['sku' => 'A"B']])], ',', '"', $escape);
            fclose($handle);
            $parsed = $model->previewFile($csv);
            check($parsed['rows'][0]['variants'][0]['sku'] === 'A"B', 'CSV preserves JSON quotes for escape dialect: ' . json_encode($escape));
            $imported = $model->importData($csv, 'products_full', []);
            $last = $model->received[array_key_last($model->received)];
            check($imported['imported'] === 1 && $last['data']['variants'][0]['sku'] === 'A"B',
                'CSV preview/import agree for escape dialect: ' . json_encode($escape));
        }
        foreach (['', '\\'] as $escape) {
            $title = 'A\"B';
            $images = json_encode(['image_intro' => 'images/A"B.jpg']);
            $handle = fopen($csv, 'w');
            fputcsv($handle, ['title', 'article_images'], ',', '"', $escape);
            fputcsv($handle, [$title, $images], ',', '"', $escape);
            fclose($handle);
            $parsed = $model->previewFile($csv);
            check($parsed['rows'][0]['title'] === $title && $parsed['rows'][0]['article_images'] === $images,
                'CSV scalar text/article images round trip for dialect: ' . json_encode($escape));
            $model->importData($csv, 'products_full', []);
            $last = $model->received[array_key_last($model->received)];
            check($last['data']['title'] === $title && $last['data']['article_images'] === $images,
                'Import preserves scalar text/article images for dialect: ' . json_encode($escape));
        }
        $pairedTitle = 'Paired' . str_repeat('\\', 2);
        $handle = fopen($csv, 'w');
        fputcsv($handle, ['title', 'variants'], ',', '"', '\\');
        fputcsv($handle, [$pairedTitle, json_encode([['sku' => 'A"B']])], ',', '"', '\\');
        fclose($handle);
        $parsed = $model->previewFile($csv);
        check($parsed['rows'][0]['title'] === $pairedTitle && $parsed['rows'][0]['variants'][0]['sku'] === 'A"B',
            'Legacy CSV preserves paired trailing backslashes alongside quoted JSON');
        $model->importData($csv, 'products_full', []);
        $last = $model->received[array_key_last($model->received)];
        check($last['data']['title'] === $pairedTitle && $last['data']['variants'][0]['sku'] === 'A"B',
            'Import preserves paired trailing backslashes alongside quoted JSON');

        file_put_contents($csv, "title,alias\r\n\"First\r\nSecond\",\"multiline\"\r\n");
        $parsed = $model->previewFile($csv);
        check($parsed['rows'][0]['title'] === "First\r\nSecond", 'Strict CSV quoting preserves embedded CRLF');
        foreach (["title,alias\n\"Unclosed,alias\n", "title,alias\n\"Closed\"trailing,alias\n"] as $invalidCsv) {
            file_put_contents($csv, $invalidCsv);
            try {
                $model->previewFile($csv);
                throw new \RuntimeException('Malformed CSV quoting was accepted');
            } catch (\InvalidArgumentException $e) {
                check(true, 'Malformed CSV quoting is rejected in both dialects');
            }
        }
        $samples = [''];
        $level = [''];
        for ($length = 1; $length <= 3; $length++) {
            $next = [];
            foreach ($level as $prefix) {
                foreach (['A', '\\', '"', ',', "\n"] as $char) {
                    $next[] = $prefix . $char;
                }
            }
            $samples = array_merge($samples, $next);
            $level = $next;
        }
        foreach (['', '\\'] as $escape) {
            $compared = 0;
            foreach ($samples as $sample) {
                $handle = fopen($csv, 'w+');
                fputcsv($handle, ['title', 'alias'], ',', '"', $escape);
                fputcsv($handle, [$sample, 'fixture'], ',', '"', $escape);
                rewind($handle);
                fgetcsv($handle, 0, ',', '"', $escape);
                $native = fgetcsv($handle, 0, ',', '"', $escape);
                fclose($handle);
                // PHP's legacy writer cannot round-trip every possible trailing escape.
                if ($native !== [$sample, 'fixture']) {
                    continue;
                }
                $parsed = $model->previewFile($csv);
                if (($parsed['rows'][0]['title'] ?? null) !== $sample) {
                    throw new \RuntimeException('CSV compatibility mismatch: ' . json_encode([$escape, $sample]));
                }
                $compared++;
            }
            check($compared > 100, 'CSV quote/backslash combinations match native round trips for dialect: ' . json_encode($escape));
        }

        $real = new ImportModel();
        foreach ([
            ['title' => []],
            ['title' => 'Invalid variants', 'variants' => [1]],
            ['title' => 'Invalid options', 'options' => 'not an array'],
            ['title' => 'Invalid tier prices', 'variants' => [['tier_prices' => 'not an array']]],
            ['title' => 'Invalid menu', 'menu_item' => ['alias' => 'missing-title']],
        ] as $invalidProduct) {
            try {
                $real->importProductFull($invalidProduct, ['create_menu' => true]);
                throw new \RuntimeException('Malformed product was accepted');
            } catch (\InvalidArgumentException $e) {
                check(true, 'Malformed product fields fail before database access');
            }
        }
        $real->database = new class {
            public int $quantity = 50;
            public array $inserted = [];
            public function createQuery(): \Joomla\Database\QueryInterface {
                return new class implements \Joomla\Database\QueryInterface {
                    public function __call($name, $args): self { return $this; }
                };
            }
            public function quoteName($name) { return $name; }
            public function quote($value) { return $value; }
            public function setQuery($query): void {}
            public function loadObject(): object { return (object) ['j2store_productquantity_id' => 1, 'quantity' => $this->quantity]; }
            public function loadResult(): int { return 42; }
            public function updateObject($table, $row, $key): void { $this->quantity = $row->quantity; }
            public function insertObject($table, $row): void { $this->inserted[$table] = clone $row; }
        };
        $cache = new \ReflectionProperty(ImportModel::class, 'isJ2Commerce6Cache');
        $cache->setValue($real, false);
        $quantityMethod = new \ReflectionMethod(ImportModel::class, 'importVariantQuantity');
        $quantityMethod->invoke($real, 1, ['quantity' => 30], ['quantity_mode' => 'add']);
        check($real->database->quantity === 80, 'Actual quantity helper adds 30 to 50');
        $quantityMethod->invoke($real, 1, ['quantity' => 30], ['quantity_mode' => 'replace']);
        check($real->database->quantity === 30, 'Actual quantity helper replaces 80 with 30');
        $menuMethod = new \ReflectionMethod(ImportModel::class, 'importMenuItem');
        $menuMethod->invoke($real, ['title' => 'Unpublished fixture', 'alias' => 'unpublished-fixture'], 1,
            ['menu_published' => false]);
        check($real->database->inserted['#__menu']->published === 0, 'Unchecked publication reaches the database as integer zero');
        check(isset($real->database->inserted['#__menu']->img), 'Menu insertion goes through table validation defaults');
    } finally {
        foreach ([$file, $csv, $otherFile, $base] as $owned) {
            if (is_file($owned)) unlink($owned);
        }
    }
}
