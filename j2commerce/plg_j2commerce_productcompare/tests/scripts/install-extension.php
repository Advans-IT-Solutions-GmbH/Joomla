<?php
/**
 * Install a Joomla extension using the real Joomla Installer API.
 * Usage: php install-extension.php /path/to/extension.zip
 *
 * This ensures the XML manifest is fully parsed and validated,
 * catching issues like missing folders or missing plugin attributes.
 */

// Convert all errors to exceptions so nothing is silently ignored
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

define('_JEXEC', 1);
define('JPATH_BASE', '/var/www/html');

require_once JPATH_BASE . '/includes/defines.php';

$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? '/index.php';
$_SERVER['REQUEST_METHOD'] = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';

require_once JPATH_BASE . '/includes/framework.php';

use Joomla\CMS\Factory;
use Joomla\CMS\Installer\Installer;
use Joomla\CMS\Installer\InstallerHelper;

$zipPath = $argv[1] ?? null;

if (!$zipPath || !file_exists($zipPath)) {
    fwrite(STDERR, "Usage: php install-extension.php /path/to/extension.zip\n");
    exit(2);
}

echo "Installing extension from: $zipPath\n";

try {
    // Boot the application
    $app = Factory::getApplication('administrator');

    // Extract the package
    $package = InstallerHelper::unpack($zipPath);

    if (!$package || !is_array($package)) {
        fwrite(STDERR, "ERROR: Failed to unpack $zipPath\n");
        exit(1);
    }

    echo "Package extracted to: {$package['dir']}\n";
    echo "Package type: {$package['type']}\n";

    // Install using the real Joomla Installer
    $installer = Installer::getInstance();
    $result = $installer->install($package['dir']);

    // Clean up extracted files
    InstallerHelper::cleanupInstall($zipPath, $package['extractdir'] ?? $package['dir']);

    if ($result) {
        echo "SUCCESS: Extension installed via Joomla Installer\n";

        // Verify the extension is actually in the database
        $db    = Factory::getDbo();
        $query = (method_exists($db, 'createQuery') ? $db->createQuery() : $db->getQuery(true))
            ->select('extension_id, element, type, folder, enabled')
            ->from('#__extensions')
            ->where('extension_id = ' . (int) $installer->getExtensionId());
        $ext = $db->setQuery($query)->loadObject();

        if ($ext) {
            echo "Verified in DB: id={$ext->extension_id}, element={$ext->element}, type={$ext->type}";
            if ($ext->folder) {
                echo ", folder={$ext->folder}";
            }
            echo ", enabled={$ext->enabled}\n";

            // Verify element is not empty
            if (empty($ext->element)) {
                fwrite(STDERR, "ERROR: Extension registered but element is empty!\n");
                exit(1);
            }
        } else {
            fwrite(STDERR, "ERROR: Extension not found in database after install!\n");
            exit(1);
        }

        exit(0);
    } else {
        fwrite(STDERR, "ERROR: Joomla Installer returned failure\n");
        $messages = $app->getMessageQueue();
        foreach ($messages as $msg) {
            fwrite(STDERR, "[{$msg['type']}] {$msg['message']}\n");
        }
        exit(1);
    }
} catch (\Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, "File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
