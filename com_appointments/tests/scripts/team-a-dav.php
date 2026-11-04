<?php
/**
 * Work package A: CalDAV server and system plugin. Scaffold assertions: the
 * plugin is installed and disabled, and while disabled no DAV address answers.
 */

return static function (TeamSuite $suite): void {
    $suite->test('CalDAV plugin installed and disabled after installation', static function () use ($suite) {
        return $suite->count("SELECT COUNT(*) FROM {$suite->prefix}extensions WHERE type='plugin' AND folder='system' AND element='appointmentsdav' AND enabled=0") === 1;
    });

    foreach (['/.well-known/caldav', '/dav/'] as $path) {
        $suite->test("{$path} does not answer as CalDAV while the plugin is disabled", static function () use ($suite, $path) {
            $response = $suite->http('PROPFIND', $path, ['Depth: 0']);

            return $response['status'] !== 207 && stripos($response['headers'], 'DAV:') === false;
        });
    }
};
