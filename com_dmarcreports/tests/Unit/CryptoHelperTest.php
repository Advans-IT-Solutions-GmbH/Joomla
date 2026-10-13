<?php
/**
 * @package     DMARC Reports Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

namespace Joomla\CMS {

    // The Unit suite is Joomla-free, but CryptoHelper derives its key from the
    // Joomla site secret through Factory::getApplication()->get('secret'). Provide
    // a minimal stand-in that returns a fixed secret so the encryption guarantees
    // can be exercised in isolation. Guarded so a real Joomla runtime wins.
    if (!class_exists(Factory::class, false)) {
        class Factory
        {
            public static function getApplication(): object
            {
                return new class {
                    public function get(string $key, $default = null)
                    {
                        // A deterministic, low-entropy passphrase used only to
                        // derive the key inside the unit suite. It is not a real
                        // credential and never leaves the test process.
                        return $key === 'secret' ? 'deterministic-unit-test-value' : $default;
                    }
                };
            }
        }
    }
}

namespace Advans\Component\DmarcReports\Tests\Unit {

    use Advans\Component\DmarcReports\Administrator\Helper\CryptoHelper;
    use PHPUnit\Framework\TestCase;

    require_once \dirname(__DIR__, 2)
        . '/administrator/components/com_dmarcreports/src/Helper/CryptoHelper.php';

    /**
     * Unit tests for the at-rest credential encryption. These guard the two
     * headline security properties the stored mailbox password relies on:
     * authenticated encryption (tampered ciphertext is refused, not
     * mis-decrypted) and randomized ciphertext (equal plaintexts differ).
     *
     * @since  1.0.0
     */
    class CryptoHelperTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!\function_exists('openssl_encrypt')) {
                $this->markTestSkipped('The openssl extension is required for credential encryption.');
            }
        }

        public function testEmptyPlaintextYieldsEmptyToken(): void
        {
            $this->assertSame('', CryptoHelper::encrypt(''));
        }

        public function testRoundTripRecoversPlaintext(): void
        {
            $plain = 'correct horse battery staple ✔ äöü';
            $token = CryptoHelper::encrypt($plain);

            $this->assertNotSame($plain, $token);
            $this->assertSame($plain, CryptoHelper::decrypt($token));
        }

        public function testEncryptedTokenIsOpaqueAndPrefixed(): void
        {
            $plain = 'mailbox-login-value';
            $token = CryptoHelper::encrypt($plain);

            $this->assertTrue(CryptoHelper::isEncrypted($token));
            $this->assertStringStartsWith('g1:', $token);
            // The plaintext must not be recoverable by reading the token.
            $this->assertStringNotContainsString($plain, $token);
        }

        public function testCiphertextIsRandomized(): void
        {
            $plain = 'repeat-me';
            $first  = CryptoHelper::encrypt($plain);
            $second = CryptoHelper::encrypt($plain);

            // A fresh random IV per call means equal plaintexts never produce
            // equal tokens, yet both still decrypt back to the same value.
            $this->assertNotSame($first, $second);
            $this->assertSame($plain, CryptoHelper::decrypt($first));
            $this->assertSame($plain, CryptoHelper::decrypt($second));
        }

        public function testUnencryptedValueIsReturnedUnchanged(): void
        {
            // Values without the version prefix predate encryption (or were never
            // encrypted); decrypt() passes them through so saved mailboxes keep
            // working across the upgrade that introduced encryption.
            $this->assertSame('legacy-plaintext', CryptoHelper::decrypt('legacy-plaintext'));
            $this->assertSame('', CryptoHelper::decrypt(''));
        }

        public function testTamperedCiphertextIsRejected(): void
        {
            $token = CryptoHelper::encrypt('tamper-target-value');
            $raw   = base64_decode(substr($token, 3), true);

            $this->assertNotFalse($raw);

            // Flip the final ciphertext byte: AES-256-GCM authenticates the
            // ciphertext, so the tag check must now fail and decrypt() must
            // refuse the value rather than return mangled plaintext.
            $last        = \strlen($raw) - 1;
            $raw[$last]  = $raw[$last] ^ "\xff";
            $tampered    = 'g1:' . base64_encode($raw);

            $this->assertSame('', CryptoHelper::decrypt($tampered));
        }

        public function testTamperedAuthTagIsRejected(): void
        {
            $token = CryptoHelper::encrypt('another-plaintext-value');
            $raw   = base64_decode(substr($token, 3), true);

            $this->assertNotFalse($raw);

            // The authentication tag sits after the 12-byte GCM IV; corrupting it
            // must also be refused.
            $raw[12]  = $raw[12] ^ "\xff";
            $tampered = 'g1:' . base64_encode($raw);

            $this->assertSame('', CryptoHelper::decrypt($tampered));
        }

        public function testMalformedTokenReturnsEmpty(): void
        {
            // Not valid base64 after the prefix.
            $this->assertSame('', CryptoHelper::decrypt('g1:@@not-base64@@'));
            // Valid base64 but too short to hold an IV and auth tag.
            $this->assertSame('', CryptoHelper::decrypt('g1:' . base64_encode('short')));
        }
    }
}
