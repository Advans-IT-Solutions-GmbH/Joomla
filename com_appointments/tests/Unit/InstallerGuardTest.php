<?php

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\BundledModuleGuard;
use PHPUnit\Framework\TestCase;

final class InstallerGuardTest extends TestCase
{
    public function testBundledModuleAndLanguagesAreGuardedDuringUpdates(): void
    {
        $source = sys_get_temp_dir() . '/appointments-module-' . bin2hex(random_bytes(6));
        $administrator = sys_get_temp_dir() . '/appointments-admin-' . bin2hex(random_bytes(6));
        mkdir($source . '/modules/mod_appointments/src/Helper', 0777, true);
        mkdir($source . '/modules/mod_appointments/language/en-GB', 0777, true);
        file_put_contents($source . '/modules/mod_appointments/mod_appointments.xml', '<extension/>');
        file_put_contents($source . '/modules/mod_appointments/src/Helper/AppointmentsHelper.php', '<?php');
        file_put_contents($source . '/modules/mod_appointments/language/en-GB/mod_appointments.ini', 'KEY="Value"');
        file_put_contents($source . '/modules/mod_appointments/language/en-GB/mod_appointments.sys.ini', 'MOD_APPOINTMENTS="Title"');

        try {
            $destinations = BundledModuleGuard::destinations($source, $administrator, ['mod_appointments']);
            self::assertSame(
                $source . '/modules/mod_appointments/mod_appointments.xml',
                $destinations[$administrator . '/modules/mod_appointments/mod_appointments.xml']
            );
            self::assertSame(
                $source . '/modules/mod_appointments/src/Helper/AppointmentsHelper.php',
                $destinations[$administrator . '/modules/mod_appointments/src/Helper/AppointmentsHelper.php']
            );
            self::assertSame(
                $source . '/modules/mod_appointments/language/en-GB/mod_appointments.ini',
                $destinations[$administrator . '/language/en-GB/mod_appointments.ini']
            );
            self::assertSame(
                $source . '/modules/mod_appointments/language/en-GB/mod_appointments.sys.ini',
                $destinations[$administrator . '/language/en-GB/mod_appointments.sys.ini']
            );
        } finally {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($source);
        }
    }
}
