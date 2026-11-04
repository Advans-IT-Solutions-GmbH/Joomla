<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

require_once __DIR__ . '/DavTestKit.php';

use Advans\Plugin\System\AppointmentsDav\Dav\BasicCredentials;
use Advans\Plugin\System\AppointmentsDav\Dav\DavAddress;
use PHPUnit\Framework\TestCase;

/**
 * Path matching against the DAV base address and the evaluation of the
 * Authorization header.
 */
final class DavRequestRulesTest extends TestCase
{
    public function testDefaultAddressIsTheSiteRootPlusDav(): void
    {
        $address = DavAddress::fromSetting('', 'http://www.example.org/');

        self::assertSame('https://www.example.org/dav/', $address->url);
        self::assertSame('/dav/', $address->path);
        self::assertSame(DavAddress::KIND_DAV, $address->classify('/dav/'));
        self::assertSame(DavAddress::KIND_DAV, $address->classify('/dav'));
        self::assertSame(DavAddress::KIND_DAV, $address->classify('/dav/calendars/u5/x/y.ics?x=1'));
        self::assertSame(DavAddress::KIND_WELL_KNOWN, $address->classify('/.well-known/caldav'));
        self::assertSame(DavAddress::KIND_WELL_KNOWN, $address->classify('/.well-known/caldav/'));
        self::assertNull($address->classify('/davx/'));
        self::assertNull($address->classify('/.well-known/carddav'));
        self::assertNull($address->classify('/'));
        self::assertNull($address->classify('/index.php/dav/'));
    }

    public function testASiteInASubfolder(): void
    {
        $address = DavAddress::fromSetting('', 'https://example.org/joomla/');

        self::assertSame('https://example.org/joomla/dav/', $address->url);
        self::assertSame(DavAddress::KIND_DAV, $address->classify('/joomla/dav/principals/u1/'));
        self::assertSame(DavAddress::KIND_WELL_KNOWN, $address->classify('/joomla/.well-known/caldav'));
        self::assertNull($address->classify('/dav/'));
    }

    public function testAValidSettingIsUsed(): void
    {
        $address = DavAddress::fromSetting('https://CAL.Example.org/team/calendars/', 'https://www.example.org/');

        self::assertSame('https://cal.example.org/team/calendars/', $address->url);
        self::assertSame('/team/calendars/', $address->path);
        self::assertSame(DavAddress::KIND_DAV, $address->classify('/team/calendars/calendars/u1/'));
        self::assertNull($address->classify('/dav/'));
    }

    /**
     * @return  array<string, array{0: string}>
     */
    public static function invalidSettings(): array
    {
        return [
            'plain http'          => ['http://www.example.org/dav/'],
            'no host'             => ['https:///dav/'],
            'site root'           => ['https://www.example.org/'],
            'administrator'       => ['https://www.example.org/administrator/dav/'],
            'api'                 => ['https://www.example.org/api/'],
            'media'               => ['https://www.example.org/Media/x/'],
            'query'               => ['https://www.example.org/dav/?x=1'],
            'fragment'            => ['https://www.example.org/dav/#x'],
            'user info'           => ['https://u:p@www.example.org/dav/'],
            'encoded character'   => ['https://www.example.org/d%61v/'],
            'dot segment'         => ['https://www.example.org/../dav/'],
            'four segments'       => ['https://www.example.org/a/b/c/d/'],
            'outside a subfolder' => ['https://www.example.org/other/'],
            'space'               => ['https://www.example.org/da v/'],
            'not a URL'           => ['dav'],
        ];
    }

    /**
     * @dataProvider invalidSettings
     */
    public function testAnInvalidSettingFallsBackToTheDefault(string $setting): void
    {
        $root = str_contains($setting, 'other') ? 'https://www.example.org/joomla/' : 'https://www.example.org/';
        $address = DavAddress::fromSetting($setting, $root);

        self::assertSame(rtrim($root, '/') . '/dav/', $address->url);
    }

    public function testTheAuthorizationHeaderIsReadInTheSpecifiedOrder(): void
    {
        self::assertSame('Basic a', BasicCredentials::headerFromServer(['HTTP_AUTHORIZATION' => 'Basic a', 'REDIRECT_HTTP_AUTHORIZATION' => 'Basic b']));
        self::assertSame('Basic b', BasicCredentials::headerFromServer(['REDIRECT_HTTP_AUTHORIZATION' => 'Basic b', 'PHP_AUTH_USER' => 'u']));
        self::assertSame('Basic ' . base64_encode('u:p'), BasicCredentials::headerFromServer(['PHP_AUTH_USER' => 'u', 'PHP_AUTH_PW' => 'p']));
        self::assertNull(BasicCredentials::headerFromServer(['HTTP_AUTHORIZATION' => '  ']));
        self::assertNull(BasicCredentials::headerFromServer([]));
    }

    public function testBasicCredentialsAreParsedStrictly(): void
    {
        $ok = BasicCredentials::parse('Basic ' . base64_encode('Alice:ABCD-EFGH:with-colon'));
        self::assertNotNull($ok);
        self::assertSame('Alice', $ok->username);
        self::assertSame('ABCD-EFGH:with-colon', $ok->password);
        self::assertNotNull(BasicCredentials::parse('basic ' . base64_encode('a:b')));

        foreach ([
            null,
            '',
            'Bearer token',
            'Digest username="a"',
            'Basic',
            'Basic !!!',
            'Basic ' . base64_encode('nocolon'),
            'Basic ' . base64_encode(':password'),
            'Basic ' . base64_encode("a\nb:c"),
            'Basic ' . base64_encode("a:b\0"),
            'Basic ' . base64_encode(str_repeat('u', 151) . ':p'),
            'Basic ' . base64_encode("\xff\xfe:p"),
            'Basic ' . str_repeat('A', 1100),
        ] as $header) {
            self::assertNull(BasicCredentials::parse($header), var_export($header, true));
        }
    }
}
