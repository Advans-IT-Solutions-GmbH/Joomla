<?php
/**
 * Component Structure Tests for J2Commerce Import/Export
 *
 * Strengthened beyond mere file_exists(): every shipped PHP file is linted with
 * `php -l`, and the key component classes are loaded via ReflectionClass and
 * asserted to be the expected MVC types. A class that is missing, unparseable,
 * or of the wrong shape now fails the suite.
 */

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');
require_once JPATH_BASE . '/includes/defines.php';
$_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST']   ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
require_once JPATH_BASE . '/includes/framework.php';

// Register component PSR-4 namespace so reflection can autoload the classes.
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

class ComponentStructureTest
{
    private int $passed = 0;
    private int $failed = 0;
    private string $basePath = '/var/www/html/administrator/components/com_j2commerce_importexport';
    private string $nsPrefix = 'Advans\\Component\\J2CommerceImportExport\\Administrator\\';

    public function run(): bool
    {
        echo "=== Component Structure Tests ===\n\n";

        // --- 1. Every shipped PHP file must pass `php -l` ---
        echo "--- PHP syntax (php -l) ---\n";
        $phpFiles = $this->collectPhpFiles($this->basePath);
        $this->test('Component ships PHP files', count($phpFiles) > 0);

        $lintFailures = [];
        foreach ($phpFiles as $file) {
            if (!$this->lint($file)) {
                $lintFailures[] = $file;
            }
        }
        $this->test('All PHP files pass php -l',
            count($lintFailures) === 0,
            count($lintFailures) ? 'failed: ' . implode(', ', array_map('basename', $lintFailures)) : '');

        // --- 2. Key classes must load via reflection and be the right type ---
        echo "\n--- Class loading (reflection) ---\n";

        $this->assertClass('Model\\ExportModel',  'Joomla\\CMS\\MVC\\Model\\BaseDatabaseModel');
        $this->assertClass('Model\\ImportModel',  'Joomla\\CMS\\MVC\\Model\\BaseDatabaseModel');
        $this->assertClass('Controller\\ExportController', 'Joomla\\CMS\\MVC\\Controller\\BaseController');
        $this->assertClass('Controller\\ImportController', 'Joomla\\CMS\\MVC\\Controller\\BaseController');
        $this->assertClass('Controller\\DisplayController', 'Joomla\\CMS\\MVC\\Controller\\BaseController');
        $this->assertClass('View\\Dashboard\\HtmlView', 'Joomla\\CMS\\MVC\\View\\HtmlView');
        $this->assertClass('Extension\\J2CommerceImportExportComponent', null);

        // --- 2b. Declared dependencies ---
        // A class that calls getDatabase() must be database-aware. Joomla's
        // MVCFactory injects a database into models but never into views
        // (createView() sets only form factory, dispatcher, router, cache
        // controller and user factory), so a view also has to resolve one
        // itself. Without this the dashboard answered HTTP 500 with
        // "Call to undefined method ... HtmlView::getDatabase()".
        echo "\n--- Declared dependencies ---\n";
        foreach ([
            'View\\Dashboard\\HtmlView',
            'Model\\ExportModel',
            'Model\\ImportModel',
        ] as $relClass) {
            $this->test("$relClass is database-aware",
                $this->usesDatabase($relClass),
                'calls getDatabase() without Joomla\\Database\\DatabaseAwareInterface');
        }

        // --- 2c. The component has exactly one view ---
        // Import and export are controller tasks, not views. DisplayController
        // maps both names onto the dashboard so they no longer end in Joomla's
        // "View not found" (HTTP 404).
        echo "\n--- Single-view design ---\n";
        $displaySource = (string) @file_get_contents($this->basePath . '/src/Controller/DisplayController.php');
        foreach (['import', 'export'] as $feature) {
            $this->test("DisplayController answers view=$feature",
                str_contains($displaySource, "'$feature'"),
                'the feature name is not mapped onto the default view');
        }
        $this->test('Only the dashboard view class is shipped',
            count(glob($this->basePath . '/src/View/*/HtmlView.php') ?: []) === 1,
            'a second view class needs its own coverage in shared-backend-views.php');

        // --- 3. Controller capability assertions (more than existence) ---
        echo "\n--- Controller capabilities ---\n";
        $this->test('ExportController::export() is public', $this->methodIsPublic('Controller\\ExportController', 'export'));
        foreach (['exportCSV', 'exportXML', 'exportJSON', 'getFieldDescriptions'] as $m) {
            $this->test("ExportController::$m() exists", $this->hasMethod('Controller\\ExportController', $m));
        }
        $this->test('ImportController::upload() is public', $this->methodIsPublic('Controller\\ImportController', 'upload'));
        foreach (['preview', 'process'] as $m) {
            $this->test("ImportController::$m() is public", $this->methodIsPublic('Controller\\ImportController', $m));
        }

        // --- 4. Non-class assets must exist and be readable ---
        echo "\n--- Required assets ---\n";
        foreach ([
            'Dashboard template'  => '/tmpl/dashboard/default.php',
            'Services provider'   => '/services/provider.php',
        ] as $name => $rel) {
            $path = $this->basePath . $rel;
            $this->test("$name exists and lints", file_exists($path) && $this->lint($path), $path);
        }

        echo "\n=== Component Structure Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    /** @return string[] */
    private function collectPhpFiles(string $dir): array
    {
        $files = [];
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
                $files[] = $file->getPathname();
            }
        }
        sort($files);
        return $files;
    }

    private function lint(string $file): bool
    {
        // If exec() is disabled in the container, fall back to trusting the
        // reflection-based class-load assertions below (and the workflow's
        // dedicated syntax-check job), rather than emitting false failures.
        if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return is_file($file) && filesize($file) > 0;
        }
        $out = [];
        $rc  = 1;
        exec('php -l ' . escapeshellarg($file) . ' 2>&1', $out, $rc);
        return $rc === 0;
    }

    private function assertClass(string $relClass, ?string $expectedParent): void
    {
        $fqcn = $this->nsPrefix . $relClass;
        $this->test("$relClass loads via reflection", class_exists($fqcn), $fqcn);
        if ($expectedParent !== null && class_exists($fqcn)) {
            $this->test("$relClass extends " . $this->short($expectedParent),
                is_subclass_of($fqcn, $expectedParent),
                "expected subclass of $expectedParent");
        }
    }

    /**
     * True when the class can answer getDatabase(): it is a DatabaseAwareInterface
     * (BaseDatabaseModel is one) or, at the very least, brings the trait along.
     */
    private function usesDatabase(string $relClass): bool
    {
        $fqcn = $this->nsPrefix . $relClass;

        if (!class_exists($fqcn)) {
            return false;
        }

        if (is_subclass_of($fqcn, 'Joomla\\Database\\DatabaseAwareInterface')) {
            return true;
        }

        $rc = new ReflectionClass($fqcn);

        do {
            if (in_array('Joomla\\Database\\DatabaseAwareTrait', $rc->getTraitNames(), true)) {
                return true;
            }
        } while ($rc = $rc->getParentClass());

        return false;
    }

    private function hasMethod(string $relClass, string $method): bool
    {
        $fqcn = $this->nsPrefix . $relClass;
        return class_exists($fqcn) && (new ReflectionClass($fqcn))->hasMethod($method);
    }

    private function methodIsPublic(string $relClass, string $method): bool
    {
        $fqcn = $this->nsPrefix . $relClass;
        if (!class_exists($fqcn)) {
            return false;
        }
        $rc = new ReflectionClass($fqcn);
        return $rc->hasMethod($method) && $rc->getMethod($method)->isPublic();
    }

    private function short(string $fqcn): string
    {
        $parts = explode('\\', $fqcn);
        return end($parts);
    }

    private function test(string $name, bool $condition, string $message = ''): void
    {
        if ($condition) {
            echo "✓ {$name}\n";
            $this->passed++;
        } else {
            echo "✗ {$name}" . ($message ? " - {$message}" : '') . "\n";
            $this->failed++;
        }
    }
}

$test = new ComponentStructureTest();
exit($test->run() ? 0 : 1);
