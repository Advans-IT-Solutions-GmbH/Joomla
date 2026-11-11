<?php

namespace Joomla\Database {
    interface DatabaseInterface
    {
    }

    interface QueryInterface
    {
    }

    final class ParameterType
    {
        public const INTEGER = 'integer';
        public const STRING = 'string';
    }
}

namespace Joomla\CMS\Installer {
    final class Installer
    {
        public static string $mode = 'success';
        public static array $calls = [];

        public static function getInstance(): self
        {
            return new self();
        }

        public function uninstall(string $type, int $id): bool
        {
            self::$calls[] = [$type, $id];

            if (self::$mode === 'throw') {
                throw new \RuntimeException('synthetic installer failure');
            }

            if (self::$mode === 'false') {
                return false;
            }

            \FakeDatabase::$active->remove($id);

            return true;
        }
    }
}

namespace Joomla\CMS\Language {
    final class Text
    {
        public static function _(string $key): string
        {
            return $key;
        }

        public static function sprintf(string $key, ...$values): string
        {
            return $key . ($values ? ':' . implode('|', $values) : '');
        }
    }
}

namespace Joomla\CMS\Toolbar {
    final class ToolbarHelper
    {
        public static array $preferences = [];

        public static function preferences(string $component): void
        {
            self::$preferences[] = $component;
        }
    }
}

namespace {
    use Joomla\CMS\Installer\Installer;
    use Joomla\Database\DatabaseInterface;
    use Joomla\Database\ParameterType;
    use Joomla\Database\QueryInterface;
    use Joomla\CMS\Toolbar\ToolbarHelper;

    define('_JEXEC', true);
    define('JVERSION', '6.0');

    $extensionRoot = dirname(__DIR__, 2);
    $fixtureRoot   = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'j2cleanup-auth-' . bin2hex(random_bytes(6));
    mkdir($fixtureRoot, 0777, true);
    define('JPATH_SITE', $fixtureRoot);
    define('JPATH_ADMINISTRATOR', $fixtureRoot . DIRECTORY_SEPARATOR . 'administrator');
    define('JPATH_PLUGINS', $fixtureRoot . DIRECTORY_SEPARATOR . 'plugins');
    define('JPATH_LIBRARIES', $fixtureRoot . DIRECTORY_SEPARATOR . 'libraries');
    define('J2STORE_CLEANUP_FUNCTIONS_ONLY', true);

    require $extensionRoot . DIRECTORY_SEPARATOR . 'administrator'
        . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'com_j2store_cleanup'
        . DIRECTORY_SEPARATOR . 'j2store_cleanup.php';

    final class FakeQuery implements QueryInterface
    {
        public string $operation = 'select';
        public array $ids = [];
        public array $conditions = [];
        public array $bindings = [];

        public function select($fields): self
        {
            return $this;
        }

        public function from($table): self
        {
            return $this;
        }

        public function order($order): self
        {
            return $this;
        }

        public function whereIn(string $field, array $values): self
        {
            $this->ids = $values;
            return $this;
        }

        public function where(string $condition): self
        {
            $this->conditions[] = $condition;
            return $this;
        }

        public function delete(string $table): self
        {
            $this->operation = 'delete';
            return $this;
        }

        public function bind(string $placeholder, &$value, $type = null): self
        {
            $this->bindings[$placeholder] = $value;
            return $this;
        }
    }

    final class FakeDatabase implements DatabaseInterface
    {
        public static self $active;
        private FakeQuery $query;
        private int $affectedRows = 0;
        private array $rows = [];
        public bool $failExecute = false;

        public function __construct(array $rows)
        {
            foreach ($rows as $row) {
                $this->rows[(int) $row['extension_id']] = $row;
            }
            self::$active = $this;
        }

        public function createQuery(): FakeQuery
        {
            return new FakeQuery();
        }

        public function quoteName($name)
        {
            return $name;
        }

        public function setQuery(FakeQuery $query): self
        {
            $this->query = $query;
            $this->affectedRows = 0;
            return $this;
        }

        public function loadObjectList(): array
        {
            $rows = array_filter($this->rows, fn ($row) => $this->matches($row));

            return array_map(static fn ($row) => (object) $row, array_values($rows));
        }

        public function loadObject(): ?object
        {
            $rows = $this->loadObjectList();

            return $rows[0] ?? null;
        }

        public function execute(): bool
        {
            if ($this->failExecute) {
                throw new \RuntimeException('synthetic database failure');
            }

            foreach ($this->rows as $id => $row) {
                if ($this->matches($row)) {
                    unset($this->rows[$id]);
                    $this->affectedRows++;
                }
            }

            return true;
        }

        public function getAffectedRows(): int
        {
            return $this->affectedRows;
        }

        public function remove(int $id): void
        {
            unset($this->rows[$id]);
        }

        public function has(int $id): bool
        {
            return isset($this->rows[$id]);
        }

        private function matches(array $row): bool
        {
            if ($this->query->ids && !in_array((int) $row['extension_id'], $this->query->ids, true)) {
                return false;
            }

            foreach ($this->query->conditions as $condition) {
                if (preg_match('/^([a-z_]+)\\s*=\\s*:(\\w+)$/i', $condition, $match)) {
                    if ((string) ($row[$match[1]] ?? '') !== (string) ($this->query->bindings[':' . $match[2]] ?? null)) {
                        return false;
                    }
                } elseif (preg_match('/^([a-z_]+)\\s*=\\s*(\\d+)$/i', $condition, $match)) {
                    if ((int) ($row[$match[1]] ?? 0) !== (int) $match[2]) {
                        return false;
                    }
                }
            }

            return true;
        }
    }

    final class CleanupAuthorizationTests
    {
        private int $passed = 0;
        private int $failed = 0;

        public function run(): int
        {
            $incompatiblePath = JPATH_PLUGINS . '/j2store/test_incompatible';
            mkdir($incompatiblePath, 0777, true);
            file_put_contents($incompatiblePath . '/legacy.php', '<?php JFactory::getApplication();');

            $compatiblePath = JPATH_PLUGINS . '/j2commerce/test_compatible';
            mkdir($compatiblePath, 0777, true);
            file_put_contents($compatiblePath . '/clean.php', '<?php echo "ok";');

            $this->testCandidateScope();
            $this->testAuthorizationPermissions();
            $this->testPermissionEditorConfiguration();
            $this->testDirectMutationRejections();
            $this->testMixedSelectionFailsClosed();
            $this->testEligibleRemovalPaths();
            $this->testInstallerRefusalIsNotBypassed();

            $this->removeTree($incompatiblePath);
            $this->removeTree($compatiblePath);
            $this->removeTree(sys_get_temp_dir() . DIRECTORY_SEPARATOR . basename(JPATH_SITE));

            echo "Cleanup authorization: {$this->passed} passed, {$this->failed} failed\n";

            return $this->failed === 0 ? 0 : 1;
        }

        private function testCandidateScope(): void
        {
            $this->check('J2Store plugin is a candidate', isCleanupCandidate((object) $this->row(1, 'plugin', 'payment_test', 'j2store')));
            $this->check('Namespaced shop plugin in another group is a candidate', isCleanupCandidate((object) $this->row(9, 'plugin', 'j2store_sync', 'system')));
            $this->check('J2Commerce component add-on is a candidate', isCleanupCandidate((object) $this->row(2, 'component', 'com_j2commerce_test')));
            $this->check('J2Store module is a candidate', isCleanupCandidate((object) $this->row(10, 'module', 'mod_j2store')));
            $this->check('System/security plugin is out of scope', !isCleanupCandidate((object) $this->row(3, 'plugin', 'system_security', 'system')));
            $this->check('Shop core components are protected', !isCleanupCandidate((object) $this->row(4, 'component', 'com_j2store'))
                && !isCleanupCandidate((object) $this->row(5, 'component', 'com_j2commerce')));
            $this->check('Protected extension records are not candidates', !isCleanupCandidate((object) $this->row(6, 'plugin', 'payment_protected', 'j2store', 1)));
            $this->check('Extension type and element must match', !isCleanupCandidate((object) $this->row(7, 'module', 'com_j2commerce_forged')));
            $this->check('Plugin type cannot claim a component element', !isCleanupCandidate((object) $this->row(8, 'plugin', 'com_j2commerce_forged', 'j2store')));
            $this->check('Cleanup component cannot select itself', !isCleanupCandidate((object) $this->row(8, 'component', 'com_j2store_cleanup')));
            $this->check('J2Store library is a candidate', isCleanupCandidate((object) $this->row(11, 'library', 'j2store/legacy')));
            // File extensions have no single install path: missing files cannot be verified.
            $this->check('File extensions are out of scope', !isCleanupCandidate((object) $this->row(12, 'file', 'j2store_files'))
                && !isCleanupCandidate((object) $this->row(13, 'file', 'files_j2commerce_assets')));
            $fileDb = new FakeDatabase([$this->row(12, 'file', 'j2store_files')]);
            Installer::$calls = [];
            $fileResult = cleanupExtensions($fileDb, [12]);
            $this->check('A file extension record is never removed database-only', $fileResult['error'] === 1
                && $fileDb->has(12) && Installer::$calls === []);
        }

        private function testAuthorizationPermissions(): void
        {
            $user = new class {
                public array $permissions = ['core.manage' => true, 'core.delete' => true];
                public function authorise(string $action, string $asset): bool
                {
                    return $asset === 'com_j2store_cleanup' && ($this->permissions[$action] ?? false);
                }
            };

            $this->check('Manage and delete permissions allow cleanup', canRemoveExtensions($user));
            $this->check('Manage permission opens the scan view', canAccessCleanup($user));
            $user->permissions['core.delete'] = false;
            $this->check('Manage without delete permission cannot cleanup', !canRemoveExtensions($user));
            $this->check('Manage without delete permission still opens the scan view', canAccessCleanup($user));
            $user->permissions['core.delete'] = true;
            $user->permissions['core.manage'] = false;
            $this->check('Delete without manage permission cannot cleanup', !canRemoveExtensions($user));
            $this->check('Delete without manage permission cannot open the scan view', !canAccessCleanup($user));
            $this->check('Guest cannot cleanup', !canRemoveExtensions(null));
            $this->check('Guest cannot open the scan view', !canAccessCleanup(null));

            // The legacy dispatcher performs no ACL check, so the entry file must deny a user
            // without core.manage before it handles any task or renders the scan.
            $source = (string) file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'administrator'
                . DIRECTORY_SEPARATOR . 'components' . DIRECTORY_SEPARATOR . 'com_j2store_cleanup'
                . DIRECTORY_SEPARATOR . 'j2store_cleanup.php');
            $gate    = strpos($source, 'if (!canAccessCleanup($app->getIdentity()))');
            $task    = strpos($source, "if (\$task === 'cleanup'");
            $scan    = strpos($source, '$db->setQuery($query);', $task === false ? 0 : $task);
            $this->check('Entry file denies users without core.manage before any task or scan',
                $gate !== false && $task !== false && $scan !== false && $gate < $task && $gate < $scan
                && str_contains(substr($source, $gate, 200), 'NotAllowed'));
        }

        private function testPermissionEditorConfiguration(): void
        {
            $extensionRoot = dirname(__DIR__, 2);
            $manifestPath = $extensionRoot . DIRECTORY_SEPARATOR . 'com_j2store_cleanup.xml';
            if (!is_file($manifestPath)) {
                $manifestPath = $extensionRoot . DIRECTORY_SEPARATOR . 'administrator' . DIRECTORY_SEPARATOR
                    . 'components' . DIRECTORY_SEPARATOR . 'com_j2store_cleanup'
                    . DIRECTORY_SEPARATOR . 'com_j2store_cleanup.xml';
            }
            if (!is_file($manifestPath)) {
                throw new \RuntimeException('Cleanup manifest missing from both source and installed component locations');
            }
            $manifest = simplexml_load_file($manifestPath);
            $files = $manifest->administration->files;
            $componentDirectory = $extensionRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, (string) $files['folder']);
            $packagedFiles = array_map('strval', iterator_to_array($files->filename, false));

            $this->check('Manifest installs the physical config.xml and access.xml in the component directory',
                (string) $files['folder'] === 'administrator/components/com_j2store_cleanup'
                && in_array('config.xml', $packagedFiles, true)
                && in_array('access.xml', $packagedFiles, true));

            // com_config discovers the physical config.xml and selects its /config root.
            $config = simplexml_load_file($componentDirectory . DIRECTORY_SEPARATOR . 'config.xml');
            $this->check('Physical component form has the root required by com_config', count($config->xpath('/config')) === 1);
            $rules = $config->xpath(
                '/config/fieldset[@name="permissions"]/field[@name="rules" and @type="rules"'
                . ' and @component="com_j2store_cleanup" and @section="component"]'
            );
            $this->check('Physical form exposes the component-scoped filtered and validated rules field',
                count($rules) === 1 && (string) $rules[0]['filter'] === 'rules'
                && (string) $rules[0]['validate'] === 'rules');
            $this->check('Physical permission form and manifest add no automatic allow defaults',
                count($config->xpath('//*[@default] | //rule')) === 0
                && count($manifest->xpath('//field[@name="rules"]')) === 0);

            $access = simplexml_load_file($componentDirectory . DIRECTORY_SEPARATOR . 'access.xml');
            $this->check('Packaged ACL declares admin and delete actions without granting either',
                count($access->xpath('/access/section[@name="component"]/action[@name="core.delete" or @name="core.admin"]')) === 2
                && count($access->xpath('//rule')) === 0);

            $user = new class {
                public array $permissions = [];
                public function authorise(string $action, string $asset): bool
                {
                    return $asset === 'com_j2store_cleanup' && ($this->permissions[$action] ?? false);
                }
            };
            foreach ([
                'manage and delete alone' => ['core.manage' => true, 'core.delete' => true],
                'options' => ['core.options' => true],
                'admin' => ['core.admin' => true],
                'no permissions' => [],
            ] as $name => $permissions) {
                $user->permissions = $permissions;
                ToolbarHelper::$preferences = [];
                addCleanupPreferences($user);
                $expected = !empty($permissions['core.options']) || !empty($permissions['core.admin'])
                    ? ['com_j2store_cleanup'] : [];
                $this->check("Options toolbar visibility with {$name}",
                    ToolbarHelper::$preferences === $expected);
            }
        }

        private function testDirectMutationRejections(): void
        {
            $rows = [
                $this->row(1, 'plugin', 'payment_valid', 'j2store'),
                $this->row(2, 'plugin', 'system_security', 'system'),
                $this->row(3, 'component', 'com_j2store'),
                $this->row(4, 'component', 'com_j2commerce'),
                $this->row(5, 'plugin', 'payment_protected', 'j2store', 1),
                $this->row(6, 'module', 'com_j2commerce_forged'),
                $this->row(7, 'plugin', 'com_j2commerce_forged', 'j2store'),
                $this->row(8, 'plugin', 'test_compatible', 'j2commerce'),
            ];
            $db = new FakeDatabase($rows);
            Installer::$mode = 'throw';
            Installer::$calls = [];

            foreach ([2, 3, 4, 5, 6, 7, 8] as $id) {
                $result = cleanupExtensions($db, [$id]);
                $this->check("Direct helper rejects protected/ineligible ID {$id}", $result['error'] === 1 && $db->has($id));
            }

            $this->check('Rejected IDs never reach Joomla Installer', Installer::$calls === []);
            $this->check('Low-level DB fallback rejects an out-of-scope record',
                !removeCleanupExtensionRecord($db, (object) $this->row(2, 'plugin', 'system_security', 'system'), getIssuePatterns())
                && $db->has(2));
            Installer::$mode = 'success';

            $missing = cleanupExtensions($db, [999999]);
            $this->check('Missing extension record reports an error', $missing['error'] === 1
                && str_starts_with($missing['messages'][0] ?? '', 'COM_J2STORE_CLEANUP_MSG_EXTENSION_NOT_FOUND'));

            $malformed = cleanupExtensions($db, ['1; DROP TABLE extensions']);
            $this->check('Malformed IDs are rejected without coercion', $malformed['error'] === 1 && $db->has(1));
        }

        private function testMixedSelectionFailsClosed(): void
        {
            $db = new FakeDatabase([
                $this->row(1, 'plugin', 'payment_valid', 'j2store'),
                $this->row(2, 'plugin', 'system_security', 'system'),
            ]);
            Installer::$calls = [];
            $result = cleanupExtensions($db, [1, 2]);

            $this->check('Mixed eligible and forbidden selection fails closed', $result['error'] === 1 && $db->has(1));
            $this->check('Fail-closed mixed selection does not call Installer', Installer::$calls === []);
        }

        private function testEligibleRemovalPaths(): void
        {
            $db = new FakeDatabase([
                $this->row(1, 'plugin', 'payment_missing', 'j2store'),
                $this->row(2, 'plugin', 'test_incompatible', 'j2store'),
                $this->row(3, 'component', 'com_j2commerce_test'),
            ]);

            Installer::$mode = 'success';
            Installer::$calls = [];
            $missing = cleanupExtensions($db, [1]);
            $this->check('Eligible missing-files record is removed database-only', $missing['warning'] === 1
                && !$db->has(1) && Installer::$calls === []);

            $uninstalled = cleanupExtensions($db, [2]);
            $this->check('Eligible incompatible extension uses Joomla Installer', $uninstalled['success'] === 1
                && !$db->has(2) && Installer::$calls === [['plugin', 2]]);

            $component = cleanupExtensions($db, [3]);
            $this->check('Eligible J2Commerce component is still removable', $component['warning'] === 1 && !$db->has(3));

            $directDb  = new FakeDatabase([$this->row(5, 'plugin', 'payment_direct', 'j2store')]);
            $directRow = (object) $this->row(5, 'plugin', 'payment_direct', 'j2store');
            $directlyRemoved = removeCleanupExtensionRecord($directDb, $directRow, getIssuePatterns());
            $this->check('Direct eligible database removal executes with reference-bound query values',
                $directlyRemoved && !$directDb->has(5));

            $failedDb = new FakeDatabase([$this->row(4, 'plugin', 'payment_db_error', 'j2store')]);
            $failedDb->failExecute = true;
            $failure = cleanupExtensions($failedDb, [4]);
            $this->check('Database-only failure is reported without deleting the row', $failure['error'] === 1
                && $failedDb->has(4) && str_contains($failure['messages'][0] ?? '', 'synthetic database failure'));
        }

        private function testInstallerRefusalIsNotBypassed(): void
        {
            $db = new FakeDatabase([$this->row(1, 'plugin', 'test_incompatible', 'j2store')]);
            Installer::$mode = 'false';
            Installer::$calls = [];
            $result = cleanupExtensions($db, [1]);

            $this->check('Joomla false refusal preserves the extension record', $result['error'] === 1
                && $db->has(1) && str_starts_with($result['messages'][0] ?? '', 'COM_J2STORE_CLEANUP_MSG_UNINSTALL_REFUSED'));

            Installer::$mode = 'throw';
            $result = cleanupExtensions($db, [1]);
            $this->check('Eligible installer exception uses guarded DB-only fallback', $result['warning'] === 1 && !$db->has(1));

            $failedDb = new FakeDatabase([$this->row(1, 'plugin', 'test_incompatible', 'j2store')]);
            $failedDb->failExecute = true;
            $result = cleanupExtensions($failedDb, [1]);
            $this->check('A failed fallback after an installer exception is reported through a language key',
                $result['error'] === 1 && $failedDb->has(1)
                && ($result['messages'][0] ?? '') === 'test_incompatible: COM_J2STORE_CLEANUP_MSG_FALLBACK_FAILED:'
                    . 'synthetic installer failure|synthetic database failure');
            Installer::$mode = 'success';
        }

        private function row(int $id, string $type, string $element, string $folder = '', int $protected = 0): array
        {
            return [
                'extension_id' => $id,
                'type' => $type,
                'element' => $element,
                'folder' => $folder,
                'client_id' => 0,
                'protected' => $protected,
                'manifest_cache' => '{}',
            ];
        }

        private function check(string $name, bool $condition): void
        {
            echo ($condition ? 'PASS' : 'FAIL') . ': ' . $name . "\n";
            $condition ? $this->passed++ : $this->failed++;
        }

        private function removeTree(string $path): void
        {
            if (!is_dir($path)) {
                return;
            }

            foreach (scandir($path) as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $target = $path . DIRECTORY_SEPARATOR . $entry;
                is_dir($target) ? $this->removeTree($target) : unlink($target);
            }

            rmdir($path);
        }
    }

    exit((new CleanupAuthorizationTests())->run());
}
