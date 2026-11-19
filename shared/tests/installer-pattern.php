<?php
/**
 * Offline coverage of every staged extension and its declared destinations.
 */

namespace Joomla\CMS {
    final class Version
    {
        public const MAJOR_VERSION = 5;
    }
}

namespace {
    define('_JEXEC', 1);
    set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
        throw new \ErrorException($message, 0, $severity, $file, $line);
    });
    $root = dirname(__DIR__, 2);
    $work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'advans-pattern-' . bin2hex(random_bytes(12));
    mkdir($work, 0700, true);
    define('JPATH_ROOT', $work . '/site');
    define('JPATH_SITE', JPATH_ROOT);
    define('JPATH_ADMINISTRATOR', JPATH_ROOT . '/administrator');
    define('JPATH_PLUGINS', str_replace('/', DIRECTORY_SEPARATOR, JPATH_ROOT . '/plugins'));

    require_once $root . '/shared/installer/SystemCheck.php';
    require_once $root . '/shared/installer/State.php';
    require_once $root . '/shared/installer/Destinations.php';
    require_once $root . '/shared/installer/FileGuard.php';

    $assert = static function (bool $ok, string $message): void {
        if (!$ok) {
            throw new \RuntimeException($message);
        }
    };
    $copy = static function (string $from, string $to) use (&$copy): void {
        if (!is_dir($to)) {
            mkdir($to, 0700, true);
        }

        foreach (new \DirectoryIterator($from) as $entry) {
            if ($entry->isDot() || in_array($entry->getFilename(), ['tests', 'tests-j2c4', 'tests-j2c6', 'vendor', 'build', 'node_modules'], true)
                || $entry->getExtension() === 'zip') {
                continue;
            }

            if ($entry->isDir()) {
                $copy($entry->getPathname(), $to . '/' . $entry->getFilename());
            } elseif (!copy($entry->getPathname(), $to . '/' . $entry->getFilename())) {
                throw new \RuntimeException('Cannot stage fixture file.');
            }
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
        $catalog = require $root . '/shared/installer/catalog.php';
        $checked = ['standalone' => [], 'bundled' => []];

        // Every scheduled task the catalog names is defined in tasks.php, advertised by its task
        // plugin with a translated title, and the form that gives its defaults exists.
        $routines = require $root . '/shared/installer/tasks.php';
        $taskPlugins = [
            'task/j2commerceprivacy' => '/j2commerce/plg_privacy_j2commerce/plugins/task/j2commerceprivacy',
            'task/appointments'      => '/com_appointments/plugins/task/appointments',
            'task/dmarcreports'      => '/plg_task_dmarcreports',
        ];
        $shared = [];

        foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
            $shared[$tag] = parse_ini_file($root . '/shared/installer/language/' . $tag . '/advans_installer.ini', false, INI_SCANNER_RAW);
        }

        $named = [];

        foreach ($catalog as $id => $definition) {
            foreach ($definition['tasks'] ?? [] as $routine) {
                $assert(isset($routines[$routine]), 'The catalog of ' . $id . ' names an undefined scheduled task: ' . $routine);
                $named[$routine] = true;
            }
        }

        foreach ($routines as $routine => $spec) {
            $assert(isset($named[$routine]), 'No extension checks the scheduled task ' . $routine);
            $source = $root . ($taskPlugins[implode('/', $spec['plugin'])] ?? '/missing');
            $code = implode('', array_map('file_get_contents', glob($source . '/src/Extension/*.php') ?: []));
            $assert(str_contains($code, "'" . $routine . "'"), 'The task plugin does not advertise ' . $routine);
            $assert(!isset($spec['form']) || is_file($source . '/' . $spec['form']), 'Missing task form of ' . $routine);
            $assert(is_string($spec['need']) ? in_array($spec['need'], ['required', 'optional'], true) : count($spec['need']['when'] ?? []) === 4, 'Unknown need of ' . $routine);
            $assert($spec['need'] === 'optional' || isset($spec['rules']['rule-type']), 'A required task needs execution rules: ' . $routine);

            foreach (['de-DE', 'en-GB', 'fr-FR'] as $tag) {
                $strings = [];

                foreach (glob($source . '/language/' . $tag . '/' . $spec['language'] . '.ini') ?: [] as $file) {
                    $strings += parse_ini_file($file, false, INI_SCANNER_RAW);
                }

                $assert(isset($strings[$spec['title']]), 'Untranslated title of ' . $routine . ' in ' . $tag);
                $assert(isset($shared[$tag][$spec['purpose']]), 'Untranslated purpose of ' . $routine . ' in ' . $tag);
            }
        }

        foreach (array_keys($catalog) as $id) {
            $path = is_dir($root . '/' . $id) ? $root . '/' . $id : $root . '/j2commerce/' . $id;

            if (!is_file($path . '/build.env')) {
                continue;
            }

            $stage = $work . '/' . $id;
            $copy($path, $stage);
            $process = proc_open([PHP_BINARY, $root . '/shared/build/prepare-installer.php', $stage], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $assert(proc_close($process) === 0, 'Staging failed for ' . $id . ': ' . $stdout . $stderr);

            $process = proc_open([PHP_BINARY, $root . '/shared/tests/lang-lint.php', '--package', $stage], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $assert(proc_close($process) === 0, 'Staged language delivery failed for ' . $id . ': ' . $stdout . $stderr);

            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($stage, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST) as $entry) {
                if (!$entry->isDir() || basename(dirname($entry->getPathname())) !== 'language') {
                    continue;
                }

                $assert(in_array($entry->getFilename(), ['de-DE', 'en-GB', 'fr-FR'], true), 'Unsupported staged language for ' . $id . ': ' . $entry->getFilename());
            }

            $manifests = array_merge(glob($stage . '/*.xml') ?: [], glob($stage . '/plugins/*/*/*.xml') ?: []);

            foreach ($manifests as $manifestFile) {
                $manifest = simplexml_load_file($manifestFile);

                if (!$manifest instanceof \SimpleXMLElement || $manifest->getName() !== 'extension') {
                    continue;
                }

                $definition = \Advans\Installer\V2\SystemCheck::definition(strtolower((string) $manifest->name));
                $installed = \Advans\Installer\V2\SystemCheck::root($definition);
                $source = dirname($manifestFile);
                $files = \Advans\Installer\V2\Destinations::files($manifest, $source, $manifestFile);

                foreach ($files as $destination => $candidate) {
                    $assert(is_file($candidate), 'Missing candidate for ' . $destination . ': ' . $candidate);
                    $assert(str_starts_with($destination, str_replace('\\', '/', JPATH_ROOT)), 'Destination outside Joomla.');
                }

                $assert(isset($files[str_replace('\\', '/', $installed . '/advansinstaller/SystemCheck.php')]), 'Shared check not installed for ' . (string) $manifest->name);

                if (strtolower((string) $manifest->name) === 'com_appointments') {
                    $assert(is_file($stage . '/advansinstaller/AppointmentsConflict.php')
                        && is_file($stage . '/advansinstaller/export-backup.php'),
                        'Appointments package must contain the preflight guard and backup exporter.');
                    $assert(isset($files[str_replace('\\', '/', $installed . '/advansinstaller/export-backup.php')]),
                        'Appointments backup exporter must also be installed.');
                    $companion = simplexml_load_file($stage . '/recovery/appointmentsrecovery.xml');
                    $assert($companion instanceof \SimpleXMLElement && (string) $companion->version === (string) $manifest->version
                        && is_file($stage . '/recovery/support/AppointmentsConflict.php')
                        && is_file($stage . '/recovery/LICENSE.txt'),
                        'The standalone recovery package needs the matching manifest, support and license.');
                }

                if (strtolower((string) $manifest->name) === 'plg_j2commerce_productcompare') {
                    $mirror = str_replace('\\', '/', JPATH_PLUGINS . '/j2store/productcompare');
                    $assert(isset($files[$mirror . '/advansinstaller/SystemCheck.php']), 'Joomla 5 mirror is missing from the guarded destinations.');
                    $assert(($files[str_replace('\\', '/', $installed . '/productcompare.xml')] ?? null) === $manifestFile, 'Canonical options manifest is not guarded with the incoming manifest bytes.');
                    $assert(($files[$mirror . '/productcompare.xml'] ?? null) === $manifestFile, 'Mirrored options manifest is not guarded.');
                    $alias = str_replace('\\', '/', (string) ($files[$mirror . '/language/en-GB/plg_j2store_productcompare.ini'] ?? ''));
                    $assert(str_ends_with($alias, '/language/en-GB/plg_j2commerce_productcompare.ini'), 'Joomla 5 mirror lacks the plugin language under the j2store group name.');
                }

                if ($definition['type'] === 'package') {
                    $manifestTarget = str_replace('\\', '/', dirname($installed) . '/' . basename($manifestFile));
                    $assert(isset($files[$manifestTarget]), 'Package manifest is not guarded in Joomla manifest storage.');
                    $languageTarget = str_replace('\\', '/', JPATH_ROOT . '/language/en-GB/pkg_dmarcreports.ini');
                    $assert(isset($files[$languageTarget]), 'Package site-client language destination is not guarded.');
                    continue;
                }

                $config = $definition['type'] === 'component'
                    ? simplexml_load_file($source . '/' . (string) $manifest->administration->files['folder'] . '/config.xml')
                    : $manifest->config;
                $fields = $config->xpath('.//field[@type="advanssystemcheck" and @name="advans_systemcheck"]');
                $assert(count($fields) === 1, 'Expected exactly one repeatable check for ' . (string) $manifest->name);
                $assert((string) $fields[0]['extension'] === strtolower((string) $manifest->name), 'Wrong field check identity.');
                $assert((string) $fields[0]['filter'] === 'unset', 'Read-only check must not persist a configuration value.');
                $paths = $config->xpath('.//fieldset[@name="advans_systemcheck"]/@addfieldpath');
                $fieldFile = JPATH_ROOT . '/' . ltrim((string) $paths[0], '/') . '/advanssystemcheck.php';
                $assert(isset($files[str_replace('\\', '/', $fieldFile)]), 'Custom field path is not installed.');
                $checked[dirname($manifestFile) === $stage ? 'standalone' : 'bundled'][] = strtolower((string) $manifest->name);
            }

            echo 'PASS ' . $id . ": staged support, repeatable configuration field and file destinations\n";
        }

        $expected = [
            'standalone' => [
                'com_appointments',
                'com_dmarcreports',
                'com_j2commerce_importexport',
                'com_j2store_cleanup',
                'mod_dmarcreports',
                'plg_ajax_joomlaajaxforms',
                'plg_j2commerce_productcompare',
                'plg_osmap_j2commerce',
                'plg_privacy_j2commerce',
                'plg_system_dmarcmtasts',
                'plg_system_privacyrequests',
                'plg_task_dmarcreports',
            ],
            'bundled' => [
                'plg_content_appointmentsprivacy',
                'plg_privacy_appointments',
                'plg_system_appointmentsattribution',
                'plg_system_appointmentsdav',
                'plg_system_j2commerceprivacy',
                'plg_task_appointments',
                'plg_task_j2commerceprivacy',
                'plg_user_appointments',
            ],
        ];

        foreach ($expected as $kind => $names) {
            sort($checked[$kind]);
            $assert(
                $checked[$kind] === $names,
                'Checked ' . $kind . ' non-package extensions differ. Missing: '
                . (implode(', ', array_diff($names, $checked[$kind])) ?: 'none') . '; unexpected: '
                . (implode(', ', array_diff($checked[$kind], $names)) ?: 'none')
                . '. Update the expected list only together with the extension catalog.'
            );
        }
        $keys = null;

        foreach (['en-GB', 'de-DE', 'fr-FR'] as $tag) {
            $strings = parse_ini_file($root . '/shared/installer/language/' . $tag . '/advans_installer.ini', false, INI_SCANNER_RAW);
            $assert(is_array($strings), 'Invalid shared language: ' . $tag);
            $current = array_keys($strings);
            sort($current);
            $assert($keys === null || $keys === $current, 'Shared translation keys differ: ' . $tag);
            $keys = $current;
        }

        echo "PASS shared language key parity\n";

        $original = $work . '/existing.php';
        $incoming = $work . '/incoming.php';
        file_put_contents($original, '<?php echo "original";');
        file_put_contents($incoming, '<?php echo "incoming";');
        \Advans\Installer\V2\State::directory($work . '/state/fixture/backups');
        $conflicts = \Advans\Installer\V2\FileGuard::inspect([$original => $incoming], $work . '/state/fixture/backups');
        $plan = $work . '/state/fixture/plan.php';
        \Advans\Installer\V2\State::write($plan, $conflicts);
        $digest = hash_file('sha256', $plan);
        $run = static function (array $command): array {
            $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);

            return [proc_close($process), $output];
        };
        [$code, $output] = $run([PHP_BINARY, $root . '/shared/installer/approve.php', '--approve', $plan, $digest]);
        $assert($code === 0, 'Exact-content approval failed: ' . $output);
        $approved = \Advans\Installer\V2\State::read($work . '/state/approved.php');
        $checked = \Advans\Installer\V2\FileGuard::inspect([$original => $incoming], $work . '/state/fixture/backups', $approved);
        $assert($checked[0]['approved'] === true, 'Explicit CLI approval did not bind to the exact file bytes.');
        $assert(file_get_contents($original) === '<?php echo "original";', 'Approval modified the original.');
        [$code] = $run([PHP_BINARY, $root . '/shared/installer/approve.php', '--approve', $plan, str_repeat('0', 64)]);
        $assert($code !== 0, 'Stale plan fingerprint was accepted.');
        file_put_contents($original, '<?php echo "edited after review";');
        [$code] = $run([PHP_BINARY, $root . '/shared/installer/approve.php', '--approve', $plan, $digest]);
        $assert($code !== 0, 'Edited original was accepted by the approval command.');
        [$code, $output] = $run([PHP_BINARY, $plan]);
        $assert($output === '', 'Plan envelope disclosed its content when executed.');
        [$code, $output] = $run([PHP_BINARY, $conflicts[0]['backup']]);
        $assert($output === '', 'Backup envelope disclosed its content when executed.');
        echo "PASS CLI approval binds plan and destination hashes; guarded state and backups disclose no bytes\n";
    } finally {
        $remove($work);
    }
}
