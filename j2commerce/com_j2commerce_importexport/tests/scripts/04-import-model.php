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
use Joomla\CMS\Access\Access;
use Joomla\CMS\Table\Category as CategoryTable;
use Joomla\CMS\Table\Content as ContentTable;
use Joomla\CMS\Table\Menu as MenuTable;

// A row that fails right after its menu item was stored through Joomla's menu table.
class ImportModelFailingAfterMenu extends ImportModel
{
    protected function importMenuItem(array $menuData, int $articleId, array $options): void
    {
        parent::importMenuItem($menuData, $articleId, $options);
        throw new \RuntimeException('Forced failure after the menu item was stored');
    }
}

// A row that fails in a later step of its database transaction.
class ImportModelFailingInRowTransaction extends ImportModel
{
    protected function importProductFiles(array $files, int $productId): void
    {
        throw new \RuntimeException('Forced failure in a later row step');
    }
}

class ImportModelTest
{
    private int $passed = 0;
    private int $failed = 0;
    private string $shop = 'j2store';

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
                $this->shop = $isJ6 ? 'j2commerce' : 'j2store';
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
                $this->testNativeItemsAndRowUndo($db, $context, $data);
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

            // Joomla's text filter applies to the importing identity, not to the session user.
            $filterText = new ReflectionMethod($model, 'applyTextFilter');
            $filterText->setAccessible(true);
            $markup = '<p>kept</p><script>alert(1)</script>';
            $this->test('The Author group (default blocklist filter) loses script elements in imported text',
                fn () => !str_contains($filterText->invoke($model, $markup), '<script')
                    && str_contains($filterText->invoke($model, $markup), '<p>kept</p>'));
            $context['application']->loadIdentity($context['identity']);
            $this->test('A group without text filtering keeps the markup of an imported text',
                fn () => $filterText->invoke($model, $markup) === $markup);
            $context['application']->loadIdentity($author);

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

            // A new category is stored before its article is authorized, so a denial must remove it again.
            $inNewCategory = function (string $label) use ($row): array {
                $item = $row($label);
                unset($item['catid']);
                $suffix = bin2hex(random_bytes(4));

                return $item + [
                    'category_path'  => 'restricted-category-' . $suffix,
                    'category_title' => 'Restricted category ' . $suffix,
                    'category_alias' => 'restricted-category-' . $suffix,
                ];
            };
            $publishInNewCategory = $inNewCategory('new category publish');
            $publishInNewCategory['article_state'] = 1;
            try {
                $this->test('A restricted author denied publication in a new category leaves no category behind',
                    fn () => $denied($publishInNewCategory, [])
                        && $this->countRows($db, '#__categories', 'alias', $publishInNewCategory['category_alias']) === 0);
            } finally {
                $this->removeImportedRow($db, $publishInNewCategory);
            }

            $draftInNewCategory = $inNewCategory('new category draft');
            try {
                $this->test('A restricted author creates a new category unpublished, with its own asset', function () use ($model, $db, $draftInNewCategory) {
                    $result = $model->importProductFull($draftInNewCategory, []);
                    $db->setQuery('SELECT ' . $db->quoteName(['published', 'asset_id']) . ' FROM ' . $db->quoteName('#__categories')
                        . ' WHERE ' . $db->quoteName('extension') . ' = ' . $db->quote('com_content')
                        . ' AND ' . $db->quoteName('alias') . ' = ' . $db->quote($draftInNewCategory['category_alias']));
                    $category = $db->loadAssoc();

                    return ($result['success'] ?? false) === true && $category
                        && (int) $category['published'] === 0 && (int) $category['asset_id'] > 0;
                });
            } finally {
                $this->removeImportedRow($db, $draftInNewCategory);
            }

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

    /**
     * Issue 319: categories and articles created by an import are complete Joomla items, and a
     * row that fails after one of Joomla's nested-set stores (whose table lock commits an open
     * MySQL transaction) leaves none of its writes behind.
     */
    private function testNativeItemsAndRowUndo(object $db, array $context, array $data): void
    {
        $context['application']->loadIdentity($context['identity']);
        $newModel = function (string $class) use ($db): ImportModel {
            $model = (new ReflectionClass($class))->newInstanceWithoutConstructor();
            $model->setDatabase($db);

            return $model;
        };
        $rowFor = function (string $label) use ($data): array {
            $suffix = bin2hex(random_bytes(4));

            return array_replace($data, [
                'title'          => 'Native ' . $label . ' ' . $suffix,
                'alias'          => 'native-' . $label . '-' . $suffix,
                'category_path'  => 'native-parent-' . $suffix . '/native-category-' . $suffix,
                'category_title' => 'Native category ' . $suffix,
                'category_alias' => 'native-category-' . $suffix,
                'variants'       => [['sku' => 'NATIVE-' . strtoupper($suffix), 'price' => 5, 'quantity' => 1]],
            ]);
        };
        $assoc = function (string $sql) use ($db): ?array {
            $db->setQuery($sql);

            return $db->loadAssoc() ?: null;
        };
        $db->setQuery('SELECT ' . $db->quoteName('menutype') . ' FROM ' . $db->quoteName('#__menu_types')
            . ' WHERE ' . $db->quoteName('client_id') . ' = 0 ORDER BY ' . $db->quoteName('id'), 0, 1);
        $menutype = (string) $db->loadResult();
        $menuOptions = ['create_menu' => true, 'menu_published' => false, 'default_menutype' => $menutype];

        $first = $rowFor('category');
        $second = array_replace($rowFor('reuse'), array_intersect_key($first, array_flip(['category_path', 'category_title', 'category_alias'])));
        $afterMenu = array_replace($rowFor('after-menu'), ['catid' => 2]);
        $afterMenu['menu_item'] = ['title' => $afterMenu['title'], 'alias' => $afterMenu['alias']];
        $inTransaction = $rowFor('in-transaction');
        $inTransaction['menu_item'] = ['title' => $inTransaction['title'], 'alias' => $inTransaction['alias']];

        try {
            try {
                $result = $newModel(ImportModel::class)->importProductFull($first, []);
            } catch (\Throwable $e) {
                echo '  [diag] import with a missing category path failed: ' . $e->getMessage() . "\n";
                $result = [];
            }
            $articleId = (int) ($result['article_id'] ?? 0);
            $category = $assoc('SELECT * FROM ' . $db->quoteName('#__categories')
                . ' WHERE ' . $db->quoteName('extension') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('alias') . ' = ' . $db->quote($first['category_alias']));
            $categoryId = (int) ($category['id'] ?? 0);
            $assetQuery = 'SELECT ' . $db->quoteName(['id', 'parent_id', 'name', 'rules']) . ' FROM ' . $db->quoteName('#__assets') . ' WHERE ';
            $categoryAsset = $assoc($assetQuery . $db->quoteName('id') . ' = ' . (int) ($category['asset_id'] ?? 0));
            $componentAsset = $assoc($assetQuery . $db->quoteName('name') . ' = ' . $db->quote('com_content'));
            $article = $assoc('SELECT ' . $db->quoteName(['asset_id', 'catid']) . ' FROM ' . $db->quoteName('#__content')
                . ' WHERE ' . $db->quoteName('id') . ' = ' . $articleId);
            $articleAsset = $assoc($assetQuery . $db->quoteName('id') . ' = ' . (int) ($article['asset_id'] ?? 0));

            $this->test('A row with a missing category path imports', fn () => ($result['success'] ?? false) === true
                && $categoryId > 0 && (int) ($article['catid'] ?? 0) === $categoryId);
            $this->test('The new category has its own asset below the Content component, without own rules',
                fn () => $categoryAsset !== null && $componentAsset !== null
                    && $categoryAsset['name'] === 'com_content.category.' . $categoryId
                    && (int) $categoryAsset['parent_id'] === (int) $componentAsset['id']
                    && $categoryAsset['rules'] === '{}');
            $this->test('The new category has a valid nested-set position and path at the root level',
                fn () => $category !== null && (int) $category['parent_id'] === 1 && (int) $category['level'] === 1
                    && $category['path'] === $first['category_alias'] && (int) $category['lft'] > 0
                    && (int) $category['rgt'] === (int) $category['lft'] + 1);
            $this->test('The new category stores JSON objects for params and metadata',
                fn () => $category !== null && json_decode((string) $category['params']) instanceof \stdClass
                    && json_decode((string) $category['metadata']) instanceof \stdClass);
            $this->test('The new article has its own asset below the category asset, without own rules',
                fn () => $articleAsset !== null && $categoryAsset !== null
                    && $articleAsset['name'] === 'com_content.article.' . $articleId
                    && (int) $articleAsset['parent_id'] === (int) $categoryAsset['id']
                    && $articleAsset['rules'] === '{}');

            Access::clearStatics();
            $this->test('The new category and article inherit the Content permissions of every core group', function () use ($categoryId, $articleId) {
                if ($categoryId < 1 || $articleId < 1) {
                    return false;
                }
                foreach ([2, 3, 4, 5, 6, 7] as $group) {
                    foreach (['core.create', 'core.edit', 'core.edit.own', 'core.edit.state', 'core.delete'] as $action) {
                        $component = Access::checkGroup($group, $action, 'com_content');
                        if (Access::checkGroup($group, $action, 'com_content.category.' . $categoryId) !== $component
                            || Access::checkGroup($group, $action, 'com_content.article.' . $articleId) !== $component) {
                            return false;
                        }
                    }
                }

                return true;
            });

            $this->test('A second row with the same missing path reuses the created category', function () use ($newModel, $second, $categoryId, $db, $assoc) {
                $result = $newModel(ImportModel::class)->importProductFull($second, []);
                $article = $assoc('SELECT ' . $db->quoteName('catid') . ' FROM ' . $db->quoteName('#__content')
                    . ' WHERE ' . $db->quoteName('id') . ' = ' . (int) ($result['article_id'] ?? 0));

                return ($result['success'] ?? false) === true && $categoryId > 0
                    && (int) ($article['catid'] ?? 0) === $categoryId
                    && $this->countRows($db, '#__categories', 'alias', $second['category_alias']) === 1;
            });

            $this->test('A failure after the menu item was stored leaves no article, product or menu item behind',
                function () use ($newModel, $afterMenu, $menuOptions, $db) {
                    try {
                        $newModel(ImportModelFailingAfterMenu::class)->importProductFull($afterMenu, $menuOptions);
                    } catch (\RuntimeException $e) {
                        return str_contains($e->getMessage(), 'Forced failure after the menu item was stored')
                            && $this->rowLeftovers($db, $afterMenu) === [];
                    }

                    return false;
                });

            $this->test('A failure in a later row step leaves no category, article, asset, product or menu item behind',
                function () use ($newModel, $inTransaction, $menuOptions, $db) {
                    try {
                        $newModel(ImportModelFailingInRowTransaction::class)->importProductFull($inTransaction, $menuOptions);
                    } catch (\RuntimeException $e) {
                        return str_contains($e->getMessage(), 'Forced failure in a later row step')
                            && $this->rowLeftovers($db, $inTransaction) === [];
                    }

                    return false;
                });
        } finally {
            foreach ([$second, $first, $afterMenu, $inTransaction] as $row) {
                $this->removeImportedRow($db, $row);
            }
        }
    }

    private function countRows(object $db, string $table, string $column, string $value, string $extra = ''): int
    {
        $db->setQuery('SELECT COUNT(*) FROM ' . $db->quoteName($table)
            . ' WHERE ' . $db->quoteName($column) . ' = ' . $db->quote($value) . $extra);

        return (int) $db->loadResult();
    }

    /**
     * Every item a row may have written that still exists, by kind.
     */
    private function rowLeftovers(object $db, array $row): array
    {
        $articleJoin = 'SELECT COUNT(*) FROM ' . $db->quoteName('#__' . $this->shop . '_products', 'p')
            . ' INNER JOIN ' . $db->quoteName('#__content', 'c') . ' ON ' . $db->quoteName('c.id') . ' = ' . $db->quoteName('p.product_source_id')
            . ' WHERE ' . $db->quoteName('p.product_source') . ' = ' . $db->quote('com_content')
            . ' AND ' . $db->quoteName('c.alias') . ' = ' . $db->quote($row['alias']);
        $db->setQuery($articleJoin);
        $products = (int) $db->loadResult();
        $counts = [
            'category'       => isset($row['catid']) ? 0 : $this->countRows($db, '#__categories', 'alias', $row['category_alias'],
                ' AND ' . $db->quoteName('extension') . ' = ' . $db->quote('com_content')),
            'category asset' => isset($row['catid']) ? 0 : $this->countRows($db, '#__assets', 'title', $row['category_title']),
            'product'        => $products,
            'article'        => $this->countRows($db, '#__content', 'alias', $row['alias']),
            'article asset'  => $this->countRows($db, '#__assets', 'title', $row['title']),
            'menu item'      => $this->countRows($db, '#__menu', 'alias', $row['alias'], ' AND ' . $db->quoteName('client_id') . ' = 0'),
            'variant'        => $this->countRows($db, '#__' . $this->shop . '_variants', 'sku', $row['variants'][0]['sku']),
        ];

        return array_filter($counts);
    }

    /**
     * Test cleanup: removes what a row left behind through Joomla's own tables, so the
     * nested sets stay valid, and falls back to plain deletes for items without an asset.
     */
    private function removeImportedRow(object $db, array $row): void
    {
        $ids = function (string $sql) use ($db): array {
            $db->setQuery($sql);

            return array_map('intval', $db->loadColumn() ?: []);
        };
        $delete = function (string $table, string $column, int $id) use ($db): void {
            $db->setQuery('DELETE FROM ' . $db->quoteName($table) . ' WHERE ' . $db->quoteName($column) . ' = ' . $id)->execute();
        };
        $productKey = $this->shop . '_product_id';
        $variantKey = $this->shop . '_variant_id';

        foreach ($ids('SELECT id FROM ' . $db->quoteName('#__menu') . ' WHERE ' . $db->quoteName('client_id') . ' = 0 AND '
            . $db->quoteName('alias') . ' = ' . $db->quote($row['alias'])) as $menuId) {
            (new MenuTable($db))->delete($menuId);
        }

        foreach ($ids('SELECT id FROM ' . $db->quoteName('#__content') . ' WHERE ' . $db->quoteName('alias') . ' = ' . $db->quote($row['alias'])) as $articleId) {
            foreach ($ids('SELECT ' . $db->quoteName($productKey) . ' FROM ' . $db->quoteName('#__' . $this->shop . '_products')
                . ' WHERE ' . $db->quoteName('product_source') . ' = ' . $db->quote('com_content')
                . ' AND ' . $db->quoteName('product_source_id') . ' = ' . $articleId) as $productId) {
                foreach ($ids('SELECT ' . $db->quoteName($variantKey) . ' FROM ' . $db->quoteName('#__' . $this->shop . '_variants')
                    . ' WHERE ' . $db->quoteName('product_id') . ' = ' . $productId) as $variantId) {
                    $delete('#__' . $this->shop . '_productquantities', 'variant_id', $variantId);
                }
                $delete('#__' . $this->shop . '_variants', 'product_id', $productId);
                $delete('#__' . $this->shop . '_products', $productKey, $productId);
            }
            if (!(new ContentTable($db))->delete($articleId)) {
                $delete('#__content', 'id', $articleId);
            }
        }

        if (isset($row['catid']) || empty($row['category_alias'])) {
            return;
        }

        foreach ($ids('SELECT id FROM ' . $db->quoteName('#__categories') . ' WHERE ' . $db->quoteName('extension') . ' = '
            . $db->quote('com_content') . ' AND ' . $db->quoteName('alias') . ' = ' . $db->quote($row['category_alias'])) as $categoryId) {
            $table = new CategoryTable($db);
            if (!$table->load($categoryId) || !$table->delete($categoryId)) {
                // A category stored without an asset cannot be deleted through the table.
                $delete('#__categories', 'id', $categoryId);
                (new CategoryTable($db))->rebuild();
            }
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
