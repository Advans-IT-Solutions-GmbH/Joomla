<?php
/**
 * Exercise the common lifecycle against a disposable filesystem and Joomla API doubles.
 * The CI installer-message suite additionally uses real Joomla.
 */

namespace Joomla\CMS\Installer {
    class InstallerScript
    {
        public function preflight($type, $parent)
        {
            return true;
        }
    }
}

namespace Joomla\CMS {
    final class Version
    {
        public const MAJOR_VERSION = 5;

        public function getShortVersion(): string
        {
            return '5.4.0';
        }
    }

    final class Factory
    {
        public static object $app;
        public static object $db;

        public static function getApplication(): object
        {
            return self::$app;
        }

        public static function getContainer(): object
        {
            return new class {
                public function get(string $class): object
                {
                    return Factory::$db;
                }
            };
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        public static array $strings = [];

        public static function _(string $key): string
        {
            return self::$strings[$key] ?? $key;
        }

        public static function sprintf(string $key, mixed ...$arguments): string
        {
            return sprintf(self::_($key), ...$arguments);
        }
    }
}

namespace Joomla\CMS\Log {
    final class Log
    {
        public const ERROR = 1;

        public static function add(string $message, int $level, string $category): void
        {
            throw new \RuntimeException('Unexpected guard failure: ' . $message);
        }
    }
}

namespace Joomla\Database {
    final class ParameterType
    {
        public const STRING = 'string';
    }
}

namespace {
    define('_JEXEC', 1);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $work = sys_get_temp_dir() . '/advans-lifecycle-' . bin2hex(random_bytes(12));
    mkdir($work, 0700, true);
    define('JPATH_ROOT', $work . '/site');
    define('JPATH_SITE', JPATH_ROOT);
    define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
    define('JPATH_PLUGINS', str_replace('/', DIRECTORY_SEPARATOR, JPATH_ROOT . '/plugins'));
    $support = dirname(__DIR__) . '/installer';
    require_once $support . '/load.php';

    $app = new class {
        public string $tag = 'en-GB';
        public array $messages = [];

        public function getLanguage(): object
        {
            return $this;
        }

        public function load(string $extension, string $root): bool
        {
            \Joomla\CMS\Language\Text::$strings = parse_ini_file($root . '/language/' . $this->tag . '/' . $extension . '.ini', false, INI_SCANNER_RAW);

            return true;
        }

        public function enqueueMessage(string $message, string $type): void
        {
            $this->messages[] = [$message, $type];
        }
    };
    \Joomla\CMS\Factory::$app = $app;
    \Joomla\CMS\Factory::$db = new class {
        public bool $enabled = false;

        public function createQuery(): object
        {
            return new class {
                public function select($value): self { return $this; }
                public function from($value): self { return $this; }
                public function where($value): self { return $this; }
                public function whereIn($column, $values, $type): self { return $this; }
            };
        }

        public function quoteName($value) { return $value; }
        public function quote($value) { return "'" . $value . "'"; }
        public function setQuery($query): self { return $this; }
        public function loadObject(): object { return (object) ['extension_id' => 1, 'enabled' => (int) $this->enabled]; }
        public function getPrefix(): string { return 'test_'; }
        public function getTableList(): array { return ['test_j2commerce_orders', 'test_j2store_orders']; }
    };
    $assert = static function (bool $ok, string $message): void {
        if (!$ok) {
            throw new \RuntimeException($message);
        }
    };
    $remove = static function (string $directory) use (&$remove): void {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }

            $entry->isDir() ? $remove($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    };

    try {
        $duplicateRuntime = $work . '/another-extension/advansinstaller';
        mkdir($duplicateRuntime, 0700, true);
        copy($support . '/load.php', $duplicateRuntime . '/load.php');
        require $duplicateRuntime . '/load.php';
        $assert(class_exists(\Advans\Installer\InstallerScript::class, false), 'Loading another extension did not reuse the shared runtime.');
        $source = $work . '/source';
        $destination = JPATH_PLUGINS . '/ajax/joomlaajaxforms';
        mkdir($source, 0700, true);
        mkdir($destination, 0700, true);
        file_put_contents($source . '/payload.php', '<?php echo "new";');
        file_put_contents($destination . '/payload.php', '<?php echo "foreign";');
        $xml = '<extension type="plugin" group="ajax"><name>PLG_AJAX_JOOMLAAJAXFORMS</name><files><filename>payload.php</filename></files></extension>';
        file_put_contents($source . '/joomlaajaxforms.xml', $xml);
        $parent = new class($source, simplexml_load_string($xml)) {
            public function __construct(private string $source, private \SimpleXMLElement $manifest) {}
            public function getParent(): self { return $this; }
            public function getManifest(): \SimpleXMLElement { return $this->manifest; }
            public function getPath(string $key): string { return $key === 'manifest' ? $this->source . '/joomlaajaxforms.xml' : $this->source; }
        };
        $installer = new class extends \Advans\Installer\InstallerScript {
            protected function extensionPostflight($type, $parent)
            {
                return true;
            }
        };
        $assert($installer->preflight('install', $parent) === false, 'Foreign destination was not blocked.');
        $assert(file_get_contents($destination . '/payload.php') === '<?php echo "foreign";', 'Blocked preflight changed a destination.');
        $plan = JPATH_ADMINISTRATOR . '/advans-installer-state/plg_ajax_joomlaajaxforms/plan.php';
        $assert(is_file($plan), 'Blocked preflight did not preserve a review plan.');
        $process = proc_open([PHP_BINARY, $support . '/approve.php', '--approve', $plan, hash_file('sha256', $plan)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Approval command failed: ' . $output);
        $assert($installer->preflight('install', $parent) === true, 'Approved retry was not accepted.');
        copy($source . '/payload.php', $destination . '/payload.php');
        copy($source . '/joomlaajaxforms.xml', $destination . '/joomlaajaxforms.xml');

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $app->tag = $tag;
            $app->messages = [];
            $assert($installer->postflight('install', $parent) === true, 'Successful extension postflight was not preserved.');
            $html = $app->messages[0][0];
            $strings = \Joomla\CMS\Language\Text::$strings;

            foreach (['ADVANS_CHECK_PASSED', 'ADVANS_CHECK_BLOCKERS', 'ADVANS_CHECK_WARNINGS', 'ADVANS_CHECK_REPEAT'] as $key) {
                $assert(str_contains(html_entity_decode($html, ENT_QUOTES, 'UTF-8'), $strings[$key]), 'Missing translated section: ' . $tag . ' ' . $key);
            }

            $assert(!str_contains($html, 'ADVANS_CHECK_'), 'Untranslated shared key in live report.');
            $assert(str_contains($html, 'extension_id=1'), 'Report does not link to the repeatable plugin check.');
        }

        \Joomla\CMS\Factory::$db->enabled = false;
        $disabled = \Advans\Installer\SystemCheck::results('plg_ajax_joomlaajaxforms');
        \Joomla\CMS\Factory::$db->enabled = true;
        $enabled = \Advans\Installer\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(count($disabled['warnings']) === 1 && count($enabled['warnings']) === 0, 'Repeating the check did not refresh actual plugin state.');
        $assert($enabled['blockers'] === [], 'Available platform was reported blocked.');
        $mixedShops = \Advans\Installer\SystemCheck::results('plg_osmap_j2commerce');
        $shopRows = array_values(array_filter($mixedShops['passed'], static fn(array $row): bool => $row[0] === \Joomla\CMS\Language\Text::_('ADVANS_CHECK_SHOP')));
        $assert(count($shopRows) === 1 && $shopRows[0][1] === 'com_j2commerce, com_j2store', 'Mixed-shop check hid an enabled shop or implied a shared routing priority.');
        $productSource = $work . '/product-source';
        $canonical = JPATH_PLUGINS . '/j2commerce/productcompare';
        $mirror = JPATH_PLUGINS . '/j2store/productcompare';
        foreach ([$productSource, $canonical, $mirror] as $directory) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($productSource . '/payload.php', '<?php echo "incoming";');
        file_put_contents($canonical . '/custom.php', '<?php echo "canonical custom";');
        file_put_contents($mirror . '/custom.php', '<?php echo "mirror custom";');
        $productXml = '<extension type="plugin" group="j2commerce"><name>plg_j2commerce_productcompare</name><files><filename plugin="productcompare">payload.php</filename></files></extension>';
        file_put_contents($productSource . '/productcompare.xml', $productXml);
        $productFiles = \Advans\Installer\Destinations::files(simplexml_load_string($productXml), $productSource, $productSource . '/productcompare.xml');
        require dirname(__DIR__, 2) . '/j2commerce/plg_j2commerce_productcompare/script.php';
        $copyMirror = new \ReflectionMethod(\PlgJ2commerceProductcompareInstallerScript::class, 'copyMirrorFiles');
        $copyMirror->invoke(new \PlgJ2commerceProductcompareInstallerScript(), $productFiles, $mirror);
        $assert(file_get_contents($mirror . '/payload.php') === '<?php echo "incoming";', 'Incoming mirrored payload was not copied.');
        $assert(file_get_contents($mirror . '/custom.php') === '<?php echo "mirror custom";', 'An unlisted canonical file overwrote a mirror customization.');
        echo "PASS common lifecycle: abort, verified backup, explicit approval, retry, translated reports and refreshed state\n";
    } finally {
        $remove($work);
    }
}
