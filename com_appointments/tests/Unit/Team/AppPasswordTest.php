<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use Advans\Component\Appointments\Administrator\Helper\Team\AppPassword;
use PHPUnit\Framework\TestCase;

final class AppPasswordTest extends TestCase
{
    private const SECRET = 'test-site-secret-for-app-passwords';

    public function testGeneratedPasswordHas160BitsAndRoundTrips(): void
    {
        $password = AppPassword::generate();

        self::assertSame(20, strlen($password['raw']));
        self::assertMatchesRegularExpression('/^[A-Z2-7]{4}(-[A-Z2-7]{4}){7}$/', $password['display']);
        self::assertSame($password['raw'], AppPassword::parse($password['display']));
    }

    public function testParsingIgnoresCaseSpacesAndHyphens(): void
    {
        $password = AppPassword::generate();
        $typed = strtolower(str_replace('-', ' ', $password['display']));

        self::assertSame($password['raw'], AppPassword::parse($typed));
        self::assertSame($password['raw'], AppPassword::parse(str_replace('-', '', $password['display'])));
    }

    public function testKnownVectorsOfTheFormat(): void
    {
        self::assertSame('AAAA-AAAA-AAAA-AAAA-AAAA-AAAA-AAAA-AAAA', AppPassword::format(str_repeat("\0", 20)));
        self::assertSame('7777-7777-7777-7777-7777-7777-7777-7777', AppPassword::format(str_repeat("\xff", 20)));
    }

    public function testAnythingElseIsNotAnAppPassword(): void
    {
        foreach (['', 'ABCD', str_repeat('A', 31), str_repeat('A', 33), str_repeat('1', 32), str_repeat('A', 31) . '!', 'my joomla password'] as $input) {
            self::assertNull(AppPassword::parse($input), var_export($input, true));
        }
    }

    public function testHashIsKeyedAndStable(): void
    {
        $raw = str_repeat("\x01", 20);
        $keys = new KeyProvider(self::SECRET);

        self::assertSame(32, strlen(AppPassword::hash($raw, $keys)));
        self::assertSame(AppPassword::hash($raw, $keys), AppPassword::hash($raw, new KeyProvider(self::SECRET)));
        self::assertNotSame(AppPassword::hash($raw, $keys), AppPassword::hash($raw, new KeyProvider(self::SECRET . 'x')));
        self::assertNotSame(AppPassword::hash($raw, $keys), AppPassword::hash(str_repeat("\x02", 20), $keys));
    }

    public function testWrappingKeyIsSeparatedFromTheHashAndBoundToTheSalt(): void
    {
        $raw = str_repeat("\x01", 20);
        $keys = new KeyProvider(self::SECRET);
        $salt = str_repeat("\x03", 16);
        $key = AppPassword::wrappingKey($raw, $salt, $keys);

        self::assertSame(32, strlen($key));
        self::assertNotSame(AppPassword::hash($raw, $keys), $key);
        self::assertNotSame($key, AppPassword::wrappingKey($raw, str_repeat("\x04", 16), $keys));
        self::assertNotSame($key, AppPassword::wrappingKey($raw, $salt, new KeyProvider(self::SECRET . 'x')));
    }

    public function testWrongSaltLengthIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AppPassword::wrappingKey(str_repeat("\x01", 20), 'short', new KeyProvider(self::SECRET));
    }
}
