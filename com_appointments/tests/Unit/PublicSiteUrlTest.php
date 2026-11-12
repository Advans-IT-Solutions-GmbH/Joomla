<?php
/**
 * @package     Appointments Tests
 * @copyright   (C) 2026 Advans IT Solutions GmbH <https://advans.ch>
 * @license     GNU General Public License version 3 or later; see LICENSE.txt
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

use Advans\Component\Appointments\Administrator\Helper\PublicSiteUrl;
use PHPUnit\Framework\TestCase;

/**
 * The only address mail links are built from. There is no fallback to the request.
 */
final class PublicSiteUrlTest extends TestCase
{
    public function testTheComponentOptionComesFirstThenLiveSite(): void
    {
        self::assertSame('https://www.example.test/site', PublicSiteUrl::resolve('https://www.example.test/site/', 'https://live.example.test'));
        self::assertSame('https://live.example.test', PublicSiteUrl::resolve('', 'https://live.example.test'));
        self::assertSame('http://localhost', PublicSiteUrl::resolve('http://localhost', ''));
    }

    public function testNothingConfiguredMeansNoAddress(): void
    {
        self::assertNull(PublicSiteUrl::resolve('', ''));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function refused(): array
    {
        return [
            'query'               => ['https://www.example.test/?x=1', ''],
            'fragment'            => ['https://www.example.test/#top', ''],
            'user and password'   => ['https://user:secret@www.example.test', ''],
            'upper-case scheme'   => ['HTTPS://www.example.test', ''],
            'surrounding spaces'  => [' https://www.example.test ', ''],
            // An option of spaces is used as it is, never replaced by live_site.
            'spaces only'         => ['   ', 'https://live.example.test'],
            'other scheme'        => ['ftp://www.example.test', ''],
            'relative'            => ['/index.php', ''],
            'invalid live_site'   => ['', 'live.example.test'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refused')]
    public function testValuesMailLinksWouldRefuseGiveNoAddress(string $option, string $liveSite): void
    {
        self::assertNull(PublicSiteUrl::resolve($option, $liveSite));
    }
}
