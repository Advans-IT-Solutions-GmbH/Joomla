<?php

namespace Advans\Component\Appointments\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class InstallerGuardTest extends TestCase
{
    public function testBundledModuleAndLanguagesAreGuardedDuringUpdates(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2) . '/script.php');
        self::assertIsString($script);
        self::assertStringContainsString('foreach (self::BUNDLED_MODULES as $element)', $script);
        self::assertStringContainsString('$moduleTarget = JPATH_ADMINISTRATOR . \'/modules/\' . $element;', $script);
        self::assertStringContainsString('$files[$moduleTarget . \'/\' . $relative]', $script);
        self::assertStringContainsString("JPATH_ADMINISTRATOR . '/language/' . $tag . '/' . $name", $script);
    }
}
