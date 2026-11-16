<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\CloseKeyCipher;
use PHPUnit\Framework\TestCase;

/**
 * The Close API key of an event type is stored only as a sealed value. Every key here is a synthetic
 * dummy; no real credential is used.
 */
final class CloseKeyCipherTest extends TestCase
{
    private const SECRET = 'TestSiteSecret0123456789abcdef';
    private const KEY = 'api_SyntheticDummyKey0123456789.NotARealCloseCredentialXyZ9';

    public function testTheStoredValueIsNotThePlainKey(): void
    {
        $sealed = (new CloseKeyCipher(self::SECRET))->seal(7, self::KEY);

        $this->assertStringStartsWith('v1:', $sealed);
        $this->assertNotSame(self::KEY, $sealed);
        $this->assertStringNotContainsString(self::KEY, $sealed);
        $this->assertStringNotContainsString(substr(self::KEY, 4, 16), $sealed);
        $raw = base64_decode(substr($sealed, 3), true);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString(self::KEY, $raw);
        $this->assertStringNotContainsString(substr(self::KEY, 4, 16), $raw);
    }

    public function testEverySealUsesAFreshNonce(): void
    {
        $cipher = new CloseKeyCipher(self::SECRET);
        $first = $cipher->seal(7, self::KEY);
        $second = $cipher->seal(7, self::KEY);

        $this->assertNotSame($first, $second);
        $this->assertNotSame(substr(base64_decode(substr($first, 3)), 0, 24), substr(base64_decode(substr($second, 3)), 0, 24));
        $this->assertSame(self::KEY, $cipher->open(7, $first));
        $this->assertSame(self::KEY, $cipher->open(7, $second));
    }

    public function testAnotherSiteSecretFailsGracefully(): void
    {
        $sealed = (new CloseKeyCipher(self::SECRET))->seal(7, self::KEY);

        // A changed site secret must not crash anything: the key is simply unreadable and is entered again.
        $this->assertNull((new CloseKeyCipher(self::SECRET . '-changed'))->open(7, $sealed));
    }

    public function testTheSealedValueIsBoundToItsEventType(): void
    {
        $cipher = new CloseKeyCipher(self::SECRET);

        $this->assertNull($cipher->open(8, $cipher->seal(7, self::KEY)));
    }

    public function testTamperedOrMalformedValuesAreRejected(): void
    {
        $cipher = new CloseKeyCipher(self::SECRET);
        $sealed = $cipher->seal(7, self::KEY);
        $raw = base64_decode(substr($sealed, 3));
        $raw[30] = $raw[30] === 'a' ? 'b' : 'a';

        foreach (['', 'v1:', 'v1:!!!', 'v2:' . substr($sealed, 3), 'v1:' . base64_encode($raw),
            'v1:' . base64_encode(substr($raw, 0, 20)), substr($sealed, 0, -4), self::KEY] as $value) {
            $this->assertNull($cipher->open(7, $value), 'Accepted: ' . strlen($value) . ' characters');
        }
    }

    public function testOnlyKeysUsableInBasicAuthenticationAreAccepted(): void
    {
        $this->assertTrue(CloseKeyCipher::valid(self::KEY));
        foreach (['', 'api_with:colon', 'api with space', "api_line\nbreak", "api_tab\t", "api_\x7f", 'api_ümlaut',
            str_repeat('a', CloseKeyCipher::MAX_LENGTH + 1)] as $key) {
            $this->assertFalse(CloseKeyCipher::valid($key), 'Accepted an unusable key of ' . strlen($key) . ' bytes');
        }
        $this->expectException(\InvalidArgumentException::class);
        (new CloseKeyCipher(self::SECRET))->seal(7, 'api_with:colon');
    }

    public function testAnEventTypeIsRequired(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new CloseKeyCipher(self::SECRET))->seal(0, self::KEY);
    }

    public function testTheHintShowsNoMoreThanTheLastFourCharacters(): void
    {
        $this->assertSame('XyZ9', CloseKeyCipher::hint(self::KEY));
        // A short key would be almost fully disclosed by four characters: no hint at all.
        $this->assertSame('', CloseKeyCipher::hint('api_short1'));
    }

    public function testAnEmptySiteSecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CloseKeyCipher('');
    }
}
