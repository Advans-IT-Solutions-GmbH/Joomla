#!/usr/bin/env php
<?php
/**
 * Install an extension package through Joomla's web installer
 * (Administrator → System → Install → Extensions), exactly as a user does.
 *
 * Environment:
 *   PACKAGE_PATH           package to upload (default /tmp/extension.zip)
 *   EXTENSION_NAME         label for the output
 *   JOOMLA_ADMIN_USERNAME  administrator login (default admin)
 *   JOOMLA_ADMIN_PASSWORD  administrator password
 *   STRICT_MESSAGES=1      fail when the installer queues a warning or an
 *                          error message, or when a message contains an
 *                          untranslated language key
 */

$baseUrl = 'http://localhost';
$adminUser = getenv('JOOMLA_ADMIN_USERNAME') ?: 'admin';
$adminPass = getenv('JOOMLA_ADMIN_PASSWORD') ?: 'Admin123!@#';
$packagePath = getenv('PACKAGE_PATH') ?: '/tmp/extension.zip';
$extensionName = getenv('EXTENSION_NAME') ?: 'Extension';
$strictMessages = getenv('STRICT_MESSAGES') === '1';

echo "=== Installing $extensionName via HTTP ===\n\n";

@unlink('/tmp/cookies.txt');

// Step 1: Login to admin
echo "Step 1: Logging in to Joomla admin...\n";
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "$baseUrl/administrator/index.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_COOKIEJAR, '/tmp/cookies.txt');
curl_setopt($ch, CURLOPT_COOKIEFILE, '/tmp/cookies.txt');
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

// Retry logic for token extraction
$token = null;
$maxRetries = 5;
for ($i = 0; $i < $maxRetries; $i++) {
    $loginPage = curl_exec($ch);

    if ($loginPage === false) {
        echo "  Retry " . ($i + 1) . "/$maxRetries: curl error\n";
        sleep(3);
        continue;
    }

    // Extract form token
    preg_match('/name="([a-f0-9]{32})" value="1"/', $loginPage, $matches);
    $token = $matches[1] ?? null;

    if ($token) {
        break;
    }

    echo "  Retry " . ($i + 1) . "/$maxRetries: no token found\n";
    sleep(3);
}

if (!$token) {
    echo "❌ Could not find login token after $maxRetries attempts\n";
    exit(1);
}

// Submit login
curl_setopt($ch, CURLOPT_URL, "$baseUrl/administrator/index.php");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
    'username' => $adminUser,
    'passwd' => $adminPass,
    'option' => 'com_login',
    'task' => 'login',
    'return' => base64_encode('index.php'),
    $token => '1'
]));
$loginResult = curl_exec($ch);

if (is_string($loginResult) && (strpos($loginResult, 'task=logout') !== false || strpos($loginResult, 'com_cpanel') !== false)) {
    echo "✅ Login successful\n\n";
} else {
    echo "❌ Login failed\n";
    exit(1);
}

// Step 2: Get installer page
echo "Step 2: Accessing installer...\n";
curl_setopt($ch, CURLOPT_URL, "$baseUrl/administrator/index.php?option=com_installer&view=install");
curl_setopt($ch, CURLOPT_POST, false);
$installerPage = curl_exec($ch);

// Extract new token
preg_match('/name="([a-f0-9]{32})" value="1"/', (string) $installerPage, $matches);
$token = $matches[1] ?? null;

if (!$token) {
    echo "❌ Could not find installer token\n";
    exit(1);
}

// Step 3: Upload and install package
echo "\nStep 3: Uploading package...\n";

if (!file_exists($packagePath)) {
    echo "❌ Package not found: $packagePath\n";
    exit(1);
}

$cfile = new CURLFile($packagePath, 'application/zip', 'extension.zip');

curl_setopt($ch, CURLOPT_URL, "$baseUrl/administrator/index.php?option=com_installer&view=install");
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, [
    'install_package' => $cfile,
    'type' => 'upload',
    'installtype' => 'upload',
    $token => '1',
    'task' => 'install.install',
    'option' => 'com_installer'
]);

$installResult = (string) curl_exec($ch);
curl_close($ch);

/**
 * Collect the system messages of the rendered page, both from server-side
 * rendered <joomla-alert> elements / .alert blocks and from the
 * "joomla.messages" script option used by client-side rendering.
 *
 * @return array<int, array{type:string, text:string}>
 */
function collect_messages(string $html): array
{
    $messages = [];

    // The backend always renders a <noscript> alert (JavaScript required); it is
    // not an installer message.
    $html = preg_replace('/<noscript\b.*?<\/noscript>/is', '', $html);

    if (preg_match_all('/<joomla-alert[^>]*type="([a-z]+)"[^>]*>(.*?)<\/joomla-alert>/is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $messages[] = ['type' => strtolower($match[1]), 'text' => $match[2]];
        }
    }

    if (!$messages && preg_match_all('/<div class="alert alert-([a-z]+)[^"]*"[^>]*>(.*?)<\/div>/is', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $match) {
            $messages[] = ['type' => strtolower($match[1]), 'text' => $match[2]];
        }
    }

    if (preg_match_all('/<script[^>]*class="joomla-script-options[^"]*"[^>]*>(.*?)<\/script>/is', $html, $m)) {
        foreach ($m[1] as $json) {
            $options = json_decode(html_entity_decode($json), true);

            foreach (($options['joomla.messages'] ?? []) as $group) {
                foreach ((array) $group as $type => $texts) {
                    foreach ((array) $texts as $text) {
                        $messages[] = ['type' => strtolower((string) $type), 'text' => (string) $text];
                    }
                }
            }
        }
    }

    foreach ($messages as &$message) {
        $message['text'] = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($message['text']))));
    }

    return $messages;
}

$messages = collect_messages($installResult);

if ($messages) {
    echo "Installation messages:\n";
    foreach ($messages as $message) {
        echo "  [{$message['type']}] " . mb_substr($message['text'], 0, 400) . "\n";
    }
    echo "\n";
}

// Check result
$succeeded = strpos($installResult, 'was successful') !== false
    || strpos($installResult, 'successfully installed') !== false;

if (!$succeeded) {
    echo "❌ Installation may have failed\n";
    file_put_contents('/tmp/install-result.html', $installResult);
    echo "Full output saved to /tmp/install-result.html\n";
    exit(1);
}

echo "✅ Installation successful!\n";

if ($strictMessages) {
    $problems = [];

    if (!$messages) {
        $problems[] = 'no system message could be read from the installer response';
    }

    foreach ($messages as $message) {
        if (in_array($message['type'], ['warning', 'danger', 'error'], true)) {
            $problems[] = "unexpected {$message['type']} message: " . mb_substr($message['text'], 0, 300);
        }

        if (preg_match_all('/\b(?:PLG|COM|MOD|PKG|TPL|LIB|JLIB|FILES)_[A-Z0-9][A-Z0-9_]*\b/', $message['text'], $keys)) {
            $problems[] = 'untranslated language key(s): ' . implode(', ', array_unique($keys[0]));
        }
    }

    if ($problems) {
        echo "❌ Installer message check failed:\n  - " . implode("\n  - ", $problems) . "\n";
        file_put_contents('/tmp/install-result.html', $installResult);
        exit(1);
    }

    echo "✅ Installer messages: no warnings, errors or untranslated keys\n";
}

exit(0);
