<?php
/**
 * Team calendars integration suite. One CI job, one runner; every work package
 * owns exactly one part file (team-<letter>-*.php) and adds its tests there, so
 * that parallel branches never change the same file. A part returns a callable
 * that receives the suite and registers its tests with $suite->test().
 *
 * The parts run in the order of their letter. A part may leave data behind for
 * a later part only if that part documents it; each part cleans up its own rows.
 */

final class TeamSuite
{
    public mysqli $db;
    public string $prefix;
    public string $base;
    private int $passed = 0;
    private int $failed = 0;

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->prefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
        $this->base = getenv('TEAM_BASE_URL') ?: 'http://localhost';
    }

    public function test(string $name, callable $fn): void
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
            echo "✗ {$name} - Error: " . get_class($e) . ': ' . $e->getMessage() . "\n";
            $this->failed++;
        }
    }

    /**
     * The column names of a table, in table order.
     *
     * @return string[]
     */
    public function columns(string $table): array
    {
        $names = [];
        $result = $this->db->query("SHOW COLUMNS FROM {$this->prefix}{$table}");

        while ($result && ($row = $result->fetch_assoc())) {
            $names[] = $row['Field'];
        }

        return $names;
    }

    public function count(string $sql): int
    {
        $result = $this->db->query($sql);
        $row = $result ? $result->fetch_row() : null;

        return $row ? (int) $row[0] : -1;
    }

    /**
     * One HTTP request without following redirects.
     *
     * @return array{status: int, headers: string, body: string}
     */
    public function http(string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $size = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return ['status' => $status, 'headers' => substr($raw, 0, $size), 'body' => substr($raw, $size)];
    }

    public function run(): bool
    {
        echo "=== Team Calendar Tests ===\n\n";
        $parts = glob(__DIR__ . '/team-*.php') ?: [];
        sort($parts);

        if ($parts === []) {
            $this->test('Team calendar test parts are present', static fn () => false);
        }

        foreach ($parts as $part) {
            echo "\n--- " . basename($part) . " ---\n";
            $register = require $part;
            $register($this);
        }

        echo "\n=== Team Calendar Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";

        return $this->failed === 0;
    }
}

$suite = new TeamSuite();
exit($suite->run() ? 0 : 1);
