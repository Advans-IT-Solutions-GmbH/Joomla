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

    /**
     * Known derivation vectors, computed once from the documented formula
     * hash_hmac('sha256', 'com_appointments:' . $purpose, $siteSecret, true).
     *
     * These pin the algorithm, the label and the argument order, which is the
     * only thing that actually protects the links already sitting in people's
     * mailboxes. Changing any of them changes every key on every installation
     * and silently invalidates every reschedule, cancel and erasure link ever
     * issued. If a change here is really wanted, it needs a migration that
     * keeps the old keys verifiable, not a new expected value in this test.
     */
    private const VECTORS = [
        KeyProvider::PURPOSE_SIGNED_LINK => '00042ab814d87e1a175199f8766f06a54535ba8172f79520e952d595c7302ddf',
        KeyProvider::PURPOSE_RATE_LIMIT  => 'c9af6e0ac0767c861994cc66eacdcd2b6a60012cebd5f2ccb2c252d5c89f2207',
    ];

    public function testKeyMatchesTheKnownDerivationVector(): void
    {
        $provider = new KeyProvider(self::SECRET);

        foreach (self::VECTORS as $purpose => $expected) {
            $this->assertSame(
                $expected,
                bin2hex($provider->key($purpose)),
                "The derived key for '$purpose' changed. Every link already sent would stop working."
            );
        }
    }

    public function testKeyIsStableForTheSameSecretAndPurpose(): void
    {
        $first  = (new KeyProvider(self::SECRET))->key(KeyProvider::PURPOSE_SIGNED_LINK);
        $second = (new KeyProvider(self::SECRET))->key(KeyProvider::PURPOSE_SIGNED_LINK);

        // A link that was signed yesterday must still verify today, so the key
        // may never contain a random or time-dependent part. The vector test
        // above covers stability across versions, this one within a run.
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
