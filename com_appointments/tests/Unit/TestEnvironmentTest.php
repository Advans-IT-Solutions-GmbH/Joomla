<?php

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/scripts/appointments-environment.php';

final class TestEnvironmentTest extends TestCase
{
    public function testLoopbackOriginsAndInvalidExplicitValues(): void
    {
        $previous = getenv('APPOINTMENTS_TEST_BASE_URL');
        try {
            putenv('APPOINTMENTS_TEST_BASE_URL');
            self::assertSame('http://localhost', appointments_test_base_url());
            foreach (['http://127.0.0.1:43462', 'https://localhost:43464/'] as $url) {
                putenv('APPOINTMENTS_TEST_BASE_URL=' . $url);
                self::assertSame(rtrim($url, '/'), appointments_test_base_url());
            }
            foreach (['', 'https://example.test', 'http://user@localhost', 'http://localhost/path',
                'http://localhost?x=1', 'http://localhost#fragment', 'http://localhost:99999'] as $url) {
                putenv('APPOINTMENTS_TEST_BASE_URL=' . $url);
                $rejected = false;
                try {
                    appointments_test_base_url();
                } catch (RuntimeException $error) {
                    $rejected = true;
                }
                self::assertTrue($rejected, 'Invalid explicit origin must not silently use the default.');
            }
        } finally {
            putenv($previous === false ? 'APPOINTMENTS_TEST_BASE_URL' : 'APPOINTMENTS_TEST_BASE_URL=' . $previous);
        }
    }

    public function testRootMustBeAnExistingAbsoluteJoomlaDirectory(): void
    {
        $previous = getenv('APPOINTMENTS_TEST_ROOT');
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'appointments-environment-' . bin2hex(random_bytes(8));
        mkdir($root);
        mkdir($root . DIRECTORY_SEPARATOR . 'includes');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'configuration.php', '<?php');
        file_put_contents($root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'framework.php', '<?php');
        try {
            putenv('APPOINTMENTS_TEST_ROOT=' . $root);
            self::assertSame(realpath($root), appointments_test_root());
            foreach (['', 'relative-root', $root . DIRECTORY_SEPARATOR . 'missing'] as $invalid) {
                putenv('APPOINTMENTS_TEST_ROOT=' . $invalid);
                $rejected = false;
                try {
                    appointments_test_root();
                } catch (RuntimeException $error) {
                    $rejected = true;
                }
                self::assertTrue($rejected);
            }
        } finally {
            putenv($previous === false ? 'APPOINTMENTS_TEST_ROOT' : 'APPOINTMENTS_TEST_ROOT=' . $previous);
            unlink($root . DIRECTORY_SEPARATOR . 'includes' . DIRECTORY_SEPARATOR . 'framework.php');
            unlink($root . DIRECTORY_SEPARATOR . 'configuration.php');
            rmdir($root . DIRECTORY_SEPARATOR . 'includes');
            rmdir($root);
        }
    }
}
