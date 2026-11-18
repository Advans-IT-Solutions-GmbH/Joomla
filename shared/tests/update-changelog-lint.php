<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$files = [];

foreach (new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
) as $file) {
    if ($file->isFile() && $file->getFilename() === 'update.xml'
        && basename(dirname($file->getPathname())) === 'updates'
    ) {
        $files[] = $file->getPathname();
    }
}

sort($files, SORT_STRING);
$failed = false;

foreach ($files as $file) {
    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
    $document = simplexml_load_file($file);

    if ($document === false) {
        fwrite(STDERR, "FAIL {$relative}: invalid XML\n");
        $failed = true;
        continue;
    }

    $entries = $document->xpath('/updates/update');

    if (!is_array($entries) || $entries === []) {
        fwrite(STDERR, "FAIL {$relative}: no update entries found\n");
        $failed = true;
        continue;
    }

    $changelogFiles = glob($root . '/*/updates/changelog.xml');
    $changelogFiles = array_merge(
        is_array($changelogFiles) ? $changelogFiles : [],
        glob($root . '/j2commerce/*/updates/changelog.xml') ?: []
    );
    sort($changelogFiles, SORT_STRING);

    foreach ($changelogFiles as $file) {
        $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $file);
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
        $document = simplexml_load_file($file);

        if ($document === false
            || $document->getName() !== 'changelogs'
            || count($document->xpath('/changelogs/changelog')) === 0
        ) {
            fwrite(STDERR, "FAIL {$relative}: missing Joomla <changelogs>/<changelog> XML structure\n");
            $failed = true;
            continue;
        }

        $version = trim((string) ($document->changelog->version ?? ''));
        if (!preg_match('/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$/', $version)) {
            fwrite(STDERR, "FAIL {$relative}: changelog XML has no valid version\n");
            $failed = true;
        }
    }

    foreach ($entries as $index => $entry) {
        $version = trim((string) ($entry->version ?? ''));
        $tags = $entry->xpath('./tags/tag');
        $stable = is_array($tags) && in_array('stable', array_map(
            static fn($tag): string => trim((string) $tag),
            $tags
        ), true);

        if (!$stable) {
            continue;
        }

        $changelog = trim((string) ($entry->changelogurl ?? ''));

        if ($changelog === '') {
            fwrite(STDERR, "FAIL {$relative}: stable update {$version} lacks changelogurl\n");
            $failed = true;
            continue;
        }

        $scheme = parse_url($changelog, PHP_URL_SCHEME);
        $host = parse_url($changelog, PHP_URL_HOST);

        if (!in_array(strtolower((string) $scheme), ['http', 'https'], true)
            || $host === null
            || $host === ''
        ) {
            fwrite(STDERR, "FAIL {$relative}: stable update {$version} has invalid changelogurl: {$changelog}\n");
            $failed = true;
            continue;
        }

        if (str_contains($changelog, '/releases/tag/')) {
            fwrite(STDERR, "FAIL {$relative}: stable update {$version} changelogurl points to an HTML release page: {$changelog}\n");
            $failed = true;
            continue;
        }

        $xmlChangelog = str_contains($changelog, 'raw.githubusercontent.com/')
            || str_contains($changelog, '/releases/download/');

        if (!$xmlChangelog) {
            fwrite(STDERR, "FAIL {$relative}: stable update {$version} changelogurl is not an explicit Joomla XML endpoint: {$changelog}\n");
            $failed = true;
        }
    }
}

if ($failed) {
    exit(1);
}

fwrite(STDOUT, 'OK: every stable update entry has a valid http(s) changelog URL (' . count($files) . " update.xml files)\n");
