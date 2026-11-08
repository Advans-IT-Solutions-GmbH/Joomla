<?php
/** Download current, checksum-verified official Joomla 6 test translations. */
declare(strict_types=1);

$directory = realpath($argv[1] ?? '');
if (PHP_SAPI !== 'cli' || !$directory || !is_writable($directory)) {
    throw new RuntimeException('A writable test-artifact directory is required.');
}
$context = stream_context_create(['http' => ['timeout' => 60]]);
foreach (['de-DE', 'fr-FR'] as $locale) {
    $metadata = file_get_contents("https://update.joomla.org/language/details6/{$locale}_details.xml", false, $context);
    if ($metadata === false || !($xml = simplexml_load_string($metadata))) {
        throw new RuntimeException("Cannot read official {$locale} update metadata.");
    }
    $updates = [];
    foreach ($xml->update as $update) {
        if ((string) $update->element === 'pkg_' . $locale
            && (string) $update->type === 'package'
            && str_starts_with((string) $update->version, '6.')) {
            $updates[] = $update;
        }
    }
    usort($updates, static fn ($a, $b) => version_compare((string) $b->version, (string) $a->version));
    if (!$updates) {
        throw new RuntimeException("No Joomla 6 {$locale} package was advertised.");
    }
    $update = $updates[0];
    $url = (string) $update->downloads->downloadurl;
    $checksum = strtolower((string) $update->sha256);
    if (!str_starts_with($url, 'https://downloads.joomla.org/') || !preg_match('/^[a-f0-9]{64}$/', $checksum)) {
        throw new RuntimeException("Invalid official {$locale} download metadata.");
    }
    $package = file_get_contents($url, false, $context);
    if ($package === false || !hash_equals($checksum, hash('sha256', $package))) {
        throw new RuntimeException("Official {$locale} package checksum mismatch.");
    }
    if (file_put_contents($directory . DIRECTORY_SEPARATOR . $locale . '.zip', $package) !== strlen($package)) {
        throw new RuntimeException("Cannot save {$locale} package.");
    }
    echo "{$locale}: {$update->version}, SHA256 {$checksum}\n";
}
