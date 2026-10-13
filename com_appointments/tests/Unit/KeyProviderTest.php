<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Advans\Component\Appointments\Tests\Unit;

use Advans\Component\Appointments\Administrator\Helper\KeyProvider;
use PHPUnit\Framework\TestCase;

final class KeyProviderTest extends TestCase
{
    private const SECRET = 'TestSiteSecret0123456789abcdef';

    public function testKeyIsThirtyTwoRawBytes(): void
    {
        $key = (new KeyProvider(self::SECRET))->key(KeyProvider::PURPOSE_SIGNED_LINK);

        $this->assertSame(32, \strlen($key));
    }

    public function testKeyIsStableForTheSameSecretAndPurpose(): void
    {
        $first  = (new KeyProvider(self::SECRET))->key(KeyProvider::PURPOSE_SIGNED_LINK);
        $second = (new KeyProvider(self::SECRET))->key(KeyProvider::PURPOSE_SIGNED_LINK);

        // A link that was signed yesterday must still verify today, so the key
        // may never contain a random or time-dependent part.
        $this->assertSame($first, $second);
    }

    public function testDifferentPurposesYieldDifferentKeys(): void
    {
        $provider = new KeyProvider(self::SECRET);

        $this->assertNotSame(
            $provider->key(KeyProvider::PURPOSE_SIGNED_LINK),
            $provider->key(KeyProvider::PURPOSE_RATE_LIMIT)
        );
    }

    public function testDifferentSecretsYieldDifferentKeys(): void
    {
        $this->assertNotSame(
            (new KeyProvider(self::SECRET))->key(KeyProvider::PURPOSE_SIGNED_LINK),
            (new KeyProvider(self::SECRET . 'x'))->key(KeyProvider::PURPOSE_SIGNED_LINK)
        );
    }

    public function testTheSecretIsNotRecoverableFromTheKey(): void
    {
        $key = (new KeyProvider(self::SECRET))->key(KeyProvider::PURPOSE_SIGNED_LINK);

        $this->assertStringNotContainsString(self::SECRET, $key);
    }

    public function testAnEmptySecretIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new KeyProvider('');
    }

    public function testAnEmptyPurposeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new KeyProvider(self::SECRET))->key('');
    }
}
