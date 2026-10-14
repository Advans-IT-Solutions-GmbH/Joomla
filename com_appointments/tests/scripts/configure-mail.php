<?php

$path = '/var/www/html/configuration.php';
$configuration = file_get_contents($path);

if ($configuration === false) {
    throw new RuntimeException('The test configuration could not be read.');
}

foreach (['mailer' => 'sendmail', 'sendmail' => '/usr/local/bin/appointments-capture-mail'] as $property => $value) {
    $replacement = 'public $' . $property . ' = ' . var_export($value, true) . ';';
    $configuration = preg_replace('/public\s+\$' . $property . '\s*=\s*[^;]*;/', $replacement, $configuration, 1, $count);

    if ($configuration === null || $count !== 1) {
        throw new RuntimeException('The test mail configuration property is missing.');
    }
}

if (file_put_contents($path, $configuration) === false) {
    throw new RuntimeException('The test mail configuration could not be written.');
}
