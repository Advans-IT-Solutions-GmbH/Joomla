<?php
/**
 * Work package A: CalDAV server and system plugin, over HTTP(S) against the
 * installed site. While the plugin is disabled no DAV address answers. With
 * plugin and option on: plain HTTP is refused without a body, the well-known
 * address redirects, every method needs authentication, every answer is
 * no-store/noindex without a session cookie or session row.
 *
 * The end-to-end cases (app password, roles, sync, ETags, clean-up) need the
 * services of the other work packages; until those are delivered they are
 * reported as pending (TeamSuite::pending()), never as passed. The same cases
 * run in PHPUnit (DavServerTest) against the real sabre/dav copy with test
 * doubles of the services.
 *
 * Leaves the plugin and the option as it found them.
 */

return static function (TeamSuite $suite): void {
    $admin = '/var/www/html/administrator/components/com_appointments/src/';

    // TeamSuite::isStub() reflects service classes; they load without Joomla.
    defined('_JEXEC') or define('_JEXEC', 1);
    spl_autoload_register(static function (string $class) use ($admin): void {
        $prefix = 'Advans\\Component\\Appointments\\Administrator\\';

        if (str_starts_with($class, $prefix)) {
            $file = $admin . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require_once $file;
            }
        }
    });

    $p = $suite->prefix;
    $db = $suite->db;
    $https = str_starts_with($suite->base, 'https://');

    /**
     * One request to an absolute URL (plain HTTP or the suite's HTTPS base).
     *
     * @return array{status: int, headers: string, body: string}
     */
    $request = static function (string $method, string $url, array $headers = [], ?string $body = null): array {
        $ch = curl_init($url);
        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if (str_starts_with($url, 'https://') && is_file('/etc/ssl/appointments-test/ca.pem')) {
            $options[CURLOPT_CAINFO] = '/etc/ssl/appointments-test/ca.pem';
        }

        curl_setopt_array($ch, $options);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    };

    $header = static function (array $response, string $name): ?string {
        return preg_match('/^' . preg_quote($name, '/') . ':\s*(.*?)\r?$/mi', $response['headers'], $m) ? $m[1] : null;
    };

    $headersOk = static function (array $response) use ($header): bool {
        return $header($response, 'Cache-Control') === 'private, no-store'
            && $header($response, 'X-Robots-Tag') === 'noindex, nofollow'
            && $header($response, 'Set-Cookie') === null
            && stripos($response['headers'], 'X-Sabre') === false;
    };

    $sessions = static function () use ($suite, $p): int {
        return $suite->count("SELECT COUNT(*) FROM {$p}session");
    };

    $suite->test('CalDAV plugin installed and disabled after installation', static function () use ($suite, $p) {
        return $suite->count("SELECT COUNT(*) FROM {$p}extensions WHERE type='plugin' AND folder='system' AND element='appointmentsdav' AND enabled=0") === 1;
    });

    foreach (['/.well-known/caldav', '/dav/'] as $path) {
        $suite->test("{$path} does not answer as CalDAV while the plugin is disabled", static function () use ($suite, $path) {
            $response = $suite->http('PROPFIND', $path, ['Depth: 0']);

            return $response['status'] !== 207 && stripos($response['headers'], 'DAV:') === false;
        });
    }

    $suite->test('The bundled sabre/dav copy is installed with its autoload map', static function () {
        $lib = '/var/www/html/administrator/components/com_appointments/lib';

        return is_file($lib . '/autoload.php') && is_file($lib . '/sabre/dav/lib/DAV/Server.php') && is_file($lib . '/sabre/dav/LICENSE')
            && !is_dir($lib . '/sabre/dav/lib/CardDAV');
    });

    // Switch the plugin and the feature on for the rest of this part.
    $result = $db->query("SELECT params FROM {$p}extensions WHERE type='component' AND element='com_appointments'");
    $originalParams = (string) ($result ? $result->fetch_assoc()['params'] : '{}');
    $params = json_decode($originalParams, true) ?: [];
    $params['teamcal_enabled'] = 1;
    $statement = $db->prepare("UPDATE {$p}extensions SET params = ? WHERE type='component' AND element='com_appointments'");
    $encoded = json_encode($params);
    $statement->bind_param('s', $encoded);
    $statement->execute();
    $db->query("UPDATE {$p}extensions SET enabled = 1 WHERE type='plugin' AND folder='system' AND element='appointmentsdav'");

    try {
        $suite->test('Plain HTTP is refused with 403, no body and no redirect', static function () use ($request, $headersOk) {
            foreach (['/dav/', '/dav/principals/', '/.well-known/caldav'] as $path) {
                $response = $request('PROPFIND', 'http://localhost' . $path, ['Depth: 0']);

                if ($response['status'] !== 403 || $response['body'] !== '' || stripos($response['headers'], "\nLocation:") !== false || !$headersOk($response)) {
                    return false;
                }
            }

            return true;
        });

        $suite->test('Other site addresses are not taken over by the CalDAV plugin', static function () use ($request) {
            $response = $request('GET', 'http://localhost/index.php');

            return $response['status'] === 200 && stripos($response['headers'], 'X-Robots-Tag: noindex, nofollow') === false;
        });

        if (!$https) {
            foreach ([
                'Over HTTPS the well-known address redirects every method to the DAV address',
                'Over HTTPS every method is authenticated first (401 with a Basic challenge)',
                'Over HTTPS a 401 says whether the Authorization header reached PHP',
                'A CalDAV request leaves no Joomla session row and no cookie',
                'MKCALENDAR reaches PHP and is refused by the server, not by the web server',
            ] as $name) {
                $suite->pending($name, 'G1');
            }
        } else {
            $suite->test('Over HTTPS the well-known address redirects every method to the DAV address', static function () use ($suite, $request, $header, $headersOk) {
                foreach (['GET', 'PROPFIND', 'OPTIONS', 'PUT'] as $method) {
                    $response = $request($method, $suite->base . '/.well-known/caldav', ['Depth: 0']);

                    if ($response['status'] !== 301 || $header($response, 'Location') !== $suite->base . '/dav/' || !$headersOk($response)) {
                        return false;
                    }
                }

                return true;
            });

            $suite->test('Over HTTPS every method is authenticated first (401 with a Basic challenge)', static function () use ($suite, $request, $header, $headersOk) {
                foreach (['OPTIONS', 'PROPFIND', 'REPORT', 'PUT', 'DELETE', 'MKCALENDAR'] as $method) {
                    $response = $request($method, $suite->base . '/dav/', ['Depth: 0', 'Content-Type: application/xml'], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"/>');

                    if ($response['status'] !== 401 || !str_starts_with((string) $header($response, 'WWW-Authenticate'), 'Basic realm="')
                        || $header($response, 'X-Appointments-Authorization') !== null || $response['body'] !== '' || !$headersOk($response)) {
                        return false;
                    }
                }

                return true;
            });

            $suite->test('Over HTTPS a 401 says whether the Authorization header reached PHP', static function () use ($suite, $request, $header) {
                $response = $request('PROPFIND', $suite->base . '/dav/', ['Depth: 0', 'Authorization: Bearer not-basic']);

                return $response['status'] === 401 && $header($response, 'X-Appointments-Authorization') === 'received';
            });

            $suite->test('A CalDAV request leaves no Joomla session row and no cookie', static function () use ($suite, $request, $sessions, $headersOk) {
                $before = $sessions();

                for ($i = 0; $i < 3; $i++) {
                    $response = $request('PROPFIND', $suite->base . '/dav/', ['Depth: 0']);

                    if (!$headersOk($response)) {
                        return false;
                    }
                }

                return $sessions() === $before;
            });

            $suite->test('MKCALENDAR reaches PHP and is refused by the server, not by the web server', static function () use ($suite, $request, $header) {
                $response = $request('MKCALENDAR', $suite->base . '/dav/calendars/u1/new/');

                return $response['status'] === 401 && $header($response, 'WWW-Authenticate') !== null;
            });
        }

        // End-to-end cases: they run once the services they need are delivered.
        $needs = [
            'B' => 'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\KeyService',
            'C' => 'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\GrantService',
            'D' => 'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\DeviceService',
            'E' => 'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\TimingIndex',
            'F' => 'Advans\\Component\\Appointments\\Administrator\\Service\\Team\\LockoutService',
        ];
        $missing = static function (string ...$packages) use ($suite, $needs, $https): string {
            $open = array_values(array_filter($packages, static fn (string $p): bool => $suite->isStub($needs[$p])));

            if (!$https) {
                $open[] = 'G1';
            }

            return implode(', ', $open);
        };

        foreach ([
            ['A Joomla password is refused with the same 401 as a wrong app password, and counted', ['F']],
            ['Ten failed sign-ins lock CalDAV with 429 and Retry-After, also for the correct app password', ['D', 'F']],
            ['Discovery: current-user-principal, calendar-home-set, home of the own user only', ['B', 'C', 'D', 'F']],
            ['Default deny: without a grant the home is empty, a foreign calendar is 404', ['B', 'C', 'D', 'F']],
            ['Roles: free/busy redacted without a key, read only reads, write and manage write', ['B', 'C', 'D', 'E', 'F']],
            ['PUT, REPORT and DELETE per role, with ETag preconditions and no-uid-conflict', ['B', 'C', 'D', 'E', 'F']],
            ['sync-collection: initial, incremental, expired token answered with valid-sync-token', ['B', 'C', 'D', 'E', 'F']],
            ['Clean-up: empty view, hidden view, then 401', ['B', 'C', 'D', 'F']],
            ['An active device without second factor is refused with 403', ['B', 'C', 'D', 'F']],
            ['Object store: booking objects sealed, removed with the booking, e-mail index and retention', ['B', 'E']],
        ] as [$name, $packages]) {
            $open = $missing(...$packages);

            if ($open !== '') {
                $suite->pending($name, $open);
            } else {
                // Delivered dependencies: the case is written in this part when the last one lands.
                $suite->test($name . ' (to be written once its services are delivered)', static fn () => false);
            }
        }
    } finally {
        $statement = $db->prepare("UPDATE {$p}extensions SET params = ? WHERE type='component' AND element='com_appointments'");
        $statement->bind_param('s', $originalParams);
        $statement->execute();
        $db->query("UPDATE {$p}extensions SET enabled = 0 WHERE type='plugin' AND folder='system' AND element='appointmentsdav'");
    }

    $suite->test('The plugin and the option are back as the installation left them', static function () use ($suite, $p) {
        return $suite->count("SELECT COUNT(*) FROM {$p}extensions WHERE type='plugin' AND folder='system' AND element='appointmentsdav' AND enabled=0") === 1;
    });
};
