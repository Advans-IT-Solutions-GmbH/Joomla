<?php
/**
 * Stage shared installer support without maintaining runtime copies in extensions.
 */

if (PHP_SAPI !== 'cli' || $argc !== 2 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php prepare-installer.php BUILD_DIRECTORY\n");
    exit(1);
}

$stage = realpath($argv[1]);
$shared = dirname(__DIR__) . '/installer';

$copyTree = static function (string $source, string $target) use (&$copyTree): void {
    if (!is_dir($target) && !mkdir($target, 0755, true)) {
        throw new RuntimeException('Cannot create installer support directory.');
    }

    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot()) {
            continue;
        }

        $destination = $target . '/' . $entry->getFilename();

        if ($entry->isDir()) {
            $copyTree($entry->getPathname(), $destination);
        } elseif (!copy($entry->getPathname(), $destination)) {
            throw new RuntimeException('Cannot stage installer support file.');
        }
    }
};

$stageManifest = static function (string $path) use ($shared, $copyTree, &$stageManifest): void {
    $xml = simplexml_load_file($path);

    if (!$xml instanceof SimpleXMLElement || $xml->getName() !== 'extension') {
        throw new RuntimeException('Invalid extension manifest.');
    }

    $id = strtolower((string) $xml->name);
    $source = dirname($path);
    $type = (string) $xml['type'];
    $copyTree($shared, $source . '/advansinstaller');

    if ($type === 'package') {
        return;
    }

    $files = $type === 'component' ? $xml->administration->files : $xml->files;

    if (!count($files)) {
        throw new RuntimeException('Manifest has no installable files.');
    }

    $targetSource = $source . '/' . (string) $files['folder'];
    $copyTree($shared, rtrim($targetSource, '/') . '/advansinstaller');
    $files->addChild('folder', 'advansinstaller');

    switch ($type) {
        case 'component':
            $installed = 'administrator/components/' . $id;
            $configPath = rtrim($targetSource, '/') . '/config.xml';

            if (is_file($configPath)) {
                $config = simplexml_load_file($configPath);
            } elseif (isset($xml->config)) {
                $config = simplexml_load_string($xml->config->asXML());
            } else {
                $config = new SimpleXMLElement('<config/>');
            }

            $listed = array_map('strval', iterator_to_array($files->children(), false));

            if (!in_array('config.xml', $listed, true)) {
                $files->addChild('filename', 'config.xml');
            }

            break;
        case 'plugin':
            $element = (string) $xml->element;

            if ($element === '') {
                foreach ($files->children() as $entry) {
                    if ((string) $entry['plugin'] !== '') {
                        $element = (string) $entry['plugin'];
                        break;
                    }
                }
            }

            $installed = 'plugins/' . (string) $xml['group'] . '/' . $element;
            $config = isset($xml->config) ? $xml->config : $xml->addChild('config');
            break;
        case 'module':
            $installed = 'administrator/modules/' . $id;
            $config = isset($xml->config) ? $xml->config : $xml->addChild('config');
            break;
        default:
            throw new RuntimeException('Unsupported extension type.');
    }

    $fields = $config;

    if ($type !== 'component') {
        $existing = $config->xpath('fields[@name="params"]');
        $fields = $existing[0] ?? $config->addChild('fields');

        if (!isset($fields['name'])) {
            $fields->addAttribute('name', 'params');
        }
    }
    $fieldset = $fields->addChild('fieldset');
    $fieldset->addAttribute('name', 'advans_systemcheck');
    $fieldset->addAttribute('label', 'ADVANS_CHECK_TITLE');
    $fieldset->addAttribute('addfieldpath', '/' . $installed . '/advansinstaller');
    $field = $fieldset->addChild('field');
    $field->addAttribute('name', 'advans_systemcheck');
    $field->addAttribute('type', 'advanssystemcheck');
    $field->addAttribute('extension', $id);
    $field->addAttribute('label', 'ADVANS_CHECK_TITLE');
    $field->addAttribute('filter', 'unset');

    if ($id === 'com_dmarcreports') {
        $packageCheck = $fieldset->addChild('field');
        $packageCheck->addAttribute('name', 'advans_packagecheck');
        $packageCheck->addAttribute('type', 'advanssystemcheck');
        $packageCheck->addAttribute('extension', 'pkg_dmarcreports');
        $packageCheck->addAttribute('installedonly', '1');
        $packageCheck->addAttribute('label', 'ADVANS_CHECK_PACKAGE');
        $packageCheck->addAttribute('filter', 'unset');
    }

    if ($type === 'component' && !$config->asXML($configPath)) {
        throw new RuntimeException('Cannot stage component configuration.');
    }

    foreach (glob($source . '/plugins/*/*/*.xml') ?: [] as $child) {
        $stageManifest($child);
    }

    // Load tab labels before Joomla renders the custom field.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $entry) {
        if (!$entry->isFile() || !preg_match('~[/\\\\]([a-z]{2,3}-[A-Z]{2})[/\\\\][^/\\\\]+\.ini$~', $entry->getPathname(), $match)
            || str_contains(str_replace('\\', '/', $entry->getPathname()), '/advansinstaller/')) {
            continue;
        }

        $ini = file_get_contents($entry->getPathname());
        $translationFile = $shared . '/language/' . $match[1] . '/advans_installer.ini';

        if (!is_file($translationFile)) {
            throw new RuntimeException('Shared installer translation missing for ' . $match[1]);
        }

        $translation = file_get_contents($translationFile);

        if (!str_contains($ini, 'ADVANS_CHECK_TITLE=') && file_put_contents($entry->getPathname(), "\n" . $translation, FILE_APPEND) === false) {
            throw new RuntimeException('Cannot stage system-check translations.');
        }
    }

    if (!$xml->asXML($path)) {
        throw new RuntimeException('Cannot stage extension manifest.');
    }
};

$found = false;

foreach (glob($stage . '/*.xml') ?: [] as $manifest) {
    $xml = simplexml_load_file($manifest);

    if ($xml instanceof SimpleXMLElement && $xml->getName() === 'extension') {
        $stageManifest($manifest);
        $found = true;
    }
}

if (!$found) {
    throw new RuntimeException('No root extension manifest to stage.');
}
