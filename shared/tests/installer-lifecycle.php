<?php
/**
 * Exercise the common lifecycle against a disposable filesystem and Joomla API doubles.
 * The CI installer-message suite additionally uses real Joomla.
 */

namespace Advans\Installer {
    // An installer runtime from before V2, loaded earlier in the same request by another
    // extension (for example in a bulk update). It must not take the place of this runtime.
    abstract class InstallerScript
    {
        public function preflight($type, $parent)
        {
            return false;
        }
    }

    final class FileGuard
    {
    }

    final class Destinations
    {
    }
}

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
        public const WARNING = 2;

        /** @var list<string> */
        public static array $entries = [];

        public static function add(string $message, int $level, string $category): void
        {
            self::$entries[] = $message;
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

        public function get(string $key, mixed $default = null): mixed
        {
            return $default;
        }

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

        /** @var list<array<string, mixed>> Tasks saved through the Scheduler's task model. */
        public array $saved = [];

        public function getIdentity(): object
        {
            return (object) ['id' => 42];
        }

        public function bootComponent(string $component): object
        {
            $app = $this;

            return new class($app) {
                public function __construct(private object $app) {}
                public function getMVCFactory(): self { return $this; }
                public function createModel(string $name, string $prefix, array $config): self { return $this; }

                public function save(array $data): bool
                {
                    $this->app->saved[] = $data;
                    \Joomla\CMS\Factory::$db->tasks[$data['type']][] = ['id' => 100 + count($this->app->saved), 'state' => $data['state'],
                        'last_execution' => null, 'last_exit_code' => 0, 'times_executed' => 0];

                    return true;
                }
            };
        }
    };
    \Joomla\CMS\Factory::$app = $app;
    \Joomla\CMS\Factory::$db = new class {
        public bool $enabled = false;
        /** @var array<string, list<array<string, mixed>>> Scheduler tasks by routine. */
        public array $tasks = [];
        /** @var array<string, array{enabled: int, params: string}> Extensions by "type:folder:element". */
        public array $extensions = [];
        private object $query;

        public function createQuery(): object
        {
            return new class {
                public string $from = '';
                /** @var list<string> */
                public array $where = [];
                public function select($value): self { return $this; }
                public function from($value): self { $this->from = (string) $value; return $this; }
                public function where($value): self { $this->where[] = (string) $value; return $this; }
                public function whereIn($column, $values, $type): self { return $this; }
                public function order($value): self { return $this; }
            };
        }

        public function quoteName($value) { return $value; }
        public function quote($value) { return "'" . $value . "'"; }
        public function setQuery($query): self { $this->query = $query; return $this; }

        private function condition(string $column): ?string
        {
            foreach ($this->query->where as $condition) {
                if (preg_match("/^$column = '(.*)'$/", $condition, $match) === 1) {
                    return $match[1];
                }
            }

            return null;
        }

        private function key(): string
        {
            return $this->condition('type') . ':' . ($this->condition('folder') ?? '') . ':' . $this->condition('element');
        }

        public function loadObject(): ?object
        {
            if (isset($this->extensions[$this->key()])) {
                return (object) ['extension_id' => 7, 'enabled' => $this->extensions[$this->key()]['enabled']];
            }

            // Lookups by folder and of the Scheduler component see only what a test registered.
            if ($this->condition('folder') !== null || $this->condition('element') === 'com_scheduler') {
                return null;
            }

            return (object) ['extension_id' => 1, 'enabled' => (int) $this->enabled];
        }

        public function loadResult(): ?string { return $this->extensions[$this->key()]['params'] ?? null; }
        public function loadAssocList(): array { return $this->tasks[$this->condition('type')] ?? []; }
        public function getPrefix(): string { return 'test_'; }
        public function getTableList(): array { return ['test_j2commerce_orders', 'test_j2store_orders', 'test_scheduler_tasks']; }
    };    $assert = static function (bool $ok, string $message): void {
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
        $assert(class_exists(\Advans\Installer\V2\InstallerScript::class, false), 'Loading another extension did not reuse the shared runtime.');
        // Mixed bulk update: an older runtime already holds Advans\Installer; this one must still
        // use its own classes, from its own files.
        $assert(class_exists(\Advans\Installer\InstallerScript::class, false), 'The older runtime fixture is not loaded.');

        foreach (['InstallerScript', 'FileGuard', 'Destinations', 'Planner', 'Knowledge', 'Approval', 'State'] as $runtimeClass) {
            $file = (new \ReflectionClass('Advans\\Installer\\V2\\' . $runtimeClass))->getFileName();
            $assert(realpath((string) $file) === realpath($support . '/' . $runtimeClass . '.php'), 'An older runtime replaced ' . $runtimeClass . '.');
        }
        $source = $work . '/source';
        $destination = JPATH_PLUGINS . '/ajax/joomlaajaxforms';
        $state = JPATH_ADMINISTRATOR . '/advans-installer-state/plg_ajax_joomlaajaxforms';
        mkdir($source, 0700, true);
        mkdir($destination, 0700, true);
        file_put_contents($source . '/payload.php', '<?php echo "new";');
        file_put_contents($source . '/untouched.php', '<?php echo "untouched, new release";');
        file_put_contents($destination . '/payload.php', '<?php echo "changed on this site";');
        file_put_contents($destination . '/untouched.php', '<?php echo "earlier release";');
        // The catalog of earlier releases knows the untouched file, not the changed one.
        \Advans\Installer\V2\State::write($source . '/' . \Advans\Installer\V2\Knowledge::CATALOG, [
            'complete' => true,
            'hashes'   => [hash('sha256', '<?php echo "earlier release";')],
        ]);
        $xml = '<extension type="plugin" group="ajax"><name>PLG_AJAX_JOOMLAAJAXFORMS</name><files><filename>payload.php</filename><filename>untouched.php</filename></files></extension>';
        file_put_contents($source . '/joomlaajaxforms.xml', $xml);
        $parent = new class($source, simplexml_load_string($xml)) {
            public function __construct(private string $source, private \SimpleXMLElement $manifest) {}
            public function getParent(): self { return $this; }
            public function getManifest(): \SimpleXMLElement { return $this->manifest; }
            public function getPath(string $key): string { return $key === 'manifest' ? $this->source . '/joomlaajaxforms.xml' : $this->source; }
        };
        $installer = new class extends \Advans\Installer\V2\InstallerScript {
            /** @var array<string, string> */
            public array $foreign = [];

            protected function guardedFiles(string $type, object $parent): array
            {
                return $this->foreign;
            }

            protected function extensionPostflight($type, $parent)
            {
                return true;
            }
        };
        $install = static function () use ($source, $destination): void {
            foreach (['payload.php', 'untouched.php', 'joomlaajaxforms.xml'] as $name) {
                copy($source . '/' . $name, $destination . '/' . $name);
            }
        };
        $backups = static fn(): array => glob($state . '/backups/*.php') ?: [];
        $messageText = static fn(): string => html_entity_decode(implode("\n", array_column($app->messages, 0)), ENT_QUOTES, 'UTF-8');

        // 1. Own files: the update decides by itself. The changed file is backed up, the untouched one is not.
        $app->messages = [];
        $assert($installer->preflight('update', $parent) === true, 'An update of own files must not stop.');
        $assert(file_get_contents($destination . '/payload.php') === '<?php echo "changed on this site";', 'Preflight changed a destination.');
        $assert(count($backups()) === 1, 'Exactly the changed own file must be backed up.');
        $changedBackup = $backups()[0];
        $assert(
            \Advans\Installer\V2\FileGuard::restoreBytes($changedBackup, $destination . '/payload.php', hash('sha256', '<?php echo "changed on this site";')) === '<?php echo "changed on this site";',
            'The backup does not hold the changed bytes.'
        );
        $install();
        $assert($installer->postflight('update', $parent) === true, 'Successful extension postflight was not preserved.');
        $report = $messageText();
        $assert(str_contains($report, 'plugins/ajax/joomlaajaxforms/payload.php') && !str_contains($report, 'untouched.php'), 'The report must name exactly the changed own file.');
        $assert(str_contains($report, 'advans-installer-state/plg_ajax_joomlaajaxforms/backups'), 'The report must name the backup location.');
        $record = \Advans\Installer\V2\State::read($state . '/' . \Advans\Installer\V2\Knowledge::RECORD);
        $assert(($record['files']['plugins/ajax/joomlaajaxforms/payload.php'] ?? '') === hash('sha256', '<?php echo "new";'), 'The record lacks a written file.');

        // 2. The record makes the next update recognise the files this installation wrote.
        file_put_contents($source . '/payload.php', '<?php echo "newer";');
        $app->messages = [];
        $assert($installer->preflight('update', $parent) === true, 'An update over recorded files must not stop.');
        $assert(count($backups()) === 1, 'A recorded, untouched file must not be backed up again.');
        $install();
        $installer->postflight('update', $parent);
        $assert(!str_contains($messageText(), 'payload.php'), 'An untouched file must not be reported.');

        // 3. A foreign file without a safe automatic choice pauses the installation, with a plain explanation.
        $foreign = JPATH_ROOT . '/media/other_extension/other.php';
        mkdir(dirname($foreign), 0700, true);
        file_put_contents($foreign, '<?php echo "other extension";');
        file_put_contents($source . '/foreign-incoming.php', '<?php echo "ours";');
        $installer->foreign = [$foreign => $source . '/foreign-incoming.php'];
        $app->tag = 'de-DE';
        \Advans\Installer\V2\SystemCheck::loadLanguage();
        $app->messages = [];
        $assert($installer->preflight('update', $parent) === false, 'An unclassified foreign file must pause the installation.');
        $assert(file_get_contents($foreign) === '<?php echo "other extension";', 'The pause changed the foreign file.');
        $paused = $messageText();
        $assert(str_contains($paused, 'Es wurde nichts verändert') && str_contains($paused, 'media/other_extension/other.php'), 'The pause must explain in the site language which file is affected.');
        $plan = $state . '/plan.php';
        $assert(is_file($plan) && str_contains($paused, hash_file('sha256', $plan)), 'The pause must name the review plan for the command-line approval.');
        $process = proc_open([PHP_BINARY, $support . '/approve.php', '--approve', $plan, hash_file('sha256', $plan)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $assert(proc_close($process) === 0, 'Approval command failed: ' . $output);
        $app->tag = 'en-GB';
        \Advans\Installer\V2\SystemCheck::loadLanguage();
        $app->messages = [];
        $assert($installer->preflight('update', $parent) === true, 'Approved retry was not accepted.');
        copy($source . '/foreign-incoming.php', $foreign);
        $install();
        $installer->postflight('update', $parent);
        $assert(str_contains($messageText(), 'media/other_extension/other.php'), 'The approved foreign change must be reported.');
        $assert(!is_file($plan), 'A successful installation must remove the paused plan.');
        $installer->foreign = [];

        // 4. A backup that cannot be written stops the installation before anything changes.
        file_put_contents($destination . '/payload.php', '<?php echo "changed again";');
        mkdir($state . '/backups/' . hash('sha256', \Advans\Installer\V2\FileGuard::normalize($destination . '/payload.php') . "\0" . hash('sha256', '<?php echo "changed again";')) . '.php');
        $app->messages = [];
        $assert($installer->preflight('update', $parent) === false, 'A failed backup must stop the installation.');
        $assert(str_contains($messageText(), 'plugins/ajax/joomlaajaxforms/payload.php could not be written or checked'), 'The backup failure must be explained.');
        $assert(file_get_contents($destination . '/payload.php') === '<?php echo "changed again";', 'A failed backup changed a destination.');
        $assert(\Joomla\CMS\Log\Log::$entries !== [], 'A failed backup must be logged.');
        \Joomla\CMS\Log\Log::$entries = [];
        file_put_contents($destination . '/payload.php', '<?php echo "newer";');

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $app->tag = $tag;
            $app->messages = [];
            $assert($installer->preflight('update', $parent) === true, 'Untouched update must not stop.');
            $assert($installer->postflight('install', $parent) === true, 'Successful extension postflight was not preserved.');
            $html = (string) (array_values(array_filter(array_column($app->messages, 0), static fn(string $message): bool => str_contains($message, 'advans-system-check')))[0] ?? '');
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
        $disabled = \Advans\Installer\V2\SystemCheck::results('plg_ajax_joomlaajaxforms');
        \Joomla\CMS\Factory::$db->enabled = true;
        $enabled = \Advans\Installer\V2\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(count($disabled['warnings']) === count($enabled['warnings']) + 1, 'Repeating the check did not refresh actual plugin state.');
        $assert($enabled['blockers'] === [], 'Available platform was reported blocked.');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_HTTPS_UNKNOWN'), array_column($enabled['warnings'], 1), true), 'CLI transport was incorrectly treated as verified.');
        $app->cli = false;
        $insecure = \Advans\Installer\V2\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_HTTPS_INSECURE'), array_column($insecure['warnings'], 1), true), 'HTTP request was not reported insecure.');
        \Joomla\CMS\Uri\Uri::$scheme = 'https';
        $secure = \Advans\Installer\V2\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_HTTPS_CURRENT'), array_column($secure['passed'], 1), true), 'HTTPS administrator request was not recognized.');
        $rewrite = "RewriteCond %{QUERY_STRING} ^option=com_ [NC]\nRewriteRule ^index\\.php$ / [R=301,L]\n";
        file_put_contents(JPATH_ROOT . '/.htaccess', $rewrite);
        $blocked = \Advans\Installer\V2\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_BLOCKED'), array_column($blocked['warnings'], 1), true), 'Blocking option rewrite was not reported.');
        $assert(file_get_contents(JPATH_ROOT . '/.htaccess') === $rewrite, 'Routing check modified existing server configuration.');
        $allowlisted = "RewriteCond %{QUERY_STRING} !^option=com_ajax [NC]\n" . $rewrite;
        file_put_contents(JPATH_ROOT . '/.htaccess', $allowlisted);
        $safe = \Advans\Installer\V2\SystemCheck::results('plg_ajax_joomlaajaxforms');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_UNVERIFIED'), array_column($safe['warnings'], 1), true), 'Allowlisted routes were blocked or incorrectly reported as HTTP-verified.');
        $routing = new \Advans\Installer\V2\RoutingCheck();
        $assert($routing->analyse("RewriteRule ^component/(.*)$ / [R=301,L]") === ['component'], 'Component route blocking was missed.');
        $assert($routing->analyse("RewriteCond %{QUERY_STRING} !plugin= [NC]\nRewriteRule ^component/(.*)$ / [R=301,L]") === [], 'Plugin exclusion was ignored.');
        $assert($routing->analyse("RewriteCond %{QUERY_STRING} !plugin= [NC,OR]\nRewriteCond %{REQUEST_URI} ^/component/\nRewriteRule ^component/(.*)$ / [R=301,L]") === ['component'], 'OR group bypass was missed.');
        $assert($routing->analyse("RewriteCond %{HTTPS} off\nRewriteRule ^(.*)$ https://%{HTTP_HOST}/$1 [R=301,L]") === [], 'Generic HTTPS redirection was treated as component blocking.');
        $assert($routing->analyse($rewrite, 'com_appointments') === ['option'], 'Non-AJAX component option routes were not checked.');
        foreach ([
            "RewriteCond %{QUERY_STRING} ^option=com_ajax [NC]\n",
            "RewriteCond %{QUERY_STRING} plugin=j2commercePrivacy [NC]\nRewriteCond %{QUERY_STRING} group=privacy [NC]\nRewriteCond %{QUERY_STRING} task=confirmRequest [NC]\n",
            "RewriteCond %{QUERY_STRING} plugin=j2commercePrivacy [NC]\nRewriteCond %{QUERY_STRING} group=privacy [NC]\nRewriteCond %{QUERY_STRING} task=deleteAddress [NC]\n",
        ] as $privacyConditions) {
            $privacyRule = $privacyConditions . "RewriteRule ^index\\.php$ - [F,L]\n";
            file_put_contents(JPATH_ROOT . '/.htaccess', $privacyRule);
            $privacy = \Advans\Installer\V2\SystemCheck::results('plg_privacy_j2commerce');
            $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_BLOCKED'), array_column($privacy['warnings'], 1), true), 'Privacy AJAX endpoint blocking was missed.');
            file_put_contents(JPATH_ROOT . '/.htaccess', "RewriteCond %{QUERY_STRING} !group=privacy [NC]\n" . $privacyRule);
            $privacy = \Advans\Installer\V2\SystemCheck::results('plg_privacy_j2commerce');
            $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_UNVERIFIED'), array_column($privacy['warnings'], 1), true), 'Privacy AJAX route exclusion was ignored.');
        }
        foreach (['format=html', 'format=json&task=capabilities'] as $parameters) {
            $conditions = "RewriteCond %{QUERY_STRING} plugin=privacyrequests [NC]\nRewriteCond %{QUERY_STRING} group=system [NC]\nRewriteCond %{QUERY_STRING} " . $parameters . " [NC]\n";
            $rule = $conditions . "RewriteRule ^index\\.php$ - [F,L]\n";
            file_put_contents(JPATH_ROOT . '/.htaccess', $rule);
            $privacy = \Advans\Installer\V2\SystemCheck::results('plg_system_privacyrequests');
            $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_BLOCKED'), array_column($privacy['warnings'], 1), true), 'Generic privacy form/capability route blocking was missed.');
            file_put_contents(JPATH_ROOT . '/.htaccess', "RewriteCond %{QUERY_STRING} !plugin=privacyrequests [NC]\n" . $rule);
            $privacy = \Advans\Installer\V2\SystemCheck::results('plg_system_privacyrequests');
            $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_UNVERIFIED'), array_column($privacy['warnings'], 1), true), 'Generic privacy route exclusion was ignored.');
        }
        file_put_contents(JPATH_ROOT . '/.htaccess', "RewriteCond %{QUERY_STRING} plugin=joomlaajaxforms [NC]\nRewriteRule ^index\\.php$ - [F,L]\n");
        $privacy = \Advans\Installer\V2\SystemCheck::results('plg_system_privacyrequests');
        $assert(in_array(\Joomla\CMS\Language\Text::_('ADVANS_CHECK_ROUTING_UNVERIFIED'), array_column($privacy['warnings'], 1), true), 'Generic privacy check incorrectly targeted another AJAX plugin.');
        $mixedShops = \Advans\Installer\V2\SystemCheck::results('plg_osmap_j2commerce');
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
        $productFiles = \Advans\Installer\V2\Destinations::files(simplexml_load_string($productXml), $productSource, $productManifest);
        $productOwned = \Advans\Installer\V2\Destinations::owned(simplexml_load_string($productXml), $productSource);
        $assert(\Advans\Installer\V2\Destinations::isOwned($canonical . '/productcompare.xml', $productOwned), 'The options manifest lies in the plugin folder and is its own file.');
        $assert(!\Advans\Installer\V2\Destinations::isOwned($mirror . '/payload.php', $productOwned), 'The Joomla 5 mirror lies in J2Store\'s plugin group and is foreign territory.');
        $assert(\Advans\Installer\V2\Destinations::isOwned(JPATH_ADMINISTRATOR . '/language/en-GB/plg_j2commerce_productcompare.ini', $productOwned), 'The plugin language file is its own file.');
        $assert(!\Advans\Installer\V2\Destinations::isOwned(JPATH_ADMINISTRATOR . '/language/en-GB/plg_j2store_other.ini', $productOwned), 'Another extension\'s language file is foreign.');
        require dirname(__DIR__, 2) . '/j2commerce/plg_j2commerce_productcompare/script.php';
        $decide = new \ReflectionMethod(\PlgJ2commerceProductcompareInstallerScript::class, 'foreignDecision');
        $mirrorPayload = \Advans\Installer\V2\FileGuard::normalize($mirror . '/payload.php');
        $assert($decide->invoke(new \PlgJ2commerceProductcompareInstallerScript(), $mirrorPayload, str_repeat('0', 64), false) === \Advans\Installer\V2\Planner::REPLACE, 'A mirror that holds only this plugin\'s files must be replaced after a backup.');
        file_put_contents($mirror . '/other.xml', '<extension type="plugin" group="j2store"><name>plg_j2store_other</name></extension>');
        $assert($decide->invoke(new \PlgJ2commerceProductcompareInstallerScript(), $mirrorPayload, str_repeat('0', 64), false) === \Advans\Installer\V2\Planner::SKIP, 'A mirror folder owned by another extension must be left untouched.');
        unlink($mirror . '/other.xml');
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
        // 6. Scheduled tasks: a missing required task is created switched on and reported, an
        //    existing one is never changed or duplicated, one switched off on purpose is reported,
        //    an extension without required tasks says nothing.
        $tasks = \Advans\Installer\V2\ScheduledTasks::class;
        $handled = new \ReflectionProperty($tasks, 'handled');
        $db = \Joomla\CMS\Factory::$db;
        $reset = static function () use ($handled, $app): void {
            $handled->setValue(null, []);
            $app->messages = [];
            $app->saved = [];
        };
        $app->tag = 'en-GB';
        \Advans\Installer\V2\SystemCheck::loadLanguage();
        $strings = \Joomla\CMS\Language\Text::$strings;
        $text = static fn(): string => html_entity_decode(implode("\n", array_column($app->messages, 0)), ENT_QUOTES, 'UTF-8');
        $repository = dirname(__DIR__, 2);

        foreach ([
            'task/j2commerceprivacy/forms/autocleanup.xml' => '/j2commerce/plg_privacy_j2commerce/plugins/task/j2commerceprivacy/forms/autocleanup.xml',
            'task/appointments/forms/batch.xml'            => '/com_appointments/plugins/task/appointments/forms/batch.xml',
        ] as $target => $form) {
            mkdir(dirname(JPATH_PLUGINS . '/' . $target), 0700, true);
            copy($repository . $form, JPATH_PLUGINS . '/' . $target);
        }

        $db->extensions = [
            'plugin:task:j2commerceprivacy' => ['enabled' => 1, 'params' => '{}'],
            'plugin:privacy:j2commerce'     => ['enabled' => 0, 'params' => '{"retention_years":"7","fiscal_year_end":"06-30","anonymize_orders":"0","delete_addresses":"0"}'],
            'component::com_scheduler'      => ['enabled' => 1, 'params' => '{}'],
            'plugin:system:schedulerunner'  => ['enabled' => 1, 'params' => '{}'],
        ];
        $routine = 'plg_task_j2commerceprivacy.autocleanup';

        // A missing required task is created with the parent's saved privacy options.
        $reset();
        $tasks::ensure('plg_privacy_j2commerce');
        $assert(count($app->saved) === 1 && $app->saved[0]['type'] === $routine && $app->saved[0]['state'] === 1, 'A missing required task must be created once, switched on.');
        $rules = $app->saved[0]['execution_rules'];
        $assert($rules['rule-type'] === 'interval-days' && $rules['interval-days'] === 1 && $rules['exec-time'] === '02:15' && $rules['exec-day'] === gmdate('d'), 'The anonymisation task must run every day at 02:15 UTC.');
        $assert($app->saved[0]['params'] === ['retention_years' => 7, 'fiscal_year_end' => '06-30', 'anonymize_orders' => 0, 'delete_addresses' => 0], 'The task must inherit the privacy plugin settings, including explicit cleanup opt-outs: ' . json_encode($app->saved[0]['params']));
        $assert($app->saved[0]['title'] === 'PLG_TASK_J2COMMERCEPRIVACY_TASK_AUTOCLEANUP_TITLE' && $app->saved[0]['created_by'] === 42, 'The task must carry the routine title and its creator.');
        $assert(count($app->messages) === 2 && $app->messages[0][1] === 'message', 'The created task and an unproven Lazy Scheduler trigger must be reported.');
        $assert(str_contains($text(), 'was missing. The installation created it and switched it on: it runs every day at 02:15 UTC. It anonymises shop orders')
            && str_contains($text(), 'Retention period: 7 years after the end of the fiscal year on 06-30'), 'The report must name the schedule, the purpose and the settings: ' . $text());
        $passed = array_column(\Advans\Installer\V2\SystemCheck::results('plg_privacy_j2commerce')['passed'], 1);
        $assert(in_array($strings['ADVANS_CHECK_TASK_NOT_RUN'], $passed, true), 'The system check must list the switched-on task that has not run yet.');

        // Update with the task switched on: nothing saved, nothing said.
        $reset();
        $before = $db->tasks;
        $tasks::ensure('plg_privacy_j2commerce');
        $assert($app->saved === [] && $db->tasks === $before && count($app->messages) === 1 && str_contains($text(), 'Lazy Scheduler'), 'An existing switched-on task must stay unchanged and the unproven trigger must be reported.');

        // Switched off or trashed on purpose: unchanged, reported with the way to switch it on, a blocker in the system check.
        foreach ([0, -2] as $state) {
            $db->tasks[$routine] = [['id' => 5, 'state' => $state, 'last_execution' => '2026-01-01 02:15:00', 'last_exit_code' => 0, 'times_executed' => 3]];
            $before = $db->tasks;
            $reset();
            $tasks::ensure('plg_privacy_j2commerce');
            $assert($app->saved === [] && $db->tasks === $before, 'A task switched off on purpose must not be changed or duplicated.');
            $assert(count($app->messages) === 2 && str_contains($text(), 'is switched off or in the trash')
                && str_contains($text(), 'System → Manage → Scheduled Tasks') && str_contains($text(), 'Lazy Scheduler'), 'A switched-off required task must be reported with the menu path and the trigger warning.');
            $blockers = array_column(\Advans\Installer\V2\SystemCheck::results('plg_privacy_j2commerce')['blockers'], 1);
            $assert(in_array($strings['ADVANS_CHECK_TASK_OFF'], $blockers, true), 'The system check must report a switched-off required task as a blocker.');
        }

        // One report per request: the package and its children check the same routine.
        $reset();
        $tasks::ensure('plg_privacy_j2commerce');
        $count = count($app->messages);
        $tasks::ensure('plg_task_j2commerceprivacy');
        $assert($count === 2 && count($app->messages) === 2, 'A routine and its trigger must be reported once per request.');

        // Extensions without required tasks: no message, nothing created.
        foreach (['plg_ajax_joomlaajaxforms', 'plg_system_privacyrequests', 'com_j2store_cleanup'] as $id) {
            $reset();
            $tasks::ensure($id);
            $assert($app->messages === [] && $app->saved === [], 'An extension without required tasks must say nothing: ' . $id);
        }

        // Classification: reminders while offsets are set, retention always, Close never by the shared runtime,
        // internet calendar routines only while the internet calendars are switched on.
        $db->extensions['plugin:task:appointments'] = ['enabled' => 1, 'params' => '{}'];
        $created = static function (string $params) use ($db, $reset, $tasks, $app): array {
            $db->tasks = [];
            $db->extensions['component::com_appointments'] = ['enabled' => 1, 'params' => $params];
            $reset();
            $tasks::ensure('com_appointments');

            return array_column($app->saved, 'type');
        };
        $assert($created('{}') === ['plg_task_appointments.reminders', 'plg_task_appointments.retention', 'plg_task_appointments.teamretention'], 'Defaults: reminders, retention and calendar upkeep retention are required.');
        $assert($app->saved[0]['params'] === ['batch_size' => 100] && $app->saved[0]['execution_rules']['interval-minutes'] === 5, 'Reminders: every five minutes, batch size 100.');
        $assert($created('{"reminder_offsets":" , ","teamcal_enabled":"1"}') === ['plg_task_appointments.retention', 'plg_task_appointments.teamcleanup',
            'plg_task_appointments.teamlog', 'plg_task_appointments.teaminvitations', 'plg_task_appointments.teamretention'], 'Without offsets no reminders; internet calendars on need their four tasks.');

        // Joomla's Scheduler cannot start tasks: reported once; a recent run proves it does.
        $db->tasks = [$routine => [['id' => 5, 'state' => 1, 'last_execution' => gmdate('Y-m-d H:i:s', time() - 3600), 'last_exit_code' => 0, 'times_executed' => 9]]];
        $db->extensions['plugin:system:schedulerunner']['enabled'] = 0;
        $reset();
        $tasks::ensure('plg_privacy_j2commerce');
        $assert($app->messages === [], 'A run within two days proves that the Scheduler starts tasks.');
        $db->tasks[$routine][0]['last_execution'] = '2026-01-01 02:15:00';
        $reset();
        $tasks::ensure('plg_privacy_j2commerce');
        $assert(count($app->messages) === 1 && $app->messages[0][1] === 'warning' && str_contains($text(), 'System - Schedule Runner'), 'A Scheduler without trigger must be reported.');
        $db->extensions['plugin:system:schedulerunner']['enabled'] = 1;
        $db->extensions['component::com_scheduler']['params'] = '{"lazy_scheduler":{"enabled":0},"webcron":{"enabled":0}}';
        $reset();
        $tasks::ensure('plg_privacy_j2commerce');
        $assert(str_contains($text(), 'Neither the Lazy Scheduler nor Web Cron'), 'A Scheduler started only by a server cron job must be reported.');
        $db->extensions['component::com_scheduler']['params'] = '{"lazy_scheduler":{"enabled":1},"webcron":{"enabled":0}}';
        $reset();
        $tasks::ensure('plg_privacy_j2commerce');
        $assert(count($app->messages) === 1 && $app->messages[0][1] === 'message' && str_contains($text(), 'started by visits'), 'Configured Lazy Scheduler is informational until a real recent run proves it.');

        // Duplicate routines: report the task that actually ran, not the lowest-ID duplicate.
        $db->tasks[$routine] = [
            ['id' => 3, 'state' => 1, 'last_execution' => null, 'last_exit_code' => 0, 'times_executed' => 0],
            ['id' => 7, 'state' => 1, 'last_execution' => gmdate('Y-m-d H:i:s', time() - 3600), 'last_exit_code' => 0, 'times_executed' => 4],
        ];
        $inspected = \Advans\Installer\V2\ScheduledTasks::inspect('plg_privacy_j2commerce');
        $routineRow = array_values(array_filter($inspected, static fn(array $row): bool => $row['routine'] === $routine))[0] ?? null;
        $assert($routineRow !== null && (int) ($routineRow['task']['id'] ?? 0) === 7, 'Duplicate enabled tasks must prefer the task with the most recent execution.');

        // Task plugin missing: nothing can be created, the report says why.
        unset($db->extensions['plugin:task:j2commerceprivacy']);
        $db->tasks = [];
        $reset();
        $tasks::ensure('plg_privacy_j2commerce');
        $assert($app->saved === [] && str_contains($text(), 'cannot be created, because its task plugin is not installed'), 'A missing task plugin must be reported.');
        $db->extensions = [];
        $db->tasks = [];

        foreach (['de-DE', 'fr-FR'] as $tag) {
            $app->tag = $tag;
            \Advans\Installer\V2\SystemCheck::loadLanguage();
            $assert(\Advans\Installer\V2\ScheduledTasks::schedule(['rule-type' => 'interval-days', 'interval-days' => 1, 'exec-time' => '02:15']) !== 'ADVANS_TASKS_DAILY_AT', 'Untranslated schedule in ' . $tag);
        }

        $app->tag = 'en-GB';
        \Advans\Installer\V2\SystemCheck::loadLanguage();
        echo "PASS scheduled tasks: missing required task created switched on and reported, existing task unchanged, switched-off task reported, no message without required tasks, Scheduler trigger checked\n";
        echo "PASS common lifecycle: own files decided automatically with verified backups and record, foreign pause with approval, backup failure stops before changes, translated reports and refreshed state\n";
    } finally {
        $remove($work);
    }
}
