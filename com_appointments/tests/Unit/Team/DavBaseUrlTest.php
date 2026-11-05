<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Service\Team\TeamServices;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/administrator/components/com_appointments/src/Service/Team/TeamServices.php';

final class DavBaseUrlTest extends TestCase
{
    private const ROOT = 'https://www.example.org/';

    public function testAcceptedAddressesAreNormalised(): void
    {
        self::assertSame('https://www.example.org/dav/', TeamServices::acceptDavBaseUrl('https://www.example.org/dav', self::ROOT));
        self::assertSame('https://calendar.example.org/dav/', TeamServices::acceptDavBaseUrl('https://Calendar.Example.org/dav/', self::ROOT));
        self::assertSame('https://www.example.org/site/cal/dav/', TeamServices::acceptDavBaseUrl('https://www.example.org/site/cal/dav/', 'https://www.example.org/site/'));
        self::assertSame('https://www.example.org:8443/a/b/c/', TeamServices::acceptDavBaseUrl('https://www.example.org:8443/a/b/c', self::ROOT));
    }

    public function testUnusableAddressesAreRefused(): void
    {
        foreach ([
            '',
            'http://www.example.org/dav/',
            'https:///dav/',
            'https://user:pass@www.example.org/dav/',
            'https://www.example.org/dav/?x=1',
            'https://www.example.org/dav/#x',
            'https://www.example.org/',
            'https://www.example.org/a/b/c/d/',
            'https://www.example.org/administrator/',
            'https://www.example.org/Media/dav/',
            'https://www.example.org/.well-known/',
            'https://www.example.org/da v/',
            'https://www.example.org/.dav/',
            'https://www.example.org/other/dav/' . str_repeat('x', 260),
        ] as $url) {
            self::assertNull(TeamServices::acceptDavBaseUrl($url, self::ROOT), $url);
        }

        self::assertNull(TeamServices::acceptDavBaseUrl('https://www.example.org/dav/', 'https://www.example.org/site/'));
    }
}
