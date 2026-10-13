<?php
// Mirror upstream build_package.php validateSqlAlignment() EXACTLY.
//
// Upstream picks the highest COMPLETE .sql filename via PHP version_compare()
// (so a dated suffix like 6.6.3-2026-09-23-1 is treated as newer than 6.6.3),
// then derives its MAJOR.MINOR.PATCH base and fails the build if that base
// exceeds the manifest <version>. We replicate it verbatim here — instead of a
// sort -V approximation — so the resolution never selects a commit that the
// upstream build step would then reject with "SQL alignment failure".
//
// Same check as in our other repositories, copied unchanged.
// Used by the composite action .github/actions/resolve-shops (step "Resolve latest shop releases"),
// which every Build & Test workflow with a J2Commerce 6 lane runs once per workflow run.
//
// Usage: php check-sql-alignment.php "<newline-separated .sql paths>" <manifestVersion>
// Prints ALIGNED or MISALIGNED.

[$script, $filesRaw, $manifestVersion] = [$argv[0], $argv[1] ?? '', $argv[2] ?? ''];

$highest = '0.0.0';
foreach (preg_split('/\R/', trim($filesRaw)) as $f) {
    if ($f === '') {
        continue;
    }
    $ver = pathinfo($f, PATHINFO_FILENAME);
    if (version_compare($ver, $highest, '>')) {
        $highest = $ver;
    }
}

$highestBase = explode('-', $highest, 2)[0];
$highestBase = implode('.', array_slice(explode('.', $highestBase), 0, 3));

echo version_compare($highestBase, $manifestVersion, '>') ? 'MISALIGNED' : 'ALIGNED';
