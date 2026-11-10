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
        public static function getDate(): object {
            return new class { public function toSql(): string { return '2026-01-01 00:00:00'; } };
        }
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
        public int $home = 0;
        public static bool $failStore = false;
        private object $row;
        public function __construct(private object $db) { $this->row = (object) ['id' => 0, 'home' => 0]; }
        public function setLocation(int $parent, string $position): void { $this->db->menuLocations[] = [$parent, $position]; }
        public function load(array $keys): bool {
            $stored = $this->db->inserted['#__menu'] ?? null;
            foreach ($keys as $key => $value) {
                if (!$stored || (string) ($stored->$key ?? '') !== (string) $value) {
                    return false;
                }
            }
            $this->row = clone $stored;
            $this->home = (int) $stored->home;
            return true;
        }
        public function bind(array $data): bool { $this->row = (object) array_replace((array) $this->row, $data); return true; }
        public function check(): bool { $this->row->img = ' '; return true; }
        public function store(): bool {
            if (self::$failStore) { return false; }
            if (!$this->row->id) { $this->row->id = ++$this->db->menuInsertions; }
            $this->db->insertObject('#__menu', $this->row);
            return true;
        }
        public function getError(): string { return 'Menu fixture validation failed'; }
    }
    class Category
    {
        public function __construct(private object $db) {}
        public function rebuild(): void {}
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
    class AuthorizationUser
    {
        public int $id = 7;
        public array $permissions = [];
        public array $authorizedCategories = [];
        public function authorise(string $action, string $asset): bool
        {
            return $this->permissions[$action . ':' . $asset] ?? false;
        }
        public function getAuthorisedCategories(string $extension, string $action): array
        {
            return $this->authorizedCategories[$extension] ?? [];
        }
    }
    class AuthorizationQuery implements \Joomla\Database\QueryInterface
    {
        public function __call(string $name, array $arguments): self { return $this; }
    }
    class AuthorizationDatabase
    {
        public object $article;
        public int $result = 0;
        public array $results = [];
        public array $objectResults = [];
        public array $inserted = [];
        public array $updated = [];
        public int $objectLoads = 0;
        public int $transactionsStarted = 0;
        public int $transactionsRolledBack = 0;
        public function transactionStart(): void { $this->transactionsStarted++; }
        public function transactionCommit(): void {}
        public function transactionRollback(): void { $this->transactionsRolledBack++; }
        public function createQuery(): \Joomla\Database\QueryInterface { return new AuthorizationQuery(); }
        public function quoteName(string $name): string { return $name; }
        public function quote(string $value): string { return "'" . $value . "'"; }
        public function setQuery($query): self { return $this; }
        public function loadObject(): ?object {
            $this->objectLoads++;
            $result = $this->objectResults ? array_shift($this->objectResults) : $this->article;
            return $result ? clone $result : null;
        }
        public function loadResult(): int { return $this->results ? array_shift($this->results) : $this->result; }
        public function insertObject(string $table, object $row): void { $this->inserted[$table] = clone $row; }
        public function updateObject(string $table, object $row, string $key): void { $this->updated[$table] = clone $row; }
        public function insertid(): int { return 42; }
        public function resetAuthorizationScenario(array $results, array $objects = []): void {
            $this->results = $results;
            $this->objectResults = $objects;
            $this->inserted = [];
            $this->updated = [];
            $this->objectLoads = 0;
            $this->transactionsStarted = 0;
            $this->transactionsRolledBack = 0;
        }
    }
    class AuthorizationModel extends ImportModel
    {
        public object $identity;
        public ?string $activeShop = 'j2commerce';
        protected function getImportIdentity(): object { return $this->identity; }
        protected function getActiveShop(): ?string { return $this->activeShop; }
        public function authorize(?object $article, int $categoryId, array $data): array
        {
            return $this->authorizeArticleImport($article, $categoryId, $data);
        }
        public function find(array $data, string $alias): ?object
        {
            return $this->findExistingArticle($data, $alias);
        }
        public function importArticleData(array $data, int $categoryId, array $options): int
        {
            return $this->importArticle($data, $categoryId, $options);
        }
        public function category(array $data, array $options = []): int
        {
            return $this->ensureCategory($data, $options);
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
        public int $categoryAccess = 1;
        public function __construct(private object $session, private object $input) {}
        public function get(string $key): mixed {
            return $key === 'access' ? $this->categoryAccess : null;
        }
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
            public int $menuInsertions = 0;
            public array $menuLocations = [];
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
        $initialMenuId = $real->database->inserted['#__menu']->id;
        $menuMethod->invoke($real, ['title' => 'Updated fixture', 'alias' => 'unpublished-fixture'], 1,
            ['menu_published' => false, 'update_existing' => true]);
        check($real->database->menuInsertions === 1 && $real->database->inserted['#__menu']->id === $initialMenuId
            && $real->database->inserted['#__menu']->title === 'Updated fixture', 'Menu-enabled re-import updates the existing linked entry');
        check($real->database->menuLocations === [[1, 'last-child']], 'Only new menu entries receive a nested-set location');
        try {
            \Joomla\CMS\Table\Menu::$failStore = true;
            $menuMethod->invoke($real, ['title' => 'Failed fixture', 'alias' => 'unpublished-fixture'], 1, []);
            throw new \RuntimeException('Menu persistence failure was ignored');
        } catch (\RuntimeException $e) {
            check($e->getMessage() === 'Menu fixture validation failed', 'Menu table persistence failures are surfaced');
        } finally {
            \Joomla\CMS\Table\Menu::$failStore = false;
        }

        $user = new AuthorizationUser();
        $authModel = new AuthorizationModel();
        $authModel->identity = $user;
        $existingArticle = (object) ['id' => 80, 'created_by' => 7, 'catid' => 2, 'state' => 1, 'access' => 1];
        $authDb = new AuthorizationDatabase();
        $authDb->article = clone $existingArticle;
        $authModel->database = $authDb;
        $user->permissions = ['core.edit.own:com_content.article.80' => true];
        $authorized = $authModel->authorize($existingArticle, 2, []);
        check($authorized === ['state' => 1, 'access' => 1], 'Article owners may use core.edit.own without changing protected fields');
        check((function () use ($authModel, $existingArticle): bool {
            try { $authModel->authorize((object) ['id' => 80, 'created_by' => 8, 'catid' => 2, 'state' => 1, 'access' => 1], 2, []); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'Foreign article updates are denied without core.edit');

        check((function () use ($authModel, $existingArticle): bool {
            try { $authModel->authorize($existingArticle, 2, ['article_state' => 0]); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'Publication changes require core.edit.state');
        check((function () use ($authModel, $existingArticle): bool {
            try { $authModel->authorize($existingArticle, 2, ['access' => 2]); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'Access-level changes require core.edit.state');
        $user->permissions['core.edit.state:com_content.article.80'] = true;
        check($authModel->authorize($existingArticle, 2, ['article_state' => 0, 'access' => 2])
            === ['state' => 0, 'access' => 2], 'core.edit.state permits authorized publication and access changes');

        check((function () use ($authModel): bool {
            try { $authModel->authorize(null, 2, []); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'New articles require core.create on the target category');
        $user->permissions['core.create:com_content.category.2'] = true;
        check($authModel->authorize(null, 2, []) === ['state' => 0, 'access' => 1],
            'New articles default to unpublished when core.edit.state is absent');
        $user->permissions['core.edit.state:com_content.category.2'] = true;
        check($authModel->authorize(null, 2, []) === ['state' => 1, 'access' => 1],
            'New articles retain the published default for users with core.edit.state');
        $authDb->resetAuthorizationScenario([], []);
        check($authModel->importArticleData(
            ['title' => 'Authorized publisher default', 'alias' => 'authorized-publisher-default'],
            2,
            ['update_existing' => false]
        ) === 42
            && $authDb->inserted['#__content']->state === 1,
            'An authorized publisher importing without article_state preserves the published default');
        $user->permissions['core.edit.state:com_content.category.2'] = false;
        $authDb->resetAuthorizationScenario([], []);
        check($authModel->importArticleData(
            ['title' => 'Restricted publisher default', 'alias' => 'restricted-publisher-default'],
            2,
            ['update_existing' => false]
        ) === 42
            && $authDb->inserted['#__content']->state === 0,
            'A creator without core.edit.state importing without article_state gets an unpublished article');
        check((function () use ($authModel): bool {
            try { $authModel->authorize(null, 2, ['article_state' => 1]); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'Publishing a new article requires core.edit.state');
        check((function () use ($authModel): bool {
            try { $authModel->authorize(null, 2, ['access' => 2]); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'Assigning a non-default access level requires core.edit.state');

        $authDb->result = 0;
        check((function () use ($authModel): bool {
            try { $authModel->find(['article_id' => 80], 'unrelated'); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'An ID match without an active-shop product link cannot be updated');
        check((function () use ($authModel): bool {
            try { $authModel->find([], 'colliding-alias'); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'An alias collision without an active-shop product link cannot be updated');
        $authDb->result = 1;
        check($authModel->find(['article_id' => 80], 'existing')?->id === 80,
            'A linked active-shop article remains eligible for update resolution');
        check($authModel->find(['variants' => [['sku' => 'ACTIVE-SKU']]], 'new-alias')?->id === 80,
            'SKU resolution accepts a product linked in the active shop');
        $user->permissions = ['core.edit.own:com_content.article.80' => true];
        check($authModel->importArticleData(
            ['title' => 'Authorized update', 'alias' => 'existing', 'article_id' => 80],
            2,
            ['update_existing' => true]
        ) === 80
            && isset($authDb->updated['#__content'])
            && $authDb->updated['#__content']->id === 80,
            'An owned active-shop article updates through the guarded import path');
        $authDb->updated = [];
        $authModel->activeShop = null;
        check((function () use ($authModel): bool {
            try { $authModel->find(['article_id' => 80], 'existing'); }
            catch (\RuntimeException $e) { return $e->getCode() === 403; }
            return false;
        })(), 'A product linked only in the inactive-shop fallback is not accepted');

        $authModel->activeShop = 'j2commerce';
        $authDb->resetAuthorizationScenario([1, 0], [$existingArticle]);
        check((function () use ($authModel, $authDb): bool {
            try {
                $authModel->importProductFull(
                    ['title' => 'Forged target', 'alias' => 'forged-target', 'article_id' => 80, 'catid' => 2],
                    ['update_existing' => true]
                );
            } catch (\RuntimeException $e) {
                return $e->getCode() === 403
                    && $authDb->transactionsStarted === 1
                    && $authDb->transactionsRolledBack === 1
                    && $authDb->inserted === []
                    && $authDb->updated === [];
            }
            return false;
        })(), 'Denied linked-article resolution rolls back before article or product mutation');

        $authDb->result = 0;
        $authDb->results = [];
        $authModel->activeShop = 'j2commerce';
        $user->permissions = ['core.create:com_content.category.2' => true];
        check($authModel->importArticleData(
            ['title' => 'Create instead of update', 'alias' => 'create-instead-of-update', 'article_id' => 80],
            2,
            ['update_existing' => false]
        ) === 42
            && $authDb->updated === []
            && $authDb->inserted['#__content']->state === 0,
            'update_existing=false preserves create-new behavior without updating the matched article');

        $authDb->inserted = [];
        $authDb->updated = [];
        $user->permissions = [];
        check((function () use ($authModel, $authDb): bool {
            try { $authModel->category(['category_path' => 'new-path', 'category_title' => 'New', 'category_alias' => 'new']); }
            catch (\RuntimeException $e) { return $e->getCode() === 403 && $authDb->inserted === []; }
            return false;
        })(), 'Category creation is denied without Joomla Content creation permission');
        check((function () use ($authModel): bool {
            try { $authModel->category(['catid' => 42]); }
            catch (\InvalidArgumentException $e) { return true; }
            return false;
        })(), 'A supplied category ID must identify a Content category');
        check((function () use ($authModel): bool {
            try { $authModel->category([], ['default_category' => 42]); }
            catch (\InvalidArgumentException $e) { return true; }
            return false;
        })(), 'The configured default category must identify a Content category');
        $user->permissions['core.create:com_content'] = true;
        $user->permissions['core.edit.state:com_content'] = true;
        Factory::$application->categoryAccess = 2;
        check($authModel->category(['category_path' => 'new-path', 'category_title' => 'New', 'category_alias' => 'new']) === 42
            && isset($authDb->inserted['#__categories'])
            && $authDb->inserted['#__categories']->published === 1
            && $authDb->inserted['#__categories']->access === 2,
            'Component-level core.create and core.edit.state permit published categories at the site access default');
        $authDb->inserted = [];
        $user->permissions = [];
        $user->permissions['core.create:com_content'] = true;
        check($authModel->category(['category_path' => 'component-only-path', 'category_title' => 'Component only', 'category_alias' => 'component-only']) === 42
            && isset($authDb->inserted['#__categories'])
            && $authDb->inserted['#__categories']->published === 0
            && $authDb->inserted['#__categories']->access === 2,
            'Component creator without component core.edit.state creates unpublished categories at the site access default');
        $authDb->inserted = [];
        $user->permissions = [];
        $user->authorizedCategories = ['com_content' => [2]];
        $user->permissions['core.edit.state:com_content.category.2'] = true;
        check($authModel->category(['category_path' => 'category-scoped-path', 'category_title' => 'Scoped', 'category_alias' => 'scoped']) === 42
            && isset($authDb->inserted['#__categories'])
            && $authDb->inserted['#__categories']->published === 0
            && $authDb->inserted['#__categories']->access === 2,
            'Category-scoped core.edit.state does not grant new-category publication without component core.edit.state');
        $authDb->inserted = [];
        $user->permissions = [];
        $user->authorizedCategories = ['com_content' => [2]];
        check($authModel->category(['category_path' => 'category-only-path', 'category_title' => 'Category only', 'category_alias' => 'category-only']) === 42
            && isset($authDb->inserted['#__categories'])
            && $authDb->inserted['#__categories']->published === 0
            && $authDb->inserted['#__categories']->access === 2,
            'Category-scoped creator without state permission creates unpublished categories at the site access default');
        $authDb->inserted = [];
        Factory::$application->categoryAccess = 1;
        $user->authorizedCategories = [];

        $assertDeniedImportHasNoMutations = function (
            string $name,
            array $data,
            array $options,
            array $permissions,
            array $dbResults,
            array $dbObjects = [],
            ?int $expectedObjectLoads = null
        ) use ($authDb, $authModel, $user): void {
            $authDb->resetAuthorizationScenario($dbResults, $dbObjects);
            $user->permissions = $permissions;
            try {
                $authModel->importProductFull($data, $options);
                throw new \RuntimeException('Denied import unexpectedly succeeded: ' . $name);
            } catch (\RuntimeException $e) {
                check($e->getCode() === 403
                    && $authDb->transactionsStarted === 1
                    && $authDb->transactionsRolledBack === 1
                    && $authDb->inserted === []
                    && $authDb->updated === []
                    && ($expectedObjectLoads === null || $authDb->objectLoads === $expectedObjectLoads),
                    $name . ' rolls back without article, product, category, or related-row writes');
            }
        };

        $nonProductArticle = (object) [
            'id' => 80, 'created_by' => 7, 'catid' => 2, 'state' => 1, 'access' => 1,
        ];
        $productArticle = (object) [
            'id' => 81, 'created_by' => 7, 'catid' => 2, 'state' => 1, 'access' => 1,
        ];
        $collisionPermissions = ['core.create:com_content.category.2' => true];
        $allMatchKeys = [
            'title' => 'Collision candidate',
            'alias' => 'collision-alias',
            'article_id' => 80,
            'catid' => 2,
            'variants' => [['sku' => 'COLLISION-SKU']],
            'product_images' => [['image' => 'fixture.jpg']],
            'options' => [['option_name' => 'fixture']],
        ];
        $assertDeniedImportHasNoMutations(
            'Combined ID, alias and SKU stops at the first non-product ID match',
            $allMatchKeys,
            ['update_existing' => true],
            $collisionPermissions,
            [1, 0],
            [$nonProductArticle],
            1
        );
        $aliasFirst = $allMatchKeys;
        unset($aliasFirst['article_id']);
        $assertDeniedImportHasNoMutations(
            'Combined alias and SKU stops at the first unrelated alias match',
            $aliasFirst,
            ['update_existing' => true],
            $collisionPermissions,
            [1, 0],
            [null, $nonProductArticle],
            2
        );
        $skuFirst = $allMatchKeys;
        unset($skuFirst['article_id']);
        $skuFirst['alias'] = 'no-alias-match';
        $assertDeniedImportHasNoMutations(
            'SKU candidate without an active-shop product association is rejected',
            $skuFirst,
            ['update_existing' => true],
            $collisionPermissions,
            [1, 0],
            [null, $nonProductArticle],
            2
        );

        $assertDeniedImportHasNoMutations(
            'Category creation denial leaves all rows untouched',
            [
                'title' => 'Category permission denial',
                'alias' => 'category-permission-denial',
                'category_path' => 'new-category-path',
                'category_title' => 'New category',
                'category_alias' => 'new-category',
            ],
            ['update_existing' => false],
            [],
            [0]
        );
        $assertDeniedImportHasNoMutations(
            'Category move denial leaves all rows untouched',
            [
                'title' => 'Category move denial',
                'alias' => 'category-move-denial',
                'article_id' => 80,
                'catid' => 3,
            ],
            ['update_existing' => true],
            ['core.edit.own:com_content.article.80' => true],
            [1, 1, 1],
            [$nonProductArticle],
            1
        );
        $assertDeniedImportHasNoMutations(
            'Publication denial leaves all rows untouched',
            [
                'title' => 'Publication permission denial',
                'alias' => 'publication-permission-denial',
                'catid' => 2,
                'article_state' => 1,
            ],
            ['update_existing' => false],
            ['core.create:com_content.category.2' => true],
            [1]
        );
        $assertDeniedImportHasNoMutations(
            'Access-level denial leaves all rows untouched',
            [
                'title' => 'Access permission denial',
                'alias' => 'access-permission-denial',
                'catid' => 2,
                'access' => 2,
            ],
            ['update_existing' => false],
            ['core.create:com_content.category.2' => true],
            [1]
        );
        $assertDeniedImportHasNoMutations(
            'Existing article publication denial leaves all rows untouched',
            [
                'title' => 'Existing publication denial',
                'alias' => 'existing-publication-denial',
                'article_id' => 81,
                'catid' => 2,
                'article_state' => 0,
            ],
            ['update_existing' => true],
            ['core.edit.own:com_content.article.81' => true],
            [1, 1],
            [$productArticle],
            1
        );
        $assertDeniedImportHasNoMutations(
            'Existing article access denial leaves all rows untouched',
            [
                'title' => 'Existing access denial',
                'alias' => 'existing-access-denial',
                'article_id' => 81,
                'catid' => 2,
                'access' => 2,
            ],
            ['update_existing' => true],
            ['core.edit.own:com_content.article.81' => true],
            [1, 1],
            [$productArticle],
            1
        );
    } finally {
        foreach ([$file, $csv, $otherFile, $base] as $owned) {
            if (is_file($owned)) unlink($owned);
        }
    }
}
