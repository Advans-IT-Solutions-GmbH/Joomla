<?php
/**
 * Active-shop detection tests for J2Commerce Import/Export.
 *
 * The component must work with the ACTIVE shop component, not with whatever
 * tables happen to exist. After a migration both #__j2store_* and
 * #__j2commerce_* tables exist, and a J2Store site may still carry
 * j2commerce tables of an aborted installation.
 *
 * On each stack this test adds the table set of the OTHER shop (minimal
 * #__<other>_products / #__<other>_variants) with different test data and a
 * disabled extension row for the other component, then asserts through the
 * public ExportModel::exportData('variants') which data set is exported:
 *
 *   A  installed shop enabled, other disabled   → installed shop's data
 *   B  installed shop disabled, other enabled   → other shop's data
 *   C  both disabled (fallback)                 → j2commerce data (table rule)
 *
 * J6 stack (J2COMMERCE_STACK=j6): installed = J2Commerce 6, other = J2Store.
 *   Scenario B fails with the former table-only detection.
 * J5 stack: installed = J2Store 4, other = J2Commerce 6 tables.
 *   Scenario A fails with the former table-only detection.
 *
 * Every scenario uses a new model instance (the detection is cached per
 * instance). Afterwards only the tables/rows created here are removed and the
 * extension states are restored.
 */
define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\QueryInterface;

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

use Advans\Component\J2CommerceImportExport\Administrator\Model\ExportModel;

class ActiveShopDetectionTest
{
    private DatabaseInterface $db;
    private bool $isJ6Stack;

    /** Table set prefix ('j2commerce' / 'j2store') of the really installed shop */
    private string $installed;
    /** Table set prefix of the shop added by this test */
    private string $other;

    /** @var array<string, array{id:int, enabled:int, created:bool}> */
    private array $components = [];
    /** @var string[] Tables created by this test (without prefix) */
    private array $createdTables = [];
    /** @var array<int, array{table:string, pk:string, id:int}> Rows inserted into pre-existing tables */
    private array $insertedRows = [];

    /** @var array<string, string> SKU per table set */
    private array $sku = [];

    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        $this->db        = Factory::getContainer()->get(DatabaseInterface::class);
        $this->isJ6Stack = getenv('J2COMMERCE_STACK') === 'j6';
        $this->installed = $this->isJ6Stack ? 'j2commerce' : 'j2store';
        $this->other     = $this->isJ6Stack ? 'j2store' : 'j2commerce';
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "PASS $name\n";
                $this->passed++;
            } else {
                echo "FAIL $name\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "FAIL $name — " . get_class($e) . ': ' . $e->getMessage() . "\n";
            $this->failed++;
        }
    }

    private function query(): QueryInterface
    {
        return method_exists($this->db, 'createQuery') ? $this->db->createQuery() : $this->db->getQuery(true);
    }

    private function element(string $set): string
    {
        return 'com_' . $set;
    }

    private function tableExists(string $table): bool
    {
        return !empty($this->db->setQuery(
            'SHOW TABLES LIKE ' . $this->db->quote($this->db->getPrefix() . $table)
        )->loadResult());
    }

    private function loadExtension(string $element): ?object
    {
        return $this->db->setQuery(
            $this->query()
                ->select([$this->db->quoteName('extension_id'), $this->db->quoteName('enabled')])
                ->from($this->db->quoteName('#__extensions'))
                ->where($this->db->quoteName('type') . ' = ' . $this->db->quote('component'))
                ->where($this->db->quoteName('element') . ' = ' . $this->db->quote($element))
                ->order($this->db->quoteName('extension_id') . ' ASC')
        )->loadObject() ?: null;
    }

    private function setEnabled(string $element, int $enabled): void
    {
        $this->db->setQuery(
            $this->query()
                ->update($this->db->quoteName('#__extensions'))
                ->set($this->db->quoteName('enabled') . ' = ' . $enabled)
                ->where($this->db->quoteName('extension_id') . ' = ' . (int) $this->components[$element]['id'])
        )->execute();
    }

    /**
     * Records the real extension row of the installed shop and makes sure a
     * row for the other shop exists (inserted disabled when missing).
     */
    private function prepareExtensions(): bool
    {
        $installedRow = $this->loadExtension($this->element($this->installed));
        if ($installedRow === null) {
            echo "INFO no component row for {$this->element($this->installed)}\n";
            return false;
        }
        $this->components[$this->element($this->installed)] = [
            'id'      => (int) $installedRow->extension_id,
            'enabled' => (int) $installedRow->enabled,
            'created' => false,
        ];

        $otherElement = $this->element($this->other);
        $otherRow     = $this->loadExtension($otherElement);
        if ($otherRow !== null) {
            $this->components[$otherElement] = [
                'id'      => (int) $otherRow->extension_id,
                'enabled' => (int) $otherRow->enabled,
                'created' => false,
            ];
            echo "INFO existing $otherElement row #{$otherRow->extension_id} (enabled={$otherRow->enabled})\n";
            return true;
        }

        $extension = (object) [
            'name'           => $otherElement,
            'type'           => 'component',
            'element'        => $otherElement,
            'folder'         => '',
            'client_id'      => 1,
            'enabled'        => 0,
            'access'         => 1,
            'protected'      => 0,
            'locked'         => 0,
            'manifest_cache' => '{}',
            'params'         => '{}',
            'custom_data'    => '',
            'ordering'       => 0,
            'state'          => 0,
            'note'           => 'temporary row of 09-active-shop-detection.php',
        ];
        $this->db->insertObject('#__extensions', $extension, 'extension_id');
        $this->components[$otherElement] = [
            'id'      => (int) $extension->extension_id,
            'enabled' => 0,
            'created' => true,
        ];
        echo "INFO registered temporary $otherElement row #{$extension->extension_id} (enabled=0)\n";

        return (int) $extension->extension_id > 0;
    }

    /**
     * Creates the minimal product/variant tables of the other shop when they
     * do not exist yet. The columns cover what the fixture writes and what
     * exportData('variants') reads.
     */
    private function createOtherTables(): void
    {
        $set    = $this->other;
        $tables = [
            $set . '_products' => 'CREATE TABLE %s ('
                . ' `' . $set . '_product_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `product_source_id` INT NOT NULL DEFAULT 0,'
                . ' `product_source` VARCHAR(255) NOT NULL DEFAULT \'\','
                . ' `product_type` VARCHAR(255) NOT NULL DEFAULT \'\','
                . ' `visibility` TINYINT NOT NULL DEFAULT 1,'
                . ' `enabled` TINYINT NOT NULL DEFAULT 1,'
                . ' `taxprofile_id` INT NOT NULL DEFAULT 0,'
                . ' `vendor_id` INT NOT NULL DEFAULT 0,'
                . ' `addtocart_text` VARCHAR(255) NOT NULL DEFAULT \'\','
                . ' `up_sells` TEXT NULL,'
                . ' `cross_sells` TEXT NULL,'
                . ' `params` TEXT NULL,'
                . ' PRIMARY KEY (`' . $set . '_product_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
            $set . '_variants' => 'CREATE TABLE %s ('
                . ' `' . $set . '_variant_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,'
                . ' `product_id` INT UNSIGNED NOT NULL DEFAULT 0,'
                . ' `sku` VARCHAR(255) NOT NULL DEFAULT \'\','
                . ' `price` DECIMAL(15,5) NOT NULL DEFAULT 0,'
                . ' `pricing_calculator` VARCHAR(255) NOT NULL DEFAULT \'\','
                . ' `shipping` TINYINT NOT NULL DEFAULT 0,'
                . ' `quantity_restriction` TINYINT NOT NULL DEFAULT 0,'
                . ' `allow_backorder` TINYINT NOT NULL DEFAULT 0,'
                . ' `is_master` TINYINT NOT NULL DEFAULT 0,'
                . ' `isdefault_variant` TINYINT NOT NULL DEFAULT 0,'
                . ' `enabled` TINYINT NOT NULL DEFAULT 1,'
                . ' `params` TEXT NULL,'
                . ' PRIMARY KEY (`' . $set . '_variant_id`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4',
        ];

        foreach ($tables as $table => $sql) {
            if ($this->tableExists($table)) {
                echo "INFO table #__$table already exists; only test rows are added\n";
                continue;
            }
            $this->db->setQuery(sprintf($sql, $this->db->quoteName('#__' . $table)))->execute();
            $this->createdTables[] = $table;
            echo "INFO created temporary table #__$table\n";
        }
    }

    /**
     * Inserts one product with a master variant into the given table set.
     */
    private function seed(string $set, string $label): void
    {
        $sku = 'ASD-' . $label . '-' . strtoupper(bin2hex(random_bytes(4)));

        $product = (object) [
            'product_source_id' => 0,
            'product_source'    => 'com_content',
            'product_type'      => 'simple',
            'visibility'        => 1,
            'enabled'           => 1,
            'taxprofile_id'     => 0,
            'vendor_id'         => 0,
            'addtocart_text'    => '',
            'up_sells'          => '',
            'cross_sells'       => '',
            'params'            => '{}',
        ];
        $pkProd = $set . '_product_id';
        $this->db->insertObject('#__' . $set . '_products', $product, $pkProd);
        $productId = (int) $this->db->insertid();
        $this->trackRow($set . '_products', $pkProd, $productId);

        $variant = (object) [
            'product_id'           => $productId,
            'sku'                  => $sku,
            'price'                => $set === 'j2commerce' ? 16.00 : 4.00,
            'pricing_calculator'   => 'standard',
            'shipping'             => 1,
            'quantity_restriction' => 0,
            'allow_backorder'      => 0,
            'is_master'            => 1,
            'isdefault_variant'    => 1,
            'enabled'              => 1,
            'params'               => '{}',
        ];
        $pkVar = $set . '_variant_id';
        $this->db->insertObject('#__' . $set . '_variants', $variant, $pkVar);
        $variantId = (int) $this->db->insertid();
        $this->trackRow($set . '_variants', $pkVar, $variantId);

        $this->sku[$set] = $sku;
        echo "INFO seeded #__{$set}_variants SKU $sku (product $productId, variant $variantId)\n";
    }

    private function trackRow(string $table, string $pk, int $id): void
    {
        // Rows in tables created here disappear with the table.
        if (!in_array($table, $this->createdTables, true)) {
            $this->insertedRows[] = ['table' => $table, 'pk' => $pk, 'id' => $id];
        }
    }

    /**
     * Sets the enabled state of both shop components.
     */
    private function activate(int $installedEnabled, int $otherEnabled): void
    {
        $this->setEnabled($this->element($this->installed), $installedEnabled);
        $this->setEnabled($this->element($this->other), $otherEnabled);
    }

    /**
     * Exports variants with a NEW model instance (detection is cached per
     * instance) and reports which seeded SKUs and key columns are present.
     */
    private function exportVariants(): array
    {
        // newInstanceWithoutConstructor avoids BaseDatabaseModel::__construct()
        // calling Factory::getApplication() (same pattern as 03-export-model.php).
        $model = (new \ReflectionClass(ExportModel::class))->newInstanceWithoutConstructor();
        $model->setDatabase($this->db);

        $rows   = $model->exportData('variants');
        $result = ['j2commerce' => false, 'j2store' => false, 'key' => null, 'count' => count($rows)];

        foreach ($rows as $row) {
            foreach (['j2commerce', 'j2store'] as $set) {
                if (isset($this->sku[$set]) && ($row['sku'] ?? null) === $this->sku[$set]) {
                    $result[$set] = true;
                }
            }
        }
        if ($rows !== []) {
            $first = $rows[0];
            $result['key'] = array_key_exists('j2commerce_variant_id', $first)
                ? 'j2commerce'
                : (array_key_exists('j2store_variant_id', $first) ? 'j2store' : null);
        }

        return $result;
    }

    private function assertExports(string $scenario, string $expected): void
    {
        $unexpected = $expected === 'j2commerce' ? 'j2store' : 'j2commerce';
        $export     = $this->exportVariants();
        echo "INFO $scenario: {$export['count']} rows, key set " . ($export['key'] ?? 'none') . "\n";

        $this->test("$scenario: export contains #__{$expected}_variants test data", fn () => $export[$expected] === true);
        $this->test("$scenario: export does not contain #__{$unexpected}_variants test data", fn () => $export[$unexpected] === false);
        $this->test("$scenario: exported rows use {$expected}_variant_id", fn () => $export['key'] === $expected);
    }

    private function restore(): void
    {
        echo "\n--- Restore ---\n";

        foreach ($this->insertedRows as $row) {
            try {
                $this->db->setQuery(
                    $this->query()
                        ->delete($this->db->quoteName('#__' . $row['table']))
                        ->where($this->db->quoteName($row['pk']) . ' = ' . (int) $row['id'])
                )->execute();
            } catch (\Throwable $e) {
                echo "WARN could not delete row {$row['id']} from #__{$row['table']}: {$e->getMessage()}\n";
            }
        }

        foreach ($this->createdTables as $table) {
            try {
                $this->db->setQuery('DROP TABLE IF EXISTS ' . $this->db->quoteName('#__' . $table))->execute();
                echo "INFO dropped temporary table #__$table\n";
            } catch (\Throwable $e) {
                echo "WARN could not drop #__$table: {$e->getMessage()}\n";
            }
        }

        foreach ($this->components as $element => $info) {
            try {
                if ($info['created']) {
                    $this->db->setQuery(
                        $this->query()
                            ->delete($this->db->quoteName('#__extensions'))
                            ->where($this->db->quoteName('extension_id') . ' = ' . (int) $info['id'])
                    )->execute();
                    echo "INFO removed temporary $element row #{$info['id']}\n";
                    continue;
                }
                $this->setEnabled($element, $info['enabled']);
                echo "INFO restored $element enabled={$info['enabled']}\n";
            } catch (\Throwable $e) {
                echo "WARN could not restore $element: {$e->getMessage()}\n";
            }
        }

        $this->test('Restore: temporary tables removed', function () {
            foreach ($this->createdTables as $table) {
                if ($this->tableExists($table)) {
                    return false;
                }
            }
            return true;
        });
        $this->test('Restore: extension states as before', function () {
            foreach ($this->components as $info) {
                $row = $this->db->setQuery(
                    $this->query()
                        ->select($this->db->quoteName('enabled'))
                        ->from($this->db->quoteName('#__extensions'))
                        ->where($this->db->quoteName('extension_id') . ' = ' . (int) $info['id'])
                )->loadResult();

                if ($info['created'] && $row !== null) {
                    return false; // temporary row still present
                }
                if (!$info['created'] && (int) $row !== $info['enabled']) {
                    return false;
                }
            }
            return true;
        });
    }

    public function run(): bool
    {
        echo "=== Active Shop Detection Tests ===\n";
        echo 'Stack: ' . ($this->isJ6Stack ? 'J6 + J2Commerce 6' : 'J5 + J2Store 4')
            . " (installed {$this->installed}, added {$this->other})\n\n";

        // Preconditions: the installed shop must really be there. No skip.
        $installedTable = $this->installed . '_products';
        $this->test("Precondition: #__$installedTable exists", fn () => $this->tableExists($installedTable));
        if (!$this->tableExists($installedTable)) {
            return $this->summary();
        }

        try {
            $this->runScenarios();
        } catch (\Throwable $e) {
            $this->test('Scenario run without exception', fn () => false);
            echo '  ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        } finally {
            $this->restore();
        }

        return $this->summary();
    }

    private function runScenarios(): void
    {
        $this->test('Precondition: component row for ' . $this->element($this->installed) . ' exists', fn () => $this->prepareExtensions());
        if (!isset($this->components[$this->element($this->installed)], $this->components[$this->element($this->other)])) {
            return;
        }

        echo "\n--- Fixture ---\n";
        $this->createOtherTables();
        $this->test("Fixture: #__{$this->other}_products exists", fn () => $this->tableExists($this->other . '_products'));
        $this->test("Fixture: #__{$this->other}_variants exists", fn () => $this->tableExists($this->other . '_variants'));
        $this->seed($this->installed, 'INSTALLED');
        $this->seed($this->other, 'OTHER');

        echo "\n--- A: installed shop active, other component disabled ---\n";
        $this->activate(1, 0);
        $this->assertExports('A (' . $this->element($this->installed) . ' active)', $this->installed);

        echo "\n--- B: installed shop disabled, other component active ---\n";
        $this->activate(0, 1);
        $this->assertExports('B (' . $this->element($this->other) . ' active)', $this->other);

        echo "\n--- C: no shop component active (table fallback) ---\n";
        $this->activate(0, 0);
        $this->assertExports('C (none active, j2commerce tables present)', 'j2commerce');
    }

    private function summary(): bool
    {
        echo "\n=== Active Shop Detection Summary ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n";
        echo 'Total:  ' . ($this->passed + $this->failed) . "\n";

        return $this->failed === 0;
    }
}

$test = new ActiveShopDetectionTest();
exit($test->run() ? 0 : 1);
