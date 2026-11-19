<?php
declare(strict_types=1);

require_once __DIR__ . '/../installer/FileGuard.php';

use Advans\Installer\V2\FileGuard;

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

    $export = static function (string $hash, string $output) use ($conflicts, $destination): int {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../installer/export-backup.php', '--export',
                $conflicts[0]['backup'], $destination, $hash, $output],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start backup export.');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process);
    };
    $exported = $base . DIRECTORY_SEPARATOR . 'exported.php';
    expect($export($conflicts[0]['existing'], $exported) === 0 && file_get_contents($exported) === $existingBytes,
        'The verified backup must export to a new file.');
    expect($export($conflicts[0]['existing'], $exported) !== 0 && file_get_contents($exported) === $existingBytes,
        'Export must not overwrite an existing output.');
    $wrongHashOutput = $base . DIRECTORY_SEPARATOR . 'wrong-hash.php';
    expect($export(str_repeat('0', 64), $wrongHashOutput) !== 0 && !file_exists($wrongHashOutput),
        'Export must reject a backup that does not match the reviewed hash.');
    expect($export($conflicts[0]['existing'], $destination) !== 0 && file_get_contents($destination) === $existingBytes,
        'Export must never overwrite the live destination.');
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
        expect(iterator_count(new FilesystemIterator($backupRoot, FilesystemIterator::SKIP_DOTS)) === 1,
            'Symlink rejection must occur before creating any backup.');
        $passed++;
    }

    // compare() reports differing destinations and every incoming hash without writing anything.
    $compareRoot = $base . DIRECTORY_SEPARATOR . 'compare';
    mkdir($compareRoot);
    $changed = $compareRoot . DIRECTORY_SEPARATOR . 'changed.php';
    writeFile($changed, $existingBytes);
    $comparison = FileGuard::compare([$changed => $source, $identicalDestination => $identicalSource, $absentDestination => $source]);
    expect(count($comparison['incoming']) === 3, 'compare() must hash every incoming file.');
    expect(array_keys($comparison['differing']) === [FileGuard::normalize($changed)], 'compare() must list only differing existing destinations.');
    expect(iterator_count(new FilesystemIterator($compareRoot, FilesystemIterator::SKIP_DOTS)) === 1, 'compare() must not write backups.');
    $passed++;

    // backup() writes a verified, deduplicated backup whose decoded bytes are the original bytes.
    $compareBackups = $compareRoot . DIRECTORY_SEPARATOR . 'backups';
    mkdir($compareBackups);
    $existingHash = hash('sha256', $existingBytes);
    $backup = FileGuard::backup($changed, $existingHash, $compareBackups);
    expect(FileGuard::restoreBytes($backup, $changed, $existingHash) === $existingBytes, 'A backup must decode to the exact original bytes.');
    expect(FileGuard::backup($changed, $existingHash, $compareBackups) === $backup, 'The same bytes must reuse the same backup.');
    $passed++;

    // A backup that cannot be written is a BackupFailure naming the destination.
    $blocked = $compareRoot . DIRECTORY_SEPARATOR . 'blocked.php';
    writeFile($blocked, 'blocked original');
    $blockedHash = hash('sha256', 'blocked original');
    mkdir($compareBackups . DIRECTORY_SEPARATOR . hash('sha256', FileGuard::normalize($blocked) . "\0" . $blockedHash) . '.php');

    try {
        FileGuard::backup($blocked, $blockedHash, $compareBackups);
        throw new RuntimeException('An unwritable backup must fail.');
    } catch (\Advans\Installer\V2\BackupFailure $failure) {
        expect($failure->destination === FileGuard::normalize($blocked), 'The backup failure must name the destination.');
    }

    expect(file_get_contents($blocked) === 'blocked original', 'A failed backup must leave the destination unchanged.');
    $passed++;

    // prune() removes only backups past the retention period that this installation did not use.
    $old = $compareBackups . DIRECTORY_SEPARATOR . str_repeat('a', 64) . '.php';
    $oldKept = $compareBackups . DIRECTORY_SEPARATOR . str_repeat('b', 64) . '.php';
    writeFile($old, 'old');
    writeFile($oldKept, 'old but used');
    touch($old, time() - 200 * 86400);
    touch($oldKept, time() - 200 * 86400);
    expect(FileGuard::prune($compareBackups, 180 * 86400, [$oldKept]) === 1, 'Exactly one expired backup must be removed.');
    expect(!is_file($old) && is_file($oldKept) && is_file($backup), 'Retention must keep recent and in-use backups.');
    $passed++;
} finally {
    removeTree($base);
}

echo 'FileGuard tests passed: ' . $passed . '; skipped: ' . $skipped . PHP_EOL;
