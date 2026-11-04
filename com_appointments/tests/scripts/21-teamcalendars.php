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
    private int $pending = 0;

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
     * A test that cannot run yet because another work package still ships its
     * stub. It is reported on its own line, never as a pass. With
     * TEAM_NO_PENDING=1 (the integration branch before the pull request to main)
     * every pending test is a failure, so nothing stays pending by accident.
     *
     * @param  string  $packages  The work packages it waits for, e.g. "B, D, F".
     */
    public function pending(string $name, string $packages): void
    {
        if (getenv('TEAM_NO_PENDING') === '1') {
            echo "✗ {$name} - PENDING {$packages} not allowed here\n";
            $this->failed++;

            return;
        }

        echo "… {$name} - PENDING {$packages}\n";
        $this->pending++;
    }

    /**
     * Whether a team service class is still the stub of its work package.
     */
    public function isStub(string $class): bool
    {
        $file = (new \ReflectionClass($class))->getFileName();

        return $file !== false && str_contains((string) file_get_contents($file), 'COM_APPOINTMENTS_TEAM_NOT_READY');
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
        echo "Passed: {$this->passed}, Failed: {$this->failed}, Pending: {$this->pending}\n";

        return $this->failed === 0;
    }
}

$suite = new TeamSuite();
exit($suite->run() ? 0 : 1);
