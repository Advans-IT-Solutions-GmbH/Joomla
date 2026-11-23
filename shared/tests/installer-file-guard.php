<?php
declare(strict_types=1);

define('_JEXEC', 1);

require_once __DIR__ . '/../installer/FileGuard.php';
require_once __DIR__ . '/../installer/State.php';
require_once __DIR__ . '/../installer/Knowledge.php';
require_once __DIR__ . '/../installer/Planner.php';

use Advans\Installer\V3\FileGuard;
use Advans\Installer\V3\Knowledge;
use Advans\Installer\V3\Planner;
use Advans\Installer\V3\State;

$base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'installer-file-guard-' . bin2hex(random_bytes(8));
$site = $base . DIRECTORY_SEPARATOR . 'site';
$ownedDestination = $site . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'owned.php';
$ownedSource = $base . DIRECTORY_SEPARATOR . 'owned-incoming.php';
$unknownDestination = $site . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'example' . DIRECTORY_SEPARATOR . 'unknown.php';
$unknownSource = $base . DIRECTORY_SEPARATOR . 'unknown-incoming.php';
$foreignDestination = $site . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'other' . DIRECTORY_SEPARATOR . 'foreign.php';
$foreignSource = $base . DIRECTORY_SEPARATOR . 'foreign-incoming.php';
$backupRoot = $base . DIRECTORY_SEPARATOR . 'state' . DIRECTORY_SEPARATOR . 'backups';
$passed = 0;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
        removeTree($item->getPathname());
    }

    rmdir($path);
}

mkdir($site . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'example', 0700, true);
mkdir($site . DIRECTORY_SEPARATOR . 'plugins' . DIRECTORY_SEPARATOR . 'other', 0700, true);
State::directory($backupRoot);
define('JPATH_ROOT', $site);

try {
    $knownBytes = '<?php echo "earlier release";';
    $incomingBytes = '<?php echo "new release";';
    $unknownBytes = '<?php echo "local changes";';
    $foreignBytes = '<?php echo "foreign extension";';
    file_put_contents($ownedDestination, $knownBytes);
    file_put_contents($ownedSource, $incomingBytes);
    file_put_contents($unknownDestination, $unknownBytes);
    file_put_contents($unknownSource, $incomingBytes);
    file_put_contents($foreignDestination, $foreignBytes);
    file_put_contents($foreignSource, $incomingBytes);
    $legacyCopy = $backupRoot . DIRECTORY_SEPARATOR . 'legacy.php';
    file_put_contents($legacyCopy, 'pre-existing server copy');
    $legacyHash = hash_file('sha256', $legacyCopy);
    $catalog = $base . DIRECTORY_SEPARATOR . Knowledge::CATALOG;
    State::write($catalog, ['complete' => true, 'hashes' => [hash('sha256', $knownBytes)]]);
    $knowledge = Knowledge::load($catalog, $base . DIRECTORY_SEPARATOR . 'missing-record.php');

    $comparison = FileGuard::compare([
        $ownedDestination => $ownedSource,
        $unknownDestination => $unknownSource,
        $foreignDestination => $foreignSource,
    ]);
    expect(count($comparison['incoming']) === 3 && count($comparison['differing']) === 3,
        'Comparison must report every differing destination and incoming hash.');
    expect(file_get_contents($ownedDestination) === $knownBytes
        && file_get_contents($unknownDestination) === $unknownBytes
        && file_get_contents($foreignDestination) === $foreignBytes,
        'Comparison must never change existing destinations.');
    expect(hash_file('sha256', $legacyCopy) === $legacyHash
        && iterator_count(new FilesystemIterator($backupRoot, FilesystemIterator::SKIP_DOTS)) === 1,
        'Comparison must leave pre-existing server copies untouched and create no new copies.');
    $passed++;
    $differingFor = static function (string $destination) use ($comparison): array {
        $key = FileGuard::normalize($destination);

        return [$key => $comparison['differing'][$key]];
    };

    $ownedPlan = Planner::plan(
        $differingFor($ownedDestination),
        [],
        static fn(): bool => true,
        static fn(): string => Planner::HALT,
        $knowledge
    );
    expect(count($ownedPlan['changes']) === 1 && $ownedPlan['halts'] === []
        && !array_key_exists('backup', $ownedPlan['changes'][0])
        && hash_file('sha256', $legacyCopy) === $legacyHash,
        'A known unchanged owned file may be updated without server-copy metadata or changing legacy copies.');
    $passed++;

    $unknownOwnedPlan = Planner::plan(
        $differingFor($unknownDestination),
        [],
        static fn(): bool => true,
        static fn(): string => Planner::REPLACE,
        $knowledge
    );
    expect($unknownOwnedPlan['changes'] === [] && count($unknownOwnedPlan['halts']) === 1,
        'An unknown changed owned file must halt before Joomla copies files.');
    $passed++;

    $unknownForeignPlan = Planner::plan(
        $differingFor($foreignDestination),
        [],
        static fn(): bool => false,
        static fn(): string => Planner::REPLACE,
        $knowledge
    );
    expect($unknownForeignPlan['changes'] === [] && count($unknownForeignPlan['halts']) === 1,
        'An unknown foreign file must halt even when a caller requests replacement.');
    $skippedForeignPlan = Planner::plan(
        $differingFor($foreignDestination),
        [],
        static fn(): bool => false,
        static fn(): string => Planner::SKIP,
        $knowledge
    );
    expect($skippedForeignPlan['skipped'] === [FileGuard::normalize($foreignDestination)]
        && $skippedForeignPlan['changes'] === [],
        'An explicitly skipped foreign operation must not touch the destination.');
    $passed++;

    expect(!method_exists(FileGuard::class, 'backup')
        && !method_exists(FileGuard::class, 'prune')
        && !method_exists(FileGuard::class, 'restoreBytes'),
        'The new FileGuard must not expose backup, prune or restore-bytes APIs.');
    expect(hash_file('sha256', $legacyCopy) === $legacyHash,
        'No planning path may modify or clean up pre-existing server copies.');
    $passed++;
} finally {
    removeTree($base);
}

echo 'FileGuard tests passed: ' . $passed . PHP_EOL;
