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

namespace Joomla\CMS\Uri {
    final class Uri
    {
        public static string $scheme = 'http';

        public static function getInstance(): self { return new self(); }
        public function getScheme(): string { return self::$scheme; }
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
        public bool $cli = true;

        public function isClient(string $client): bool
        {
            return $client === ($this->cli ? 'cli' : 'administrator');
        }

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

            $entry->isDir() && !$entry->isLink() ? $remove($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    };

    try {
        $duplicateRuntime = $work . '/another-extension/advansinstaller';
        mkdir($duplicateRuntime, 0700, true);
        copy($support . '/load.php', $duplicateRuntime . '/load.php');
        $class = 'JFormFieldAdvanssystemcheck';
        require $duplicateRuntime . '/load.php';
        $assert($class === 'JFormFieldAdvanssystemcheck', 'Runtime loader overwrote Joomla FormHelper caller scope.');
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
            $document = new \DOMDocument();
            $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);
            $section = $document->getElementsByTagName('section')->item(0);
            $assert($section !== null && $section->getAttribute('style') === 'min-width:0;max-width:100%;overflow-wrap:anywhere', 'Shared report must wrap long function names within narrow administrator panels.');
        }

        \Joomla\CMS\Factory::$db->enabled = false;
        $disabled = \Advans\Installer\SystemCheck::results('plg_ajax_joomlaajaxforms');
        \Joomla\CMS\Factory::$db->enabled = true;
        $enabled = \Advans\Installer\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(count($disabled['warnings']) === count($enabled['warnings']) + 1, 'Repeating the check did not refresh actual plugin state.');
        $assert($enabled['blockers'] === [], 'Available platform was reported blocked.');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_HTTPS_UNKNOWN'), array_column($enabled['warnings'], 1), true), 'CLI transport was incorrectly treated as verified.');
        $app->cli = false;
        $insecure = \Advans\Installer\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_HTTPS_INSECURE'), array_column($insecure['warnings'], 1), true), 'HTTP request was not reported insecure.');
        \Joomla\CMS\Uri\Uri::$scheme = 'https';
        $secure = \Advans\Installer\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_HTTPS_CURRENT'), array_column($secure['passed'], 1), true), 'HTTPS administrator request was not recognized.');
        $rewrite = "RewriteCond %{QUERY_STRING} ^option=com_ [NC]\nRewriteRule ^index\\.php$ / [R=301,L]\n";
        file_put_contents(JPATH_ROOT . '/.htaccess', $rewrite);
        $blocked = \Advans\Installer\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_BLOCKED'), array_column($blocked['warnings'], 1), true), 'Blocking option rewrite was not reported.');
        $assert(file_get_contents(JPATH_ROOT . '/.htaccess') === $rewrite, 'Routing check modified existing server configuration.');
        $allowlisted = "RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]\n" . $rewrite;
        file_put_contents(JPATH_ROOT . '/.htaccess', $allowlisted);
        $safe = \Advans\Installer\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_UNVERIFIED'), array_column($safe['warnings'], 1), true), 'Allowlisted routes were blocked or incorrectly reported as HTTP-verified.');
        $routing = new \Advans\Installer\RoutingCheck();
        $assert($routing->analyse("RewriteRule ^component/(.*)$ / [R=301,L]") === ['component'], 'Component route blocking was missed.');
        $assert($routing->analyse("RewriteCond %{QUERY_STRING} !plugin= [NC]\nRewriteRule ^component/(.*)$ / [R=301,L]") === [], 'Plugin exclusion was ignored.');
        $assert($routing->analyse("RewriteCond %{QUERY_STRING} !plugin= [NC,OR]\nRewriteCond %{REQUEST_URI} ^/component/\nRewriteRule ^component/(.*)$ / [R=301,L]") === ['component'], 'OR group bypass was missed.');
        $assert($routing->analyse("RewriteCond %{HTTPS} off\nRewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]") === [], 'Generic HTTPS redirection was treated as component blocking.');
        $assert($routing->analyse($rewrite, 'com_appointments') === ['option'], 'Non-AJAX component option routes were not checked.');
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
        $productManifest = $productSource . '/plg_j2commerce_productcompare.xml';
        file_put_contents($productManifest, $productXml);
        file_put_contents($canonical . '/productcompare.xml', '<foreign/>');
        $productFiles = \Advans\Installer\Destinations::files(simplexml_load_string($productXml), $productSource, $productManifest);
        mkdir($work . '/options-backups', 0700);
        $optionsPlan = \Advans\Installer\FileGuard::inspect($productFiles, $work . '/options-backups');
        $assert(count($optionsPlan) === 1 && !$optionsPlan[0]['approved'], 'A foreign options manifest must require explicit approval.');
        $assert(file_get_contents($canonical . '/productcompare.xml') === '<foreign/>', 'Options manifest inventory changed existing bytes.');
        \Advans\Installer\FileGuard::verifyBackup($optionsPlan[0]['backup'], $optionsPlan[0]['destination'], $optionsPlan[0]['existing']);
        require dirname(__DIR__, 2) . '/j2commerce/plg_j2commerce_productcompare/script.php';
        $optionsInstaller = new \ReflectionMethod(\PlgJ2commerceProductcompareInstallerScript::class, 'installOptionsManifest');
        $optionsParent = new class($productManifest) {
            public function __construct(private string $manifest) {}
            public function getParent(): self { return $this; }
            public function getPath(string $key): string { return $this->manifest; }
        };
        $optionsInstaller->invoke(new \PlgJ2commerceProductcompareInstallerScript(), $optionsParent);
        $assert(file_get_contents($canonical . '/productcompare.xml') === $productXml, 'Plugin options manifest was not installed with its element name.');
        $copyMirror = new \ReflectionMethod(\PlgJ2commerceProductcompareInstallerScript::class, 'copyMirrorFiles');
        $copyMirror->invoke(new \PlgJ2commerceProductcompareInstallerScript(), $productFiles, $mirror);
        $assert(file_get_contents($mirror . '/payload.php') === '<?php echo "incoming";', 'Incoming mirrored payload was not copied.');
        $assert(file_get_contents($mirror . '/productcompare.xml') === $productXml, 'Joomla 5 mirror lacks the element-named options manifest.');
        $assert(file_get_contents($mirror . '/custom.php') === '<?php echo "mirror custom";', 'An unlisted canonical file overwrote a mirror customization.');
        $legacyCopy = new \ReflectionMethod(\PlgJ2commerceProductcompareInstallerScript::class, 'copyLegacyTree');
        $legacyCopy->invoke(new \PlgJ2commerceProductcompareInstallerScript(), $canonical, $work . '/legacy-copy');
        $assert(file_get_contents($work . '/legacy-copy/custom.php') === '<?php echo "canonical custom";', 'Legacy uninstall copy helper did not preserve the old plugin files.');
        if (PHP_OS_FAMILY !== 'Windows') {
            $remove($mirror);
            $assert(symlink($canonical, $mirror), 'Could not create the disposable legacy mirror symlink.');
            (new \PlgJ2commerceProductcompareInstallerScript())->uninstall(null);
            $assert(!is_link($mirror) && is_file($mirror . '/custom.php'), 'Legacy symlink uninstall did not prepare a real directory for Joomla.');
            $assert(is_file($canonical . '/custom.php'), 'Legacy symlink preparation removed its original target.');
        }
        echo "PASS common lifecycle: abort, verified backup, explicit approval, retry, translated reports and refreshed state\n";
    } finally {
        $remove($work);
    }
}
