<?php
/**
 * Site page tests for the Appointments component.
 *
 * The structure suite only proves that the site files exist, and the shared
 * backend-views suite covers administrator views only. Nothing would open the
 * public page, so a broken route, a model query against a missing column, an
 * unloaded language file or a mistyped asset name would ship unnoticed. This
 * suite therefore requests the component's public pages over HTTP and checks
 * the response the way the shared backend suite does for the administrator.
 *
 * It only reads; it changes nothing on the site.
 */

class SitePageTest
{
    private $passed = 0;
    private $failed = 0;
    private $baseUrl;

    /** @var array<string, string> */
    private $strings = [];

    public function __construct()
    {
        $this->baseUrl = rtrim(getenv('SITE_PAGE_BASE_URL') ?: 'http://localhost', '/');

        $file = '/var/www/html/language/en-GB/com_appointments.ini';

        if (is_file($file)) {
            $this->strings = parse_ini_file($file, false, INI_SCANNER_RAW) ?: [];
        }
    }

    public function run(): bool
    {
        echo "=== Site Page Tests ===\n\n";

        $this->test('Site language file is installed and readable', function () {
            return $this->strings !== [];
        });

        $pages = [
            'entry point without a view' => 'index.php?option=com_appointments',
            'event type list view'       => 'index.php?option=com_appointments&view=eventtypes',
        ];

        foreach ($pages as $label => $path) {
            $response = $this->request($path);

            $this->test("{$label}: answers HTTP 200", function () use ($response) {
                if ($response['code'] !== 200) {
                    echo "    HTTP {$response['code']} at {$response['url']}\n";
                }

                return $response['code'] === 200;
            });

            $this->test("{$label}: no PHP error in the output", function () use ($response) {
                if (preg_match('/(?:PHP )?(Fatal error|Parse error|Warning|Notice|Deprecated):[^\n<]*/', $response['body'], $m)) {
                    echo "    {$m[0]}\n";

                    return false;
                }

                return true;
            });

            $this->test("{$label}: no Joomla error page", function () use ($response) {
                $needles = ['An error has occurred', 'Error: 500', 'Error: 404', 'Call to a member function'];

                foreach ($needles as $needle) {
                    if (stripos($response['body'], $needle) !== false) {
                        echo "    found: {$needle}\n";

                        return false;
                    }
                }

                return true;
            });

            $this->test("{$label}: no untranslated language key", function () use ($response) {
                if (preg_match('/COM_APPOINTMENTS_[A-Z0-9_]+/', $response['body'], $m)) {
                    echo "    raw key: {$m[0]}\n";

                    return false;
                }

                return true;
            });

            $this->test("{$label}: shows a string from the component's language file", function () use ($response) {
                return $this->showsOwnString($response['body']);
            });

            $this->test("{$label}: serves the component stylesheet from this installation", function () use ($response) {
                // The asset has to come from this server. A remote URL would
                // break the component's defining constraint, so the path is
                // asserted, not merely the presence of a stylesheet.
                return (bool) preg_match(
                    '#(?:href|src)="(?:/|\.\./)*media/com_appointments/css/appointments\.css#',
                    $response['body']
                );
            });
        }

        echo "\n=== Site Page Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }

    /**
     * Whether the page shows at least one text of the component's own language
     * file. A page that rendered the layout but loaded no language would pass
     * every other check.
     */
    private function showsOwnString(string $body): bool
    {
        foreach ($this->strings as $value) {
            $value = trim((string) $value);

            // Values with a placeholder are not comparable literally.
            if ($value === '' || strpos($value, '%') !== false) {
                continue;
            }

            if (strpos($body, $value) !== false
                || strpos($body, htmlspecialchars($value, ENT_QUOTES, 'UTF-8')) !== false
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Redirects are followed: Joomla answers a bare `option=` request with a
     * permanent redirect to the canonical URL of the view, which is correct
     * behaviour and not something this suite should fail on. Where the redirect
     * ends is still checked, because the two content assertions only pass on the
     * component's own page.
     *
     * @return array{code:int, body:string, url:string}
     */
    private function request(string $path): array
    {
        $handle = curl_init($this->baseUrl . '/' . ltrim($path, '/'));
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $body = curl_exec($handle);
        $code = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $url  = (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_URL);
        curl_close($handle);

        return ['code' => $code, 'body' => $body === false ? '' : $body, 'url' => $url];
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) {
                echo "✓ {$name}\n";
                $this->passed++;
            } else {
                echo "✗ {$name}\n";
                $this->failed++;
            }
        } catch (\Throwable $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new SitePageTest();
exit($test->run() ? 0 : 1);
