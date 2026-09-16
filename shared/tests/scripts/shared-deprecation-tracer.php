<?php
/**
 * Deprecation tracer for the production-like test containers.
 *
 * Loaded through auto_prepend_file (CLI and Apache) by
 * `shared-deprecations.php --arm`; never part of an extension package.
 *
 * It records, with the call stack, as one JSON line per unique occurrence:
 *   - every E_DEPRECATED and E_USER_DEPRECATED, including @-suppressed ones
 *     (Joomla core and its libraries trigger most deprecations with
 *     @trigger_error(), so PHP's own error log never shows them), and
 *   - every Joomla log entry in the "deprecated" category (Joomla also reports
 *     deprecations with Log::add(..., 'deprecated') without trigger_error()).
 *
 * shared-deprecations.php evaluates the file and attributes each entry to the
 * first caller outside Joomla's libraries.
 *
 * Joomla replaces the error handler in libraries/bootstrap.php and again in
 * includes/framework.php. The arm step therefore appends a call to
 * advans_deprecation_tracer_rearm() to the framework files, which registers
 * the handler again (chaining to Joomla's handler) and adds the log callback.
 */

if (!function_exists('advans_deprecation_tracer_record')) {
    function advans_deprecation_tracer_file(): string
    {
        return getenv('ADVANS_DEPRECATION_TRACE') ?: '/tmp/php-deprecation-trace.log';
    }

    function advans_deprecation_tracer_record(string $source, string $message, string $file, int $line, array $trace): void
    {
        static $seen = [];
        static $busy = false;
        static $hashes = [];

        if ($busy) {
            return;
        }

        $busy = true;

        try {
            $frames = [];

            foreach ($trace as $frame) {
                if (!isset($frame['file']) || $frame['file'] === __FILE__) {
                    continue;
                }

                $entry = ['file' => $frame['file'], 'line' => (int) ($frame['line'] ?? 0)];

                if (isset($frame['function'])) {
                    $entry['call'] = (isset($frame['class']) ? $frame['class'] . ($frame['type'] ?? '::') : '') . $frame['function'];
                }

                // Installer scripts run from a temporary folder that is deleted
                // afterwards; the hash lets the evaluation match the package file.
                if (str_contains($frame['file'], '/install_')) {
                    if (!array_key_exists($frame['file'], $hashes)) {
                        $hashes[$frame['file']] = is_file($frame['file']) ? sha1_file($frame['file']) : null;
                    }

                    if ($hashes[$frame['file']] !== null) {
                        $entry['sha1'] = $hashes[$frame['file']];
                    }
                }

                $frames[] = $entry;

                if (count($frames) >= 40) {
                    break;
                }
            }

            $key = md5($source . "\0" . $message . "\0" . $file . ':' . $line . "\0" . json_encode($frames));

            if (isset($seen[$key])) {
                return;
            }

            $seen[$key] = true;
            $logFile    = advans_deprecation_tracer_file();

            // Safety net against runaway logs (50 MB).
            if (@filesize($logFile) > 52428800) {
                return;
            }

            $record = [
                'source'  => $source,
                'message' => $message,
                'file'    => $file,
                'line'    => $line,
                'sapi'    => PHP_SAPI,
                'request' => $_SERVER['REQUEST_URI'] ?? ($_SERVER['argv'][0] ?? ''),
                'frames'  => $frames,
            ];

            @file_put_contents(
                $logFile,
                json_encode($record, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n",
                FILE_APPEND | LOCK_EX
            );
        } finally {
            $busy = false;
        }
    }

    function advans_deprecation_tracer_handler(int $errno, string $errstr, string $errfile = '', int $errline = 0): bool
    {
        advans_deprecation_tracer_record(
            $errno === E_USER_DEPRECATED ? 'E_USER_DEPRECATED' : 'E_DEPRECATED',
            $errstr,
            $errfile,
            $errline,
            debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)
        );

        // Keep the behaviour of the handler that was active before (Joomla's
        // deprecation logger); without one, PHP's standard handler logs the entry.
        $previous = $GLOBALS['__advans_deprecation_tracer_previous'] ?? null;

        if (is_callable($previous)) {
            return (bool) $previous($errno, $errstr, $errfile, $errline);
        }

        return false;
    }

    function advans_deprecation_tracer_log_entry($entry): void
    {
        $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($trace as $frame) {
            // Already recorded by the error handler (Joomla forwards E_USER_DEPRECATED to its log).
            if (in_array($frame['function'] ?? '', ['handleUserDeprecatedErrors', 'advans_deprecation_tracer_handler'], true)) {
                return;
            }
        }

        advans_deprecation_tracer_record('joomla-log', (string) ($entry->message ?? ''), '', 0, $trace);
    }

    function advans_deprecation_tracer_rearm(): void
    {
        $previous = set_error_handler('advans_deprecation_tracer_handler', E_DEPRECATED | E_USER_DEPRECATED);

        if ($previous !== 'advans_deprecation_tracer_handler') {
            $GLOBALS['__advans_deprecation_tracer_previous'] = $previous;
        }

        if (empty($GLOBALS['__advans_deprecation_tracer_logger']) && class_exists('Joomla\\CMS\\Log\\Log')) {
            \Joomla\CMS\Log\Log::addLogger(
                ['logger' => 'callback', 'callback' => 'advans_deprecation_tracer_log_entry'],
                \Joomla\CMS\Log\Log::ALL,
                ['deprecated']
            );
            $GLOBALS['__advans_deprecation_tracer_logger'] = true;
        }
    }

    $GLOBALS['__advans_deprecation_tracer_previous'] = null;
    set_error_handler('advans_deprecation_tracer_handler', E_DEPRECATED | E_USER_DEPRECATED);
}
