<?php
/**
 * Write the catalog of released file hashes that the shared installer ships in advansinstaller/.
 *
 * The installer treats an existing file as untouched when its bytes equal a file of any earlier
 * release of the same extension. The catalog therefore holds the SHA-256 of every file, as built,
 * of every earlier release: each release is rebuilt from its own source with its own build
 * scripts, exactly as its publish workflow built it, and the files of the resulting ZIP (and of
 * the child ZIPs of a package) are hashed. Hashing the built files, not the sources, covers the
 * manifests and language files that the build stamps.
 *
 * Releases are found as merged release commits ("release: <prefix> vX.Y.Z") in the history of
 * HEAD and as tags "<prefix>-vX.Y.Z". The publish workflow deletes older tags, so the release
 * commits are the complete source. HEAD itself is excluded when it is the release commit of the
 * version being built, so the release preparation and the publication build identical catalogs.
 *
 * Usage: php known-hashes.php EXTENSION_DIRECTORY OUTPUT_FILE
 *
 * Environment:
 *   ADVANS_KNOWN_HASHES=skip      write an empty catalog (rebuilds of history, quick local builds)
 *   ADVANS_KNOWN_HASHES_STRICT=1  fail when the history is unavailable or a release cannot be rebuilt
 */

if (PHP_SAPI !== 'cli' || $argc !== 3 || !is_dir($argv[1])) {
    fwrite(STDERR, "Usage: php known-hashes.php EXTENSION_DIRECTORY OUTPUT_FILE\n");
    exit(1);
}

$extension = realpath($argv[1]);
$output = $argv[2];
$strict = getenv('ADVANS_KNOWN_HASHES_STRICT') === '1';

$run = static function (array $command, ?string $cwd = null, ?array $env = null): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, $env);

    if (!is_resource($process)) {
        return [1, '', 'cannot start ' . $command[0]];
    }

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), (string) $stdout, (string) $stderr];
};

$readEnv = static function (string $content): array {
    $values = [];

    foreach (preg_split('/\R/', $content) as $line) {
        if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)=["\']?([^"\'#]*?)["\']?\s*(?:#.*)?$/', $line, $match) === 1) {
            $values[$match[1]] = $match[2];
        }
    }

    return $values;
};

$write = static function (array $catalog) use ($output): void {
    $directory = dirname($output);

    if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
        throw new RuntimeException('Cannot create the catalog directory.');
    }

    // Same envelope as the installer state: not readable over HTTP once installed.
    $bytes = "<?php exit; ?>\n" . json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";

    if (file_put_contents($output, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Cannot write the catalog.');
    }
};

$hashArchive = static function (string $zipFile, array &$hashes) use (&$hashArchive): void {
    $zip = new ZipArchive();

    if ($zip->open($zipFile) !== true) {
        throw new RuntimeException('Cannot open a rebuilt package.');
    }

    try {
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if (str_ends_with($name, '/')) {
                continue;
            }

            $bytes = $zip->getFromIndex($index);

            if (!is_string($bytes)) {
                throw new RuntimeException('Cannot read a rebuilt package entry.');
            }

            if (str_ends_with(strtolower($name), '.zip')) {
                $nested = tempnam(sys_get_temp_dir(), 'advans-known-');
                file_put_contents($nested, $bytes);

                try {
                    $hashArchive($nested, $hashes);
                } finally {
                    unlink($nested);
                }

                continue;
            }

            $hashes[hash('sha256', $bytes)] = true;
        }
    } finally {
        $zip->close();
    }
};

$removeTree = static function (string $path) use (&$removeTree): void {
    if (is_link($path) || is_file($path)) {
        @chmod($path, 0644);
        unlink($path);

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) {
        $removeTree($entry->getPathname());
    }

    rmdir($path);
};

$config = $readEnv((string) file_get_contents($extension . '/build.env'));
$name = $config['EXTENSION_NAME'] ?? '';
$version = $config['VERSION'] ?? '';
$prefix = $config['RELEASE_PREFIX'] ?? '';

if ($name === '' || $version === '' || preg_match('/^[a-z][a-z0-9-]*$/', $prefix) !== 1) {
    fwrite(STDERR, "build.env must set EXTENSION_NAME, VERSION and RELEASE_PREFIX (the tag prefix without -v).\n");
    exit(1);
}

$catalog = ['extension' => $name, 'complete' => false, 'versions' => [], 'unreproducible' => [], 'failed' => [], 'hashes' => []];

if (getenv('ADVANS_KNOWN_HASHES') === 'skip') {
    $catalog['unreproducible'][] = 'catalog generation skipped (ADVANS_KNOWN_HASHES=skip)';
    $write($catalog);
    echo "Known-hashes catalog skipped for $name\n";
    exit(0);
}

[$code, $root] = $run(['git', '-C', $extension, 'rev-parse', '--show-toplevel']);
$root = trim($root);
[, $shallow] = $code === 0 ? $run(['git', '-C', $root, 'rev-parse', '--is-shallow-repository']) : [1, 'true'];

if ($code !== 0 || trim($shallow) !== 'false') {
    $message = 'the release history is not available (no git checkout or a shallow clone; fetch the full history)';

    if ($strict) {
        fwrite(STDERR, "Known-hashes catalog for $name: $message\n");
        exit(1);
    }

    $catalog['unreproducible'][] = $message;
    $write($catalog);
    echo "Known-hashes catalog for $name is incomplete: $message\n";
    exit(0);
}

[, $head] = $run(['git', '-C', $root, 'rev-parse', 'HEAD']);
$head = trim($head);
$pattern = '/^release: ' . preg_quote($prefix, '/') . ' v(\d+\.\d+\.\d+)(?:\s|$)/';
$releases = [];
[, $log] = $run(['git', '-C', $root, 'log', '--format=%H%x09%s', 'HEAD']);

foreach (preg_split('/\R/', trim($log)) as $line) {
    [$sha, $subject] = array_pad(explode("\t", $line, 2), 2, '');

    if (preg_match($pattern, $subject, $match) === 1) {
        $releases[$sha] = $match[1];
    }
}

[, $tags] = $run(['git', '-C', $root, 'tag', '-l', $prefix . '-v*']);

foreach (preg_split('/\R/', trim($tags)) as $tag) {
    if (preg_match('/^' . preg_quote($prefix, '/') . '-v(\d+\.\d+\.\d+)$/', $tag, $match) !== 1) {
        continue;
    }

    [$tagCode, $sha] = $run(['git', '-C', $root, 'rev-list', '-n', '1', $tag]);

    if ($tagCode === 0) {
        $releases[trim($sha)] ??= $match[1];
    }
}

// The release commit of the version being built is the build itself, not an earlier release.
if (isset($releases[$head]) && $releases[$head] === $version) {
    unset($releases[$head]);
}

uasort($releases, 'version_compare');
$hashes = [];
$work = sys_get_temp_dir() . '/advans-known-hashes-' . bin2hex(random_bytes(8));
mkdir($work, 0700, true);
$environment = getenv();
$environment['ADVANS_KNOWN_HASHES'] = 'skip';

try {
    foreach ($releases as $sha => $releaseVersion) {
        [, $tree] = $run(['git', '-C', $root, 'ls-tree', '-r', '--name-only', $sha]);
        $directory = null;

        foreach (preg_split('/\R/', trim($tree)) as $path) {
            if ($path !== 'build.env' && !str_ends_with($path, '/build.env')) {
                continue;
            }

            [, $content] = $run(['git', '-C', $root, 'show', $sha . ':' . $path]);

            if (($readEnv($content)['EXTENSION_NAME'] ?? '') === $name) {
                $directory = dirname($path);
                break;
            }
        }

        if ($directory === null) {
            $catalog['unreproducible'][] = "$releaseVersion: no build configuration in that release";
            continue;
        }

        $source = $work . '/' . substr($sha, 0, 12);
        mkdir($source, 0700, true);
        [$archiveCode, , $archiveError] = $run(['git', '-C', $root, 'archive', '--format=tar', '-o', $source . '.tar', $sha]);
        [$tarCode, , $tarError] = $archiveCode === 0 ? $run(['tar', '-xf', $source . '.tar', '-C', $source]) : [1, '', $archiveError];

        if ($tarCode !== 0) {
            $catalog['failed'][] = "$releaseVersion: cannot export the release source";
            fwrite(STDERR, "Cannot export $name $releaseVersion: $tarError\n");
            continue;
        }

        $buildDirectory = $directory === '.' ? $source : $source . '/' . $directory;
        [$buildCode, $buildOut, $buildErr] = $run(['bash', 'build.sh'], $buildDirectory, $environment);
        $packages = glob($buildDirectory . '/*.zip') ?: [];

        if ($buildCode !== 0 || count($packages) !== 1) {
            $catalog['failed'][] = "$releaseVersion: the release build failed";
            fwrite(STDERR, "Rebuilding $name $releaseVersion failed:\n" . substr($buildOut . $buildErr, -4000) . "\n");
            $removeTree($source);
            continue;
        }

        $hashArchive($packages[0], $hashes);
        $catalog['versions'][] = $releaseVersion;
        echo "Hashed the built files of $name $releaseVersion\n";
        $removeTree($source);
        @unlink($source . '.tar');
    }
} finally {
    $removeTree($work);
}

$catalog['hashes'] = array_keys($hashes);
sort($catalog['hashes'], SORT_STRING);
$catalog['complete'] = $catalog['failed'] === [];
$write($catalog);
echo 'Known-hashes catalog for ' . $name . ': ' . count($catalog['versions']) . ' releases, '
    . count($catalog['hashes']) . " distinct files\n";

foreach (array_merge($catalog['unreproducible'], $catalog['failed']) as $note) {
    echo "  note: $note\n";
}

if ($strict && !$catalog['complete']) {
    fwrite(STDERR, "Known-hashes catalog for $name is incomplete.\n");
    exit(1);
}
