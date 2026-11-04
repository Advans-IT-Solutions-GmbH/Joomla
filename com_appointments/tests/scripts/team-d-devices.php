<?php
/**
 * Work package D: «My calendars», devices, mails. Scaffold assertions: devices
 * store no app password, and «My calendars» is not reachable while the team
 * calendars are switched off.
 */

return static function (TeamSuite $suite): void {
    $suite->test('Devices store a keyed hash and a wrapped key, never the app password', static function () use ($suite) {
        $columns = $suite->columns('appointments_devices');

        return in_array('secret_hash', $columns, true) && in_array('usk_wrapped', $columns, true)
            && !in_array('password', $columns, true) && !in_array('app_password', $columns, true);
    });

    $suite->test('«My calendars» answers 404 while the team calendars are off', static function () use ($suite) {
        return $suite->http('GET', '/index.php?option=com_appointments&view=mycalendars')['status'] === 404;
    });
};
