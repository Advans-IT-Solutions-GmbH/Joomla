<?php
/**
 * Work package F: log, lock-out, clean-up, privacy. Scaffold assertions: the
 * log can hold no calendar content, and the lock-out stores no plain user name
 * or address.
 */

return static function (TeamSuite $suite): void {
    $suite->test('The team calendar log has exactly the specified columns', static function () use ($suite) {
        return $suite->columns('appointments_teamlog') === ['id', 'occurred_utc', 'event', 'actor_user_id', 'actor_device_id', 'subject_user_id', 'calendar_id', 'object_ref', 'details', 'ip_hash', 'prev_hash', 'row_hash'];
    });

    $suite->test('Failed sign-ins are counted per keyed bucket only', static function () use ($suite) {
        return $suite->columns('appointments_auth_failures') === ['bucket', 'failures', 'locked_until_utc', 'last_failure_utc'];
    });

    $suite->test('User plugin installed and disabled after installation', static function () use ($suite) {
        return $suite->count("SELECT COUNT(*) FROM {$suite->prefix}extensions WHERE type='plugin' AND folder='user' AND element='appointments' AND enabled=0") === 1;
    });
};
