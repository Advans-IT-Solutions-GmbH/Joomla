<?php

function appointments_test_root(): string
{
    $configured = getenv('APPOINTMENTS_TEST_ROOT');
    $path = $configured === false ? '/var/www/html' : $configured;
    if (!preg_match('~^(?:[A-Za-z]:[\\\\/]|/)~', $path)) {
        throw new RuntimeException('APPOINTMENTS_TEST_ROOT must be an absolute Joomla directory.');
    }
    $root = realpath($path);
    if ($root === false || !is_file($root . '/configuration.php') || !is_file($root . '/includes/framework.php')) {
        throw new RuntimeException('APPOINTMENTS_TEST_ROOT must contain the test Joomla installation.');
    }
    return $root;
}

function appointments_test_base_url(): string
{
    $configured = getenv('APPOINTMENTS_TEST_BASE_URL');
    $url = $configured === false ? 'http://localhost' : $configured;
    $parts = parse_url($url);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || !in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '[::1]'], true)
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment'])
        || !in_array($parts['path'] ?? '', ['', '/'], true)) {
        throw new RuntimeException('APPOINTMENTS_TEST_BASE_URL must be a loopback HTTP(S) origin.');
    }
    return rtrim($url, '/');
}
