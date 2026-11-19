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
        public static bool $acceptPreflight = true;
        public static int $preflightCalls = 0;

        public function preflight($type, $parent)
        {
            self::$preflightCalls++;
            return self::$acceptPreflight;
        }
    }
}

namespace Advans\Installer\AppointmentsConflict\V1 {
    function chmod(string $file, int $mode): bool
    {
        $directory = dirname($file);
        if (\Advans\Installer\V2\FileGuard::normalize(dirname($directory))
                !== \Advans\Installer\V2\FileGuard::normalize(realpath(sys_get_temp_dir())) || is_link($directory)
            || (DIRECTORY_SEPARATOR === '/' && (fileperms($directory) & 0077) !== 0)) {
            throw new \RuntimeException('Restored plaintext must remain in private staging before publication.');
        }

        return \chmod($file, $mode);
    }
}

namespace Joomla\CMS\Installer\Adapter {
    class ModuleAdapter
    {
        protected $oldFiles;
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
    require_once $support . '/AppointmentsConflict.php';

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

        // Appointments stops before the shared retry/approval path for unknown owned files.
        $appointmentSource = $work . '/appointments-source';
        $appointmentState = JPATH_ADMINISTRATOR . '/advans-installer-state/com_appointments';
        $componentFile = JPATH_ADMINISTRATOR . '/components/com_appointments/example.php';
        $pluginFile = JPATH_PLUGINS . '/task/appointments/example.php';
        mkdir($appointmentSource . '/plugins/task/appointments', 0700, true);
        mkdir($appointmentSource . '/administrator/components/com_appointments/src/Helper', 0700, true);
        copy(dirname(__DIR__, 2) . '/com_appointments/administrator/components/com_appointments/src/Helper/BundledModuleGuard.php',
            $appointmentSource . '/administrator/components/com_appointments/src/Helper/BundledModuleGuard.php');
        mkdir($appointmentSource . '/modules/mod_appointments/src', 0700, true);
        file_put_contents($appointmentSource . '/modules/mod_appointments/mod_appointments.xml',
            '<extension type="module" client="administrator"><name>MOD_APPOINTMENTS</name>'
            . '<files><filename module="mod_appointments">mod_appointments.php</filename><folder>src</folder></files></extension>');
        file_put_contents($appointmentSource . '/modules/mod_appointments/mod_appointments.php', '<?php');
        mkdir(dirname($componentFile), 0700, true);
        mkdir(dirname($pluginFile), 0700, true);
        file_put_contents($appointmentSource . '/plugins/task/appointments/appointments.xml',
            '<extension type="plugin" group="task"><name>PLG_TASK_APPOINTMENTS</name></extension>');
        $appointmentManifest = simplexml_load_string('<extension type="component"><name>COM_APPOINTMENTS</name></extension>');
        $appointmentOwned = \Advans\Installer\V2\Destinations::owned($appointmentManifest, $appointmentSource);
        $appointmentOwned = \Advans\Installer\V2\Destinations::mergeOwned($appointmentOwned, [
            'prefixes' => [JPATH_ADMINISTRATOR . '/modules/mod_appointments'],
            'files' => [],
            'languages' => ['mod_appointments'],
        ]);
        $appointmentFiles = [];
        foreach ([$componentFile, $pluginFile] as $i => $path) {
            $candidate = $appointmentSource . '/candidate-' . $i;
            file_put_contents($candidate, 'release ' . $i);
            file_put_contents($path, 'unidentified ' . $i);
            $appointmentFiles[$path] = $candidate;
        }
        $appointmentKnowledge = \Advans\Installer\V2\Knowledge::load(
            $appointmentSource . '/missing-catalog.php', $appointmentState . '/installed.php'
        );
        $conflicts = \Advans\Installer\AppointmentsConflict\V1\Guard::inspect(
            $appointmentFiles, $appointmentOwned, $appointmentKnowledge, $appointmentState
        );
        $assert(count($conflicts) === 2, 'Unknown component and bundled plugin files must both stop the update.');
        foreach ($conflicts as $conflict) {
            $assert(
                \Advans\Installer\V2\FileGuard::restoreBytes($conflict['backup'], $conflict['destination'], $conflict['existing'])
                    === file_get_contents($conflict['destination']),
                'The Appointments conflict backup must contain the verified existing bytes.'
            );
        }
        // An existing shared approval cannot bypass this separate guard, and a new incoming hash
        // must be shown on a retry rather than reusing an earlier comparison.
        \Advans\Installer\V2\State::write(dirname($appointmentState) . '/approved.php', [
            \Advans\Installer\V2\FileGuard::normalize($componentFile) => [
                'existing' => hash_file('sha256', $componentFile),
                'incoming' => hash_file('sha256', $appointmentFiles[$componentFile]),
            ],
            \Advans\Installer\V2\FileGuard::normalize($pluginFile) => [
                'existing' => hash_file('sha256', $pluginFile),
                'incoming' => hash_file('sha256', $appointmentFiles[$pluginFile]),
            ],
        ]);
        file_put_contents($appointmentFiles[$componentFile], 'different release');
        $retry = \Advans\Installer\AppointmentsConflict\V1\Guard::inspect(
            $appointmentFiles, $appointmentOwned, $appointmentKnowledge, $appointmentState
        );
        $assert(count($retry) === 2 && $retry[0]['incoming'] === hash('sha256', 'different release'),
            'Retry must not accept an approval for another incoming hash.');
        $assert(file_get_contents($componentFile) === 'unidentified 0' && file_get_contents($pluginFile) === 'unidentified 1',
            'The conflict guard must not replace either destination.');
        // The normal successful-install ledger records only release bytes actually present.
        copy($appointmentFiles[$componentFile], $componentFile);
        $incoming = \Advans\Installer\V2\FileGuard::compare($appointmentFiles)['incoming'];
        \Advans\Installer\V2\Knowledge::record($appointmentState . '/installed.php', 'fixture', $incoming);
        $record = \Advans\Installer\V2\State::read($appointmentState . '/installed.php');
        $assert(isset($record['files']['administrator/components/com_appointments/example.php'])
            && !isset($record['files']['plugins/task/appointments/example.php']),
            'The ledger must record installed release bytes, not preserved unknown bytes.');
        $appointmentKnowledge = \Advans\Installer\V2\Knowledge::load(
            $appointmentSource . '/missing-catalog.php', $appointmentState . '/installed.php'
        );
        file_put_contents($appointmentFiles[$componentFile], 'later release');
        $retry = \Advans\Installer\AppointmentsConflict\V1\Guard::inspect(
            $appointmentFiles, $appointmentOwned, $appointmentKnowledge, $appointmentState
        );
        $assert(count($retry) === 1 && $retry[0]['existing'] === hash('sha256', 'unidentified 1'),
            'Recorded release bytes must not be treated as unknown on the next update.');

        $recovery = \Advans\Installer\AppointmentsConflict\V1\Guard::class;
        $reviewState = $appointmentState . '/review-tests';
        $reviewSource = $appointmentSource . '/review-staging';
        mkdir($reviewSource, 0700, true);
        $reviewFiles = [];
        foreach ([
            JPATH_ADMINISTRATOR . '/components/com_appointments/src/recovery.php',
            JPATH_PLUGINS . '/task/appointments/src/recovery.php',
            JPATH_ADMINISTRATOR . '/modules/mod_appointments/src/recovery.php',
            JPATH_ADMINISTRATOR . '/components/com_appointments/recovery.xml',
        ] as $i => $path) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0700, true);
            }
            $candidate = $reviewSource . '/review-' . $i . '.php';
            file_put_contents($path, 'unknown recovery ' . $i);
            file_put_contents($candidate, 'release recovery ' . $i);
            $reviewFiles[\Advans\Installer\V2\FileGuard::normalize($path)] = $candidate;
        }
        $rows = $recovery::inspect($reviewFiles, $appointmentOwned, $appointmentKnowledge, $reviewState);
        $review = $recovery::review($reviewState, 'recovery-test', $rows, $reviewFiles);
        $refused = static function (callable $action) use ($assert): void {
            try {
                $action();
            } catch (\RuntimeException) {
                return;
            }
            $assert(false, 'An unsupported or manipulated recovery request must be refused.');
        };
        $eligible = array_filter($review['rows'], static fn(array $row): bool => $row['keep']);
        $metadata = array_diff_key($review['rows'], $eligible);
        $assert(count($eligible) === 3 && count($metadata) === 1,
            'Component, plugin and module code can be kept, but installer metadata cannot.');
        $file = array_key_first($eligible);
        $removalDestination = $review['rows'][$file]['destination'];
        $removalSource = \Advans\Installer\V2\Destinations::operation($appointmentSource, 'Remove obsolete code');
        $assert(!$recovery::canKeep($removalDestination, $removalSource, [$removalDestination => $removalSource]),
            'A removal marker must never be offered as a Keep local copy input, even under src.');
        $refused(static fn() => $recovery::decide($reviewState, str_repeat('0', 64), $file, 'replace'));
        $refused(static fn() => $recovery::decide($reviewState, $review['id'], '../configuration.php', 'replace'));
        $refused(static fn() => $recovery::decide($reviewState, $review['id'], $file, 'approve'));
        $refused(static fn() => $recovery::decide($reviewState, $review['id'], array_key_first($metadata), 'keep'));
        $refused(static fn() => $recovery::apply($review, $reviewFiles, $reviewSource));
        $assert($recovery::current($reviewState)['choices'] === [],
            'Refused requests must not save any choice.');
        foreach ($review['rows'] as $rowId => $row) {
            $recovery::decide($reviewState, $review['id'], $rowId, $rowId === $file ? 'keep' : 'replace');
        }
        $approved = $recovery::current($reviewState);
        $releaseHashes = \Advans\Installer\V2\FileGuard::compare($reviewFiles)['incoming'];
        $recovery::apply($approved, $reviewFiles, $reviewSource);
        $refused(static fn() => $recovery::assertFreshSource($reviewSource));
        $refused(static fn() => $recovery::apply($approved, $reviewFiles, $reviewSource));
        foreach ($approved['rows'] as $rowId => $row) {
            $expected = $rowId === $file ? $row['existing'] : $row['incoming'];
            $assert(hash_file('sha256', $reviewFiles[$row['destination']]) === $expected,
                'Only the kept file may substitute its package copy input.');
            copy($reviewFiles[$row['destination']], $row['destination']);
        }
        $recovery::journal($reviewState, $approved);
        $recovery::verifyInstalled($approved);
        \Advans\Installer\V2\Knowledge::record($reviewState . '/installed.php', 'fixture', $releaseHashes);
        $keptRelative = \Advans\Installer\V2\Knowledge::relative($approved['rows'][$file]['destination']);
        $assert(!isset(\Advans\Installer\V2\State::read($reviewState . '/installed.php')['files'][$keptRelative]),
            'Kept local bytes must not enter the release ledger, and their staged source must not be reusable.');
        $stagingBefore = glob(realpath(sys_get_temp_dir()) . '/appointments-*') ?: [];
        $restoreId = array_key_first(array_diff_key($eligible, [$file => true]));
        $restoreRow = $approved['rows'][$restoreId];
        $refused(static fn() => $recovery::restore($reviewState, $approved['id'], $restoreId, str_repeat('f', 64)));
        file_put_contents($restoreRow['destination'], 'later unrelated bytes');
        $refused(static fn() => $recovery::restore($reviewState, $approved['id'], $restoreId, $restoreRow['incoming']));
        $assert(file_get_contents($restoreRow['destination']) === 'later unrelated bytes',
            'A stale restoration must leave unrelated bytes untouched.');
        copy($reviewFiles[$restoreRow['destination']], $restoreRow['destination']);
        $recovery::restore($reviewState, $approved['id'], $restoreId, $restoreRow['incoming']);
        $assert(hash_file('sha256', $restoreRow['destination']) === $restoreRow['existing'],
            'Explicit recovery must restore the verified original bytes.');
        $refused(static fn() => $recovery::restore($reviewState, $approved['id'], $restoreId, $restoreRow['incoming']));
        unlink($restoreRow['destination']);
        $recovery::restore($reviewState, $approved['id'], $restoreId, '');
        $assert(hash_file('sha256', $restoreRow['destination']) === $restoreRow['existing'],
            'Explicit recovery may recreate a missing eligible file.');
        $assert((glob(realpath(sys_get_temp_dir()) . '/appointments-*') ?: []) === $stagingBefore,
            'Recovery must remove its private staging directory after publication.');
        $backupRoot = $reviewState . '/recovery/backups';
        $orphan = $backupRoot . '/' . hash('sha256', 'orphan backup') . '.php';
        $pending = $backupRoot . '/' . hash('sha256', 'pending backup') . '.php';
        $recent = $backupRoot . '/' . hash('sha256', 'recent backup') . '.php';
        foreach ([$orphan, $pending, $recent] as $path) {
            copy($restoreRow['backup'], $path);
        }
        $oldTime = time() - 181 * 86400;
        foreach (array_merge(array_column($approved['rows'], 'backup'), [$orphan, $pending]) as $path) {
            touch($path, $oldTime);
        }
        $pendingRow = $restoreRow;
        $pendingRow['backup'] = $pending;
        \Advans\Installer\V2\State::write($reviewState . '/recovery/review.php', [
            'rows' => ['pending' => $pendingRow],
        ]);
        $assert($recovery::pruneBackups($reviewState, 180 * 86400) === 1 && !file_exists($orphan)
            && is_file($pending) && is_file($recent) && is_file($restoreRow['backup']),
            'Retention must remove expired orphan backups, preserving pending, latest-attempt and recent backups.');
        require_once dirname(__DIR__, 2) . '/com_appointments/recovery/script.php';
        $companionInstaller = new \Com_appointmentsrecoveryInstallerScript();
        $parentChecks = \Joomla\CMS\Installer\InstallerScript::$preflightCalls;
        \Joomla\CMS\Installer\InstallerScript::$acceptPreflight = false;
        $assert($companionInstaller->preflight('install', new \stdClass()) === false
            && \Joomla\CMS\Installer\InstallerScript::$preflightCalls === $parentChecks + 1,
            'The independently installed companion must honor a failed Joomla compatibility preflight.');
        \Joomla\CMS\Installer\InstallerScript::$acceptPreflight = true;

        // Exercise the real component entry point: it must stop before the shared installer can
        // consume an approval, including when a bundled plugin is the only remaining conflict.
        mkdir($appointmentSource . '/administrator/language/en-GB', 0700, true);
        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $languageDirectory = $appointmentSource . '/administrator/language/' . $tag;
            if (!is_dir($languageDirectory)) {
                mkdir($languageDirectory, 0700, true);
            }
            file_put_contents($languageDirectory . '/com_appointments.ini', 'TEST="test"');
        }
        mkdir(JPATH_ADMINISTRATOR . '/language/en-GB', 0700, true);
        copy(dirname(__DIR__, 2) . '/com_appointments/administrator/language/en-GB/com_appointments.ini',
            JPATH_ADMINISTRATOR . '/language/en-GB/com_appointments.ini');
        copy($appointmentFiles[$componentFile], $appointmentSource . '/administrator/example.php');
        copy($appointmentFiles[$pluginFile], $appointmentSource . '/plugins/task/appointments/example.php');
        file_put_contents($appointmentSource . '/plugins/task/appointments/appointments.xml',
            '<extension type="plugin" group="task"><name>PLG_TASK_APPOINTMENTS</name><files><filename plugin="appointments">example.php</filename></files></extension>');
        $appointmentXml = '<extension type="component"><name>COM_APPOINTMENTS</name><files/>'
            . '<administration><files folder="administrator"><filename>example.php</filename></files></administration></extension>';
        file_put_contents($appointmentSource . '/appointments.xml', $appointmentXml);
        $appointmentParent = new class($appointmentSource, simplexml_load_string($appointmentXml)) {
            public function __construct(private string $source, private \SimpleXMLElement $manifest) {}
            public function getParent(): self { return $this; }
            public function getManifest(): \SimpleXMLElement { return $this->manifest; }
            public function getPath(string $key): string { return $key === 'manifest' ? $this->source . '/appointments.xml' : $this->source; }
        };
        require_once dirname(__DIR__, 2) . '/com_appointments/script.php';
        $moduleInstaller = new \Com_appointmentsInstallerScript();
        $moduleSource = $appointmentSource . '/modules/mod_appointments/src/late.php';
        $moduleDestination = JPATH_ADMINISTRATOR . '/modules/mod_appointments/src/late.php';
        file_put_contents($moduleSource, 'module release');
        file_put_contents($moduleDestination, 'module local');
        $moduleRoot = JPATH_ADMINISTRATOR . '/modules/mod_appointments';
        mkdir($moduleRoot . '/src/obsolete', 0700, true);
        file_put_contents($moduleRoot . '/src/obsolete/local.php', 'unknown obsolete module');
        file_put_contents($moduleRoot . '/src/retained.php', 'unknown retained module');
        file_put_contents($moduleRoot . '/mod_appointments.xml',
            '<extension type="module" client="administrator"><name>MOD_APPOINTMENTS</name><files>'
            . '<folder>src/obsolete</folder><filename>src/retained.php</filename></files></extension>');
        $moduleMethod = new \ReflectionMethod($moduleInstaller, 'bundledModuleFiles');
        $moduleFiles = $moduleMethod->invoke($moduleInstaller, $appointmentSource, ['mod_appointments']);
        $obsolete = str_replace('\\', '/', $moduleRoot . '/src/obsolete/local.php');
        $assert(isset($moduleFiles[$obsolete]) && str_contains($moduleFiles[$obsolete], '/.advans-operations/')
            && !isset($moduleFiles[str_replace('\\', '/', $moduleRoot . '/src/retained.php')]),
            'Module plans must guard obsolete declarations, but not a file retained by Joomla under a declared folder.');
        $obsoleteRows = $recovery::inspect([$obsolete => $moduleFiles[$obsolete]], $appointmentOwned, $appointmentKnowledge, $reviewState);
        $obsoleteReview = $recovery::review($reviewState, 'obsolete-module', $obsoleteRows, $moduleFiles);
        $obsoleteId = array_key_first($obsoleteReview['rows']);
        $assert(!$obsoleteReview['rows'][$obsoleteId]['keep'] && $obsoleteReview['rows'][$obsoleteId]['remove'],
            'Obsolete module code must have a verified backup and a removal-only review row.');
        $refused(static fn() => $recovery::decide($reviewState, $obsoleteReview['id'], $obsoleteId, 'keep'));
        $assert($recovery::backupBytes($obsoleteReview['rows'][$obsoleteId]) === 'unknown obsolete module',
            'The obsolete module backup must preserve the unknown bytes.');
        $snapshotMethod = new \ReflectionMethod($moduleInstaller, 'snapshotBundled');
        (new \ReflectionProperty($moduleInstaller, 'conflictFiles'))->setValue($moduleInstaller, $moduleFiles);
        (new \ReflectionProperty($moduleInstaller, 'bundledSnapshots'))->setValue($moduleInstaller, [
            'modules/mod_appointments' => $snapshotMethod->invoke($moduleInstaller, $moduleFiles),
        ]);
        $verifyMethod = new \ReflectionMethod($moduleInstaller, 'verifyBundledBeforeInstall');
        $verifyModule = static fn() => $verifyMethod->invoke($moduleInstaller, $appointmentSource,
            'modules/mod_appointments', 'administrator/modules/mod_appointments');
        $verifyModule();
        file_put_contents($moduleDestination, 'late unreviewed module');
        $refused($verifyModule);
        file_put_contents($moduleDestination, 'module local');
        file_put_contents($moduleSource, 'late candidate');
        $refused($verifyModule);
        $assert(file_get_contents($moduleDestination) === 'module local',
            'Late module validation must not overwrite an installed file.');
        file_put_contents($moduleSource, 'module release');
        file_put_contents($appointmentSource . '/modules/mod_appointments/src/added.php', 'late new module candidate');
        file_put_contents($moduleRoot . '/src/added.php', 'unknown late module destination');
        $refused($verifyModule);
        $assert(file_get_contents($moduleRoot . '/src/added.php') === 'unknown late module destination',
            'A new recursive module input must not overwrite an unreviewed destination.');
        unlink($appointmentSource . '/modules/mod_appointments/src/added.php');
        unlink($moduleRoot . '/src/added.php');
        file_put_contents($moduleRoot . '/src/obsolete/added.php', 'late obsolete file');
        $refused($verifyModule);
        unlink($moduleRoot . '/src/obsolete/added.php');
        $verifyModule();
        $pluginSource = $appointmentSource . '/plugins/task/appointments';
        mkdir($pluginSource . '/src', 0700, true);
        file_put_contents($pluginSource . '/appointments.xml',
            '<extension type="plugin" group="task"><name>PLG_TASK_APPOINTMENTS</name>'
            . '<files><filename plugin="appointments">example.php</filename><folder>src</folder></files></extension>');
        $pluginFiles = \Advans\Installer\V2\Destinations::files(
            simplexml_load_file($pluginSource . '/appointments.xml'), $pluginSource, $pluginSource . '/appointments.xml'
        );
        (new \ReflectionProperty($moduleInstaller, 'bundledSnapshots'))->setValue($moduleInstaller, [
            'plugins/task/appointments' => $snapshotMethod->invoke($moduleInstaller, $pluginFiles),
        ]);
        $verifyPlugin = static fn() => $verifyMethod->invoke($moduleInstaller, $appointmentSource,
            'plugins/task/appointments', 'plugins/task/appointments');
        $verifyPlugin();
        file_put_contents($pluginSource . '/src/added.php', 'late new plugin candidate');
        file_put_contents(JPATH_PLUGINS . '/task/appointments/src/added.php', 'unknown late plugin destination');
        $refused($verifyPlugin);
        $assert(file_get_contents(JPATH_PLUGINS . '/task/appointments/src/added.php') === 'unknown late plugin destination',
            'A new recursive plugin input must not overwrite an unreviewed destination.');
        unlink($pluginSource . '/src/added.php');
        unlink(JPATH_PLUGINS . '/task/appointments/src/added.php');
        unlink($moduleDestination);
        unlink($obsolete);
        unlink($moduleRoot . '/src/retained.php');
        unlink($moduleRoot . '/mod_appointments.xml');
        rmdir($moduleRoot . '/src/obsolete');

        $appointmentInstaller = new \Com_appointmentsInstallerScript();
        copy($reviewSource . '/.advans-recovery-staged.php', $appointmentSource . '/.advans-recovery-staged.php');
        $app->messages = [];
        $assert($appointmentInstaller->preflight('update', $appointmentParent) === false
            && str_contains($messageText(), 'COM_APPOINTMENTS_INSTALL_CONFLICT_ERROR'),
            'The real installer must reject a reused staging directory before the shared installer can trust its local bytes.');
        unlink($appointmentSource . '/.advans-recovery-staged.php');
        $app->messages = [];
        $assert($appointmentInstaller->preflight('update', $appointmentParent) === false,
            'The Appointments installer must stop before Joomla copies files.');
        $assert(str_contains($messageText(), 'plugins/task/appointments/example.php')
            && str_contains($messageText(), hash('sha256', 'unidentified 1')),
            'The Appointments installer must display the unknown bundled plugin and its hash.');
        $assert($appointmentInstaller->preflight('update', $appointmentParent) === false,
            'Repeating an Appointments update must not approve unknown files.');

        copy($appointmentFiles[$pluginFile], $pluginFile);
        \Advans\Installer\V2\State::write($appointmentState . '/installed.php', [
            'version' => 'fixture',
            'files' => [
                'administrator/components/com_appointments/example.php' => hash_file('sha256', $componentFile),
                'plugins/task/appointments/example.php' => hash_file('sha256', $pluginFile),
            ],
        ]);
        $changingInstaller = new class($componentFile) extends \Com_appointmentsInstallerScript {
            private int $comparisons = 0;
            public function __construct(private string $file) {}
            protected function guardedFiles(string $type, object $parent): array
            {
                if (++$this->comparisons === 2) {
                    file_put_contents($this->file, 'changed during shared preflight');
                }
                return parent::guardedFiles($type, $parent);
            }
        };
        $assert($changingInstaller->preflight('update', $appointmentParent) === false,
            'A file becoming unknown during shared preflight must stop the installation.');
        $assert(file_get_contents($componentFile) === 'changed during shared preflight',
            'The second comparison must not replace the changed file.');

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

        // A missing task uses its own cleanup defaults; only retention settings come from the parent.
        $reset();
        $tasks::ensure('plg_privacy_j2commerce');
        $assert(count($app->saved) === 1 && $app->saved[0]['type'] === $routine && $app->saved[0]['state'] === 1, 'A missing required task must be created once, switched on.');
        $rules = $app->saved[0]['execution_rules'];
        $assert($rules['rule-type'] === 'interval-days' && $rules['interval-days'] === 1 && $rules['exec-time'] === '02:15' && $rules['exec-day'] === gmdate('d'), 'The anonymisation task must run every day at 02:15 UTC.');
        $assert($app->saved[0]['params'] === ['retention_years' => 7, 'fiscal_year_end' => '06-30', 'anonymize_orders' => 1, 'delete_addresses' => 1], 'The task must retain its own cleanup defaults and inherit only the plugin retention settings: ' . json_encode($app->saved[0]['params']));
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
