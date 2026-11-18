<?php

declare(strict_types=1);

$rootArgument = $argv[1] ?? null;
$resolvedRoot = $rootArgument !== null ? realpath($rootArgument) : false;
$root = $resolvedRoot !== false ? $resolvedRoot : dirname(__DIR__, 2);
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

        $changelogFile = dirname($file) . DIRECTORY_SEPARATOR . 'changelog.xml';
        if (!is_file($changelogFile)) {
            fwrite(STDERR, "FAIL {$relative}: stable update {$version} has no sibling changelog.xml\n");
            $failed = true;
            continue;
        }

        $changelogDocument = simplexml_load_file($changelogFile);
        $changelogRelative = str_replace($root . DIRECTORY_SEPARATOR, '', $changelogFile);
        $changelogRelative = str_replace(DIRECTORY_SEPARATOR, '/', $changelogRelative);

        if ($changelogDocument === false
            || $changelogDocument->getName() !== 'changelogs'
            || count($changelogDocument->xpath('/changelogs/changelog')) === 0
        ) {
            fwrite(STDERR, "FAIL {$changelogRelative}: missing Joomla <changelogs>/<changelog> XML structure\n");
            $failed = true;
            continue;
        }

        $changelogVersion = trim((string) ($changelogDocument->changelog->version ?? ''));
        if ($changelogVersion !== $version) {
            fwrite(STDERR, "FAIL {$changelogRelative}: changelog version {$changelogVersion} does not match update version {$version}\n");
            $failed = true;
        }

        $releaseNotes = $changelogDocument->xpath('/changelogs/changelog/item | /changelogs/changelog/change/item | /changelogs/changelog/note/item');
        $releaseNotesText = is_array($releaseNotes)
            ? array_map(static fn($item): string => trim((string) $item), $releaseNotes)
            : [];
        $usableReleaseNotes = false;

        foreach ($releaseNotesText as $text) {
            if (preg_match(
                '#^Release notes: https://github\.com/Advans-IT-Solutions-GmbH/Joomla/releases/tag/[a-z0-9-]+-v' . preg_quote($version, '#') . '$#',
                $text
            ) === 1) {
                $usableReleaseNotes = true;
                break;
            }
        }

        if (!$usableReleaseNotes) {
            fwrite(STDERR, "FAIL {$changelogRelative}: changelog has no version-tagged Release notes item\n");
            $failed = true;
        }

        $scheme = parse_url($changelog, PHP_URL_SCHEME);
        $host = parse_url($changelog, PHP_URL_HOST);
        $path = parse_url($changelog, PHP_URL_PATH);

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

        $xmlChangelog = is_string($path)
            && preg_match('/\.xml$/i', $path) === 1
            && (
                strtolower((string) $host) === 'raw.githubusercontent.com'
                || (
                    strtolower((string) $host) === 'github.com'
                    && str_contains($path, '/releases/download/')
                )
            );

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
