<?php
/**
 * Package structure lint.
 *
 * Multi-part extensions (more than one installable extension in one extension root) are always
 * shipped as a real Joomla package (pkg_*), single-part extensions as their own ZIP. This check
 * fails when
 *  - an extension root holds more than one installable extension (component, module, plugin,
 *    template, library) and no package manifest lists every one of them, or
 *  - a package manifest lists a child that has no manifest in the tree, or misses a child that its
 *    build script stages from the tree.
 *
 * Roots that still have to be converted are named in PENDING with the package that will replace them.
 * They are reported but do not fail; an entry whose package exists fails, so the list only shrinks.
 *
 * COMPANIONS names manifests that a package ships next to its children on purpose, to be installed
 * on their own: they are not children of the package and are not counted.
 *
 * Usage: php shared/tests/package-structure-check.php [repository root]
 *
 * @copyright (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license   GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

const PENDING = [];

const COMPANIONS = [
    // Appointments Recovery reviews a paused installation of the package, so it is installed on its
    // own and must stay installable while the package itself cannot be installed.
    'com_appointments/recovery/appointmentsrecovery.xml',
];

$root = rtrim(str_replace('\\', '/', $argv[1] ?? dirname(__DIR__, 2)), '/');
$skip = '#(^|/)(tests?|tests-[a-z0-9]+|vendor|node_modules|updates|\.git|\.github|\.claude|build|docker|shared)(/|$)#';

/** @return list<string> */
function files(string $root): array
{
    $result = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'xml') {
            $result[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }

    sort($result);

    return $result;
}

/** The extension root a path belongs to: the first folder, below j2commerce/ the second one. */
function extensionRoot(string $path): string
{
    $parts = explode('/', $path);

    return $parts[0] === 'j2commerce' && count($parts) > 2 ? $parts[0] . '/' . $parts[1] : $parts[0];
}

$manifests = [];   // root => list of [type, key, path]
$packages = [];    // package element => [path, root, children => list of "type:key"]
$errors = [];

foreach (files($root) as $path) {
    if (preg_match($skip, $path) === 1) {
        continue;
    }

    $xml = @simplexml_load_file($root . '/' . $path);

    if (!$xml instanceof SimpleXMLElement || $xml->getName() !== 'extension') {
        continue;
    }

    $type = (string) $xml['type'];
    $base = extensionRoot($path);

    if (in_array($path, COMPANIONS, true)) {
        continue;
    }

    if ($type === 'package') {
        $element = 'pkg_' . strtolower((string) $xml->packagename);
        $children = [];

        foreach ($xml->files->file ?? [] as $file) {
            $children[] = (string) $file['type'] . ':' . (string) $file['id'] . (isset($file['group']) ? '@' . (string) $file['group'] : '');
        }

        $packages[$element] = ['path' => $path, 'root' => $base, 'children' => $children];

        continue;
    }

    if (!in_array($type, ['component', 'module', 'plugin', 'template', 'library'], true)) {
        continue;
    }

    // The element as Joomla stores it: a plugin is named by its manifest file (or its plugin attribute),
    // a module by its constant name, a component by its element or name.
    if ($type === 'plugin') {
        $key = basename($path, '.xml');

        foreach ($xml->files->filename ?? [] as $filename) {
            if (isset($filename['plugin'])) {
                $key = (string) $filename['plugin'];
            }
        }

        $key .= '@' . (string) $xml['group'];
    } elseif ($type === 'module') {
        $key = strtolower((string) $xml->name);
    } else {
        $key = isset($xml->element) ? (string) $xml->element : strtolower((string) $xml->name);
    }
    $manifests[$base][] = ['type' => $type, 'key' => $key, 'path' => $path];
}

$listed = [];   // "type:key" of every child any package lists

foreach ($packages as $package) {
    foreach ($package['children'] as $child) {
        $listed[$child] = true;
    }
}

foreach ($manifests as $base => $items) {
    if (count($items) < 2) {
        continue;
    }

    $missing = [];

    foreach ($items as $item) {
        if (!isset($listed[$item['type'] . ':' . $item['key']])) {
            $missing[] = $item['path'];
        }
    }

    if ($missing === []) {
        continue;
    }

    if (isset(PENDING[$base])) {
        if (isset($packages[PENDING[$base]])) {
            $errors[] = "$base is listed as pending conversion, but " . PENDING[$base] . ' exists: remove it from PENDING in shared/tests/package-structure-check.php.';
        } else {
            echo "NOTE $base holds " . count($items) . ' installable extensions and still has to ship as ' . PENDING[$base] . ".\n";
        }

        continue;
    }

    $errors[] = "$base holds " . count($items) . ' installable extensions without a package listing all of them (not listed: ' . implode(', ', $missing) . '). Ship it as a pkg_* package.';
}

// A package lists only children that exist and every child its build script stages.
foreach ($packages as $element => $package) {
    $known = [];

    foreach ($manifests as $items) {
        foreach ($items as $item) {
            $known[$item['type'] . ':' . $item['key']] = true;
        }
    }

    foreach ($package['children'] as $child) {
        [$type, $rest] = explode(':', $child, 2);
        $id = explode('@', $rest)[0];
        $group = explode('@', $rest)[1] ?? '';
        $candidates = [$type . ':' . $rest, $type . ':' . $id . ($group !== '' ? '@' . $group : '')];

        if (array_intersect($candidates, array_keys($known)) === []) {
            $errors[] = "$element lists $child, which has no manifest in the tree.";
        }
    }

    $build = $root . '/' . dirname($package['path']) . '/build.sh';

    if (!is_file($build)) {
        continue;
    }

    preg_match_all('#"\.\./([A-Za-z0-9_./-]+):[A-Za-z0-9_.-]+\.zip"#', (string) file_get_contents($build), $matches);

    foreach ($matches[1] as $directory) {
        // The entries are relative to the package folder (j2commerce/pkg_x/../plg_y = j2commerce/plg_y).
        $parts = [];

        foreach (explode('/', dirname($package['path']) . '/../' . $directory) as $part) {
            if ($part === '..') {
                array_pop($parts);
            } elseif ($part !== '' && $part !== '.') {
                $parts[] = $part;
            }
        }

        $directory = implode('/', $parts);

        if (!is_dir($root . '/' . $directory)) {
            $errors[] = "$element stages $directory, which does not exist.";

            continue;
        }

        $directoryRoot = extensionRoot($directory);

        foreach ($manifests[$directoryRoot] ?? [] as $item) {
            if (!isset($listed[$item['type'] . ':' . $item['key']])) {
                $errors[] = "$element stages $directory but does not list {$item['type']} {$item['key']} ({$item['path']}).";
            }
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

echo 'Package structure OK: ' . count($packages) . ' package(s), ' . count($manifests) . " extension root(s) checked.\n";
