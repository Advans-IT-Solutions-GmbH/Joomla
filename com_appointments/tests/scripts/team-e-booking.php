<?php
/**
 * Work package E: booking page and calendars. Scaffold assertions: an event
 * type can name a target calendar, and a fresh installation starts with the
 * token feeds switched off.
 */

return static function (TeamSuite $suite): void {
    $suite->test('Event types carry an optional target calendar', static function () use ($suite) {
        $result = $suite->db->query("SHOW COLUMNS FROM {$suite->prefix}appointments_eventtypes LIKE 'calendar_id'");
        $row = $result ? $result->fetch_assoc() : null;

        return $row && $row['Null'] === 'YES';
    });

    $suite->test('A fresh installation records the token feeds as switched off', static function () use ($suite) {
        $result = $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'");
        $row = $result ? $result->fetch_assoc() : null;
        $params = $row ? json_decode((string) $row['params'], true) : null;

        return is_array($params) && ($params['legacy_feeds'] ?? null) === '0';
    });
};
