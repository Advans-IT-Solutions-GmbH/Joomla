#!/usr/bin/env php
<?php

$directory = '/tmp/appointments-mail';
$message = stream_get_contents(STDIN);

if ($message === false || !is_dir($directory)
    || file_put_contents($directory . '/' . bin2hex(random_bytes(16)) . '.eml', $message, LOCK_EX) === false) {
    fwrite(STDERR, "The test mail capture could not persist a message.\n");
    exit(75);
}
