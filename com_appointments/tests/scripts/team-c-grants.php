<?php
/**
 * Work package C: calendars, grants, invitations, administration. Scaffold
 * assertions: default deny in the schema (one grant per user and calendar, no
 * token column on calendars) and the administrator views render.
 */

return static function (TeamSuite $suite): void {
    $suite->test('A calendar has no secret address column', static function () use ($suite) {
        foreach ($suite->columns('appointments_calendars') as $column) {
            if (preg_match('/token|secret|password/', $column)) {
                return false;
            }
        }

        return true;
    });

    $suite->test('At most one grant per user and calendar', static function () use ($suite) {
        $result = $suite->db->query("SHOW INDEX FROM {$suite->prefix}appointments_calendar_grants WHERE Key_name = 'idx_grant_calendar_user' AND Non_unique = 0");

        return $result && $result->num_rows === 2;
    });

    $suite->test('Invitations store only a hash of their token', static function () use ($suite) {
        $columns = $suite->columns('appointments_invitations');

        return in_array('token_hash', $columns, true) && !in_array('token', $columns, true);
    });

    $suite->test('Installation created both team groups below the registration group and enforces MFA for them', static function () use ($suite) {
        $result = $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'");
        $params = json_decode((string) ($result ? $result->fetch_assoc()['params'] : ''), true) ?: [];
        $internal = (int) (($params['team_groups'] ?? [])[0] ?? 0);
        $external = (int) ($params['external_group'] ?? 0);
        $result = $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_users'");
        $users = json_decode((string) ($result ? $result->fetch_assoc()['params'] : ''), true) ?: [];
        $parent = (int) ($users['new_usertype'] ?? 2) ?: 2;
        $forced = array_map('intval', (array) ($users['forceMFAUserGroups'] ?? []));

        return $internal > 0 && $external > 0 && $internal !== $external
            && $suite->count("SELECT COUNT(*) FROM {$suite->prefix}usergroups WHERE id IN ({$internal},{$external}) AND parent_id = {$parent}") === 2
            && in_array($internal, $forced, true) && in_array($external, $forced, true)
            && (int) ($params['team_viewlevel'] ?? 0) > 0;
    });

    $suite->test('The team groups carry no permission of their own', static function () use ($suite) {
        $result = $suite->db->query("SELECT params FROM {$suite->prefix}extensions WHERE type='component' AND element='com_appointments'");
        $params = json_decode((string) ($result ? $result->fetch_assoc()['params'] : ''), true) ?: [];
        $ids = array_filter([(int) (($params['team_groups'] ?? [])[0] ?? 0), (int) ($params['external_group'] ?? 0)]);
        $result = $suite->db->query("SELECT rules FROM {$suite->prefix}assets WHERE name = 'root.1'");
        $rules = json_decode((string) ($result ? $result->fetch_assoc()['rules'] : ''), true) ?: [];

        foreach ($rules as $groups) {
            foreach ($ids as $id) {
                if (isset($groups[(string) $id])) {
                    return false;
                }
            }
        }

        return $ids !== [];
    });
};
