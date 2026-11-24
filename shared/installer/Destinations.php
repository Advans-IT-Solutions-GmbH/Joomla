<?php
/**
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Installer\V3;

defined('_JEXEC') or die;

final class Destinations
{
    /** @return array<string,string> Absolute destination => absolute source. */
    public static function files(\SimpleXMLElement $manifest, string $source, string $manifestFile): array
    {
        $id = strtolower((string) $manifest->name);
        $definition = SystemCheck::definition($id);
        $root = str_replace('\\', '/', SystemCheck::root($definition));
        $files = [];

        if ($definition['type'] === 'component') {
            self::nodes($files, $manifest->files, $source, JPATH_ROOT . '/components/' . $definition['element']);
            self::nodes($files, $manifest->administration->files, $source, $root);
        } elseif ($definition['type'] !== 'package') {
            self::nodes($files, $manifest->files, $source, $root);
        }

        foreach ([$manifest->languages, $manifest->administration->languages] as $index => $languages) {
            if (!$languages) {
                continue;
            }

            $base = ($definition['type'] === 'component' && $index === 0) || $definition['type'] === 'package'
                ? JPATH_ROOT : JPATH_ADMINISTRATOR;

            foreach ($languages->children() as $language) {
                $client = (string) $language['client'];
                $languageBase = $client === 'administrator' ? JPATH_ADMINISTRATOR : ($client === 'site' ? JPATH_ROOT : $base);
                self::add($files, $languageBase . '/language/' . (string) $language['tag'] . '/' . basename((string) $language),
                    $source . '/' . self::relative((string) $languages['folder']) . '/' . self::relative((string) $language));
            }
        }

        foreach ($manifest->media as $media) {
            self::nodes($files, $media, $source, JPATH_ROOT . '/media/' . self::relative((string) $media['destination']));
        }

        if ((string) $manifest->scriptfile !== '') {
            self::add($files, $root . '/' . self::relative((string) $manifest->scriptfile), $source . '/' . self::relative((string) $manifest->scriptfile));
        }

        $manifestTarget = ($definition['type'] === 'package' ? dirname($root) : $root) . '/' . basename($manifestFile);
        self::add($files, $manifestTarget, $manifestFile);

        if ($id === 'plg_j2commerce_productcompare') {
            self::add($files, $root . '/productcompare.xml', $manifestFile);
        }

        if ($id === 'com_dmarcreports') {
            self::add($files, $root . '/com_dmarcreports.xml', self::operation($source, 'Remove obsolete DMARC Reports manifest'));
        }

        if ($definition['type'] === 'package') {
            self::add($files, $root . '/LICENSE.txt', $source . '/LICENSE.txt');
            self::tree($files, $source . '/advansinstaller', $root . '/advansinstaller');
        }

        // These are installed by the component's postflight, not its <files>.
        foreach (glob($source . '/plugins/*/*/*.xml') ?: [] as $childManifest) {
            $child = simplexml_load_file($childManifest);

            if (!$child instanceof \SimpleXMLElement || (string) $child['type'] !== 'plugin') {
                throw new \RuntimeException('Invalid bundled plugin manifest.');
            }

            $files = self::merge($files, self::files($child, dirname($childManifest), $childManifest));
        }

        // Product Compare mirrors the installed plugin for Joomla 5's shop group. A legacy symbolic
        // link there is replaced by real copies in the plugin's postflight, not inspected here.
        if ($id === 'plg_j2commerce_productcompare' && \Joomla\CMS\Version::MAJOR_VERSION < 6
            && !is_link(JPATH_PLUGINS . '/j2store/productcompare')) {
            foreach ($files as $target => $candidate) {
                if (str_starts_with($target, $root . '/')) {
                    $relative = substr($target, strlen($root) + 1);
                    self::add($files, JPATH_PLUGINS . '/j2store/productcompare/' . $relative, $candidate);

                    // The plugin edit screen loads plg_<folder>_<element>(.sys).ini,
                    // so the j2store group needs the language under that name too.
                    if (preg_match('#^language/[a-z]{2}-[A-Z]{2}/plg_j2commerce_productcompare(\.sys)?\.ini$#', $relative)) {
                        $alias = str_replace('/plg_j2commerce_productcompare', '/plg_j2store_productcompare', $relative);
                        self::add($files, JPATH_PLUGINS . '/j2store/productcompare/' . $alias, $candidate);
                    }
                }
            }
        }

        self::removedDeclarations($files, $manifest, $source, $manifestTarget, $root);

        return $files;
    }

    /**
     * The extension's own territory: its folders, its media folder, its manifest storage and its
     * language files by name, for the manifest and every plugin it bundles under plugins/.
     *
     * @return array{prefixes: list<string>, files: list<string>, languages: list<string>}
     */
    public static function owned(\SimpleXMLElement $manifest, string $source): array
    {
        $id = strtolower((string) $manifest->name);
        $definition = SystemCheck::definition($id);
        $element = $definition['element'];
        $root = SystemCheck::root($definition);
        $owned = ['prefixes' => [], 'files' => [], 'languages' => [$id]];

        switch ($definition['type']) {
            case 'component':
                $owned['prefixes'] = [
                    JPATH_ROOT . '/components/' . $element,
                    $root,
                    JPATH_ROOT . '/media/' . $element,
                    JPATH_ROOT . '/api/components/' . $element,
                ];
                $owned['languages'][] = $element;
                break;
            case 'plugin':
                $owned['prefixes'] = [$root, JPATH_ROOT . '/media/' . $id];
                break;
            case 'module':
                $owned['prefixes'] = [$root, JPATH_ROOT . '/media/' . $element];
                $owned['languages'][] = $element;
                break;
            case 'package':
                $owned['prefixes'] = [$root];
                $owned['files'] = [dirname($root) . '/' . $element . '.xml'];
                $owned['languages'][] = $element;
                break;
        }

        foreach ($manifest->media as $media) {
            $destination = self::relative((string) $media['destination']);
            $first = strtolower(explode('/', trim($destination, '/'))[0]);

            if ($first !== '' && in_array($first, [strtolower($element), $id], true)) {
                $owned['prefixes'][] = JPATH_ROOT . '/media/' . $destination;
            }
        }

        foreach (glob($source . '/plugins/*/*/*.xml') ?: [] as $childManifest) {
            $child = simplexml_load_file($childManifest);

            if (!$child instanceof \SimpleXMLElement || (string) $child['type'] !== 'plugin') {
                throw new \RuntimeException('Invalid bundled plugin manifest.');
            }

            $owned = self::mergeOwned($owned, self::owned($child, dirname($childManifest)));
        }

        return $owned;
    }

    /**
     * @param array{prefixes: list<string>, files: list<string>, languages: list<string>} $owned
     * @param array{prefixes: list<string>, files: list<string>, languages: list<string>} $extra
     * @return array{prefixes: list<string>, files: list<string>, languages: list<string>}
     */
    public static function mergeOwned(array $owned, array $extra): array
    {
        foreach (['prefixes', 'files', 'languages'] as $key) {
            $owned[$key] = array_values(array_unique(array_merge($owned[$key], $extra[$key])));
        }

        return $owned;
    }

    /** @param array{prefixes: list<string>, files: list<string>, languages: list<string>} $owned */
    public static function isOwned(string $destination, array $owned): bool
    {
        $path = self::comparable($destination);

        foreach ($owned['prefixes'] as $prefix) {
            if (str_starts_with($path, rtrim(self::comparable($prefix), '/') . '/')) {
                return true;
            }
        }

        foreach ($owned['files'] as $file) {
            if ($path === self::comparable($file)) {
                return true;
            }
        }

        // Language files count only in Joomla's global language folders, by the extension's name.
        foreach ([JPATH_ROOT . '/language/', JPATH_ADMINISTRATOR . '/language/'] as $base) {
            $base = rtrim(self::comparable($base), '/') . '/';

            if (!str_starts_with($path, $base) || substr_count(substr($path, strlen($base)), '/') !== 1) {
                continue;
            }

            [$tag, $name] = explode('/', substr($path, strlen($base)));

            foreach ($owned['languages'] as $language) {
                $language = strtolower($language);

                if (in_array(strtolower($name), [$language . '.ini', $language . '.sys.ini', strtolower($tag) . '.' . $language . '.ini', strtolower($tag) . '.' . $language . '.sys.ini'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function comparable(string $path): string
    {
        return str_replace('\\', '/', FileGuard::normalize(str_replace('\\', '/', $path)));
    }

    public static function operation(string $source, string $action): string
    {
        $directory = $source . '/.advans-operations';

        if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
            throw new \RuntimeException('Cannot stage installer operation.');
        }

        $path = $directory . '/' . hash('sha256', $action) . '.php';
        State::write($path, ['operation' => $action]);

        return $path;
    }

    public static function removedDeclarations(array &$files, \SimpleXMLElement $manifest, string $source, string $installedManifest, string $root): void
    {
        if (!is_file($installedManifest)) {
            return;
        }

        $previous = libxml_use_internal_errors(true);

        try {
            $old = simplexml_load_file($installedManifest);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$old instanceof \SimpleXMLElement) {
            throw new \RuntimeException('The existing manifest cannot be inspected safely.');
        }

        $pairs = (string) $manifest['type'] === 'component'
            ? [[$old->files, $manifest->files, JPATH_ROOT . '/components/' . strtolower((string) $manifest->name)],
                [$old->administration->files, $manifest->administration->files, $root]]
            : [[$old->files, $manifest->files, $root]];

        foreach ($pairs as [$oldNodes, $newNodes, $target]) {
            $retainedFiles = [];
            $retainedFolders = [];

            foreach ($newNodes->children() ?? [] as $node) {
                if ($node->getName() === 'folder') {
                    $retainedFolders[] = self::relative((string) $node);
                } else {
                    $retainedFiles[] = self::relative((string) $node);
                }
            }

            foreach ($oldNodes->children() ?? [] as $node) {
                $name = self::relative((string) $node);
                $retained = $node->getName() === 'folder'
                    ? in_array($name, $retainedFolders, true)
                        || count(array_filter($retainedFiles, static fn(string $file): bool => str_starts_with($file, $name . '/'))) > 0
                    : in_array($name, $retainedFiles, true) || in_array(dirname($name), $retainedFolders, true);
                if ($retained) {
                    continue;
                }

                $destination = $target . '/' . $name;
                $operation = self::operation($source, 'Remove obsolete declared path: ' . $name);

                if (is_dir($destination) && !is_link($destination)) {
                    $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($destination, \FilesystemIterator::SKIP_DOTS));

                    foreach ($entries as $entry) {
                        self::add($files, $entry->getPathname(), $operation);
                    }
                } elseif (file_exists($destination) || is_link($destination)) {
                    self::add($files, $destination, $operation);
                }
            }
        }
    }

    private static function nodes(array &$files, \SimpleXMLElement $nodes, string $source, string $target): void
    {
        $folder = self::relative((string) $nodes['folder']);
        $base = rtrim($source . '/' . $folder, '/');

        foreach ($nodes->children() ?? [] as $entry) {
            $name = self::relative((string) $entry);

            if ($entry->getName() === 'folder') {
                self::tree($files, $base . '/' . $name, $target . '/' . $name);
            } else {
                self::add($files, $target . '/' . $name, $base . '/' . $name);
            }
        }
    }

    private static function tree(array &$files, string $source, string $target): void
    {
        if (!is_dir($source) || is_link($source)) {
            throw new \RuntimeException('Invalid package directory.');
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $entry) {
            if ($entry->isLink() || !$entry->isFile()) {
                throw new \RuntimeException('Invalid package file.');
            }

            self::add($files, $target . '/' . substr($entry->getPathname(), strlen($source) + 1), $entry->getPathname());
        }
    }

    private static function relative(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        if (str_starts_with($path, '/') || str_contains($path, ':') || in_array('..', explode('/', $path), true)) {
            throw new \RuntimeException('Unsafe package path.');
        }

        return $path;
    }

    private static function add(array &$files, string $target, string $source): void
    {
        $target = str_replace('\\', '/', $target);

        if (isset($files[$target]) && hash_file('sha256', $files[$target]) !== hash_file('sha256', $source)) {
            throw new \RuntimeException('Package destinations overlap with different content.');
        }

        $files[$target] = $source;
    }

    public static function merge(array $files, array $extra): array
    {
        foreach ($extra as $target => $source) {
            self::add($files, $target, $source);
        }

        return $files;
    }
}
