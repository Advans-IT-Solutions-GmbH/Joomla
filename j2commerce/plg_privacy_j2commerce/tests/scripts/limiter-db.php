<?php
/**
 * @package     J2Commerce Privacy Plugin
 * @subpackage  Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 *
 * Attempt limiter of the confirmation page against a real database, outside Joomla (CI job
 * "Attempt Limiter"): the install, update and uninstall SQL and Security\ConfirmAttempts on
 * PostgreSQL and MySQL, with joomla/database 3 (Joomla 5) and 4 (Joomla 6). The Joomla lanes run
 * on MySQL only, so this job is the PostgreSQL coverage of the schema files and of the counting
 * statements (upsert, locking read, release, purge) including genuinely parallel requests.
 *
 * Environment: LIMITER_AUTOLOAD (vendor/autoload.php with joomla/database), LIMITER_DB
 * (postgresql or mysql), LIMITER_PASSWORD (password of the CI service container). Called with
 * "worker" as first argument, it is one of the parallel requests (see the parallel test below).
 */

define('_JEXEC', 1);

require (string) getenv('LIMITER_AUTOLOAD');
require dirname(__DIR__, 2) . '/src/Security/ConfirmAttempts.php';

use Advans\Plugin\Privacy\J2Commerce\Security\ConfirmAttempts;
use Joomla\Database\DatabaseDriver;
use Joomla\Database\DatabaseFactory;

const LIMITER_SECRET = 'limiter-test-secret';

function limiterIsPostgres(): bool
{
    return getenv('LIMITER_DB') === 'postgresql';
}

function limiterDatabase(): DatabaseDriver
{
    $db = (new DatabaseFactory())->getDriver(limiterIsPostgres() ? 'pgsql' : 'mysqli', [
        'host'     => '127.0.0.1',
        'port'     => limiterIsPostgres() ? 5432 : 3306,
        'user'     => 'limiter',
        'password' => (string) getenv('LIMITER_PASSWORD'),
        'database' => 'limiter',
        'prefix'   => 'lim_',
    ]);
    $db->connect();

    return $db;
}

// One parallel request: connect first, wait for the common start, then reserve.
if (($argv[1] ?? '') === 'worker') {
    [, , $start, $email, $ip, $now] = $argv;
    $limiter = new ConfirmAttempts(limiterDatabase(), LIMITER_SECRET);
    $ready   = microtime(true) < (float) $start;

    while (microtime(true) < (float) $start) {
        usleep(500);
    }

    echo "\nRESULT:" . ($limiter->reserve(['email' => $email, 'ip' => $ip], (int) $now) ? '1' : '0') . ($ready ? 'r' : 'l') . "\n";
    exit(0);
}

$failed = 0;

function check(string $name, bool $ok, string $detail = ''): void
{
    global $failed;

    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . ($ok || $detail === '' ? '' : ' (' . $detail . ')') . "\n";
    $failed += $ok ? 0 : 1;
}

$db     = limiterDatabase();
$sqlDir = dirname(__DIR__, 2) . '/sql';
$type   = limiterIsPostgres() ? 'postgresql' : 'mysql';
$table  = $db->quoteName(ConfirmAttempts::TABLE);

echo 'Database: ' . $db->getServerType() . ' ' . $db->getVersion() . ', driver ' . $db->getName()
    . ', joomla/database ' . \Composer\InstalledVersions::getPrettyVersion('joomla/database') . "\n";

$runSql = static function (string $file) use ($db): void {
    foreach ($db->splitSql((string) file_get_contents($file)) as $statement) {
        if (trim($statement) !== '') {
            $db->setQuery($statement)->execute();
        }
    }
};
$tableExists = static fn (): bool => \in_array($db->replacePrefix(ConfirmAttempts::TABLE), $db->getTableList(), true);
$runs        = static function (callable $work): string {
    try {
        $work();

        return '';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
};

// Schema: install, install again and the update file on an installed table (both idempotent).
$runSql($sqlDir . '/uninstall.' . $type . '.utf8.sql');
$error = $runs(static fn () => $runSql($sqlDir . '/install.' . $type . '.utf8.sql'));
check('Install SQL creates the table', $error === '' && $tableExists(), $error);
$error = $runs(static fn () => $runSql($sqlDir . '/install.' . $type . '.utf8.sql'));
check('Install SQL runs again on an existing table (postflight backstop)', $error === '', $error);

foreach (glob($sqlDir . '/updates/' . $type . '/*.sql') ?: [] as $update) {
    $error = $runs(static fn () => $runSql($update));
    check('Update SQL ' . basename($update) . ' runs on an installed table', $error === '', $error);
}

$limiter  = new ConfirmAttempts($db, LIMITER_SECRET);
$t0       = time();
$max      = ConfirmAttempts::MAX_EMAIL_FAILURES;
$maxIp    = ConfirmAttempts::MAX_IP_FAILURES;
$window   = ConfirmAttempts::WINDOW;
$count    = static function (string $scope, string $value) use ($db, $table, $limiter): ?int {
    $value = $db->setQuery(
        'SELECT ' . $db->quoteName('failures') . ' FROM ' . $table
        . ' WHERE ' . $db->quoteName('scope') . ' = ' . $db->quote($scope)
        . ' AND ' . $db->quoteName('subject_hash') . ' = ' . $db->quote($limiter->hash($scope, $value))
    )->loadResult();

    return $value === null ? null : (int) $value;
};
$rows = static fn (string $scope): int => (int) $db->setQuery(
    'SELECT COUNT(*) FROM ' . $table . ' WHERE ' . $db->quoteName('scope') . ' = ' . $db->quote($scope)
)->loadResult();

// Requests one after the other: exactly the limit is admitted, every attempt is counted.
$admitted = 0;

for ($i = 0; $i < $max + 3; $i++) {
    $admitted += $limiter->reserve(['email' => 'sequential@example.com', 'ip' => '192.0.2.1'], $t0) ? 1 : 0;
}

check('Per address: exactly the limit of attempts is admitted', $admitted === $max, (string) $admitted);
check('Per address: every attempt is counted', $count('email', 'sequential@example.com') === $max + 3);
check('Exceeded limit is reported', $limiter->exceeds(['email' => 'sequential@example.com'], $t0));

// Per IP address: new addresses from one client, the IP address is checked first.
$before   = $rows('email');
$admitted = 0;

for ($i = 1; $i <= $maxIp + 5; $i++) {
    $admitted += $limiter->reserve(['email' => 'flood-' . $i . '@example.com', 'ip' => '192.0.2.2'], $t0) ? 1 : 0;
}

check('Per IP: exactly the IP limit of attempts is admitted', $admitted === $maxIp, (string) $admitted);
check('Per IP: after the limit no row is written for new addresses', $rows('email') - $before === $maxIp, (string) ($rows('email') - $before));

// registerFailure() counts like reserve().
$limiter->registerFailure(['email' => 'session@example.com', 'ip' => '192.0.2.5'], $t0);
check('A failure refused by the session limit is counted', $count('email', 'session@example.com') === 1 && $count('ip', '192.0.2.5') === 1);

// The window ends: a new window starts with one attempt.
check('After the window a new window starts', $limiter->reserve(['email' => 'sequential@example.com'], $t0 + $window + 1)
    && $count('email', 'sequential@example.com') === 1);

// Successful attempts are given back: a shared network is not blocked by correct confirmations.
$admitted = 0;

for ($i = 1; $i <= $maxIp + 5; $i++) {
    $subjects  = ['email' => 'shared-' . $i . '@example.com', 'ip' => '192.0.2.3'];
    $admitted += $limiter->reserve($subjects, $t0 + 10) ? 1 : 0;
    $limiter->release($subjects, $t0 + 10);
}

check('Release: more correct confirmations than the IP limit from one client are all admitted', $admitted === $maxIp + 5, (string) $admitted);
check('Release: a count that reaches zero removes its row', $count('ip', '192.0.2.3') === null && $count('email', 'shared-1@example.com') === null);

// Release after a window rollover: the new window keeps its attempt.
$rollover = ['email' => 'rollover@example.com'];
$limiter->reserve($rollover, $t0 + 100);
$limiter->reserve($rollover, $t0 + 100 + $window - 1);
$limiter->reserve($rollover, $t0 + 100 + $window + 1);
$limiter->release($rollover, $t0 + 100 + $window - 1);
check('Release after a window rollover leaves the attempt of the new window counted', $count('email', 'rollover@example.com') === 1,
    var_export($count('email', 'rollover@example.com'), true));

// Genuinely parallel requests: separate processes, each with its own connection.
$start     = sprintf('%.3F', microtime(true) + 5.0);
$processes = [];

for ($i = 0; $i < $max + 5; $i++) {
    $pipes       = [];
    $processes[] = [proc_open([PHP_BINARY, __FILE__, 'worker', $start, 'parallel@example.com', '192.0.2.4', (string) ($t0 + 200)],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes), $pipes];
}

$results = [];
$errors  = [];

foreach ($processes as [$process, $pipes]) {
    $output    = (string) stream_get_contents($pipes[1]);
    $error     = trim((string) stream_get_contents($pipes[2]));
    $results[] = preg_match('/^RESULT:([01][rl])$/m', $output, $match) ? $match[1] : '';
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0 || $results[array_key_last($results)] === '') {
        $errors[] = trim($output . ' ' . $error);
    }
}

check('Parallel: every worker was ready before the common start and finished cleanly',
    $errors === [] && \count(array_filter($results, static fn ($r): bool => \strlen($r) === 2 && $r[1] === 'r')) === \count($results),
    implode(' ', $results) . ' ' . implode(' | ', $errors));
check('Parallel: exactly the limit of attempts is admitted',
    \count(array_filter($results, static fn ($r): bool => ($r[0] ?? '') === '1')) === $max
    && \count(array_filter($results, static fn ($r): bool => ($r[0] ?? '') === '0')) === 5, implode(' ', $results));
check('Parallel: every attempt is counted once', $count('email', 'parallel@example.com') === $max + 5,
    var_export($count('email', 'parallel@example.com'), true));

// Only hashes are stored; ended windows are purged.
$stored = $db->setQuery('SELECT ' . $db->quoteName('subject_hash') . ' FROM ' . $table)->loadColumn();
check('Only 64-character hashes are stored', $stored !== [] && array_filter($stored, static fn ($h): bool => !preg_match('/^[0-9a-f]{64}$/', (string) $h)) === []);
$limiter->purgeExpired($t0 + 10 * $window);
check('Rows of ended windows are purged', (int) $db->setQuery('SELECT COUNT(*) FROM ' . $table)->loadResult() === 0);

// Uninstall removes the table.
$error = $runs(static fn () => $runSql($sqlDir . '/uninstall.' . $type . '.utf8.sql'));
check('Uninstall SQL drops the table', $error === '' && !$tableExists(), $error);

echo $failed === 0 ? "All limiter tests passed\n" : $failed . " limiter test(s) failed\n";
exit($failed === 0 ? 0 : 1);
