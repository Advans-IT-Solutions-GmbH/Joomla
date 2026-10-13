<?php
/**
 * Structure tests for the Appointments component: the database schema and the
 * key source files shipped in the installed package.
 */

class StructureTest
{
    private $db;
    private $passed = 0;
    private $failed = 0;
    private $dbPrefix;
    private $admin = '/var/www/html/administrator/components/com_appointments';
    private $site = '/var/www/html/components/com_appointments';

    /**
     * Every table the install schema creates, without the `#__` prefix.
     */
    private const TABLES = [
        'appointments_schedules',
        'appointments_schedule_rules',
        'appointments_eventtypes',
        'appointments_blocked',
        'appointments_bookings',
        'appointments_fields',
        'appointments_templates',
        'appointments_audit',
        'appointments_ratelimit',
    ];

    public function __construct()
    {
        require '/var/www/html/configuration.php';
        $config = new JConfig();
        $this->dbPrefix = $config->dbprefix;
        $this->db = new mysqli($config->host, $config->user, $config->password, $config->db);
    }

    public function run(): bool
    {
        echo "=== Structure Tests ===\n\n";

        foreach (self::TABLES as $table) {
            $this->test("Table #__{$table} exists", function () use ($table) {
                $result = $this->db->query("SHOW TABLES LIKE '{$this->dbPrefix}{$table}'");
                return $result && $result->num_rows === 1;
            });
        }

        $this->test('Bookings table carries the generated slot column', function () {
            $result = $this->db->query("SHOW COLUMNS FROM {$this->dbPrefix}appointments_bookings LIKE 'state_active'");
            return $result && $result->num_rows === 1;
        });

        $this->test('One active booking per slot is enforced by a unique index', function () {
            // The concurrency guarantee of this component is a database
            // constraint, not a check in PHP: the second simultaneous insert
            // for the same slot has to fail. A missing index would make that
            // guarantee disappear without any other test noticing.
            $result = $this->db->query("SHOW INDEX FROM {$this->dbPrefix}appointments_bookings WHERE Key_name = 'idx_active_slot'");

            if (!$result) {
                return false;
            }

            $columns = [];

            while ($row = $result->fetch_assoc()) {
                if ((int) $row['Non_unique'] !== 0) {
                    return false;
                }

                $columns[(int) $row['Seq_in_index']] = $row['Column_name'];
            }

            ksort($columns);

            return array_values($columns) === ['eventtype_id', 'start_utc', 'state_active'];
        });

        $this->test('A cancelled booking frees the slot again', function () {
            // Explicit UTC, never the server's default zone: the whole schema
            // stores UTC and a test must not be the one place that guesses.
            $utc = new \DateTimeZone('UTC');
            $reference = new \DateTimeImmutable('now', $utc);
            $now = $reference->format('Y-m-d H:i:s');
            $start = $reference->add(new \DateInterval('P10D'))->format('Y-m-d H:i:s');
            $end = $reference->add(new \DateInterval('P10DT20M'))->format('Y-m-d H:i:s');
            $table = "{$this->dbPrefix}appointments_bookings";

            // mysqli reports a duplicate key as an exception by default, so the
            // refusal is caught here instead of being read from a return value.
            $insert = function (string $uid, int $state) use ($table, $start, $end, $now): bool {
                try {
                    return (bool) $this->db->query(
                        "INSERT INTO {$table} (uid, eventtype_id, host_user_id, start_utc, end_utc, state, created_utc, modified_utc)"
                        . " VALUES ('{$uid}', 9901, 0, '{$start}', '{$end}', {$state}, '{$now}', '{$now}')"
                    );
                } catch (\mysqli_sql_exception $e) {
                    return false;
                }
            };

            $this->db->query("DELETE FROM {$table} WHERE eventtype_id = 9901");

            $firstConfirmed = $insert('structure-test-1', 1);
            // The second confirmed booking for the same slot must be refused.
            $secondRefused = !$insert('structure-test-2', 1);
            // After cancelling the first one, the slot is free again.
            $this->db->query("UPDATE {$table} SET state = 2 WHERE uid = 'structure-test-1'");
            $thirdAccepted = $insert('structure-test-3', 1);

            $this->db->query("DELETE FROM {$table} WHERE eventtype_id = 9901");

            return $firstConfirmed && $secondRefused && $thirdAccepted;
        });

        $this->test('The audit table holds no personal data', function () {
            // Deletion evidence must never become a second copy of the data it
            // documents, so the column set is pinned here.
            $result = $this->db->query("SHOW COLUMNS FROM {$this->dbPrefix}appointments_audit");

            if (!$result) {
                return false;
            }

            $columns = [];

            while ($row = $result->fetch_assoc()) {
                $columns[] = $row['Field'];
            }

            sort($columns);
            $expected = ['action', 'actor_user_id', 'booking_id', 'id', 'occurred_utc', 'path'];

            return $columns === $expected;
        });

        $files = [
            'Administrator provider'  => $this->admin . '/services/provider.php',
            'Component class'         => $this->admin . '/src/Extension/AppointmentsComponent.php',
            'KeyProvider helper'      => $this->admin . '/src/Helper/KeyProvider.php',
            'Dashboard view'          => $this->admin . '/src/View/Dashboard/HtmlView.php',
            'Dashboard layout'        => $this->admin . '/tmpl/dashboard/default.php',
            'Install SQL'             => $this->admin . '/sql/install.mysql.utf8.sql',
            'Uninstall SQL'           => $this->admin . '/sql/uninstall.mysql.utf8.sql',
            'Access rules'            => $this->admin . '/access.xml',
            'Administrator LICENSE'   => $this->admin . '/LICENSE.txt',
            'Site provider'           => $this->site . '/services/provider.php',
            'Site event type list'    => $this->site . '/src/View/Eventtypes/HtmlView.php',
            'Site layout'             => $this->site . '/tmpl/eventtypes/default.php',
            'Site LICENSE'            => $this->site . '/LICENSE.txt',
        ];

        foreach ($files as $label => $path) {
            $this->test("{$label} is installed", function () use ($path) {
                return file_exists($path);
            });
        }

        echo "\n=== Structure Test Summary ===\n";
        echo "Passed: {$this->passed}, Failed: {$this->failed}\n";
        return $this->failed === 0;
    }

    private function test(string $name, callable $fn): void
    {
        try {
            if ($fn()) { echo "✓ {$name}\n"; $this->passed++; }
            else { echo "✗ {$name}\n"; $this->failed++; }
        } catch (\Exception $e) {
            echo "✗ {$name} - Error: {$e->getMessage()}\n";
            $this->failed++;
        }
    }
}

$test = new StructureTest();
exit($test->run() ? 0 : 1);
