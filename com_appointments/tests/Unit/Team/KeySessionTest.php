<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\Team\CipherSuite;
use Advans\Component\Appointments\Administrator\Service\Team\KeySession;
use PHPUnit\Framework\TestCase;

$serviceDir = dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team';

foreach (['KeySessionInterface', 'KeySession'] as $class) {
    require_once $serviceDir . '/' . $class . '.php';
}

/**
 * The pure parts of the session escrow. The sign-in with password and second
 * factor, the cookie and the session table are tested in team-b-keys.php
 * against a real Joomla.
 */
final class KeySessionTest extends TestCase
{
    public function testEscrowIsBoundToUserClientModeVersionAndNonce(): void
    {
        $nonce = str_repeat("\x01", 16);
        $pub = str_repeat("\x03", 32);
        $salt = str_repeat("\x04", 16);
        $base = KeySession::escrowAd(5, 'site', 'key', 1, $nonce, $pub, $salt, true);

        self::assertSame(CipherSuite::associatedData('key-escrow', 5, 'site', 'key', 1, str_repeat('01', 16), str_repeat('03', 32), str_repeat('04', 16), 1), $base);

        foreach ([
            KeySession::escrowAd(6, 'site', 'key', 1, $nonce, $pub, $salt, true),
            KeySession::escrowAd(5, 'administrator', 'key', 1, $nonce, $pub, $salt, true),
            KeySession::escrowAd(5, 'site', 'create', 1, $nonce, $pub, $salt, true),
            KeySession::escrowAd(5, 'site', 'key', 2, $nonce, $pub, $salt, true),
            KeySession::escrowAd(5, 'site', 'key', 1, str_repeat("\x02", 16), $pub, $salt, true),
            KeySession::escrowAd(5, 'site', 'key', 1, $nonce, str_repeat("\x05", 32), $salt, true),
            KeySession::escrowAd(5, 'site', 'key', 1, $nonce, $pub, str_repeat("\x06", 16), true),
            KeySession::escrowAd(5, 'site', 'key', 1, $nonce, $pub, $salt, false),
        ] as $other) {
            self::assertNotSame($base, $other);
        }
    }

    public function testEscrowBoxOpensOnlyInItsPlace(): void
    {
        $sessionKey = CipherSuite::randomKey();
        $nonce = random_bytes(16);
        $box = CipherSuite::encrypt($sessionKey, str_repeat("\x07", 32), KeySession::escrowAd(5, 'site', 'key', 1, $nonce, '', '', true));

        self::assertNotNull(CipherSuite::decrypt($sessionKey, $box, KeySession::escrowAd(5, 'site', 'key', 1, $nonce, '', '', true)));
        self::assertNull(CipherSuite::decrypt($sessionKey, $box, KeySession::escrowAd(5, 'administrator', 'key', 1, $nonce, '', '', true)), 'site escrow in the administrator');
        self::assertNull(CipherSuite::decrypt($sessionKey, $box, KeySession::escrowAd(9, 'site', 'key', 1, $nonce, '', '', true)), 'another user');
        self::assertNull(CipherSuite::decrypt(CipherSuite::randomKey(), $box, KeySession::escrowAd(5, 'site', 'key', 1, $nonce, '', '', true)), 'another cookie');
    }

    public function testSilentSignInsAreJoomlasDefaultsAtLeast(): void
    {
        // Without Joomla (unit run) the com_users option cannot be read; the
        // defaults of plg_user_joomla stay.
        $types = KeySession::silentResponseTypes();

        self::assertContains('cookie', $types);
        self::assertContains('passwordless', $types);
    }
}
