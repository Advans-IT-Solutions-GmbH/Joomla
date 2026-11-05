<?php
declare(strict_types=1);

require_once __DIR__ . '/../installer/FileGuard.php';

use Advans\Installer\FileGuard;

$base = __DIR__ . DIRECTORY_SEPARATOR . '.file-guard-test-' . bin2hex(random_bytes(8));
$backupRoot = $base . DIRECTORY_SEPARATOR . 'backups';
$passed = 0;
$skipped = 0;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function writeFile(string $path, string $bytes): void
{
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        throw new RuntimeException('Test fixture could not be written.');
    }
}

function expectRuntimeException(callable $operation, string $message): void
{
    try {
        $operation();
    } catch (RuntimeException) {
        return;
    }

    throw new RuntimeException($message);
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

if (!mkdir($base)) {
    throw new RuntimeException('Test directory could not be created.');
}

try {
    if (!mkdir($backupRoot)) {
        throw new RuntimeException('Test backup directory could not be created.');
    }

    $existingBytes = "<?php echo 'old'; ?>\n";
    $incomingBytes = "<?php echo 'new'; ?>\n";
    $destination = $base . DIRECTORY_SEPARATOR . 'changed.php';
    $source = $base . DIRECTORY_SEPARATOR . 'candidate.php';
    writeFile($destination, $existingBytes);
    writeFile($source, $incomingBytes);

    $conflicts = FileGuard::inspect([$destination => $source], $backupRoot);
    expect(count($conflicts) === 1, 'A changed destination must be reported.');
    expect($conflicts[0]['approved'] === false, 'A changed destination must require explicit approval.');
    expect(file_get_contents($destination) === $existingBytes, 'Inspection must not replace the destination.');
    expect(is_file($conflicts[0]['backup']), 'A changed destination must have a backup.');
    expect(str_starts_with((string) file_get_contents($conflicts[0]['backup']), "<?php http_response_code(404); exit; ?>\n"),
        'The backup must have a PHP access guard.');
    $backupData = json_decode(substr((string) file_get_contents($conflicts[0]['backup']), strlen("<?php http_response_code(404); exit; ?>\n")), true);
    expect(is_array($backupData) && base64_decode($backupData['content'], true) === $existingBytes,
        'The backup must preserve the exact original bytes in encoded form.');
    FileGuard::verifyBackup($conflicts[0]['backup'], $destination, $conflicts[0]['existing']);
    $passed++;

    expectRuntimeException(
        static fn() => FileGuard::verifyBackup(
            $conflicts[0]['backup'],
            $base . DIRECTORY_SEPARATOR . 'different-destination.php',
            $conflicts[0]['existing']
        ),
        'A backup bound to another destination must be rejected.'
    );
    $passed++;

    $approved = [
        $conflicts[0]['destination'] => [
            'existing' => $conflicts[0]['existing'],
            'incoming' => $conflicts[0]['incoming'],
        ],
    ];
    $approvedConflicts = FileGuard::inspect([$destination => $source], $backupRoot, $approved);
    expect(count($approvedConflicts) === 1 && $approvedConflicts[0]['approved'] === true,
        'An exact approval must be recognized.');
    expect($approvedConflicts[0]['backup'] === $conflicts[0]['backup'],
        'A valid existing deterministic backup must be reused.');
    $passed++;

    $staleExisting = $approved;
    $staleExisting[$conflicts[0]['destination']]['existing'] = str_repeat('0', 64);
    $staleIncoming = $approved;
    $staleIncoming[$conflicts[0]['destination']]['incoming'] = str_repeat('0', 64);
    expect(FileGuard::inspect([$destination => $source], $backupRoot, $staleExisting)[0]['approved'] === false,
        'An approval for different existing bytes must be stale.');
    expect(FileGuard::inspect([$destination => $source], $backupRoot, $staleIncoming)[0]['approved'] === false,
        'An approval for different incoming bytes must be stale.');
    $passed++;

    writeFile($conflicts[0]['backup'], 'tampered backup');
    expectRuntimeException(
        static fn() => FileGuard::verifyBackup($conflicts[0]['backup'], $destination, $conflicts[0]['existing']),
        'An existing deterministic backup with invalid contents must be rejected.'
    );
    $passed++;

    $identicalDestination = $base . DIRECTORY_SEPARATOR . 'identical.php';
    $identicalSource = $base . DIRECTORY_SEPARATOR . 'identical-candidate.php';
    writeFile($identicalDestination, 'identical');
    writeFile($identicalSource, 'identical');
    expect(FileGuard::inspect([$identicalDestination => $identicalSource], $backupRoot) === [],
        'Identical files must pass without a conflict.');
    $passed++;

    $absentDestination = $base . DIRECTORY_SEPARATOR . 'absent.php';
    expect(FileGuard::inspect([$absentDestination => $source], $backupRoot) === [],
        'An absent destination must pass without a conflict.');
    $passed++;

    expectRuntimeException(
        static fn() => FileGuard::inspect([$base . DIRECTORY_SEPARATOR . 'missing-dest.php' => $base . DIRECTORY_SEPARATOR . 'missing-source.php'], $backupRoot),
        'A missing candidate source must fail closed.'
    );
    $passed++;

    $directoryDestination = $base . DIRECTORY_SEPARATOR . 'directory-dest';
    mkdir($directoryDestination);
    expectRuntimeException(
        static fn() => FileGuard::inspect([$directoryDestination => $source], $backupRoot),
        'A directory destination must fail closed.'
    );
    $passed++;

    $unwritableRoot = $base . DIRECTORY_SEPARATOR . 'not-a-directory';
    writeFile($unwritableRoot, 'file');
    expectRuntimeException(
        static fn() => FileGuard::inspect([$destination => $source], $unwritableRoot),
        'A backup write failure must fail closed.'
    );
    $passed++;

    $outside = $base . DIRECTORY_SEPARATOR . 'outside';
    $escape = $base . DIRECTORY_SEPARATOR . 'escape';
    mkdir($outside);

    $symlinkWarning = null;
    set_error_handler(
        static function (int $severity, string $message) use (&$symlinkWarning): bool {
            if ($severity === E_WARNING && str_starts_with($message, 'symlink():')) {
                $symlinkWarning = $message;

                return true;
            }

            return false;
        }
    );
    $symlinkCreated = symlink($outside, $escape);
    restore_error_handler();

    if (!$symlinkCreated) {
        if (DIRECTORY_SEPARATOR !== '\\' || !is_string($symlinkWarning) || !str_contains(strtolower($symlinkWarning), 'permission denied')) {
            throw new RuntimeException('The symlink escape test could not create its link.');
        }

        $skipped++;
    } else {
        expectRuntimeException(
            static fn() => FileGuard::inspect([$escape . DIRECTORY_SEPARATOR . 'target.php' => $source], $backupRoot),
            'A destination with a symlink ancestor must be rejected.'
        );
        expect(count(new FilesystemIterator($backupRoot, FilesystemIterator::SKIP_DOTS)) === 1,
            'Symlink rejection must occur before creating any backup.');
        $passed++;
    }
} finally {
    removeTree($base);
}

echo 'FileGuard tests passed: ' . $passed . '; skipped: ' . $skipped . PHP_EOL;
